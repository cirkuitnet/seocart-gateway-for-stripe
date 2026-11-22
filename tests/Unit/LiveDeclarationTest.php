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
use SEOCart\Tests\Conformance\Payment\Live\LiveRun;

/**
 * The guard built from tests/live.php refuses an argument shaped like one of Stripe's keys, a signing secret, a client secret or a receipt's address, and blanks each out of a text, while Stripe's ids pass; the run's installation is the same version-5 uuid on every run, made from the address of the repository the declaration derives; and the cleanup reads the run list as PaymentIntents alone.
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
	 * Tests the installation uuid, whole: RFC 9562's version-5 uuid of https://github.com/cirkuitnet/seocart-gateway-for-stripe in the URL namespace, the address made from the repository the declaration derives.
	 *
	 * The webhook endpoint every run sets up at Stripe is found by this uuid, so it never changes:
	 * the literal is the uuid the runs have used since the live workflow began.
	 *
	 * Planted violation, shown red and removed: one offset of installUuid()'s substr() calls changed.
	 *
	 * @since 0.2.0
	 */
	public function test_the_installation_is_the_version_5_uuid_of_the_repository(): void {
		$this->assertSame( 'a8c53d85-ff8c-52ea-9f2c-67efde16e51b', StripeRun::installUuid() );
	}

	/**
	 * Tests that the cleanup reads the run list as PaymentIntents: a line of another prefix, or with a `pi_` id holding a character no PaymentIntent's id has, is left out, though SEOCart's run list takes both.
	 *
	 * Planted violation, shown red and removed: the shape's check dropped from StripeRun::paymentIntents().
	 *
	 * @since 0.2.0
	 */
	public function test_the_run_list_is_read_as_payment_intents_alone(): void {
		$text = "pi_3Abc 00000000-0000-5000-8000-000000000001\nwe_3Abc 00000000-0000-5000-8000-000000000002\npi_3A-bc 00000000-0000-5000-8000-000000000003\n";

		$this->assertCount( 3, LiveRun::runList( $text ) );
		$this->assertSame( array( 'pi_3Abc' => '00000000-0000-5000-8000-000000000001' ), StripeRun::paymentIntents( $text ) );
	}
}
