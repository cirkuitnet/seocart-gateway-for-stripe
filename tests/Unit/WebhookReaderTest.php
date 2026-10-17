<?php
/**
 * Tests what each verified Stripe event reads as
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\GatewayForStripe\Tests\Support\FakeContext;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\GatewayForStripe\WebhookReader;

/**
 * Each event type the gateway subscribes to reads as one result, from the PaymentIntent's metadata or the Refund's own
 * fields; a dispute is ignored with SEOCart's reason and names the PaymentIntent it concerns; every other type is
 * ignored; a PaymentIntent another integration created is ignored; an event of the other mode than its address is
 * rejected, after the signature holds.
 *
 * Planted violations, each shown red and removed:
 * - the `livemode` check removed: the live event on the test address is read;
 * - a Refund's PaymentIntent left unchecked: the other integration's refund is not ignored;
 * - a refund's result without its PaymentIntent: SEOCart could not find the payment it refunds;
 * - a dispute's PaymentIntent not passed on, or passed on unchecked, or another reason than SEOCart's.
 *
 * @since 0.2.0
 */
final class WebhookReaderTest extends GatewayTestCase {

	/**
	 * Tests the four PaymentIntent events.
	 *
	 * @since 0.2.0
	 */
	public function test_each_payment_intent_event_reads_as_its_result(): void {
		$events = array(
			'event-payment_intent.amount_capturable_updated' => array( Operation::Authorize, Outcome::Approved, 2500, Fixtures::CHARGE_ID, null ),
			'event-payment_intent.succeeded'      => array( Operation::Capture, Outcome::Approved, 2500, Fixtures::CHARGE_ID, null ),
			'event-payment_intent.canceled'       => array( Operation::Void, Outcome::Approved, 2500, Fixtures::INTENT_ID, null ),
			'event-payment_intent.payment_failed' => array( Operation::Authorize, Outcome::Declined, 2500, 'ch_3Fixture0002', 'insufficient_funds' ),
		);

		foreach ( $events as $fixture => $expected ) {
			$reading = $this->read( Fixtures::text( $fixture ) );

			$this->assertSame( WebhookReadingKind::Result, $reading->kind, $fixture );
			$this->assertNotNull( $reading->result );
			$this->assertSame( Fixtures::INTENT_UUID, $reading->result->intentUuid );
			$this->assertSame( Fixtures::INTENT_ID, $reading->result->providerIntentId );
			$this->assertSame( $expected, array( $reading->result->operation, $reading->result->outcome, $reading->result->amount->minorUnits(), $reading->result->providerObjectId, $reading->result->errorCode ), $fixture );
			$this->assertSame( '2025-10-09T08:55:00+00:00', $reading->occurredAt?->format( 'c' ), 'When the event happened, information only.' );
		}

		$this->assertSame( 'automatic', $this->context->log->contextOf( 'stripe.intent_canceled' )['cancellation_reason'] ?? null );
	}

	/**
	 * Tests the Refund events: the PaymentIntent and the refund's uuid, or none for a refund made elsewhere.
	 *
	 * @since 0.2.0
	 */
	public function test_each_refund_event_reads_as_the_refunds_result(): void {
		$created   = $this->read( Fixtures::text( 'event-refund.created' ) );
		$failed    = $this->read( Fixtures::text( 'event-refund.failed' ) );
		$dashboard = $this->read( Fixtures::text( 'event-refund.updated-dashboard' ) );

		foreach ( array( $created, $failed, $dashboard ) as $reading ) {
			$this->assertNotNull( $reading->result );
			$this->assertSame( array( Operation::Refund, '', Fixtures::INTENT_ID ), array( $reading->result->operation, $reading->result->intentUuid, $reading->result->providerIntentId ) );
		}

		$this->assertSame(
			array_values( array_filter( array_keys( WebhookReader::EVENTS ), static fn( string $type ): bool => str_starts_with( $type, 'refund.' ) ) ),
			array( $created->eventType, $dashboard->eventType, $failed->eventType ),
			'Every Refund event the gateway reads is read above: SEOCart finds the payment of each by its PaymentIntent.'
		);

		$this->assertSame( array( Outcome::Approved, 're_3Fixture0001', Fixtures::REFUND_UUID ), array( $created->result?->outcome, $created->result?->providerObjectId, $created->refundUuid ) );
		$this->assertSame( array( Outcome::Declined, 'expired_or_canceled_card' ), array( $failed->result?->outcome, $failed->result?->errorCode ) );
		$this->assertSame( $created->result?->providerObjectId, $failed->result?->providerObjectId, 'A refund that fails after it was given is reported, declined, for the same Refund: SEOCart\'s ledger tells it from the approval by its outcome.' );
		$this->assertSame( array( 're_3Fixture0099', null ), array( $dashboard->result?->providerObjectId, $dashboard->refundUuid ), 'A refund made at Stripe carries no uuid of SEOCart\'s.' );
		$this->assertSame( array( 'stripe.refund_failed' ), $this->context->log->codes() );
	}

