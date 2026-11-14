<?php
/**
 * Tests that the checkout's card form script agrees with the gateway and with SEOCart's island contract
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\StripeApi;

/**
 * The island script, assets/island.js, says in the browser whether Stripe takes a payment, as the gateway's isAvailable() says it
 * on the server; the two cannot share a declaration across languages, so this test reads the script's limits and holds them to
 * the PHP constants. It also holds the script to what it may do: register with the one statement SEOCart documents, for the
 * contract version SEOCart implements, name no origin but Stripe.js's, send no request of its own and keep nothing in the
 * browser's storage.
 *
 * The script's limits are object literals of plain names and numbers, each on its one line, read as JSON once its names are
 * quoted: a literal that cannot be read so fails the test.
 *
 * Planted violation, shown red and removed: the script's minimum for USD changed to 49: the minimum's test fails.
 *
 * @since 0.2.0
 */
final class IslandTest extends TestCase {

	/**
	 * Returns the script's source.
	 *
	 * @since 0.2.0
	 *
	 * @return string The source.
	 */
	private static function script(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/island.js' );
	}

	/**
	 * Returns the source with its comments removed, so that only code is read.
	 *
	 * @since 0.2.0
	 *
	 * @return string The code.
	 */
	private static function code(): string {
		return (string) preg_replace( '#^\s*//.*$#m', '', (string) preg_replace( '#/\*.*?\*/#s', '', self::script() ) );
	}

	/**
	 * Reads one of the script's constants: `const NAME = <literal>;` on one line, as JSON once its names are quoted.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The constant's name.
	 * @return mixed The value.
	 */
	private static function constant( string $name ): mixed {
		$found = preg_match( '/^\t*const ' . $name . ' = (.+);$/m', self::code(), $match );

		self::assertSame( 1, $found, "The script declares no constant {$name} on one line." );

		$json  = (string) preg_replace( '/([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', str_replace( "'", '"', $match[1] ) );
		$value = json_decode( $json, true );

		self::assertNotNull( $value, "The script's {$name} cannot be read: {$match[1]}" );

		return $value;
	}

	/**
	 * Tests that the smallest amount, by account country and currency, is the gateway's.
	 *
	 * @since 0.2.0
	 */
	public function test_the_minimum_is_the_gateways(): void {
		$this->assertSame( Gateway::MINIMUM_MINOR, self::constant( 'MINIMUM_MINOR' ) );
	}

	/**
	 * Tests that the largest amount is the gateway's.
	 *
	 * @since 0.2.0
	 */
	public function test_the_maximum_is_the_gateways(): void {
		$this->assertSame( Gateway::MAXIMUM_MINOR, self::constant( 'MAXIMUM_MINOR' ) );
	}

	/**
	 * Tests that the publishable key's prefix of each mode is the one the gateway accepts for that mode, and no other's.
	 *
	 * @since 0.2.0
	 */
	public function test_the_key_prefixes_are_the_ones_the_gateway_accepts(): void {
		$prefixes = self::constant( 'KEY_PREFIXES' );

		$this->assertSame( array_map( static fn( Mode $mode ): string => $mode->value, Mode::cases() ), array_keys( $prefixes ), 'One prefix per mode.' );

		foreach ( Mode::cases() as $mode ) {
			foreach ( $prefixes as $of => $prefix ) {
				$this->assertSame( $of === $mode->value, StripeApi::isKeyOf( $mode, $prefix . 'example', array( 'pk' ) ), "The {$of} prefix, in {$mode->value} mode." );
			}
		}
	}

	/**
	 * Tests that the island registers with the statement SEOCart's documentation gives, for the gateway's id.
	 *
	 * @since 0.2.0
	 */
	public function test_the_island_registers_with_the_documented_statement(): void {
		$idiom = '( ( window.seocart = window.seocart || {} ).payments = window.seocart.payments || { queue: [], register( island ) { this.queue.push( island ); }, } ).register( { id: \'' . Gateway::ID . '\',';

		// Its words as written, whatever the line breaks: a member access may begin a line.
		$code = str_replace( ' .', '.', (string) preg_replace( '/\s+/', ' ', self::code() ) );

		$this->assertStringContainsString( $idiom, $code );
		$this->assertSame( 1, substr_count( self::code(), '.register(' ), 'The island registers once.' );
	}

	/**
	 * Tests that the island is written against the contract version the SEOCart it is pinned to implements.
	 *
	 * @since 0.2.0
	 */
	public function test_the_contract_version_is_the_one_seocart_implements(): void {
		$registry = (string) file_get_contents( dirname( __DIR__, 3 ) . '/seocart/assets/storefront/payments/registry.js' );

		$this->assertSame( 1, preg_match( "/^export const CONTRACT_VERSION = '([0-9.]+)';$/m", $registry, $core ), 'SEOCart\'s registry declares its contract version.' );
		$this->assertSame( 1, preg_match_all( "/contractVersion: '([0-9.]+)',/", self::code(), $island ) );
		$this->assertSame( array( $core[1] ), $island[1] );
	}

	/**
	 * Tests that Stripe.js's address is the only one the script names, in its code or its comments.
	 *
	 * @since 0.2.0
	 */
	public function test_stripe_js_is_the_only_address_named(): void {
		preg_match_all( '#[a-z]+://[^\s\'"`)]*#i', self::script(), $addresses );

		$this->assertSame( array( 'https://js.stripe.com/v3/' ), array_values( array_unique( $addresses[0] ) ) );
		$this->assertSame( 1, preg_match( "#^\tconst STRIPE_JS = 'https://js\\.stripe\\.com/v3/';$#m", self::code() ) );
	}

	/**
	 * Tests that the script sends no request of its own and keeps nothing in the browser's storage.
	 *
	 * @since 0.2.0
	 */
	public function test_the_script_sends_nothing_and_keeps_nothing(): void {
		foreach ( array( 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'WebSocket', 'EventSource', 'import(', 'localStorage', 'sessionStorage', 'indexedDB', 'document.cookie', 'eval(', 'new Function' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, self::code(), "The script uses {$forbidden}." );
		}
	}
}
