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

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Integration\StripeSuiteDeclarations;
use SEOCart\Tests\Conformance\Payment\TapeRefused;
use SEOCart\Tests\Conformance\Payment\TapeScrubber;

/**
 * SEOCart's tape scrubber, built from StripeSuiteDeclarations as the suite builds it, keeps a test publishable key of any length Stripe may give and refuses a secret key in its place.
 *
 * Every key is made up as the test runs, so no key-shaped text is kept in the repository.
 *
 * Planted violation, shown red and removed: the publishable key's shape back to exactly 99
 * characters after `pk_test_` (each other length refused).
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
