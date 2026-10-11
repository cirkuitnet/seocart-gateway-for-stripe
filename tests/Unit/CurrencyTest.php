<?php
/**
 * Tests that the gateway moves money only in the currencies it declares
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\Tests\Support\FakeContext;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * SEOCart refuses an operation its capability matrix does not declare before it asks the gateway; called directly with
 * an amount in a currency outside USD, GBP and EUR, the gateway itself refuses an authorization, a capture or a refund
 * with GatewayUnavailable, before it builds any request. An answer is read only in a currency the gateway declares
 * and, for a request, in the request's own currency; a delivery in another currency is rejected.
 *
 * Planted violations, each shown red and removed:
 * - the currency check left out of authorize(): a PaymentIntent in JPY is requested;
 * - an answer's currency taken without the declared list: the delivery in JPY is read;
 * - an answer's currency taken without the request's: the answers in EUR and GBP are read;
 * - a void's PaymentIntent taken in another currency: the void in EUR is read.
 *
 * @since 0.2.0
 */
final class CurrencyTest extends GatewayTestCase {

	/**
	 * Tests each money operation with JPY: refused, nothing sent, the refusal logged with the operation and the currency.
	 *
	 * @since 0.2.0
	 */
	public function test_a_currency_the_gateway_does_not_declare_is_refused_before_any_request(): void {
		$yen = Money::of( 2500, Currency::of( 'JPY' ) );

		$this->unavailable( fn() => $this->gateway->authorize( new PaymentRequest( Fixtures::INTENT_UUID, $yen, 'pm_card_visa', Mode::Test, '0199c0de-0000-7000-8000-0000000000aa', '1001' ) ) );
		$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, $yen, Mode::Test ) ) );
		$refused = $this->unavailable( fn() => $this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, $yen, Fixtures::REFUND_UUID, Mode::Test ) ) );

		$this->assertSame( array(), $this->context->stripe->requests, 'Nothing was sent.' );
		$this->assertSame( array( 'stripe.currency_refused', 'stripe.currency_refused', 'stripe.currency_refused' ), $this->context->log->codes() );
		$this->assertSame(
			array(
				'operation' => 'refund',
				'currency'  => 'JPY',
			),
			$this->context->log->lines[2]['context']
		);
		$this->assertStringContainsString( 'JPY', $refused->getMessage() );
	}

	/**
	 * Tests answers in a currency the gateway does not take, or not the request's: each is refused, and a delivery in JPY is rejected.
	 *
	 * @since 0.2.0
	 */
	public function test_an_answer_in_another_currency_is_not_read(): void {
		$usd = Money::of( 2500, Currency::of( 'USD' ) );

		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-capture', array( 'currency' => 'jpy' ) ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-capture', array( 'currency' => 'eur' ) ) );
		$this->context->stripe->answer( 'POST', 'payment_intents/' . Fixtures::INTENT_ID . '/capture', 200, Fixtures::with( 'pi-succeeded', array( 'currency' => 'gbp' ) ) );
		$this->context->stripe->answer( 'POST', 'payment_intents/' . Fixtures::INTENT_ID . '/cancel', 200, Fixtures::with( 'pi-canceled', array( 'currency' => 'eur' ) ) );
		$this->context->stripe->answer( 'POST', 'refunds', 200, Fixtures::with( 'refund-succeeded', array( 'currency' => 'jpy' ) ) );
		$this->context->stripe->answer( 'POST', 'refunds', 200, Fixtures::with( 'refund-succeeded', array( 'currency' => 'eur' ) ) );
		$this->context->stripe->answer( 'GET', 'payment_intents/' . Fixtures::INTENT_ID, 200, Fixtures::with( 'pi-requires-capture', array( 'currency' => 'eur' ) ) );

		$this->unavailable( fn() => $this->gateway->authorize( new PaymentRequest( Fixtures::INTENT_UUID, $usd, 'pm_card_visa', Mode::Test, '0199c0de-0000-7000-8000-0000000000aa', '1001' ) ) );
		$this->unavailable( fn() => $this->gateway->authorize( new PaymentRequest( Fixtures::INTENT_UUID, $usd, 'pm_card_visa', Mode::Test, '0199c0de-0000-7000-8000-0000000000aa', '1001' ) ) );
		$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, $usd, Mode::Test ) ) );
		$this->unavailable( fn() => $this->gateway->void( new VoidRequest( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, $usd, Mode::Test, 'customer_request' ) ) );
		$this->unavailable( fn() => $this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, $usd, Fixtures::REFUND_UUID, Mode::Test ) ) );
		$this->unavailable( fn() => $this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, $usd, Fixtures::REFUND_UUID, Mode::Test ) ) );
		$this->unavailable( fn() => $this->gateway->query( new PaymentQuery( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, $usd, Mode::Test ) ) );

		$this->assertSame( array_fill( 0, 7, 'stripe.unexpected_answer' ), $this->context->log->codes() );

		$body    = Fixtures::with( 'event-payment_intent.succeeded', array( 'data' => array( 'object' => array( 'currency' => 'jpy' ) ) ) );
		$now     = $this->context->clock->now();
		$reading = $this->gateway->readWebhook( new WebhookEnvelope( 'stripe', Mode::Test, array( 'stripe-signature' => Fixtures::signature( $body, FakeContext::TEST_SIGNING_SECRET, $now->getTimestamp() ) ), $body, $now ) );

		$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::MALFORMED ), array( $reading->kind, $reading->reason ) );
	}

	/**
	 * Tests that the currencies refused are exactly those the matrix leaves out.
	 *
	 * @since 0.2.0
	 */
	public function test_the_currencies_taken_are_the_matrixs(): void {
		$this->assertSame( Gateway::CURRENCIES, $this->gateway->describe()->matrix->currencies() );
	}
}
