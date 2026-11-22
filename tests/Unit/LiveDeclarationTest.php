<?php
/**
 * Tests what the plugin states of its live workflow, and what its own classes add to SEOCart's for a run
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\StripeRun;

/**
 * The guard built from tests/live.php refuses an argument shaped like one of Stripe's keys, a signing secret, a client secret or a receipt's address, and blanks each out of a text, while Stripe's ids pass; and the run's installation is the version-5 uuid of the repository's address, the same on every run, that repository being the one the declaration derives.
 *
 * Every canary is built when the test runs, so no key-shaped text is kept in the repository.
 * SEOCart's own tests hold the shapes every extension shares: an address with a query, an
 * authorization header and a card number.
 *
 * Planted violation, shown red and removed: each shape taken out of the declaration's shapes() in
 * turn: its canary is formatted into the message, and redact() leaves it in the text.
 *
 * @since 0.2.0
 */
final class LiveDeclarationTest extends TestCase {

	/**
	 * Tests that a message of Stripe's ids, counts and words is formatted as asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_message_of_ids_and_counts_is_formatted(): void {
		$this->assertSame( 'pi_3Abc answered requires_capture after 2 tries.', StripeRun::guard()->say( '%1$s answered %2$s after %3$d tries.', 'pi_3Abc', 'requires_capture', 2 ) );
		$this->assertNull( StripeRun::guard()->shapeOf( 'https://example.org/' ) );
	}

	/**
	 * Tests that each of Stripe's canaries is refused as an argument, by its kind and never its value, and blanked out of a text.
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
			StripeRun::guard()->say( 'The answer was %s.', $canary );
			$this->fail( sprintf( 'A message carried %s.', $kind ) );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( $kind, $refused->getMessage() );
			$this->assertStringNotContainsString( $canary, $refused->getMessage() );
		}

		$redacted = StripeRun::guard()->redact( 'before ' . $canary . ' after' );

		$this->assertStringNotContainsString( $canary, $redacted );
		$this->assertStringStartsWith( 'before ', $redacted );
		$this->assertStringEndsWith( ' after', $redacted );
	}

	/**
	 * Returns a canary of each of Stripe's shapes, made up as the test runs.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> Each shape's name and canary, by name.
	 */
	public static function canaries(): array {
		$random = static fn( int $length ): string => substr( str_repeat( bin2hex( random_bytes( 32 ) ), 2 ), 0, $length );

		return array(
			'secret key'       => array( 'a secret or restricted key', 'sk_test_' . $random( 24 ) ),
			'restricted key'   => array( 'a secret or restricted key', 'rk_test_' . $random( 24 ) ),
			'live publishable' => array( 'a publishable key', 'pk_live_' . $random( 24 ) ),
			'test publishable' => array( 'a publishable key', 'pk_test_' . $random( 99 ) ),
			'signing secret'   => array( 'a webhook signing secret', 'whsec_' . $random( 32 ) ),
			'client secret'    => array( 'a client secret', 'pi_' . $random( 24 ) . '_secret_' . $random( 25 ) ),
			'receipt'          => array( 'a receipt\'s address', '"receipt_url":"https://pay.example/receipts/x"' ),
		);
	}

	/**
	 * Tests the installation uuid: RFC 9562's version-5 uuid of the repository's address in the URL namespace, its version and variant bits set, the repository being the one the declaration derives.
	 *
	 * @since 0.2.0
	 */
	public function test_the_installation_is_the_version_5_uuid_of_the_repository(): void {
		$uuid = StripeRun::installUuid();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid );
		$this->assertSame( $uuid, StripeRun::installUuid() );
		// The first 32 bits are those of SHA-1 over the namespace's 16 bytes and the name.
		$this->assertSame( substr( sha1( (string) hex2bin( '6ba7b8119dad11d180b400c04fd430c8' ) . StripeRun::REPOSITORY_URL ), 0, 8 ), substr( $uuid, 0, 8 ) );
		$this->assertSame( 'https://github.com/' . StripeRun::declaration()->repository(), StripeRun::REPOSITORY_URL );
	}
}
