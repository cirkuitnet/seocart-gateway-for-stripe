<?php
/**
 * SoakPlan: the payments of the Stripe soak, and the order their real events are delivered in
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Says what the soak does with Stripe's test mode, and in what order it delivers the events Stripe sent back.
 *
 * The soak places `size` payments: two declined, one with Stripe's dispute card, and the rest
 * captured, voided or captured then refunded (the first of those in part). Their events, read back
 * from Stripe, are delivered as SEOCart's own soak delivers its stand-in's: each payment's events
 * in the order Stripe created them, the payments merged at random, each event delivered once more
 * or twice more at random somewhere after its first delivery, and of those further copies about one
 * in twenty re-signed with a wrong secret and one in twenty signed 400 seconds before the clock,
 * which the store must refuse without a receipt. A seed decides every choice, so a red run can be
 * repeated.
 *
 * @since 0.2.0
 */
final class SoakPlan {

	/**
	 * The fewest payments a soak places: the fewest that place every kind, a void and a refund among them.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MIN_SIZE = 7;

	/**
	 * The most payments a soak places: about 200 requests, well under Stripe's test-mode rate limit.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_SIZE = 60;

	/**
	 * The share, in percent, of further copies re-signed with a wrong secret; as many more are signed too long ago.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const REJECTED_PERCENT = 5;

	/**
	 * How many further copies an event gets at most: every event is delivered genuinely one to three times.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_COPIES = 2;

	/**
	 * How far before the clock a stale copy is signed, in seconds: beyond the 300 Stripe's signature allows.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const STALE_SECONDS = 400;

	/**
	 * Each kind of delivery, and what the store must answer it.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const EXPECTED = array(
		'first'        => 'settled',
		'copy'         => 'settled',
		'wrong_secret' => 'rejected',
		'stale'        => 'rejected',
	);

	/**
	 * Reads the soak's size as the workflow's input gives it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When it is not a whole number from MIN_SIZE to MAX_SIZE.
	 *
	 * @param string $input The input.
	 * @return int The size.
	 */
	public static function size( string $input ): int {
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,2}$/D', $input ) || (int) $input < self::MIN_SIZE || (int) $input > self::MAX_SIZE ) {
			throw new \InvalidArgumentException( sprintf( 'The soak\'s size is a whole number from %1$d to %2$d.', self::MIN_SIZE, self::MAX_SIZE ) );
		}

		return (int) $input;
	}

	/**
	 * Returns the kind of each payment of a soak: declined, disputed, captured, voided or refunded.
	 *
	 * @since 0.2.0
	 *
	 * @param int $size The size.
	 * @return list<string> Each payment's kind, in the order placed.
	 */
	public static function kinds( int $size ): array {
		$kinds = array( 'declined', 'disputed', 'declined' );
		$cycle = array( 'captured', 'voided', 'captured', 'refunded' );

		$fixed = count( $kinds );

		for ( $index = 0; $index < $size - $fixed; $index++ ) {
			$kinds[] = $cycle[ $index % count( $cycle ) ];
		}

		return array_slice( $kinds, 0, $size );
	}

	/**
	 * Plans the deliveries of the payments' events.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, list<string>> $byPayment Each payment's event ids, in the order Stripe created them, by payment.
	 * @param int                         $seed      The seed.
	 * @return list<array{event: string, payment: string, kind: string, expect: string}> The deliveries, in order.
	 */
	public static function plan( array $byPayment, int $seed ): array {
		$random = new Randomizer( new Mt19937( $seed ) );
		$firsts = self::merged( $byPayment, $random );
		$after  = array_fill( 0, count( $firsts ), array() );

		foreach ( $firsts as $position => $first ) {
			for ( $copy = $random->getInt( 0, self::MAX_COPIES ); $copy > 0; $copy-- ) {
				$roll = $random->getInt( 1, 100 );
				$kind = $roll <= self::REJECTED_PERCENT ? 'wrong_secret' : ( $roll <= 2 * self::REJECTED_PERCENT ? 'stale' : 'copy' );

				$after[ $random->getInt( $position, count( $firsts ) - 1 ) ][] = array(
					'event'   => $first['event'],
					'payment' => $first['payment'],
					'kind'    => $kind,
					'expect'  => self::EXPECTED[ $kind ],
				);
			}
		}

		$plan = array();

		foreach ( $firsts as $position => $first ) {
			$plan[] = $first;

			foreach ( $after[ $position ] as $copy ) {
				$plan[] = $copy;
			}
		}

		return $plan;
	}

	/**
	 * Checks a plan against the payments' events: what a plan must be for the soak to prove anything.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, list<string>>                                               $byPayment Each payment's event ids, in the order Stripe created them, by payment.
	 * @param list<array{event: string, payment: string, kind: string, expect: string}> $plan      The deliveries.
	 * @return list<string> What is wrong; none for a sound plan.
	 */
	public static function violations( array $byPayment, array $plan ): array {
		$problems = array();
		$genuine  = array();
		$firsts   = array();

		foreach ( $plan as $position => $delivery ) {
			$event = $delivery['event'];

			if ( ! in_array( $event, $byPayment[ $delivery['payment'] ] ?? array(), true ) ) {
				$problems[] = sprintf( 'Delivery %1$d is of %2$s, which is no event of the payment %3$s.', $position, $event, $delivery['payment'] );
				continue;
			}

			if ( ( self::EXPECTED[ $delivery['kind'] ] ?? null ) !== $delivery['expect'] ) {
				$problems[] = sprintf( 'Delivery %1$d, a %2$s delivery of %3$s, is expected %4$s.', $position, $delivery['kind'], $event, $delivery['expect'] );
			}

			if ( 'first' === $delivery['kind'] ) {
				if ( isset( $firsts[ $event ] ) ) {
					$problems[] = sprintf( 'The event %s is delivered first twice.', $event );
				}

				$firsts[ $event ]                  = $position;
				$genuine[ $delivery['payment'] ][] = $event;
			} elseif ( ! isset( $firsts[ $event ] ) ) {
				$problems[] = sprintf( 'Delivery %1$d, a copy of %2$s, comes before the event\'s first delivery.', $position, $event );
			}
		}

		foreach ( $byPayment as $payment => $events ) {
			if ( ( $genuine[ $payment ] ?? array() ) !== $events ) {
				$problems[] = sprintf( 'The events of the payment %s are not each delivered first once, in the order Stripe created them.', $payment );
			}
		}

		$counts = array_count_values( array_column( array_filter( $plan, static fn( array $delivery ): bool => 'settled' === $delivery['expect'] ), 'event' ) );

		foreach ( $counts as $event => $count ) {
			if ( $count > 1 + self::MAX_COPIES ) {
				$problems[] = sprintf( 'The event %1$s is delivered genuinely %2$d times, more than %3$d.', $event, $count, 1 + self::MAX_COPIES );
			}
		}

		return $problems;
	}

	/**
	 * Merges the payments' events at random, each payment's in its order: the first delivery of every event.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, list<string>> $byPayment Each payment's event ids, in order, by payment.
	 * @param Randomizer                  $random    The seeded source.
	 * @return list<array{event: string, payment: string, kind: string, expect: string}> The first deliveries.
	 */
	private static function merged( array $byPayment, Randomizer $random ): array {
		$next   = array_fill_keys( array_keys( array_filter( $byPayment, static fn( array $events ): bool => array() !== $events ) ), 0 );
		$merged = array();

		while ( array() !== $next ) {
			$payment = (string) array_keys( $next )[ $random->getInt( 0, count( $next ) - 1 ) ];

			$merged[] = array(
				'event'   => $byPayment[ $payment ][ $next[ $payment ] ],
				'payment' => $payment,
				'kind'    => 'first',
				'expect'  => self::EXPECTED['first'],
			);

			if ( ++$next[ $payment ] >= count( $byPayment[ $payment ] ) ) {
				unset( $next[ $payment ] );
			}
		}

		return $merged;
	}
}
