<?php
/**
 * Tests that only the run's own events are taken from a shared Stripe account
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\EventSelection;

/**
 * The events of the run's PaymentIntents, and of their refunds and disputes, are kept, in the order Stripe created them; an event of any other PaymentIntent is not, even one tagged with a run's uuid or naming a run's id alone.
 *
 * Planted violation, shown red and removed: in EventSelection::isOurs(), keep a PaymentIntent's
 * event by its uuid alone: the planted event of another PaymentIntent tagged with the run's uuid
 * is kept.
 *
 * @since 0.2.0
 */
final class LiveEventSelectionTest extends TestCase {

	/**
	 * The run's intent uuid.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UUID = '0f9c2b1e-4d3a-5b6c-8d7e-9f0a1b2c3d4e';

	/**
	 * Tests that the run's events are kept and every planted event of another is not.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_runs_events_are_kept(): void {
		$intents = array( 'pi_run' => self::UUID );
		$events  = array(
			self::intentEvent( 'evt_1', 'pi_run', self::UUID, 10 ),
			self::intentEvent( 'evt_planted_other_uuid', 'pi_other', '11111111-2222-5333-8444-555555555555', 10 ),
			self::intentEvent( 'evt_planted_run_uuid_other_id', 'pi_other', self::UUID, 10 ),
			self::intentEvent( 'evt_planted_run_id_other_uuid', 'pi_run', '11111111-2222-5333-8444-555555555555', 10 ),
			self::objectEvent( 'evt_2', 'refund', 'pi_run', 11 ),
			self::objectEvent( 'evt_planted_refund', 'refund', 'pi_other', 11 ),
			self::objectEvent( 'evt_3', 'dispute', 'pi_run', 12 ),
			self::objectEvent( 'evt_planted_charge', 'charge', 'pi_run', 12 ),
			array( 'id' => 'evt_planted_shapeless' ),
		);

		$this->assertSame( array( 'evt_1', 'evt_2', 'evt_3' ), array_column( EventSelection::ours( $events, $intents ), 'id' ) );
	}

	/**
	 * Tests that the events are grouped by PaymentIntent, oldest first, and their API versions tallied.
	 *
	 * @since 0.2.0
	 */
	public function test_events_are_grouped_oldest_first_and_their_versions_tallied(): void {
		$events = array(
			self::objectEvent( 'evt_refund', 'refund', 'pi_run', 30 ),
			self::intentEvent( 'evt_authorized', 'pi_run', self::UUID, 10 ),
			self::intentEvent( 'evt_captured', 'pi_run', self::UUID, 20 ),
		);

		$events[0]['api_version'] = '2026-09-30.endive';

		$this->assertSame( array( 'evt_authorized', 'evt_captured', 'evt_refund' ), array_column( EventSelection::byIntent( $events )['pi_run'], 'id' ) );
		$this->assertSame(
			array(
				'2020-08-27'        => 2,
				'2026-09-30.endive' => 1,
			),
			EventSelection::apiVersions( $events )
		);
	}

	/**
	 * Returns an event of a PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id      The event's id.
	 * @param string $intent  The PaymentIntent's id.
	 * @param string $uuid    The uuid it is tagged with.
	 * @param int    $created When it was created.
	 * @return array<string, mixed> The event.
	 */
	private static function intentEvent( string $id, string $intent, string $uuid, int $created ): array {
		return array(
			'id'          => $id,
			'created'     => $created,
			'api_version' => '2020-08-27',
			'data'        => array(
				'object' => array(
					'id'       => $intent,
					'object'   => 'payment_intent',
					'metadata' => array( 'intent_uuid' => $uuid ),
				),
			),
		);
	}

	/**
	 * Returns an event of an object that names a PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id      The event's id.
	 * @param string $kind    The object's kind.
	 * @param string $intent  The PaymentIntent it names.
	 * @param int    $created When it was created.
	 * @return array<string, mixed> The event.
	 */
	private static function objectEvent( string $id, string $kind, string $intent, int $created ): array {
		return array(
			'id'          => $id,
			'created'     => $created,
			'api_version' => '2020-08-27',
			'data'        => array(
				'object' => array(
					'id'             => $kind . '_1',
					'object'         => $kind,
					'payment_intent' => $intent,
				),
			),
		);
	}
}
