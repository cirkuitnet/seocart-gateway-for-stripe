<?php
/**
 * Tests that every id the gateway takes from Stripe or from SEOCart's ledger is an id of the kind expected, or refused
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\GatewayForStripe\Tests\Support\FakeContext;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\GatewayForStripe\WebhookReader;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * An id reaches the gateway in an answer, in a delivery or from SEOCart's ledger. Each is checked against the kind its
 * field holds (StripeCodes::id()) before it names a result, goes into a request or is handed to SEOCart; one that
 * fails makes the request unavailable, or the delivery rejected, and is passed on nowhere. An event's type is passed
 * on only when the gateway reads it. The values tried are shaped like a leaked key, or like a word a path would take.
 *
 * Planted violations, each shown red and removed:
 * - Results taking an id without its kind: the answer test and the delivery test fail;
 * - the void's id taken unchecked: the void test fails;
 * - the refund list read without checking every refund's id: the cursor test and the refund-list test fail;
 * - a present id field that is not text taken as missing: the present-field test fails;
 * - the event's id taken unchecked: the delivery test fails;
 * - an event type the gateway does not read passed on as Stripe wrote it: the delivery test fails.
 *
 * @since 0.2.0
 */
final class StripeIdsTest extends GatewayTestCase {

	/**
	 * A key, as a field Stripe answers could hold it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY = 'sk_test_leakedInAnAnswer';

	/**
	 * Tests answers naming a key where a PaymentIntent's, a charge's or a refund's id belongs: none is read.
	 *
	 * @since 0.2.0
	 */
	public function test_an_answer_naming_an_id_of_another_kind_is_not_read(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-capture', array( 'id' => self::KEY ) ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-capture', array( 'latest_charge' => self::KEY ) ) );
		$this->context->stripe->answer( 'POST', 'refunds', 200, Fixtures::with( 'refund-succeeded', array( 'id' => self::KEY ) ) );
		$this->context->stripe->answer( 'POST', 'refunds', 200, Fixtures::with( 'refund-succeeded', array( 'payment_intent' => self::KEY ) ) );

		$this->unavailable( fn() => $this->gateway->authorize( self::request() ) );
		$this->unavailable( fn() => $this->gateway->authorize( self::request() ) );
		$this->unavailable( fn() => $this->gateway->refund( self::refund() ) );
		$this->unavailable( fn() => $this->gateway->refund( self::refund() ) );

		$this->assertSame( array_fill( 0, 4, 'stripe.unexpected_answer' ), $this->context->log->codes() );
		$this->assertStringNotContainsString( self::KEY, $this->written() );
	}

	/**
	 * Tests a void whose recorded id is a word a path would take, and one whose search finds a PaymentIntent named by a key: nothing is cancelled.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_cancels_only_a_payment_intent_named_by_its_id(): void {
		$found       = Fixtures::object( 'pi-requires-capture' );
		$found['id'] = self::KEY;
		$this->context->stripe->answer( 'GET', 'payment_intents/search', 200, Fixtures::listOf( '/v1/payment_intents/search', array( $found ), false, 'search_result' ) );

		$this->unavailable( fn() => $this->gateway->void( self::void( 'pi_bad' ) ) );
		$this->unavailable( fn() => $this->gateway->void( self::void( null ) ) );

		$this->assertSame( array( 'GET payment_intents/search' ), $this->context->stripe->sent() );
		$this->assertSame( array( 'not_a_payment_intent', 'not_a_payment_intent' ), array_column( array_column( $this->context->log->lines, 'context' ), 'status' ) );
		$this->assertStringNotContainsString( self::KEY, $this->written() );
	}

	/**
	 * Tests a refund list whose last refund, not the one asked for, is named by a key: the next page is not asked for.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_list_is_followed_only_by_a_refunds_id(): void {
		$other                            = Fixtures::object( 'refund-succeeded' );
		$other['id']                      = self::KEY;
		$other['metadata']['refund_uuid'] = '0199c0de-0000-7000-8000-00000000b002';
		$this->context->stripe->answer( 'GET', 'refunds', 200, Fixtures::listOf( '/v1/refunds', array( $other ), true ) );

		$this->unavailable( fn() => $this->gateway->queryRefund( self::refund() ) );

		$this->assertSame( array( 'GET refunds' ), $this->context->stripe->sent() );
		$this->assertSame( array( 'not_a_refund' ), array_column( array_column( $this->context->log->lines, 'context' ), 'status' ) );
		$this->assertStringNotContainsString( self::KEY, $this->written() );
	}

	/**
	 * Tests refund lists with a refund that is not named by a refund's id (a key, no id at all, a key after the refund asked for): the lookup is refused, never answered not found.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_list_is_read_only_when_every_refund_is_named_by_a_refunds_id(): void {
		$keyed                            = Fixtures::object( 'refund-succeeded' );
		$keyed['id']                      = self::KEY;
		$keyed['metadata']['refund_uuid'] = '0199c0de-0000-7000-8000-00000000b002';
		$nameless                         = $keyed;
		unset( $nameless['id'] );

		foreach ( array( array( $keyed ), array( $nameless ), array( Fixtures::object( 'refund-succeeded' ), $keyed ) ) as $listed ) {
			$this->context->stripe->answer( 'GET', 'refunds', 200, Fixtures::listOf( '/v1/refunds', $listed ) );
			$this->unavailable( fn() => $this->gateway->queryRefund( self::refund() ) );
		}

		$this->assertSame( array_fill( 0, 3, 'GET refunds' ), $this->context->stripe->sent() );
		$this->assertSame( array_fill( 0, 3, 'not_a_refund' ), array_column( array_column( $this->context->log->lines, 'context' ), 'status' ) );
		$this->assertStringNotContainsString( self::KEY, $this->written() );
	}

	/**
	 * Tests id fields that are present but not text (empty, a number, an object): the answer is not read; a field that is JSON null is no id.
	 *
	 * @since 0.2.0
	 */
	public function test_a_present_id_field_that_is_not_an_id_is_not_read(): void {
		foreach ( array( '', 42, array( 'id' => 'ch_3Fixture0001' ) ) as $charge ) {
			$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-capture', array( 'latest_charge' => $charge ) ) );
			$this->unavailable( fn() => $this->gateway->authorize( self::request() ) );
		}

		$this->context->stripe->answer( 'POST', 'refunds', 200, Fixtures::with( 'refund-succeeded', array( 'payment_intent' => 42 ) ) );
		$this->unavailable( fn() => $this->gateway->refund( self::refund() ) );

		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-capture', array( 'latest_charge' => null ) ) );
		$approved = $this->gateway->authorize( self::request() );

		$this->assertSame( array_fill( 0, 4, 'stripe.unexpected_answer' ), $this->context->log->codes() );
		$this->assertSame( array( Outcome::Approved, null, Fixtures::INTENT_ID ), array( $approved->outcome, $approved->providerObjectId, $approved->providerIntentId ) );
	}

	/**
	 * Tests deliveries naming a key as the event's id, as its type, as its PaymentIntent's id and as its refund's id: only Stripe's ids and the gateway's own words are passed on.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_passes_on_only_stripe_ids_and_types_it_reads(): void {
		$byId     = $this->read( Fixtures::with( 'event-payment_intent.succeeded', array( 'id' => self::KEY ) ) );
		$byType   = $this->read( Fixtures::with( 'event-customer.created', array( 'type' => self::KEY ) ) );
		$byIntent = $this->read( Fixtures::with( 'event-payment_intent.succeeded', array( 'data' => array( 'object' => array( 'id' => self::KEY ) ) ) ) );
		$byRefund = $this->read( Fixtures::with( 'event-refund.created', array( 'data' => array( 'object' => array( 'id' => self::KEY ) ) ) ) );

		foreach ( array( $byId, $byIntent, $byRefund ) as $rejected ) {
			$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::MALFORMED ), array( $rejected->kind, $rejected->reason ) );
		}

		$this->assertSame( array( WebhookReadingKind::Ignored, 'evt_3Fixture0010', WebhookReader::UNHANDLED_TYPE, WebhookReader::UNHANDLED_TYPE ), array( $byType->kind, $byType->eventId, $byType->eventType, $byType->reason ) );
		$this->assertStringNotContainsString( self::KEY, var_export( array( $byId, $byType, $byIntent, $byRefund ), true ) . $this->written() );
	}

	/**
	 * Reads a body delivered to the test address, signed now.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The body.
	 * @return WebhookReading The reading.
	 */
	private function read( string $body ): WebhookReading {
		$now = $this->context->clock->now();

		return $this->gateway->readWebhook( new WebhookEnvelope( 'stripe', Mode::Test, array( 'stripe-signature' => Fixtures::signature( $body, FakeContext::TEST_SIGNING_SECRET, $now->getTimestamp() ) ), $body, $now ) );
	}

	/**
	 * Builds an authorization request.
	 *
	 * @since 0.2.0
	 *
	 * @return PaymentRequest The request.
	 */
	private static function request(): PaymentRequest {
		return new PaymentRequest( Fixtures::INTENT_UUID, Money::of( 2500, Currency::of( 'USD' ) ), 'pm_card_visa', Mode::Test, '0199c0de-0000-7000-8000-0000000000aa', '1001', 'https://shop.example/' );
	}

	/**
	 * Builds a refund of the fixture's PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayRefund The refund.
	 */
	private static function refund(): GatewayRefund {
		return new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, Money::of( 1000, Currency::of( 'USD' ) ), Fixtures::REFUND_UUID, Mode::Test );
	}

	/**
	 * Builds a void of the intent.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $intentId The PaymentIntent's id, as SEOCart recorded it; null for none.
	 * @return VoidRequest The void.
	 */
	private static function void( ?string $intentId ): VoidRequest {
		return new VoidRequest( Fixtures::INTENT_UUID, $intentId, Money::of( 2500, Currency::of( 'USD' ) ), Mode::Test, 'customer_request' );
	}
}
