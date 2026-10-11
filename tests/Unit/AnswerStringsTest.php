<?php
/**
 * Tests that nothing Stripe wrote reaches the log unless it is a value Stripe documents
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
use SEOCart\GatewayForStripe\StripeApi;
use SEOCart\GatewayForStripe\StripeCodes;
use SEOCart\GatewayForStripe\Tests\Support\FakeContext;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * An answer's fields are strings Stripe wrote, and any of them can hold a card number or a key. The gateway logs an
 * error's type and code only when they are values Stripe documents, an id only in the shape of a Stripe id, and never
 * an error's message, parameter name or links; a decline code it does not know never reaches a result either.
 *
 * Planted violations, each shown red and removed:
 * - the error's message or parameter name passed through on the refused-request line: the refusal test fails;
 * - the error's type or code logged as Stripe wrote it: on the refused-request line the unlisted-code test fails, on
 *   the line of an answer the gateway cannot act on the path test fails;
 * - a decline code Stripe does not document kept as the result's code: the unlisted-code test fails;
 * - the foreign PaymentIntent's id logged as Stripe wrote it: the foreign-id test fails;
 * - a request's path logged as it was sent: the path test fails;
 * - the cancellation reason or the refund's failure reason logged as Stripe wrote it: the delivery test fails.
 *
 * @since 0.2.0
 */
final class AnswerStringsTest extends GatewayTestCase {

	/**
	 * A card number, as a field Stripe answers could hold it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CARD = '4242 4242 4242 4242';

	/**
	 * A key, as a field Stripe answers could echo it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY = 'sk_test_leakedInAnAnswer';

	/**
	 * Tests a refused request whose parameter is a card number and whose message holds a key: neither reaches the log.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refusal_logs_its_documented_type_and_code_and_nothing_else_stripe_wrote(): void {
		$error = self::error( 'invalid_request_error', 'parameter_invalid_integer' );
		$this->context->stripe->answer( 'POST', 'payment_intents', 400, $error );
		$this->context->stripe->answer( 'POST', 'payment_intents/pi_3Fixture0001/cancel', 404, $error );
		$this->context->stripe->answer( 'POST', 'refunds', 401, $error );

		$declined = $this->gateway->authorize( self::request() );
		$this->unavailable( fn() => $this->gateway->void( new VoidRequest( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, Money::of( 2500, Currency::of( 'USD' ) ), Mode::Test, 'customer_request' ) ) );
		$this->unavailable( fn() => $this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, Money::of( 100, Currency::of( 'USD' ) ), Fixtures::REFUND_UUID, Mode::Test ) ) );

		$this->assertSame( array( Outcome::Declined, 'parameter_invalid_integer' ), array( $declined->outcome, $declined->errorCode ) );
		$this->assertSame( array( 'stripe.request_refused', 'stripe.declined', 'stripe.request_refused', 'stripe.unauthorized' ), $this->context->log->codes() );
		$this->assertSame( array( 'invalid_request_error', 'parameter_invalid_integer' ), array( $this->context->log->lines[0]['context']['error_type'], $this->context->log->lines[0]['context']['error_code'] ) );
		$this->assertNothingStripeWroteWasLogged();
	}

	/**
	 * Tests an error whose type and code are not values Stripe documents: logged as nothing, and the result's code is the gateway's own.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unlisted_type_or_code_is_logged_as_nothing(): void {
		$this->context->stripe->answer( 'POST', 'payment_intents', 400, self::error( 'card_error ' . self::KEY, '4242424242424242' ) );
		$this->context->stripe->answer( 'POST', 'payment_intents', 402, self::error( 'card_error', 'card_declined', self::KEY ) );

		$unlisted = $this->gateway->authorize( self::request() );
		$hidden   = $this->gateway->authorize( self::request() );

		$this->assertSame( array( null, null ), array( $this->context->log->lines[0]['context']['error_type'], $this->context->log->lines[0]['context']['error_code'] ) );
		$this->assertSame( 'card_declined', $unlisted->errorCode, 'The gateway\'s own code stands in for one Stripe does not document.' );
		$this->assertSame( 'card_declined', $hidden->errorCode, 'A decline code Stripe does not document gives way to the documented error code.' );
		$this->assertNothingStripeWroteWasLogged();
	}

	/**
	 * Tests a foreign PaymentIntent whose id is not in the shape of one: its id is logged as nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_an_id_not_in_the_shape_of_a_stripe_id_is_logged_as_nothing(): void {
		$this->context->stripe->answer(
			'POST',
			'payment_intents',
			200,
			Fixtures::with(
				'pi-requires-capture',
				array(
					'id'       => self::CARD,
					'metadata' => array( 'intent_uuid' => '0199c0de-0000-7000-8000-00000000ffff' ),
				)
			)
		);

		$this->unavailable( fn() => $this->gateway->authorize( self::request() ) );

		$this->assertSame( array( 'stripe.foreign_intent' ), $this->context->log->codes() );
		$this->assertNull( $this->context->log->lines[0]['context']['provider_intent_id'] );
		$this->assertNothingStripeWroteWasLogged();
	}

	/**
	 * Tests a request sent to a path not built by path() and holding something not in the shape of a Stripe id, answered with a type and code Stripe does not document: the log line puts `{id}` in the path and logs the type and code as nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_path_segment_not_in_the_shape_of_a_stripe_id_is_logged_as_a_placeholder(): void {
		$odd   = 'payment_intents/4242424242424242/capture';
		$known = StripeApi::path( 'payment_intents', Fixtures::INTENT_ID, 'capture' );
		$this->context->stripe->answer( 'POST', $odd, 500, self::error( 'api_error ' . self::KEY, '4242424242424242' ) );
		$this->context->stripe->answer( 'POST', $known, 500, self::error( 'api_error', 'resource_missing' ) );

		$api = new StripeApi( $this->context );
		$this->unavailable( static fn() => $api->post( Mode::Test, $odd, array() ) );
		$this->unavailable( static fn() => $api->post( Mode::Test, $known, array() ) );

		$logged = array_column( $this->context->log->lines, 'context' );

		$this->assertSame( array( 'payment_intents/{id}/capture', $known ), array_column( $logged, 'path' ) );
		$this->assertSame( array( null, 'api_error' ), array_column( $logged, 'error_type' ) );
		$this->assertSame( array( null, 'resource_missing' ), array_column( $logged, 'error_code' ) );
		$this->assertNothingStripeWroteWasLogged();
	}

	/**
	 * Tests deliveries whose reasons are not values Stripe documents: the cancellation reason and the refund's failure are logged as nothing or as the gateway's own word.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_logs_only_documented_reasons(): void {
		$this->read( Fixtures::with( 'event-payment_intent.canceled', array( 'data' => array( 'object' => array( 'cancellation_reason' => self::KEY . ' ' . self::CARD ) ) ) ) );
		$reading = $this->read( Fixtures::with( 'event-refund.failed', array( 'data' => array( 'object' => array( 'failure_reason' => self::KEY ) ) ) ) );

		$logged = (array) $this->context->log->contextOf( 'stripe.intent_canceled' );

		$this->assertArrayHasKey( 'cancellation_reason', $logged );
		$this->assertNull( $logged['cancellation_reason'] );
		$this->assertSame( 'failed', $reading->result?->errorCode );
		$this->assertSame( 'failed', $this->context->log->contextOf( 'stripe.refund_failed' )['error_code'] ?? null );
		$this->assertNothingStripeWroteWasLogged();
	}

	/**
	 * Tests the shapes StripeCodes accepts.
	 *
	 * @since 0.2.0
	 */
	public function test_the_shapes_the_gateway_repeats(): void {
		$this->assertSame( 'pi_3Fixture0001', StripeCodes::id( 'pi_3Fixture0001', 'pi' ) );
		$this->assertNull( StripeCodes::id( 'ch_3Fixture0001', 'pi' ), 'Another kind of id.' );
		$this->assertNull( StripeCodes::id( self::CARD ) );
		$this->assertNull( StripeCodes::id( 'pi_short' ) );
		$this->assertNull( StripeCodes::errorCode( 'not_a_documented_code' ) );
		$this->assertNull( StripeCodes::declineCode( 'card_declined' ), 'An error code is not a decline code.' );
		$this->assertSame( 'insufficient_funds', StripeCodes::declineCode( 'insufficient_funds' ) );
		$this->assertNull( StripeCodes::word( 'Has Spaces' ) );
	}

