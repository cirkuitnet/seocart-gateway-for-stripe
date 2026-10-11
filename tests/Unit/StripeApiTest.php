<?php
/**
 * Tests how every request to Stripe is made, and how each kind of answer is classified
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\CredentialUnavailable;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\TransportFailure;
use SEOCart\GatewayForStripe\StripeApi;
use SEOCart\GatewayForStripe\Tests\Support\FakeContext;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * Every request names the declared host, carries the key of its mode and the pinned API version; a POST carries a
 * form body and, when given, the idempotency key; nothing is sent when the key saved for a mode is another mode's.
 * An answer the gateway can act on (2xx; 400, 402 or 404 with an error object) is returned; every other answer is
 * GatewayUnavailable, logged with its status and never its body.
 *
 * Planted violations, each shown red and removed:
 * - `Stripe-Version` sent on POST only: the GET header test fails;
 * - the key/mode guard removed: the live key is sent from the test mode, and the guard test fails;
 * - the path's refusal removed: the path test and the sixteen-digit test fail;
 * - the ledger id's shape check removed: the ledger-id test fails.
 *
 * @since 0.2.0
 */
final class StripeApiTest extends GatewayTestCase {

	/**
	 * Tests a POST's headers and body.
	 *
	 * @since 0.2.0
	 */
	public function test_a_post_carries_the_modes_key_the_version_a_form_body_and_its_key(): void {
		$this->context->stripe->answer( 'POST', 'refunds', 200, Fixtures::text( 'refund-succeeded' ) );

		$answer = ( new StripeApi( $this->context ) )->post( Mode::Test, 'refunds', array( 'amount' => 1000 ), 'key-1' );
		$sent   = $this->context->stripe->last();

		$this->assertSame( 200, $answer->status );
		$this->assertSame( 'stripe-api', $sent->hostId );
		$this->assertSame( 'https://api.stripe.com/v1/refunds', $sent->url );
		$this->assertSame(
			array(
				'Authorization'   => 'Bearer ' . FakeContext::TEST_KEY,
				'Stripe-Version'  => '2026-09-30.endive',
				'Content-Type'    => 'application/x-www-form-urlencoded',
				'Idempotency-Key' => 'key-1',
			),
			$sent->headers
		);
		$this->assertSame( 'amount=1000', $sent->body );
	}

	/**
	 * Tests a GET and a DELETE: the key and the version, the query in the URL, no body, no idempotency key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_get_and_a_delete_carry_the_key_and_the_version_and_nothing_else(): void {
		$this->context->stripe->answer( 'GET', 'webhook_endpoints', 200, Fixtures::listOf( '/v1/webhook_endpoints', array() ) );
		$this->context->stripe->answer( 'DELETE', 'webhook_endpoints/we_1Fixture0001', 200, Fixtures::text( 'webhook-endpoint-deleted' ) );
		$api = new StripeApi( $this->context );

		$api->get( Mode::Live, 'webhook_endpoints', array( 'limit' => 100 ) );
		$api->delete( Mode::Live, StripeApi::path( 'webhook_endpoints', 'we_1Fixture0001' ) );

		foreach ( $this->context->stripe->requests as $sent ) {
			$this->assertSame(
				array(
					'Authorization'  => 'Bearer ' . FakeContext::LIVE_KEY,
					'Stripe-Version' => '2026-09-30.endive',
				),
				$sent->headers
			);
			$this->assertNull( $sent->body );
		}

		$this->assertSame( 'https://api.stripe.com/v1/webhook_endpoints?limit=100', $this->context->stripe->requests[0]->url );
	}

	/**
	 * Tests that nothing is sent with a key of the other mode, in either direction.
	 *
	 * @since 0.2.0
	 */
	public function test_nothing_is_sent_when_the_key_saved_for_a_mode_is_another_modes(): void {
		$this->context->settings['test']['secret_key'] = FakeContext::LIVE_KEY;
		$this->context->settings['live']['secret_key'] = FakeContext::TEST_KEY;
		$api = new StripeApi( $this->context );

		foreach ( array( Mode::Test, Mode::Live ) as $mode ) {
			$unavailable = $this->unavailable( static fn() => $api->get( $mode, 'webhook_endpoints' ) );

			$this->assertStringContainsString( "secret_key of the {$mode->value} mode", $unavailable->getMessage() );
		}

		$this->assertSame( array(), $this->context->stripe->requests );
		$this->assertSame( array( 'stripe.key_mode_mismatch', 'stripe.key_mode_mismatch' ), $this->context->log->codes() );
	}