	/**
	 * Tests the events read and ignored: a dispute, a charge refunded, a type the gateway does not read.
	 *
	 * @since 0.2.0
	 */
	public function test_disputes_and_other_events_are_ignored(): void {
		$expected = array(
			'event-charge.dispute.created' => WebhookReading::DISPUTE,
			'event-charge.refunded'        => WebhookReader::UNHANDLED_TYPE,
			'event-customer.created'       => WebhookReader::UNHANDLED_TYPE,
		);

		foreach ( $expected as $fixture => $reason ) {
			$reading = $this->read( Fixtures::text( $fixture ) );

			$this->assertSame( array( WebhookReadingKind::Ignored, $reason ), array( $reading->kind, $reading->reason ), $fixture );
			$this->assertNotNull( $reading->eventId );
		}
	}

	/**
	 * Tests a dispute: ignored with SEOCart's reason, naming the PaymentIntent it concerns and no intent uuid, even when the Dispute's own metadata holds one.
	 *
	 * @since 0.2.0
	 */
	public function test_a_dispute_names_the_payment_intent_it_concerns(): void {
		$withMetadata = Fixtures::with( 'event-charge.dispute.created', array( 'data' => array( 'object' => array( 'metadata' => array( 'intent_uuid' => Fixtures::INTENT_UUID ) ) ) ) );

		foreach ( array( Fixtures::text( 'event-charge.dispute.created' ), $withMetadata ) as $body ) {
			$reading = $this->read( $body );

			$this->assertSame( array( WebhookReadingKind::Ignored, WebhookReading::DISPUTE, Fixtures::INTENT_ID, null ), array( $reading->kind, $reading->reason, $reading->providerIntentId, $reading->intentUuid ) );
		}
	}

