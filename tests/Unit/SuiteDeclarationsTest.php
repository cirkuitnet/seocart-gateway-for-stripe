<?php
/**
 * Tests what a tape may keep of Stripe's answers, by the declarations the suite's test and the live tests share
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\Tests\Integration\StripeSuiteDeclarations;
use SEOCart\Tests\Conformance\Payment\Exchange;
use SEOCart\Tests\Conformance\Payment\TapeRefused;
use SEOCart\Tests\Conformance\Payment\TapeScrubber;

/**
 * SEOCart's tape scrubber, built from StripeSuiteDeclarations as the suite builds it, keeps a test publishable key of any length Stripe may give and refuses a secret key in its place, and keeps a refund's acquirer reference as its placeholder while a card number anywhere else is refused.
 *
 * Every key is made up as the test runs, so no key-shaped text is kept in the repository; the
 * refund's ids are those of the suite's recording.
 *
 * Planted violations, each shown red and removed: the publishable key's shape back to exactly 99
 * characters after `pk_test_` (each other length refused); the refund's reference taken out of
 * providerSecrets() (refused as a card number); the reference's declaration widened to any
 * `"reference"` field (a charge's card reference, kept, no longer refused).
 *
 * @since 0.2.0
 */
final class SuiteDeclarationsTest extends TestCase {

	use StripeSuiteDeclarations;

