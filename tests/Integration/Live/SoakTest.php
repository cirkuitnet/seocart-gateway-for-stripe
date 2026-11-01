<?php
/**
 * Tests, against Stripe's test mode, a soak of Stripe's real events delivered to the store
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration\Live;

use SEOCart\GatewayForStripe\Tests\Support\Live\EventSelection;
use SEOCart\GatewayForStripe\Tests\Support\Live\LiveRun;
use SEOCart\GatewayForStripe\Tests\Support\Live\MessageGuard;
use SEOCart\GatewayForStripe\Tests\Support\Live\SoakPlan;

require_once __DIR__ . '/LiveStripeCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/Live/SoakPlan.php';

/**
 * A soak of Stripe's real events: a run's payments, captured, voided, refunded, declined and disputed at Stripe, whose events are read back and delivered as SEOCart's own soak delivers its stand-in's (SoakPlan), settle every payment once.
 *
 * Every genuine delivery is answered 200, and every copy re-signed with a wrong secret or signed
 * 400 seconds before the clock is answered 401 and leaves no receipt. The conditions are those of
 * PolledEventsTest: the safety conditions always, each order's ledger exactly as its kind leaves
 * it when Stripe wrote every event at the API version the adapter pins. SEOCART_SOAK_SIZE gives
 * the number of payments (7 to 60), and SEOCART_SOAK_SEED the seed, which the report keeps so a
 * red run can be repeated.
 *
 * @since 0.2.0
 *
 * @group stripe-live
 */
final class SoakTest extends LiveStripeCase {

	/**
	 * The soak's size when SEOCART_SOAK_SIZE does not say.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DEFAULT_SIZE = '12';

	/**
	 * Tests that a soak of real events, delivered out of order, more than once, forged and stale, settles every payment once.
	 *
	 * @since 0.2.0
	 */
	public function test_a_soak_of_real_events_settles_every_payment_once(): void {
		$size = SoakPlan::size( '' === (string) getenv( LiveRun::SOAK_SIZE ) ? self::DEFAULT_SIZE : (string) getenv( LiveRun::SOAK_SIZE ) );
		$seed = '' === (string) getenv( LiveRun::SOAK_SEED ) ? random_int( 1, 2147483647 ) : (int) getenv( LiveRun::SOAK_SEED );

		LiveRun::report( 'seed', $seed );
		LiveRun::report( 'soak-size', $size );

		$this->begin( 'live-soak' );

		$placed  = array();
		$refunds = 0;

		foreach ( SoakPlan::kinds( $size ) as $kind ) {
			$placed[] = $this->placeKind( $kind, 'refunded' === $kind && 0 === $refunds++ );
		}

		$this->end();

		$events = $this->eventsOf( self::intentsOf( $placed ), self::expectedEvents( $placed ) );
		$byId   = array();
		$ids    = array();

		foreach ( EventSelection::byIntent( $events ) as $intent => $ofIntent ) {
			foreach ( $ofIntent as $event ) {
				$byId[ (string) $event['id'] ] = $event;
				$ids[ $intent ][]              = (string) $event['id'];
			}
		}

		$plan = SoakPlan::plan( $ids, $seed );

		$this->assertSame( array(), SoakPlan::violations( $ids, $plan ), 'The soak\'s plan is not sound.' );

		$secret = $this->runSecret();
		$wrong  = 'whsec_' . bin2hex( random_bytes( 16 ) );
		$tally  = array();
		$faults = array();

		foreach ( $plan as $position => $delivery ) {
			$event  = $byId[ $delivery['event'] ];
			$answer = match ( $delivery['kind'] ) {
				'wrong_secret' => $this->deliver( $event, new \DateTimeImmutable(), $wrong ),
				'stale'        => $this->deliver( $event, new \DateTimeImmutable(), $secret, SoakPlan::STALE_SECONDS ),
				default        => $this->deliver( $event, new \DateTimeImmutable(), $secret ),
			};
			$tally = self::tallied( $tally, $answer, $event );

			if ( ( 'rejected' === $delivery['expect'] ? 401 : 200 ) !== $answer['status'] ) {
				$faults[] = MessageGuard::say( 'delivery %1$d, %2$s of %3$s, answered %4$d %5$s', $position, $delivery['kind'], $delivery['event'], $answer['status'], (string) $answer['code'] );
			}
		}

		self::reportTally( 'api-versions', EventSelection::apiVersions( $events ) );
		self::reportTally( 'soak-answers', $tally );
		LiveRun::report( 'soak-deliveries', count( $plan ) );

		$this->assertSame( array(), $faults, 'A delivery of the soak was answered otherwise than its kind expects.' );
		$this->assertSafe( array_column( $placed, 'order_id' ), array_keys( $byId ) );

		if ( array() === array_filter( $events, static fn( array $event ): bool => ! self::atThePin( $event ) ) ) {
			$this->assertLedgersAsTheirKinds( $placed );
		}
	}
}
