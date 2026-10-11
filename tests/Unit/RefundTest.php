<?php
/**
 * Tests refund() and queryRefund()
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The gateway's refund() refunds the PaymentIntent's amount asked for, with the refund's uuid as the key and in the metadata; Stripe's
 * states map to one result each, named by the Refund. queryRefund() finds the refund by that uuid in the
 * PaymentIntent's list of refunds, which Stripe answers at once; a complete list without it is `not_found`, a list
 * that could not be read to its end is "cannot say".
 *
 * Planted violation, shown red and removed: `not_found` answered while the list still has more pages: the paging test
 * fails.
 *
 * @since 0.2.0
 */
final class RefundTest extends GatewayTestCase {

	/**
	 * Tests the request and the refund it records.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_names_the_payment_intent_its_amount_and_its_uuid(): void {
		$this->context->stripe->answer( 'POST', 'refunds', 200, Fixtures::text( 'refund-succeeded' ) );

		$result = $this->gateway->refund( self::refund() );
		$sent   = $this->context->stripe->last();

		$this->assertSame( 'payment_intent=pi_3Fixture0001&amount=1000&metadata%5Brefund_uuid%5D=' . Fixtures::REFUND_UUID, $sent->body );
		$this->assertSame( Fixtures::REFUND_UUID, $sent->headers['Idempotency-Key'] );
		$this->assertResult( $result, Outcome::Approved, 're_3Fixture0001', null );
	}

	/**
	 * Tests each state of a Refund.
	 *
	 * @since 0.2.0
	 */
	public function test_each_state_of_a_refund_is_one_result(): void {
		$states = array(
			'refund-pending'  => array( Outcome::Approved, null ),
			'refund-failed'   => array( Outcome::Declined, 'expired_or_canceled_card' ),
			'refund-canceled' => array( Outcome::Declined, 'canceled' ),
		);

		foreach ( $states as $fixture => list( $outcome, $code ) ) {
			$this->context->stripe->answer( 'POST', 'refunds', 200, Fixtures::text( $fixture ) );

			$this->assertResult( $this->gateway->refund( self::refund() ), $outcome, 're_3Fixture0001', $code );
		}
	}

	/**
	 * Tests a refund Stripe refuses, and one of an intent without a PaymentIntent.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_refund_is_declined_and_one_without_a_payment_intent_is_not_sent(): void {
		$this->context->stripe->answer( 'POST', 'refunds', 400, '{"error":{"code":"charge_already_refunded","type":"invalid_request_error","message":"Charge ch_3Fixture0001 has already been refunded."}}' );

		$this->assertResult( $this->gateway->refund( self::refund() ), Outcome::Declined, null, 'charge_already_refunded' );

		$this->unavailable( fn() => $this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, null, self::usd( 1000 ), Fixtures::REFUND_UUID, Mode::Test ) ) );
		$this->assertCount( 1, $this->context->stripe->requests );
	}

	/**
	 * Tests finding a refund in the list: the same Refund refund() named.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_is_found_by_its_uuid_in_the_list(): void {
		$other                            = Fixtures::object( 'refund-succeeded' );
		$other['id']                      = 're_3Fixture0002';
		$other['metadata']['refund_uuid'] = '0199c0de-0000-7000-8000-00000000b002';
		$this->context->stripe->answer( 'GET', 'refunds', 200, Fixtures::listOf( '/v1/refunds', array( $other, Fixtures::object( 'refund-succeeded' ) ) ) );

		$result = $this->gateway->queryRefund( self::refund() );

		$this->assertSame( 'https://api.stripe.com/v1/refunds?payment_intent=pi_3Fixture0001&limit=100', $this->context->stripe->last()->url );
		$this->assertNotNull( $result );
		$this->assertResult( $result, Outcome::Approved, 're_3Fixture0001', null );
	}

	/**
	 * Tests a complete list without the refund: not found; and the list read page by page.
	 *
	 * @since 0.2.0
	 */
	public function test_not_found_only_once_the_list_is_complete(): void {
		$other                            = Fixtures::object( 'refund-succeeded' );
		$other['id']                      = 're_3Fixture0002';
		$other['metadata']['refund_uuid'] = '0199c0de-0000-7000-8000-00000000b002';
		$this->context->stripe->answer( 'GET', 'refunds', 200, Fixtures::listOf( '/v1/refunds', array( $other ), true ) );
		$this->context->stripe->answer( 'GET', 'refunds', 200, Fixtures::listOf( '/v1/refunds', array() ) );

		$result = $this->gateway->queryRefund( self::refund() );

		$this->assertSame( 're_3Fixture0002', $this->context->stripe->parameters()['starting_after'] );
		$this->assertNotNull( $result );
		$this->assertSame( array( Operation::Refund, Outcome::Declined, 'not_found', null ), array( $result->operation, $result->outcome, $result->errorCode, $result->providerObjectId ) );
	}

	/**
	 * Tests the lists that cannot say: ten pages still going, a list Stripe refuses, an intent without a PaymentIntent.
	 *
	 * @since 0.2.0
	 */
	public function test_a_list_that_cannot_be_read_to_its_end_cannot_say(): void {
		$other                            = Fixtures::object( 'refund-succeeded' );
		$other['metadata']['refund_uuid'] = '0199c0de-0000-7000-8000-00000000b002';

		for ( $page = 0; $page < 10; $page++ ) {
			$other['id'] = 're_3Fixture01' . $page;
			$this->context->stripe->answer( 'GET', 'refunds', 200, Fixtures::listOf( '/v1/refunds', array( $other ), true ) );
		}

		$this->assertNull( $this->gateway->queryRefund( self::refund() ) );
		$this->assertCount( 10, $this->context->stripe->requests );

		$this->context->stripe->answer( 'GET', 'refunds', 404, Fixtures::text( 'error-404' ) );
		$this->assertNull( $this->gateway->queryRefund( self::refund() ) );

		$this->assertNull( $this->gateway->queryRefund( new GatewayRefund( Fixtures::INTENT_UUID, null, self::usd( 1000 ), Fixtures::REFUND_UUID, Mode::Test ) ) );
		$this->assertCount( 11, $this->context->stripe->requests );
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
	 * Builds the refund of the fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayRefund The refund.
	 */
	private static function refund(): GatewayRefund {
		return new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::INTENT_ID, self::usd( 1000 ), Fixtures::REFUND_UUID, Mode::Test );
	}

	/**
	 * Asserts a refund result's fields.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result  The result.
	 * @param Outcome       $outcome The outcome.
	 * @param string|null   $entity  The provider object.
	 * @param string|null   $code    The error code.
	 */
	private function assertResult( GatewayResult $result, Outcome $outcome, ?string $entity, ?string $code ): void {
		$this->assertSame(
			array( 'stripe', Operation::Refund, $outcome, Fixtures::INTENT_UUID, 1000, $entity, Fixtures::INTENT_ID, $code ),
			array( $result->provider, $result->operation, $result->outcome, $result->intentUuid, $result->amount->minorUnits(), $result->providerObjectId, $result->providerIntentId, $result->errorCode )
		);
	}
}