	/**
	 * Tests that a key that cannot be opened stops the request with SEOCart's own exception.
	 *
	 * @since 0.2.0
	 */
	public function test_a_missing_key_stops_the_request(): void {
		unset( $this->context->settings['test']['secret_key'] );

		$unavailable = $this->unavailable( fn() => ( new StripeApi( $this->context ) )->get( Mode::Test, 'webhook_endpoints' ) );

		$this->assertInstanceOf( CredentialUnavailable::class, $unavailable );
		$this->assertSame( array(), $this->context->stripe->requests );
	}

	/**
	 * Tests the form encoding: nested keys, lists from 0, booleans as words.
	 *
	 * @since 0.2.0
	 */
	public function test_parameters_are_encoded_as_stripe_reads_a_form(): void {
		$encoded = StripeApi::encode(
			array(
				'confirm'  => true,
				'list'     => array( 'card' ),
				'metadata' => array( 'intent_uuid' => Fixtures::INTENT_UUID ),
				'off'      => false,
			)
		);

		$this->assertSame( 'confirm=true&list%5B0%5D=card&metadata%5Bintent_uuid%5D=' . Fixtures::INTENT_UUID . '&off=false', $encoded );
	}

	/**
	 * Tests that a path takes only the words of Stripe's paths and Stripe ids.
	 *
	 * @since 0.2.0
	 */
	public function test_a_path_takes_only_ids_and_words(): void {
		$this->assertSame( 'payment_intents/' . Fixtures::INTENT_ID . '/capture', StripeApi::path( 'payment_intents', Fixtures::INTENT_ID, 'capture' ) );
		$this->assertSame( 'webhook_endpoints/we_1Fixture0001', StripeApi::path( 'webhook_endpoints', 'we_1Fixture0001' ) );

		foreach ( array( 'pi_1/../../v1/charges', 'pi_1?x=1', '', 'pi 1', '4242424242424242', 'pi_3Abc', 'xx_3Fixture0001', 'Capture' ) as $bad ) {
			$this->unavailable( static fn() => StripeApi::path( 'payment_intents', $bad ) );
		}
	}