	/**
	 * The letters and digits a made-up key is drawn from.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ALPHANUMERIC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

	/**
	 * A refund's acquirer reference whose digits pass the card checksum: Stripe's published test card number stands in for it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFERENCE = '4242424242424242';

	/**
	 * Where the suite read the refund when it refused its recording.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WHERE = 'Exchange 5 of rule02-cell-usd-us';

	/**
	 * Stands in for the two WordPress functions the scrubber calls, each as PHP's own function it wraps behaves on a whole URL and on JSON.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that a recording's settings keep a test publishable key of any length up to Stripe's 255 characters.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider publishableLengths
	 *
	 * @param int $length The letters and digits after `pk_test_`.
	 */
	public function test_a_test_publishable_key_of_any_length_stripe_may_give_is_kept( int $length ): void {
		$this->scrubber()->scanSettings(
			array(
				'publishable_key' => 'pk_test_' . self::madeUp( $length ),
				'account_country' => 'US',
			),
			'settings.json'
		);

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Returns lengths of a publishable key after its prefix: an older key's, the one recorded, and longer ones to Stripe's limit.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{int}> Each length, by name.
	 */
	public static function publishableLengths(): array {
		return array(
			'an older key'     => array( 24 ),
			'the key recorded' => array( 99 ),
			'one more'         => array( 100 ),
			'longer'           => array( 150 ),
			'the longest'      => array( 247 ),
		);
	}

	/**
	 * Tests that a key past 255 characters, or a secret or restricted key, is refused where the publishable key goes, naming the setting and never the value.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider refusedKeys
	 *
	 * @param string $prefix The key's prefix.
	 * @param int    $length The letters and digits after it.
	 */
	public function test_a_longer_key_or_a_secret_key_is_refused_as_the_publishable_key( string $prefix, int $length ): void {
		$key = $prefix . self::madeUp( $length );

		try {
			$this->scrubber()->scanSettings( array( 'publishable_key' => $key ), 'settings.json' );
			$this->fail( 'The settings kept a key no publishable key is.' );
		} catch ( TapeRefused $refused ) {
			$this->assertStringContainsString( 'publishable_key', $refused->getMessage() );
			$this->assertStringNotContainsString( $key, $refused->getMessage() );
		}
	}

	/**
	 * Returns keys no publishable key is.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, int}> Each key's prefix and length, by name.
	 */
	public static function refusedKeys(): array {
		return array(
			'past 255 characters' => array( 'pk_test_', 248 ),
			'a restricted key'    => array( 'rk_test_', 99 ),
			'a secret key'        => array( 'sk_test_', 99 ),
		);
	}

	/**
	 * Tests that a refund's acquirer reference number, digits that pass the card checksum, is kept as its placeholder in the answer that lists a PaymentIntent's refunds.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refunds_acquirer_reference_is_kept_as_its_placeholder(): void {
		$scrubber = $this->scrubber();
		$clean    = $scrubber->exchange( self::refundList( self::REFERENCE ) );

		$scrubber->scanExchange( $clean, self::WHERE );

		$this->assertStringContainsString( '"reference": "{{secret:refund_reference}}",', $clean->responseBody );
		$this->assertStringNotContainsString( self::REFERENCE, $clean->responseBody );
		$this->assertStringContainsString( '"reference_status": "available"', $clean->responseBody );
	}

	/**
	 * Tests that, without its declaration, the reference refuses the recording as a card number, as the suite refused a recording against Stripe's test mode.
	 *
	 * @since 0.2.0
	 */
	public function test_without_its_declaration_the_reference_is_refused_as_a_card_number(): void {
		$secrets = $this->providerSecrets();

		unset( $secrets['refund_reference'] );

		$scrubber = new TapeScrubber( array(), $secrets, $this->publicIdShapes(), $this->publicDigestFields(), $this->publicCodes(), $this->publicHeaders() );

		$this->expectException( TapeRefused::class );
		$this->expectExceptionMessage( self::WHERE . ', its response body field data.0.destination_details.card.reference holds a card number' );

		$scrubber->scanExchange( $scrubber->exchange( self::refundList( self::REFERENCE ) ), self::WHERE );
	}

	/**
	 * Tests that the same digits anywhere else in the refund still refuse the recording: the declaration keeps the reference alone.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_number_elsewhere_in_the_refund_is_still_refused(): void {
		$scrubber = $this->scrubber();

		$this->expectException( TapeRefused::class );
		$this->expectExceptionMessage( 'data.0.receipt_number holds a card number' );

		$scrubber->scanExchange( $scrubber->exchange( self::refundList( self::REFERENCE, self::REFERENCE ) ), self::WHERE );
	}

	/**
	 * Tests that a `reference` field in another object, a charge's card details, still refuses the recording as a card number: the declaration reaches a refund's destination details alone.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_number_in_another_objects_reference_is_still_refused(): void {
		$scrubber = $this->scrubber();

		$this->expectException( TapeRefused::class );
		$this->expectExceptionMessage( self::WHERE . ', its response body field payment_method_details.card.reference holds a card number' );

		$scrubber->scanExchange( $scrubber->exchange( self::chargeAnswer( self::REFERENCE ) ), self::WHERE );
	}

	/**
	 * Returns the exchange that lists a PaymentIntent's refunds, its one refund shaped as Stripe answers it at the pinned version once the acquirer's reference is available.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $reference     The refund's `destination_details.card.reference`.
	 * @param string|null $receiptNumber Its `receipt_number`; null for none.
	 * @return Exchange The exchange.
	 */
	private static function refundList( string $reference, ?string $receiptNumber = null ): Exchange {
		$body = <<<'JSON'
{
  "object": "list",
  "data": [
    {
      "id": "re_3URU9mJyxgXirw330poazZSA",
      "object": "refund",
      "amount": 1200,
      "balance_transaction": "txn_3URU9mJyxgXirw330yFkqFxB",
      "charge": "ch_3URU9mJyxgXirw330deyPUfC",
      "created": 1792230243,
      "currency": "usd",
      "destination_details": {
        "card": {
          "reference": "%1$s",
          "reference_status": "available",
          "reference_type": "acquirer_reference_number",
          "type": "refund"
        },
        "type": "card"
      },
      "metadata": {
        "refund_uuid": "310a4340-7a1d-5fae-a018-db12389fd7ed"
      },
      "payment_intent": "pi_3URU9mJyxgXirw330FROUxix",
      "payment_method": "pm_1URU9mJyxgXirw33PuxW6kdB",
      "reason": null,
      "receipt_number": %2$s,
      "status": "succeeded"
    }
  ],
  "has_more": false,
  "url": "/v1/refunds"
}
JSON;

		return self::answer( 'https://api.stripe.com/v1/refunds?payment_intent=pi_3URU9mJyxgXirw330FROUxix&limit=100', sprintf( $body, $reference, null === $receiptNumber ? 'null' : '"' . $receiptNumber . '"' ) );
	}

	/**
	 * Returns the exchange that fetches a charge, whose `payment_method_details.card.reference` holds the digits.
	 *
	 * @since 0.2.0
	 *
	 * @param string $reference The charge's `payment_method_details.card.reference`.
	 * @return Exchange The exchange.
	 */
	private static function chargeAnswer( string $reference ): Exchange {
		$body = <<<'JSON'
{
  "id": "ch_3URU9mJyxgXirw330deyPUfC",
  "object": "charge",
  "amount": 1200,
  "currency": "usd",
  "payment_intent": "pi_3URU9mJyxgXirw330FROUxix",
  "payment_method_details": {
    "card": {
      "brand": "visa",
      "reference": "%s"
    },
    "type": "card"
  },
  "status": "succeeded"
}
JSON;

		return self::answer( 'https://api.stripe.com/v1/charges/ch_3URU9mJyxgXirw330deyPUfC', sprintf( $body, $reference ) );
	}

	/**
	 * Returns Stripe's answer to a GET at the pinned API version.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url  The request's address.
	 * @param string $body The answer's body.
	 * @return Exchange The exchange.
	 */
	private static function answer( string $url, string $body ): Exchange {
		return new Exchange( 'GET', $url, array( 'stripe-version' => Gateway::API_VERSION ), null, 200, array( 'content-type' => 'application/json' ), $body );
	}

	/**
	 * Builds the scrubber as SEOCart's conformance harness builds it from the declarations.
	 *
	 * @since 0.2.0
	 *
	 * @return TapeScrubber The scrubber.
	 */
	private function scrubber(): TapeScrubber {
		return new TapeScrubber( array(), $this->providerSecrets(), $this->publicIdShapes(), $this->publicDigestFields(), $this->publicCodes(), $this->publicHeaders() );
	}

	/**
	 * Makes up a run of letters and digits.
	 *
	 * @since 0.2.0
	 *
	 * @param int $length Its length.
	 * @return string The run.
	 */
	private static function madeUp( int $length ): string {
		$run = '';

		for ( $at = 0; $at < $length; $at++ ) {
			$run .= self::ALPHANUMERIC[ random_int( 0, strlen( self::ALPHANUMERIC ) - 1 ) ];
		}

		return $run;
	}
}
