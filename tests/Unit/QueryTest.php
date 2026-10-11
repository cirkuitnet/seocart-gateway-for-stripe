<?php
/**
 * Tests query(): by Stripe's id, or by a search on the intent's uuid
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
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The gateway's query() retrieves the PaymentIntent by Stripe's id when SEOCart has it, and otherwise searches by the intent's uuid in
 * the metadata; only a search answers `not_found`, since a PaymentIntent Stripe named cannot vanish. Each state maps to
 * one answer: the authorization, the capture, `expired`, a decline, or null while the shopper or Stripe decides.
 *
 * Planted violation, shown red and removed: a retrieve's 404 answered `not_found`: the retrieve test fails.
 *
 * @since 0.2.0
 */
final class QueryTest extends GatewayTestCase {

	/**
	 * Tests each state of a PaymentIntent retrieved by its id.
	 *
	 * @since 0.2.0
	 */
	public function test_each_state_retrieved_is_one_answer(): void {
		$states = array(
			'pi-requires-capture'                    => array( Operation::Authorize, Outcome::Approved, Fixtures::CHARGE_ID, null ),
			'pi-succeeded'                           => array( Operation::Capture, Outcome::Approved, Fixtures::CHARGE_ID, null ),
			'pi-canceled'                            => array( Operation::Authorize, Outcome::Declined, Fixtures::INTENT_ID, 'expired' ),
			'pi-requires-payment-method-declined'    => array( Operation::Authorize, Outcome::Declined, 'ch_3Fixture0002', 'insufficient_funds' ),
			'pi-requires-payment-method-unconfirmed' => null,
			'pi-requires-action-redirect'            => null,
			'pi-processing'                          => null,
		);

		foreach ( $states as $fixture => $expected ) {
			$this->context->stripe->answer( 'GET', 'payment_intents/pi_3Fixture0001', 200, Fixtures::text( $fixture ) );

			$result = $this->gateway->query( self::query( Fixtures::INTENT_ID ) );

			$this->assertSame( 'https://api.stripe.com/v1/payment_intents/pi_3Fixture0001', $this->context->stripe->last()->url );
			$this->assertSame( $expected, null === $result ? null : array( $result->operation, $result->outcome, $result->providerObjectId, $result->errorCode ), $fixture );
		}

		$this->context->stripe->answer( 'GET', 'payment_intents/pi_3Fixture0001', 200, Fixtures::with( 'pi-requires-capture', array( 'status' => 'requires_confirmation' ) ) );
		$this->assertNull( $this->gateway->query( self::query( Fixtures::INTENT_ID ) ) );
	}

	/**
	 * Tests a PaymentIntent Stripe's id names that Stripe does not find: no answer, never not-found.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_intent_named_by_its_id_that_is_missing_is_no_answer(): void {
		$this->context->stripe->answer( 'GET', 'payment_intents/pi_3Fixture0001', 404, Fixtures::text( 'error-404' ) );

		$this->unavailable( fn() => $this->gateway->query( self::query( Fixtures::INTENT_ID ) ) );
	}

	/**
	 * Tests the search: its query, and what none, one and two PaymentIntents found mean.
	 *
	 * @since 0.2.0
	 */
	public function test_without_an_id_a_search_answers(): void {
		$intent = Fixtures::object( 'pi-requires-capture' );
		$this->context->stripe->answer( 'GET', 'payment_intents/search', 200, self::found() );
		$this->context->stripe->answer( 'GET', 'payment_intents/search', 200, self::found( $intent ) );
		$this->context->stripe->answer( 'GET', 'payment_intents/search', 200, self::found( $intent, $intent ) );

		$none = $this->gateway->query( self::query( null ) );

		$this->assertSame(
			array(
				'query' => 'metadata["intent_uuid"]:"' . Fixtures::INTENT_UUID . '"',
				'limit' => '10',
			),
			$this->context->stripe->parameters()
		);
		$this->assertNotNull( $none );
		$this->assertSame( array( Operation::Authorize, Outcome::Declined, 'not_found', null, null ), array( $none->operation, $none->outcome, $none->errorCode, $none->providerObjectId, $none->providerIntentId ) );

		$one = $this->gateway->query( self::query( null ) );

		$this->assertNotNull( $one );
		$this->assertSame( array( Outcome::Approved, Fixtures::INTENT_ID ), array( $one->outcome, $one->providerIntentId ) );
		$this->assertNull( $this->gateway->query( self::query( null ) ), 'Two PaymentIntents for one payment: the gateway cannot say.' );
		$this->assertSame( array( 'stripe.search_ambiguous' ), $this->context->log->codes() );
	}

	/**
	 * Tests a PaymentIntent created for another payment: no answer.
	 *
	 * @since 0.2.0
	 */
	public function test_another_payments_payment_intent_is_no_answer(): void {
		$this->context->stripe->answer( 'GET', 'payment_intents/pi_3Fixture0001', 200, Fixtures::with( 'pi-requires-capture', array( 'metadata' => array( 'intent_uuid' => '0199c0de-0000-7000-8000-00000000ffff' ) ) ) );

		$this->unavailable( fn() => $this->gateway->query( self::query( Fixtures::INTENT_ID ) ) );
		$this->assertSame( array( 'stripe.foreign_intent' ), $this->context->log->codes() );
	}

	/**
	 * Builds a search answer.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> ...$intents The PaymentIntents found.
	 * @return string The answer.
	 */
	private static function found( array ...$intents ): string {
		return Fixtures::listOf( '/v1/payment_intents/search', array_values( $intents ), false, 'search_result' );
	}

	/**
	 * Builds a status query.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $intentId Stripe's id, when SEOCart has it.
	 * @return PaymentQuery The query.
	 */
	private static function query( ?string $intentId ): PaymentQuery {
		return new PaymentQuery( Fixtures::INTENT_UUID, $intentId, Money::of( 2500, Currency::of( 'USD' ) ), Mode::Test );
	}
}
