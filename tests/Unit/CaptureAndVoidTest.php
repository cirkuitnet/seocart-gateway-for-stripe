<?php
/**
 * Tests capture() and void(): the requests, and what each answer means, done answers included
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The gateway's capture() always sends the amount to capture, with the key `{uuid}:capture`; a PaymentIntent captured already
 * answers that capture again. void() cancels with Stripe's reason for SEOCart's, and the key `{uuid}:void`; a
 * PaymentIntent captured before the cancel answers the authorization's approval, one cancelled already answers the
 * void again, one being processed is no answer. Without Stripe's id, void() finds the PaymentIntent by a search.
 *
 * Planted violations, each shown red and removed:
 * - "already captured" answered with GatewayUnavailable: the done-capture test fails;
 * - a void of a captured PaymentIntent answered as an error: the late-approval test fails.
 *
 * @since 0.2.0
 */
final class CaptureAndVoidTest extends GatewayTestCase {

	/**
	 * The capture path of the fixtures' PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CAPTURE = 'payment_intents/pi_3Fixture0001/capture';

	/**
	 * The cancel path of the fixtures' PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CANCEL = 'payment_intents/pi_3Fixture0001/cancel';

	/**
	 * Tests a full and a partial capture: the amount is always sent, with the derived key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_sends_its_amount_and_key_and_names_the_charge(): void {
		$this->context->stripe->answer( 'POST', self::CAPTURE, 200, Fixtures::text( 'pi-succeeded' ) );
		$this->context->stripe->answer( 'POST', self::CAPTURE, 200, Fixtures::with( 'pi-succeeded', array( 'amount_received' => 1500 ) ) );

		$full    = $this->gateway->capture( self::capture( 2500 ) );
		$fullSet = $this->context->stripe->last();
		$partial = $this->gateway->capture( self::capture( 1500 ) );

		$this->assertSame( 'amount_to_capture=2500', $fullSet->body );
		$this->assertSame( 'amount_to_capture=1500', $this->context->stripe->last()->body );
		$this->assertSame( Fixtures::INTENT_UUID . ':capture', $fullSet->headers['Idempotency-Key'] );
		$this->assertResult( $full, Operation::Capture, Outcome::Approved, 2500, Fixtures::CHARGE_ID, null );
		$this->assertResult( $partial, Operation::Capture, Outcome::Approved, 1500, Fixtures::CHARGE_ID, null );
	}

	/**
	 * Tests a capture of a PaymentIntent captured already: the same capture, from Stripe's own state.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_already_made_answers_that_capture(): void {
		$this->context->stripe->answer( 'POST', self::CAPTURE, 400, Fixtures::text( 'error-400-unexpected-state-succeeded' ) );

		$this->assertResult( $this->gateway->capture( self::capture( 2500 ) ), Operation::Capture, Outcome::Approved, 2500, Fixtures::CHARGE_ID, null );
	}

	/**
	 * Tests captures Stripe refuses: of a cancelled PaymentIntent, and of one it does not know.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_capture_is_declined_with_stripes_code(): void {
		$this->context->stripe->answer( 'POST', self::CAPTURE, 400, Fixtures::text( 'error-400-unexpected-state-canceled' ) );
		$this->context->stripe->answer( 'POST', self::CAPTURE, 404, Fixtures::text( 'error-404' ) );

		$this->assertResult( $this->gateway->capture( self::capture( 2500 ) ), Operation::Capture, Outcome::Declined, 2500, Fixtures::CHARGE_ID, 'payment_intent_unexpected_state' );
		$this->assertResult( $this->gateway->capture( self::capture( 2500 ) ), Operation::Capture, Outcome::Declined, 2500, null, 'resource_missing' );
	}

	/**
	 * Tests captures that get no answer: no PaymentIntent recorded, an outage, a PaymentIntent not captured.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_without_an_answer_is_unavailable(): void {
		$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, null, self::usd( 2500 ), Mode::Test ) ) );
		$this->assertSame( array(), $this->context->stripe->requests, 'Nothing is sent without Stripe\'s id.' );

		$this->context->stripe->answer( 'POST', self::CAPTURE, 503, '{}' );
		$this->context->stripe->answer( 'POST', self::CAPTURE, 200, Fixtures::text( 'pi-requires-capture' ) );

		$this->unavailable( fn() => $this->gateway->capture( self::capture( 2500 ) ) );
		$this->unavailable( fn() => $this->gateway->capture( self::capture( 2500 ) ) );
	}

	/**
	 * Tests Stripe's cancellation reason for each of SEOCart's void reasons, and the derived key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_sends_stripes_reason_for_seocarts_and_the_derived_key(): void {
		$reasons = array(
			'action_window_ended' => 'abandoned',
			'customer_request'    => 'requested_by_customer',
			'duplicate_order'     => 'duplicate',
			'fraud'               => 'fraudulent',
			'out_of_stock'        => null,
			'other'               => null,
			'a_future_reason'     => null,
		);

		foreach ( $reasons as $reason => $sent ) {
			$this->context->stripe->answer( 'POST', self::CANCEL, 200, Fixtures::text( 'pi-canceled' ) );

			$result = $this->gateway->void( self::void( $reason ) );

			$this->assertSame( null === $sent ? '' : 'cancellation_reason=' . $sent, $this->context->stripe->last()->body, $reason );
			$this->assertSame( Fixtures::INTENT_UUID . ':void', $this->headers()['Idempotency-Key'] );
			$this->assertResult( $result, Operation::Void, Outcome::Approved, 2500, Fixtures::INTENT_ID, null );
		}
	}

	/**
	 * Tests that an approved void reports the request's amount, what the cancellation released, whatever the PaymentIntent's own amount, done again included.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_reports_the_amount_it_released(): void {
		$this->context->stripe->answer( 'POST', self::CANCEL, 200, Fixtures::with( 'pi-canceled', array( 'amount' => 2600 ) ) );
		$this->context->stripe->answer( 'POST', self::CANCEL, 400, Fixtures::text( 'error-400-unexpected-state-canceled' ) );

		$this->assertResult( $this->gateway->void( self::void( 'customer_request', 2400 ) ), Operation::Void, Outcome::Approved, 2400, Fixtures::INTENT_ID, null );
		$this->assertResult( $this->gateway->void( self::void( 'customer_request', 2400 ) ), Operation::Void, Outcome::Approved, 2400, Fixtures::INTENT_ID, null );
	}

	/**
	 * Tests a void of a PaymentIntent the shopper completed and SEOCart captured first: the authorization's approval.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_too_late_answers_the_authorizations_approval(): void {
		$this->context->stripe->answer( 'POST', self::CANCEL, 400, Fixtures::text( 'error-400-unexpected-state-succeeded' ) );

		$this->assertResult( $this->gateway->void( self::void( 'action_window_ended' ) ), Operation::Authorize, Outcome::Approved, 2500, Fixtures::CHARGE_ID, null );
	}

	/**
	 * Tests a void of a PaymentIntent cancelled already: the same void again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_already_made_answers_that_void(): void {
		$this->context->stripe->answer( 'POST', self::CANCEL, 400, Fixtures::text( 'error-400-unexpected-state-canceled' ) );

		$this->assertResult( $this->gateway->void( self::void( 'customer_request' ) ), Operation::Void, Outcome::Approved, 2500, Fixtures::INTENT_ID, null );
	}

	/**
	 * Tests voids that get no answer: a PaymentIntent being processed, one Stripe does not know.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_that_cannot_be_made_is_unavailable(): void {
		$this->context->stripe->answer( 'POST', self::CANCEL, 400, Fixtures::text( 'error-400-unexpected-state-processing' ) );
		$this->context->stripe->answer( 'POST', self::CANCEL, 404, Fixtures::text( 'error-404' ) );

		$this->unavailable( fn() => $this->gateway->void( self::void( 'customer_request' ) ) );
		$this->unavailable( fn() => $this->gateway->void( self::void( 'customer_request' ) ) );

		$this->assertSame( array( 'stripe.unexpected_state', 'stripe.request_refused' ), $this->context->log->codes() );
	}

	/**
	 * Tests a void without Stripe's id: the PaymentIntent is found by a search on the intent's uuid, then cancelled; none found is no answer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_without_stripes_id_searches_first(): void {
		$search = Fixtures::listOf( '/v1/payment_intents/search', array( Fixtures::object( 'pi-requires-capture' ) ), false, 'search_result' );
		$this->context->stripe->answer( 'GET', 'payment_intents/search', 200, $search );
		$this->context->stripe->answer( 'POST', self::CANCEL, 200, Fixtures::text( 'pi-canceled' ) );
		$this->context->stripe->answer( 'GET', 'payment_intents/search', 200, Fixtures::listOf( '/v1/payment_intents/search', array(), false, 'search_result' ) );

		$result = $this->gateway->void( new VoidRequest( Fixtures::INTENT_UUID, null, self::usd( 2500 ), Mode::Test, 'customer_request' ) );

		$this->assertSame( array( 'GET payment_intents/search', 'POST ' . self::CANCEL ), $this->context->stripe->sent() );
		$this->assertResult( $result, Operation::Void, Outcome::Approved, 2500, Fixtures::INTENT_ID, null );

		$this->unavailable( fn() => $this->gateway->void( new VoidRequest( Fixtures::INTENT_UUID, null, self::usd( 2500 ), Mode::Test, 'customer_request' ) ) );
		$this->assertCount( 3, $this->context->stripe->requests, 'Nothing is cancelled when nothing is found.' );
	}

	/**
	 * Returns an amount in USD.
	 *
	 * @since 0.2.0
	 *
	 * @param int $minor The minor units.
	 * @return Money The amount.
	 */
	private static function usd( int $minor ): Money {
		return Money::of( $minor, Currency::of( 'USD' ) );
	}