	/**
	 * Tests a dispute whose `payment_intent` is missing, null, expanded or not a PaymentIntent's id: still ignored, naming none, never rejected, so Stripe does not deliver it again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_dispute_without_a_payment_intent_id_names_none(): void {
		$missing = Fixtures::object( 'event-charge.dispute.created' );
		unset( $missing['data']['object']['payment_intent'] );
		$bodies   = array( Fixtures::encode( $missing ) );
		$expanded = array(
			'id'     => Fixtures::INTENT_ID,
			'object' => 'payment_intent',
		);

		foreach ( array( null, $expanded, '', 12345, 'ch_3Fixture0001', 'sk_test_leakedInAnAnswer' ) as $intent ) {
			$bodies[] = Fixtures::with( 'event-charge.dispute.created', array( 'data' => array( 'object' => array( 'payment_intent' => $intent ) ) ) );
		}

		foreach ( $bodies as $body ) {
			$reading = $this->read( $body );

			$this->assertSame( array( WebhookReadingKind::Ignored, WebhookReading::DISPUTE, 'evt_3Fixture0008', null, null ), array( $reading->kind, $reading->reason, $reading->eventId, $reading->providerIntentId, $reading->intentUuid ) );
		}
	}

	/**
	 * Tests PaymentIntent events SEOCart does not act on: another integration's PaymentIntent, one holding nothing to capture.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_intent_that_is_not_ours_or_holds_nothing_is_ignored(): void {
		$event = Fixtures::object( 'event-payment_intent.amount_capturable_updated' );

		$foreign                                        = $event;
		$foreign['data']['object']['metadata']          = array();
		$nothing                                        = $event;
		$nothing['data']['object']['status']            = 'succeeded';
		$nothing['data']['object']['amount_capturable'] = 0;

		$this->assertSame( WebhookReader::NOT_OURS, $this->read( Fixtures::encode( $foreign ) )->reason );
		$this->assertSame( WebhookReader::NOTHING_CAPTURABLE, $this->read( Fixtures::encode( $nothing ) )->reason );
	}

	/**
	 * Tests Refund events of another integration (no PaymentIntent, a JSON null one, a charge's id, a key): ignored as not SEOCart's, so Stripe does not deliver them again; an object that is not a Refund stays malformed.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_without_a_payment_intent_is_not_ours(): void {
		$missing = Fixtures::object( 'event-refund.created' );
		unset( $missing['data']['object']['payment_intent'] );
		$bodies = array( Fixtures::encode( $missing ) );

		foreach ( array( null, 'ch_3Fixture0001', 'sk_test_leakedInAnAnswer' ) as $intent ) {
			$bodies[] = Fixtures::with( 'event-refund.created', array( 'data' => array( 'object' => array( 'payment_intent' => $intent ) ) ) );
		}

		foreach ( $bodies as $body ) {
			$reading = $this->read( $body );

			$this->assertSame( array( WebhookReadingKind::Ignored, WebhookReader::NOT_OURS, 'evt_3Fixture0005', 'refund.created', null ), array( $reading->kind, $reading->reason, $reading->eventId, $reading->eventType, $reading->result ) );
		}

		$this->assertSame( WebhookReading::MALFORMED, $this->read( Fixtures::with( 'event-refund.created', array( 'data' => array( 'object' => array( 'object' => 'charge' ) ) ) ) )->reason );
	}

	/**
	 * Tests events of the other mode than their address, both ways: rejected, though signed.
	 *
	 * @since 0.2.0
	 */
	public function test_an_event_of_the_other_mode_is_rejected(): void {
		$live = Fixtures::with( 'event-payment_intent.succeeded', array( 'livemode' => true ) );
		$test = Fixtures::text( 'event-payment_intent.succeeded' );

		$this->assertSame( WebhookReader::LIVEMODE_MISMATCH, $this->read( $live )->reason, 'A live event on the test address.' );
		$this->assertSame( WebhookReader::LIVEMODE_MISMATCH, $this->read( $test, Mode::Live )->reason, 'A test event on the live address.' );
		$this->assertSame( WebhookReadingKind::Result, $this->read( $live, Mode::Live )->kind );
	}

	/**
	 * Tests signed deliveries that are not events SEOCart can read.
	 *
	 * @since 0.2.0
	 */
	public function test_a_signed_delivery_that_is_not_a_readable_event_is_malformed(): void {
		$event = Fixtures::object( 'event-payment_intent.succeeded' );

		$noObject = $event;
		unset( $noObject['data'] );
		$wrongObject                             = $event;
		$wrongObject['data']['object']['object'] = 'charge';
		$noAmount                                = $event;
		unset( $noAmount['data']['object']['amount_received'] );

		foreach ( array( $noObject, $wrongObject, $noAmount, array( 'object' => 'list' ) ) as $body ) {
			$this->assertSame( WebhookReading::MALFORMED, $this->read( Fixtures::encode( $body ) )->reason );
		}
	}

	/**
	 * Reads a body delivered to an address, signed with that mode's secret now.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The body.
	 * @param Mode   $mode Optional. The address's mode. Default test.
	 * @return WebhookReading The reading.
	 */
	private function read( string $body, Mode $mode = Mode::Test ): WebhookReading {
		$secret = Mode::Live === $mode ? FakeContext::LIVE_SIGNING_SECRET : FakeContext::TEST_SIGNING_SECRET;
		$now    = $this->context->clock->now();

		return $this->gateway->readWebhook(
			new WebhookEnvelope( 'stripe', $mode, array( 'stripe-signature' => Fixtures::signature( $body, $secret, $now->getTimestamp() ) ), $body, $now )
		);
	}
}
