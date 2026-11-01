<?php
/**
 * Tests that the live tests' messages never carry a credential
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\MessageGuard;

/**
 * A message of the live tests refuses an argument shaped like a key, a signing secret, a client secret, a receipt's address or an address with a query, and redact() blanks each such value out of a text.
 *
 * Every canary is built when the test runs, so no key-shaped text is kept in the repository.
 *
 * Planted violation, shown red and removed: each shape taken out of MessageGuard::SHAPES in
 * turn: its canary is formatted into the message, and redact() leaves it in the text; the address
 * pattern back to a plain `://` (an address JSON escaped, `https:\/\/bank.example\/auth?token=...`, is left whole).
 *
 * @since 0.2.0
 */
final class LiveMessageGuardTest extends TestCase {

	/**
	 * Tests that a message of ids, counts and words is formatted as asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_message_of_ids_and_counts_is_formatted(): void {
		$this->assertSame( 'pi_3Abc answered requires_capture after 2 tries.', MessageGuard::say( '%1$s answered %2$s after %3$d tries.', 'pi_3Abc', 'requires_capture', 2 ) );
		$this->assertNull( MessageGuard::shapeOf( 'https://example.org/' ) );
	}

	/**
	 * Tests that each canary is refused as an argument, by its kind and never its value, and blanked out of a text.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider canaries
	 *
	 * @param string $kind   What the canary is shaped like.
	 * @param string $canary The canary.
	 */
	public function test_a_credential_is_refused_and_redacted( string $kind, string $canary ): void {
		try {
			MessageGuard::say( 'The answer was %s.', $canary );
			$this->fail( sprintf( 'A message carried %s.', $kind ) );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( $kind, $refused->getMessage() );
			$this->assertStringNotContainsString( $canary, $refused->getMessage() );
		}

		$redacted = MessageGuard::redact( 'before ' . $canary . ' after' );

		$this->assertStringNotContainsString( $canary, $redacted );
		$this->assertStringStartsWith( 'before ', $redacted );
		$this->assertStringEndsWith( ' after', $redacted );
	}

	/**
	 * Tests that an address json_encode() escaped, with the backslash before each slash, is blanked whole, its query's opaque value with it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_json_escaped_address_is_blanked_whole(): void {
		$opaque  = bin2hex( random_bytes( 12 ) );
		$encoded = json_encode( array( 'next' => 'https://bank.example/auth?token=' . $opaque ) );

		$this->assertStringContainsString( 'https:\/\/bank.example\/auth?token=', (string) $encoded );

		$redacted = MessageGuard::redact( 'The answer was ' . $encoded . '.' );

		$this->assertStringNotContainsString( $opaque, $redacted );
		$this->assertStringNotContainsString( 'bank.example', $redacted );
		$this->assertStringStartsWith( 'The answer was {"next":"', $redacted );
	}

	/**
	 * Returns a canary of each shape, made up as the test runs.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> Each shape's name and canary, by name.
	 */
	public static function canaries(): array {
		$random = static fn( int $length ): string => substr( str_repeat( bin2hex( random_bytes( 32 ) ), 2 ), 0, $length );

		return array(
			'secret key'         => array( 'a secret or restricted key', 'sk_test_' . $random( 24 ) ),
			'restricted key'     => array( 'a secret or restricted key', 'rk_test_' . $random( 24 ) ),
			'live publishable'   => array( 'a publishable key', 'pk_live_' . $random( 24 ) ),
			'test publishable'   => array( 'a publishable key', 'pk_test_' . $random( 99 ) ),
			'signing secret'     => array( 'a webhook signing secret', 'whsec_' . $random( 32 ) ),
			'client secret'      => array( 'a client secret', 'pi_' . $random( 24 ) . '_secret_' . $random( 25 ) ),
			'receipt'            => array( 'a receipt\'s address', '"receipt_url":"https://pay.example/receipts/x"' ),
			'address with query' => array( 'an address with a query', 'https://hooks.example/3d_secure?client_secret=' . $random( 12 ) ),
			'escaped address'    => array( 'an address with a query', 'https:\/\/bank.example\/auth?token=' . $random( 24 ) ),
		);
	}
}
