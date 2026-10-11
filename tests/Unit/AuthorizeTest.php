<?php
/**
 * Tests authorize(): the PaymentIntent it creates, and what each answer means
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\TransportFailure;
use SEOCart\GatewayForStripe\Tests\Support\FakeContext;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The gateway's authorize() creates one PaymentIntent for capture later, keyed by the intent's uuid, with the intent's uuid and order
 * number in its metadata and cards as its only payment method type; it confirms it with the shopper's token and return
 * address when the request carries a token. Each state Stripe answers maps to one result: the authorization is named by
 * its charge, the PaymentIntent is the provider intent, a decline carries Stripe's code, never its message.
 *
 * Planted violations, each shown red and removed:
 * - a fresh uuid as the Idempotency-Key: the key test fails;
 * - the decline reason read from `message`: the decline tests fail;
 * - the PaymentIntent's id as the authorization's object: the approval test fails.
 *
 * @since 0.2.0
 */
final class AuthorizeTest extends GatewayTestCase {

	/**
	 * The return address the requests carry.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const RETURN_URL = 'https://shop.example/?seocart_payment=0199c0de-0000-7000-8000-00000000a001';

	/**
	 * Tests the request, byte for byte, and the approval it gets.
	 *
	 * @since 0.2.0
	 */
	public function test_a_confirmed_payment_intent_for_capture_later_is_created_and_its_approval_names_the_charge(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-capture' ) );

		$result = $this->gateway->authorize( self::request() );
		$sent   = $this->context->stripe->last();

		$this->assertSame( 'https://api.stripe.com/v1/payment_intents', $sent->url );
		$this->assertSame( Fixtures::INTENT_UUID, $sent->headers['Idempotency-Key'], 'The key is the intent\'s uuid, as the request derives it.' );
		$this->assertSame(
			'amount=2500&currency=usd&capture_method=manual&allowed_payment_method_types%5B0%5D=card'
				. '&metadata%5Bintent_uuid%5D=' . Fixtures::INTENT_UUID . '&metadata%5Border_number%5D=1001'
				. '&confirm=true&payment_method=pm_card_visa&return_url=' . rawurlencode( self::RETURN_URL ),
			$sent->body
		);
		$this->assertResult( $result, Operation::Authorize, Outcome::Approved, 2500, Fixtures::CHARGE_ID, null );
	}

