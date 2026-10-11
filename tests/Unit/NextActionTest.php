<?php
/**
 * Tests what an authorization that waits for the shopper says they must do
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\NextAction;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\GatewayForStripe\Tests\Support\FakeContext;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * A PaymentIntent confirmed with a return address that needs the bank sends the shopper to the bank's https page Stripe
 * names, with no handle beside it; any other step, and a PaymentIntent the browser has not confirmed yet, is Stripe's
 * script's, with the client secret as its handle. The client secret is answered to the shopper only: never logged,
 * never in a URL.
 *
 * Planted violations, each shown red and removed:
 * - the client secret given beside the redirect: the redirect test fails;
 * - the client secret's form left unchecked: the own-secret test fails.
 *
 * @since 0.2.0
 */
final class NextActionTest extends GatewayTestCase {

	/**
	 * Tests a redirect to the bank's page.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_that_needs_the_bank_sends_the_shopper_to_its_page(): void {
		$page = Fixtures::object( 'pi-requires-action-redirect' )['next_action']['redirect_to_url']['url'];
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-action-redirect' ) );

		$result = $this->gateway->authorize( self::request( 'pm_card_threeDSecure2Required' ) );

		$this->assertSame( Outcome::RequiresAction, $result->outcome );
		$this->assertNotNull( $result->nextAction );
		$this->assertSame( array( NextAction::REDIRECT, $page, null ), array( $result->nextAction->type, $result->nextAction->url, $result->nextAction->clientToken ) );
		$this->assertStringNotContainsString( FakeContext::TEST_KEY, (string) $result->nextAction->url );
		$this->assertStringNotContainsString( Fixtures::CLIENT_SECRET, (string) $result->nextAction->url );
	}

	/**
	 * Tests the steps Stripe's script takes: another next action, and a PaymentIntent waiting for the browser to confirm it.
	 *
	 * @since 0.2.0
	 */
	public function test_any_other_step_is_stripes_scripts_with_the_client_secret(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-action-sdk' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-payment-method-unconfirmed' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-action-redirect', array( 'next_action' => array( 'redirect_to_url' => array( 'url' => 'http://hooks.stripe.com/insecure' ) ) ) ) );

		foreach ( array( 'pm_card_visa', null, 'pm_card_visa' ) as $token ) {
			$result = $this->gateway->authorize( self::request( $token ) );

			$this->assertNotNull( $result->nextAction );
			$this->assertSame( array( NextAction::SDK, null, Fixtures::CLIENT_SECRET ), array( $result->nextAction->type, $result->nextAction->url, $result->nextAction->clientToken ) );
		}
	}

	/**
	 * Tests client secrets that are not the PaymentIntent's own (a key, a key behind its id, another PaymentIntent's, one too long): the answer is not read, and none is written.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_payment_intents_own_client_secret_is_handed_on(): void {
		$key = 'sk_test_leakedInAnAnswer';

		foreach ( array( $key, Fixtures::INTENT_ID . '_secret_' . $key, 'pi_3Fixture0002_secret_fixtureClientSecret', Fixtures::INTENT_ID . '_secret_' . str_repeat( 'a', 65 ) ) as $secret ) {
			$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::with( 'pi-requires-action-sdk', array( 'client_secret' => $secret ) ) );
			$this->unavailable( fn() => $this->gateway->authorize( self::request( 'pm_card_visa' ) ) );
		}

		$this->assertSame( array_fill( 0, 4, 'stripe.unexpected_answer' ), $this->context->log->codes() );
		$this->assertStringNotContainsString( $key, $this->written() );
	}

	/**
	 * Tests a PaymentIntent waiting for the shopper that names neither a page nor a handle: no answer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_step_without_a_page_or_a_handle_is_no_answer(): void {
		$intent = Fixtures::object( 'pi-requires-action-sdk' );
		unset( $intent['client_secret'] );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::encode( $intent ) );

		$this->unavailable( fn() => $this->gateway->authorize( self::request( 'pm_card_visa' ) ) );
	}

	/**
	 * Tests that the other answers carry no step.
	 *
	 * @since 0.2.0
	 */
	public function test_no_other_answer_says_what_to_do(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-requires-capture' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 200, Fixtures::text( 'pi-processing' ) );

		$this->assertNull( $this->gateway->authorize( self::request( 'pm_card_visa' ) )->nextAction );
		$this->assertNull( $this->gateway->authorize( self::request( 'pm_card_visa' ) )->nextAction );
	}

	/**
	 * Builds an authorization request.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $token The payment token.
	 * @return PaymentRequest The request.
	 */
	private static function request( ?string $token ): PaymentRequest {
		return new PaymentRequest( Fixtures::INTENT_UUID, Money::of( 2500, Currency::of( 'USD' ) ), $token, Mode::Test, '0199c0de-0000-7000-8000-0000000000aa', '1001', 'https://shop.example/?seocart_payment=' . Fixtures::INTENT_UUID );
	}
}
