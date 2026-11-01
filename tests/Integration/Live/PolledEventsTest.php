<?php
/**
 * Tests, against Stripe's test mode, Stripe's real events delivered after the store's own answers, and twice
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration\Live;

use SEOCart\GatewayForStripe\Tests\Support\Live\EventSelection;
use SEOCart\GatewayForStripe\Tests\Support\Live\MessageGuard;

require_once __DIR__ . '/LiveStripeCase.php';

/**
 * Stripe's own events of an authorization, a capture, a partial refund, a void and a decline, read back from Stripe's Events list and delivered to the store's webhook route after the store had each answer, then each delivered again, settle nothing twice.
 *
 * Every delivery is answered 200 and leaves one decided receipt per event; no order has two
 * money rows of one operation, none is flagged for a person, the store's doctor finds the ledger
 * sound and no stock hold is left. Events Stripe wrote at the API version the adapter pins must
 * leave each order's ledger exactly as the store's own answers left it. Stripe writes the Events
 * list at the account's default version, which may be another: what the store answered each
 * event is then reported, by type, version and answer, and only the safety conditions are held.
 *
 * @since 0.2.0
 *
 * @group stripe-live
 */
final class PolledEventsTest extends LiveStripeCase {

	/**
	 * Tests that real events delivered after the store's answers, and again, change nothing the answers settled.
	 *
	 * @since 0.2.0
	 */
	public function test_real_events_delivered_after_the_answers_and_twice_settle_nothing_twice(): void {
		$this->begin( 'live-polled-events' );

		$placed = array(
			$this->placeKind( 'refunded', true ),
			$this->placeKind( 'voided' ),
			$this->placeKind( 'declined' ),
		);

		$this->end();

		$events = $this->eventsOf( self::intentsOf( $placed ), self::expectedEvents( $placed ) );
		$secret = $this->runSecret();
		$tally  = array();
		$faults = array();

		foreach ( array( 'after', 'again' ) as $round ) {
			foreach ( $events as $event ) {
				$answer = $this->deliver( $event, new \DateTimeImmutable(), $secret );
				$tally  = self::tallied( $tally, $answer, $event );

				if ( 200 !== $answer['status'] ) {
					$faults[] = MessageGuard::say( '%1$s delivered %2$s answered %3$d %4$s', (string) ( $event['id'] ?? '' ), $round, $answer['status'], (string) $answer['code'] );
				}
			}
		}

		self::reportTally( 'api-versions', EventSelection::apiVersions( $events ) );
		self::reportTally( 'polled-answers', $tally );

		$this->assertSame( array(), $faults, 'A real event was not answered 200.' );
		$this->assertSafe( array_column( $placed, 'order_id' ), array_map( static fn( array $event ): string => (string) ( $event['id'] ?? '' ), $events ) );

		if ( array() === array_filter( $events, static fn( array $event ): bool => ! self::atThePin( $event ) ) ) {
			$this->assertLedgersAsTheirKinds( $placed );
		}
	}
}