	/**
	 * Tests a card number given as the PaymentIntent to capture or void: refused before any request is built, and never written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_sixteen_digit_reference_is_refused_before_any_request(): void {
		$card = '4242424242424242';

		$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, $card, Money::of( 2500, Currency::of( 'USD' ) ), Mode::Test ) ) );
		$this->unavailable( fn() => $this->gateway->void( new VoidRequest( Fixtures::INTENT_UUID, $card, Money::of( 2500, Currency::of( 'USD' ) ), Mode::Test, 'customer_request' ) ) );

		$this->assertSame( array(), $this->context->stripe->sent() );
		$this->assertStringNotContainsString( $card, $this->written() );
	}

	/**
	 * Tests a ledger id that is not a PaymentIntent's (a card number, a charge's id, an id too short): capture, refund, query and the refund lookup refuse it before any request is built.
	 *
	 * @since 0.2.0
	 */
	public function test_a_ledger_id_that_is_not_a_payment_intents_reaches_no_request(): void {
		$usd = Money::of( 2500, Currency::of( 'USD' ) );

		foreach ( array( '4242424242424242', 'ch_3Fixture0001', 'pi_3Abc' ) as $id ) {
			$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, $id, $usd, Mode::Test ) ) );
			$this->unavailable( fn() => $this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, $id, $usd, Fixtures::REFUND_UUID, Mode::Test ) ) );
			$this->unavailable( fn() => $this->gateway->query( new PaymentQuery( Fixtures::INTENT_UUID, $id, $usd, Mode::Test ) ) );
			$this->unavailable( fn() => $this->gateway->queryRefund( new GatewayRefund( Fixtures::INTENT_UUID, $id, $usd, Fixtures::REFUND_UUID, Mode::Test ) ) );
		}

		$this->assertSame( array(), $this->context->stripe->sent() );
		$this->assertCount( 12, $this->context->log->lines );
		$this->assertSame( array( 'not_a_payment_intent' ), array_values( array_unique( array_column( array_column( $this->context->log->lines, 'context' ), 'status' ) ) ) );
		$this->assertStringNotContainsString( '4242424242424242', $this->written() );
	}

	/**
	 * Tests each answer that is GatewayUnavailable, with what is logged.
	 *
	 * @since 0.2.0
	 */
	public function test_every_answer_the_gateway_cannot_act_on_is_unavailable(): void {
		$cases = array(
			'not JSON'             => array( 502, '<html>Bad gateway</html>', 'stripe.unavailable' ),
			'unauthorized'         => array( 401, Fixtures::text( 'error-401' ), 'stripe.unauthorized' ),
			'forbidden'            => array( 403, Fixtures::text( 'error-401' ), 'stripe.unauthorized' ),
			'key in use'           => array( 409, Fixtures::text( 'error-409' ), 'stripe.unavailable' ),
			'other parameters'     => array( 400, Fixtures::text( 'error-400-idempotency' ), 'stripe.idempotency_mismatch' ),
			'rate limited'         => array( 429, Fixtures::text( 'error-429' ), 'stripe.unavailable' ),
			'fault'                => array( 500, Fixtures::text( 'error-500' ), 'stripe.unavailable' ),
			'outage'               => array( 503, '{}', 'stripe.unavailable' ),
			'400 without an error' => array( 400, '{"ok":true}', 'stripe.unavailable' ),
		);

		foreach ( $cases as $case => list( $status, $body, $code ) ) {
			$context = new FakeContext();
			$context->stripe->answer( 'POST', 'refunds', $status, $body );

			try {
				( new StripeApi( $context ) )->post( Mode::Test, 'refunds', array(), 'key' );
				$this->fail( "{$case}: answered." );
			} catch ( \SEOCart\Contracts\Payment\GatewayUnavailable $unavailable ) {
				$this->thrown[] = $unavailable->getMessage();
				$this->assertSame( array( $code ), $context->log->codes(), $case );
				$this->assertSame( $status, $context->log->lines[0]['context']['http_status'] ?? null, $case );
			}

			$this->context->log->lines     = array_merge( $this->context->log->lines, $context->log->lines );
			$this->context->stripe->served = array_merge( $this->context->stripe->served, $context->stripe->served );
		}
	}

	/**
	 * Tests the errors about the request itself, which are answers.
	 *
	 * @since 0.2.0
	 */
	public function test_an_error_about_the_request_is_an_answer(): void {
		foreach ( array(
			400 => 'error-400-resource-missing',
			402 => 'error-402-card-declined',
			404 => 'error-404',
		) as $status => $fixture ) {
			$this->context->stripe->answer( 'POST', 'refunds', $status, Fixtures::text( $fixture ) );

			$answer = ( new StripeApi( $this->context ) )->post( Mode::Test, 'refunds', array() );

			$this->assertTrue( $answer->isError() );
			$this->assertSame( $status, $answer->status );
			$this->assertNotSame( array(), $answer->error() );
		}

		$this->assertSame( array(), $this->context->log->codes(), 'An answer is the operation\'s to log.' );
	}

	/**
	 * Tests a request that got no answer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_without_an_answer_is_unavailable(): void {
		$this->context->stripe->fail( 'POST', 'refunds', TransportFailure::TIMEOUT );

		$unavailable = $this->unavailable( fn() => ( new StripeApi( $this->context ) )->post( Mode::Test, 'refunds', array() ) );

		$this->assertInstanceOf( TransportFailure::class, $unavailable->getPrevious() );
		$this->assertSame( 'timeout', $this->context->log->contextOf( 'stripe.unavailable' )['failure'] ?? null );
	}
}