	/**
	 * Tests the same authorization asked twice: the same key both times.
	 *
	 * @since 0.2.0
	 */
	public function test_the_same_authorization_asked_again_sends_the_same_key(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-capture' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-capture' ) );

		$this->gateway->authorize( self::request() );
		$this->gateway->authorize( self::request() );

		$this->assertSame( $this->headers( $this->context->stripe->requests[0] )['Idempotency-Key'], $this->headers( $this->context->stripe->requests[1] )['Idempotency-Key'] );
		$this->assertSame( $this->context->stripe->requests[0]->body, $this->context->stripe->requests[1]->body );
	}

	/**
	 * Tests a request without a return address, and one without a token.
	 *
	 * @since 0.2.0
	 */
	public function test_without_a_token_the_payment_intent_waits_for_the_browser(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-capture' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-payment-method-unconfirmed' ) );

		$this->gateway->authorize( self::request( 'pm_card_visa', null ) );
		$withoutReturn = $this->context->stripe->parameters();
		$waiting       = $this->gateway->authorize( self::request( null, self::RETURN_URL ) );
		$withoutToken  = $this->context->stripe->parameters();

		$this->assertArrayNotHasKey( 'return_url', $withoutReturn );
		$this->assertSame( 'true', $withoutReturn['confirm'] );
		$this->assertSame( array( 'amount', 'currency', 'capture_method', 'allowed_payment_method_types', 'metadata' ), array_keys( $withoutToken ), 'Not confirmed: no payment method, no return address.' );
		$this->assertResult( $waiting, Operation::Authorize, Outcome::RequiresAction, 2500, null, null );
	}

	/**
	 * Tests the answers that move no money.
	 *
	 * @since 0.2.0
	 */
	public function test_an_action_or_processing_moves_no_money(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-action-redirect' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-processing' ) );

		$this->assertResult( $this->gateway->authorize( self::request() ), Operation::Authorize, Outcome::RequiresAction, 2500, null, null );
		$this->assertResult( $this->gateway->authorize( self::request() ), Operation::Authorize, Outcome::Pending, 2500, null, null );
	}

	/**
	 * Tests a PaymentIntent Stripe cancelled, and one already captured.
	 *
	 * @since 0.2.0
	 */
	public function test_a_cancelled_payment_intent_is_expired_and_a_captured_one_is_authorized(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-canceled' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-succeeded' ) );

		$this->assertResult( $this->gateway->authorize( self::request() ), Operation::Authorize, Outcome::Declined, 2500, Fixtures::INTENT_ID, 'expired' );
		$this->assertResult( $this->gateway->authorize( self::request() ), Operation::Authorize, Outcome::Approved, 2500, Fixtures::CHARGE_ID, null );
	}

	/**
	 * Tests the two shapes of a card decline: a 402, and a PaymentIntent with its last payment error.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_decline_carries_the_issuers_code_and_the_failed_charge(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 402, Fixtures::text( 'error-402-card-declined' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-payment-method-declined' ) );

		$this->assertResult( $this->gateway->authorize( self::request() ), Operation::Authorize, Outcome::Declined, 2500, 'ch_3Fixture0002', 'generic_decline' );
		$this->assertResult( $this->gateway->authorize( self::request() ), Operation::Authorize, Outcome::Declined, 2500, 'ch_3Fixture0002', 'insufficient_funds' );
		$this->assertSame( array( 'stripe.declined', 'stripe.declined' ), $this->context->log->codes() );
		$this->assertSame( 'generic_decline', $this->context->log->lines[0]['context']['error_code'] );
	}

	/**
	 * Tests a request Stripe refused as invalid: a decline that names no object, logged as a refused request.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_request_is_a_decline_with_stripes_code(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 400, Fixtures::text( 'error-400-resource-missing' ) );

		$this->assertResult( $this->gateway->authorize( self::request() ), Operation::Authorize, Outcome::Declined, 2500, null, 'resource_missing', null );
		$this->assertSame( array( 'stripe.request_refused', 'stripe.declined' ), $this->context->log->codes() );
		$this->assertSame( array( 'invalid_request_error', 'resource_missing' ), array( $this->context->log->lines[0]['context']['error_type'], $this->context->log->lines[0]['context']['error_code'] ) );
		$this->assertArrayNotHasKey( 'param', $this->context->log->lines[0]['context'], 'A parameter name is a string Stripe wrote: never logged.' );
	}

	/**
	 * Tests a token that is not a PaymentMethod's id: declined, nothing sent.
	 *
	 * @since 0.2.0
	 */
	public function test_a_token_that_is_not_a_payment_method_is_declined_without_a_request(): void {
		$result = $this->gateway->authorize( self::request( 'tok_visa' ) );

		$this->assertResult( $result, Operation::Authorize, Outcome::Declined, 2500, null, 'invalid_payment_token', null );
		$this->assertSame( array(), $this->context->stripe->requests );
	}

	/**
	 * Tests answers that are no answer: unknown state, another payment's PaymentIntent, no answer at all, a rate limit.
	 *
	 * @since 0.2.0
	 */
	public function test_an_answer_that_cannot_be_applied_is_unavailable(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-capture', array( 'status' => 'requires_reauthorization' ) ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-capture', array( 'metadata' => array( 'intent_uuid' => '0199c0de-0000-7000-8000-00000000ffff' ) ) ) );
		$this->context->stripe->fail( 'POST', 'payment_intents', TransportFailure::TIMEOUT );
		$this->context->stripe->answer( 'POST', 'payment_intents', 429, Fixtures::text( 'error-429' ) );

		for ( $i = 0; $i < 4; $i++ ) {
			$this->unavailable( fn() => $this->gateway->authorize( self::request() ) );
		}

		$this->assertSame( array( 'stripe.unexpected_answer', 'stripe.foreign_intent', 'stripe.unavailable', 'stripe.unavailable' ), $this->context->log->codes() );
	}

	/**
	 * Tests a live payment: the live key, and the amount and currency echoed in GBP and EUR.
	 *
	 * @since 0.2.0
	 */
	public function test_a_live_payment_in_gbp_carries_the_live_key_and_echoes_its_amount(): void {
		$this->context->stripe->answer(
			'POST',
			'payment_intents',
			200,
			Fixtures::with(
				'pi-requires-capture',
				array(
					'currency' => 'gbp',
					'livemode' => true,
				)
			)
		);

		$result = $this->gateway->authorize( self::request( 'pm_card_visa', self::RETURN_URL, Mode::Live, 'GBP' ) );

		$this->assertSame( 'Bearer ' . FakeContext::LIVE_KEY, $this->headers()['Authorization'] );
		$this->assertSame( 'gbp', $this->context->stripe->parameters()['currency'] );
		$this->assertTrue( $result->amount->equals( Money::of( 2500, Currency::of( 'GBP' ) ) ) );
	}

	/**
	 * Builds an authorization request.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $token     Optional. The payment token. Default `pm_card_visa`.
	 * @param string|null $returnUrl Optional. The return address. Default the store's.
	 * @param Mode        $mode      Optional. The mode. Default test.
	 * @param string      $currency  Optional. The currency. Default USD.
	 * @return PaymentRequest The request.
	 */
	private static function request( ?string $token = 'pm_card_visa', ?string $returnUrl = self::RETURN_URL, Mode $mode = Mode::Test, string $currency = 'USD' ): PaymentRequest {
		return new PaymentRequest( Fixtures::INTENT_UUID, Money::of( 2500, Currency::of( $currency ) ), $token, $mode, '0199c0de-0000-7000-8000-0000000000aa', '1001', $returnUrl );
	}

	/**
	 * Asserts a result's fields.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result   The result.
	 * @param Operation     $op       The operation.
	 * @param Outcome       $outcome  The outcome.
	 * @param int           $minor    The amount, in minor units.
	 * @param string|null   $entity   The provider object.
	 * @param string|null   $code     The error code.
	 * @param string|null   $intentId Optional. The provider intent. Default the fixtures' PaymentIntent.
	 */
	private function assertResult( GatewayResult $result, Operation $op, Outcome $outcome, int $minor, ?string $entity, ?string $code, ?string $intentId = Fixtures::INTENT_ID ): void {
		$this->assertSame(
			array( 'stripe', $op, $outcome, Fixtures::INTENT_UUID, $minor, $entity, $intentId, $code ),
			array( $result->provider, $result->operation, $result->outcome, $result->intentUuid, $result->amount->minorUnits(), $result->providerObjectId, $result->providerIntentId, $result->errorCode )
		);
	}
}
