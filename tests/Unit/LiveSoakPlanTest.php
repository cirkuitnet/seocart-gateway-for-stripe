<?php
/**
 * Tests the plan of the Stripe soak
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\SoakPlan;

/**
 * The soak's size is a whole number from 7 to 60; its payments, at every size, are of every kind it promises; and its plan delivers every real event first in its payment's order, one to three times genuinely, with further copies only after the first and every forged or stale copy expected refused.
 *
 * Planted violations, each shown red and removed:
 * - SoakPlan::MAX_SIZE raised to 61: a size of 61 is accepted;
 * - SoakPlan::MIN_SIZE lowered back to 4: a size of 4 is accepted, and its soak places no void and no refund;
 * - in SoakPlan::plan(), a copy placed before its event's first delivery (its position drawn from 0): violations() names it, and the plan test fails;
 * - in SoakPlan::violations(), the order check removed: the plan with one payment's order broken passes.
 *
 * @since 0.2.0
 */
final class LiveSoakPlanTest extends TestCase {

	/**
	 * Three payments' events, in the order Stripe created them.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<string>>
	 */
	private const EVENTS = array(
		'pi_a' => array( 'evt_a1', 'evt_a2', 'evt_a3' ),
		'pi_b' => array( 'evt_b1', 'evt_b2' ),
		'pi_c' => array( 'evt_c1' ),
	);

	/**
	 * Tests that the size is read from the input, within its bounds.
	 *
	 * @since 0.2.0
	 */
	public function test_the_size_is_a_whole_number_from_7_to_60(): void {
		$this->assertSame( 12, SoakPlan::size( '12' ) );
		$this->assertSame( 7, SoakPlan::size( '7' ) );
		$this->assertSame( 60, SoakPlan::size( '60' ) );

		foreach ( array( '61', '6', '4', '3', '0', '', '012', '12.5', ' 12', '1e2', '-4', 'twelve' ) as $refused ) {
			try {
				SoakPlan::size( $refused );
				$this->fail( sprintf( 'The size "%s" was accepted.', $refused ) );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertStringContainsString( 'from 7 to 60', $expected->getMessage() );
			}
		}
	}

	/**
	 * Tests that every size the soak takes places every kind it claims: captured, declined, disputed, refunded and voided.
	 *
	 * @since 0.2.0
	 */
	public function test_every_size_from_the_fewest_places_every_kind(): void {
		$this->assertSame( array( 'declined', 'disputed', 'declined', 'captured', 'voided', 'captured', 'refunded' ), SoakPlan::kinds( 7 ) );

		for ( $size = SoakPlan::MIN_SIZE; $size <= SoakPlan::MAX_SIZE; $size++ ) {
			$kinds = array_unique( SoakPlan::kinds( $size ) );

			sort( $kinds );

			$this->assertSame( array( 'captured', 'declined', 'disputed', 'refunded', 'voided' ), $kinds, sprintf( 'A soak of %d payments.', $size ) );
		}
	}

	/**
	 * Tests the kinds of the default soak's payments: two declined, one disputed, and the rest captured, voided or refunded.
	 *
	 * @since 0.2.0
	 */
	public function test_the_default_soak_places_each_kind(): void {
		$kinds = array_count_values( SoakPlan::kinds( 12 ) );

		ksort( $kinds );

		$this->assertSame(
			array(
				'captured' => 5,
				'declined' => 2,
				'disputed' => 1,
				'refunded' => 2,
				'voided'   => 2,
			),
			$kinds
		);
		$this->assertCount( 7, SoakPlan::kinds( 7 ) );
		$this->assertCount( 60, SoakPlan::kinds( 60 ) );
	}

	/**
	 * Tests that the plan of many seeds is sound, the same for one seed, and holds refused copies.
	 *
	 * @since 0.2.0
	 */
	public function test_every_plan_is_sound_and_repeatable(): void {
		$refused = 0;

		for ( $seed = 1; $seed <= 200; $seed++ ) {
			$plan = SoakPlan::plan( self::EVENTS, $seed );

			$this->assertSame( array(), SoakPlan::violations( self::EVENTS, $plan ), sprintf( 'The plan of the seed %d.', $seed ) );
			$refused += count( array_filter( $plan, static fn( array $delivery ): bool => 'rejected' === $delivery['expect'] ) );
		}

		$this->assertSame( SoakPlan::plan( self::EVENTS, 7 ), SoakPlan::plan( self::EVENTS, 7 ) );
		$this->assertGreaterThan( 0, $refused, 'No plan of 200 seeds holds a forged or stale copy.' );
	}

	/**
	 * Tests that a plan with one payment's events out of order is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_plan_with_one_payments_order_broken_is_refused(): void {
		$plan    = SoakPlan::plan( self::EVENTS, 3 );
		$firstA1 = self::firstOf( $plan, 'evt_a1' );
		$firstA2 = self::firstOf( $plan, 'evt_a2' );

		$plan[ $firstA1 ]['event'] = 'evt_a2';
		$plan[ $firstA2 ]['event'] = 'evt_a1';

		$this->assertContains( 'The events of the payment pi_a are not each delivered first once, in the order Stripe created them.', SoakPlan::violations( self::EVENTS, $plan ) );
	}

	/**
	 * Tests that a forged copy expected to settle is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_forged_copy_expected_to_settle_is_refused(): void {
		$plan   = SoakPlan::plan( self::EVENTS, 3 );
		$plan[] = array(
			'event'   => 'evt_b1',
			'payment' => 'pi_b',
			'kind'    => 'wrong_secret',
			'expect'  => 'settled',
		);

		$this->assertContains( sprintf( 'Delivery %d, a wrong_secret delivery of evt_b1, is expected settled.', count( $plan ) - 1 ), SoakPlan::violations( self::EVENTS, $plan ) );
	}

	/**
	 * Tests that a copy delivered before its event's first delivery, or an event delivered genuinely four times, is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_an_early_copy_and_a_fourth_delivery_are_refused(): void {
		$copy = array(
			'event'   => 'evt_c1',
			'payment' => 'pi_c',
			'kind'    => 'copy',
			'expect'  => 'settled',
		);
		$plan = array_merge( array( $copy ), SoakPlan::plan( self::EVENTS, 3 ), array( $copy, $copy, $copy ) );

		$problems = SoakPlan::violations( self::EVENTS, $plan );

		$this->assertContains( 'Delivery 0, a copy of evt_c1, comes before the event\'s first delivery.', $problems );
		$this->assertNotSame( array(), preg_grep( '/^The event evt_c1 is delivered genuinely \d+ times, more than 3\.$/', $problems ) );
	}

	/**
	 * Returns the position of an event's first delivery.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{event: string, payment: string, kind: string, expect: string}> $plan  The plan.
	 * @param string                                                                    $event The event.
	 * @return int The position.
	 */
	private static function firstOf( array $plan, string $event ): int {
		foreach ( $plan as $position => $delivery ) {
			if ( $event === $delivery['event'] && 'first' === $delivery['kind'] ) {
				return $position;
			}
		}

		self::fail( sprintf( 'The plan never delivers %s first.', $event ) );
	}
}
