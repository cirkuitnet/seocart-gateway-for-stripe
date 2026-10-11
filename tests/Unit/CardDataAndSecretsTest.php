<?php
/**
 * Tests that no card detail, header, key or secret reaches what the gateway writes
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\GatewayForStripe\Tests\Support\FakeContext;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\GatewayForStripe\Tests\Support\LogScan;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * Stripe's answers carry card details: a declined PaymentIntent's last payment error holds the card's last four digits,
 * expiry and fingerprint, so does a card error, and a Charge holds them in its payment method details; they carry a
 * client secret too. The gateway runs every path that sees such an answer here, and the scan GatewayTestCase applies
 * after every test finds none of it in a log line or an exception's message. The scan itself is proved first: it finds
 * each thing it looks for.
 *
 * Planted violation, shown red and removed: StripeApi logs the decoded answer and the request's headers on a decline:
 * the scan finds `exp_month`, `last4` and `Authorization`, and every decline test fails.
 *
 * @since 0.2.0
 */
final class CardDataAndSecretsTest extends GatewayTestCase {

	/**
	 * Tests that the scan finds card details, a header, a key, a secret and a piece of a body.
	 *
	 * @since 0.2.0
	 */
	public function test_the_scan_finds_what_must_never_be_written(): void {
		$body = Fixtures::text( 'error-402-card-declined' );

		$this->assertNotSame( array(), LogScan::findings( '{"card":{"last4":"0002"}}', array(), array() ) );
		$this->assertNotSame( array(), LogScan::findings( '{"exp_month":12}', array(), array() ) );
		$this->assertNotSame( array(), LogScan::findings( 'Authorization: Bearer x', array(), array() ) );
		$this->assertNotSame( array(), LogScan::findings( 'card 4242 4242 4242 4242', array(), array() ) );
		$this->assertNotSame( array(), LogScan::findings( 'key=' . FakeContext::TEST_KEY, array( FakeContext::TEST_KEY ), array() ) );
		$this->assertNotSame( array(), LogScan::findings( substr( $body, 200, 120 ), array(), array( $body ) ) );
		$this->assertSame( array(), LogScan::findings( '{"intent_uuid":"' . Fixtures::INTENT_UUID . '","http_status":402}', array(), array() ), 'A uuid is not a card number.' );
	}

	/**
	 * Tests the declines whose answers carry card details: a 402 and a declined PaymentIntent.
	 *
	 * @since 0.2.0
	 */
	public function test_a_decline_carrying_card_details_writes_none_of_them(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 402, Fixtures::text( 'error-402-card-declined' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-payment-method-declined' ) );

		$this->gateway->authorize( self::request() );
		$this->gateway->authorize( self::request() );

		$this->assertSame( array( 'stripe.declined', 'stripe.declined' ), $this->context->log->codes() );
	}

	/**
	 * Tests the answers carrying a client secret, and a refused key: none of it is written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_client_secret_and_a_refused_key_are_never_written(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-action-sdk' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents/pi_3Fixture0001/capture', 401, Fixtures::text( 'error-401' ) );

		$this->gateway->authorize( self::request() );
		$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, Money::of( 2500, Currency::of( 'USD' ) ), Mode::Test ) ) );

		$this->assertSame( array( 'stripe.unauthorized' ), $this->context->log->codes() );
	}

	/**
	 * Tests deliveries carrying card details: a failed payment and a refunded charge.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_carrying_card_details_writes_none_of_them(): void {
		foreach ( array( 'event-payment_intent.payment_failed', 'event-charge.refunded', 'event-refund.failed' ) as $fixture ) {
			$body = Fixtures::text( $fixture );
			$now  = $this->context->clock->now();

			$this->gateway->readWebhook( new WebhookEnvelope( 'stripe', Mode::Test, array( 'stripe-signature' => Fixtures::signature( $body, FakeContext::TEST_SIGNING_SECRET, $now->getTimestamp() ) ), $body, $now ) );
			$this->context->stripe->served[] = $body;
		}

		$this->assertSame( array( 'stripe.refund_failed' ), $this->context->log->codes() );
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
}