	/**
	 * Asserts that no log line holds the planted card number, key, message, parameter or link, and that no line names a message or a parameter.
	 *
	 * @since 0.2.0
	 */
	private function assertNothingStripeWroteWasLogged(): void {
		$written = $this->written();

		foreach ( array( self::CARD, '4242424242424242', self::KEY, 'Invalid integer', 'docs.stripe.com', 'dashboard.stripe.com' ) as $planted ) {
			$this->assertStringNotContainsString( $planted, $written );
		}

		foreach ( $this->context->log->lines as $line ) {
			$this->assertSame( array(), array_intersect( array_keys( $line['context'] ), array( 'param', 'message', 'doc_url', 'request_log_url' ) ), $line['code'] );
		}
	}

	/**
	 * Builds an error answer whose free-text fields hold a card number and a key.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $type        The error's type.
	 * @param string      $code        The error's code.
	 * @param string|null $declineCode Optional. The error's decline code. Default none.
	 * @return string The answer.
	 */
	private static function error( string $type, string $code, ?string $declineCode = null ): string {
		return Fixtures::encode(
			array(
				'error' => array_filter(
					array(
						'type'            => $type,
						'code'            => $code,
						'decline_code'    => $declineCode,
						'param'           => self::CARD,
						'message'         => 'Invalid integer: ' . self::KEY,
						'doc_url'         => 'https://docs.stripe.com/error-codes/' . $code,
						'request_log_url' => 'https://dashboard.stripe.com/test/logs/req_Fixture?t=1',
					)
				),
			)
		);
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
}