	/**
	 * Builds a capture request for the fixtures' PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @param int $minor The amount to capture.
	 * @return CaptureRequest The request.
	 */
	private static function capture( int $minor ): CaptureRequest {
		return new CaptureRequest( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, self::usd( $minor ), Mode::Test );
	}

	/**
	 * Builds a void request for the fixtures' PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $reason SEOCart's void reason.
	 * @param int    $minor  Optional. The amount the intent authorized, in minor units. Default 2500.
	 * @return VoidRequest The request.
	 */
	private static function void( string $reason, int $minor = 2500 ): VoidRequest {
		return new VoidRequest( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, self::usd( $minor ), Mode::Test, $reason );
	}

	/**
	 * Asserts a result's fields.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result  The result.
	 * @param Operation     $op      The operation.
	 * @param Outcome       $outcome The outcome.
	 * @param int           $minor   The amount, in minor units.
	 * @param string|null   $entity  The provider object.
	 * @param string|null   $code    The error code.
	 */
	private function assertResult( GatewayResult $result, Operation $op, Outcome $outcome, int $minor, ?string $entity, ?string $code ): void {
		$this->assertSame(
			array( 'stripe', $op, $outcome, Fixtures::INTENT_UUID, $minor, $entity, Fixtures::INTENT_ID, $code ),
			array( $result->provider, $result->operation, $result->outcome, $result->intentUuid, $result->amount->minorUnits(), $result->providerObjectId, $result->providerIntentId, $result->errorCode )
		);
	}
}
