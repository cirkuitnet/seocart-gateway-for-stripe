<?php
/**
 * Tests the check of Stripe's webhook signature, and that a delivery is verified before anything of it is read
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\GatewayForStripe\Tests\Support\FakeContext;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\GatewayForStripe\WebhookSignature;

/**
 * A delivery verifies when one of its `v1` signatures is the HMAC-SHA256 of `t.body` with the signing secret, and its
 * `t` is within 300 seconds of the clock; every other scheme is ignored. readWebhook() checks this before it decodes
 * anything: a body that is not JSON with a bad signature is a bad signature, not a malformed delivery.
 *
 * Planted violations, each shown red and removed:
 * - the body decoded before the signature is checked: the invalid-body-bad-signature case answers `malformed`;
 * - the window measured from the event's `created`: the stale tests fail;
 * - `v0` accepted as a signature: the second-scheme test fails.
 *
 * @since 0.2.0
 */
final class WebhookSignatureTest extends GatewayTestCase {

	/**
	 * The body the checks sign.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const BODY = '{"id":"evt_1","object":"event"}';

	/**
	 * The secret the checks sign with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SECRET = 'whsec_signatureTestSecret';

	/**
	 * Tests a good signature, and the same body or secret changed.
	 *
	 * @since 0.2.0
	 */
	public function test_a_signature_verifies_only_for_its_body_and_secret(): void {
		$now = $this->now();

		$this->assertNull( WebhookSignature::check( self::header( self::BODY, $now ), self::BODY, self::SECRET, $this->clockNow() ) );
		$this->assertSame( WebhookReading::BAD_SIGNATURE, WebhookSignature::check( self::header( self::BODY, $now ), self::BODY . ' ', self::SECRET, $this->clockNow() ) );
		$this->assertSame( WebhookReading::BAD_SIGNATURE, WebhookSignature::check( self::header( self::BODY, $now ), self::BODY, 'whsec_another', $this->clockNow() ) );
	}

	/**
	 * Tests the window, both ways, on the signed timestamp.
	 *
	 * @since 0.2.0
	 */
	public function test_the_signed_timestamp_must_be_within_five_minutes(): void {
		$now = $this->now();

		$this->assertNull( WebhookSignature::check( self::header( self::BODY, $now - 300 ), self::BODY, self::SECRET, $this->clockNow() ) );
		$this->assertNull( WebhookSignature::check( self::header( self::BODY, $now + 300 ), self::BODY, self::SECRET, $this->clockNow() ) );
		$this->assertSame( WebhookReading::STALE, WebhookSignature::check( self::header( self::BODY, $now - 301 ), self::BODY, self::SECRET, $this->clockNow() ) );
		$this->assertSame( WebhookReading::STALE, WebhookSignature::check( self::header( self::BODY, $now + 301 ), self::BODY, self::SECRET, $this->clockNow() ) );
	}

	/**
	 * Tests the schemes: only `v1` counts; one good `v1` among several verifies, as while a secret is rolled.
	 *
	 * @since 0.2.0
	 */
	public function test_only_a_v1_signature_counts(): void {
		$t    = (string) $this->now();
		$good = hash_hmac( 'sha256', $t . '.' . self::BODY, self::SECRET );
		$bad  = str_repeat( '0', 64 );

		$this->assertSame( WebhookReading::BAD_SIGNATURE, WebhookSignature::check( "t={$t},v0={$good}", self::BODY, self::SECRET, $this->clockNow() ), 'v0 alone' );
		$this->assertSame( WebhookReading::BAD_SIGNATURE, WebhookSignature::check( "t={$t},v1={$bad},v0={$good}", self::BODY, self::SECRET, $this->clockNow() ), 'a bad v1 and a good v0' );
		$this->assertNull( WebhookSignature::check( "t={$t},v1={$bad},v1={$good}", self::BODY, self::SECRET, $this->clockNow() ), 'a bad v1 and a good one' );
		$this->assertNull( WebhookSignature::check( "t={$t}, v1={$good}, v0={$bad}", self::BODY, self::SECRET, $this->clockNow() ), 'spaces after the commas' );
	}

	/**
	 * Tests malformed headers.
	 *
	 * @since 0.2.0
	 */
	public function test_a_header_that_is_not_stripes_does_not_verify(): void {
		$t    = (string) $this->now();
		$good = hash_hmac( 'sha256', $t . '.' . self::BODY, self::SECRET );

		$headers = array(
			'none'           => null,
			'empty'          => '',
			'no timestamp'   => "v1={$good}",
			'two timestamps' => "t={$t},t={$t},v1={$good}",
			'a word for t'   => "t=now,v1={$good}",
			'upper-case v1'  => "t={$t},v1=" . strtoupper( $good ),
			'too long'       => "t={$t},v1={$good}," . str_repeat( 'x', 4096 ),
		);

		foreach ( $headers as $case => $header ) {
			$this->assertSame( WebhookReading::BAD_SIGNATURE, WebhookSignature::check( $header, self::BODY, self::SECRET, $this->clockNow() ), $case );
		}
	}

	/**
	 * Tests that readWebhook() verifies before it decodes: a body that is not JSON is a bad signature unless it was signed.
	 *
	 * @since 0.2.0
	 */
	public function test_nothing_is_decoded_before_the_signature_holds(): void {
		$body = '{ this is not JSON';

		$unsigned = $this->gateway->readWebhook( self::envelope( $body, 't=' . $this->now() . ',v1=' . str_repeat( 'a', 64 ) ) );
		$signed   = $this->gateway->readWebhook( self::envelope( $body, Fixtures::signature( $body, FakeContext::TEST_SIGNING_SECRET, $this->now() ) ) );

		$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::BAD_SIGNATURE ), array( $unsigned->kind, $unsigned->reason ) );
		$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::MALFORMED ), array( $signed->kind, $signed->reason ) );
	}

	/**
	 * Tests that a stale delivery is rejected on its signed timestamp, even when the event itself is fresh.
	 *
	 * @since 0.2.0
	 */
	public function test_a_retry_is_judged_by_its_own_timestamp(): void {
		$fresh = Fixtures::with( 'event-payment_intent.succeeded', array( 'created' => $this->now() ) );
		$old   = Fixtures::with( 'event-payment_intent.succeeded', array( 'created' => $this->now() - 3 * 86400 ) );

		$stale   = $this->gateway->readWebhook( self::envelope( $fresh, Fixtures::signature( $fresh, FakeContext::TEST_SIGNING_SECRET, $this->now() - 400 ) ) );
		$retried = $this->gateway->readWebhook( self::envelope( $old, Fixtures::signature( $old, FakeContext::TEST_SIGNING_SECRET, $this->now() ) ) );

		$this->assertSame( WebhookReading::STALE, $stale->reason, 'A fresh event in an old delivery is stale.' );
		$this->assertSame( WebhookReadingKind::Result, $retried->kind, 'A three-day-old event Stripe retries now is read.' );
	}

	/**
	 * Tests that the same delivery read twice gives the same reading, and that only the delivery's mode's secret is opened.
	 *
	 * @since 0.2.0
	 */
	public function test_a_valid_delivery_read_twice_reads_the_same_with_its_modes_secret(): void {
		$body     = Fixtures::text( 'event-payment_intent.succeeded' );
		$envelope = self::envelope( $body, Fixtures::signature( $body, FakeContext::TEST_SIGNING_SECRET, $this->now() ) );

		$first  = $this->gateway->readWebhook( $envelope );
		$second = $this->gateway->readWebhook( $envelope );

		$this->assertEquals( $first, $second );
		$this->assertSame( 'evt_3Fixture0002', $first->eventId );
		$this->assertSame( array( 'test/webhook_secret', 'test/webhook_secret' ), $this->context->secretReads );
	}

	/**
	 * Returns the clock's time, as a timestamp.
	 *
	 * @since 0.2.0
	 *
	 * @return int The timestamp.
	 */
	private function now(): int {
		return $this->clockNow()->getTimestamp();
	}

	/**
	 * Returns the clock's time.
	 *
	 * @since 0.2.0
	 *
	 * @return \DateTimeImmutable The time.
	 */
	private function clockNow(): \DateTimeImmutable {
		return $this->context->clock->now();
	}

	/**
	 * Returns the header of the test body, signed with the test secret at a time.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body      The body.
	 * @param int    $timestamp The time.
	 * @return string The header.
	 */
	private static function header( string $body, int $timestamp ): string {
		return Fixtures::signature( $body, self::SECRET, $timestamp );
	}

	/**
	 * Builds a delivery to the test route.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body      The body.
	 * @param string $signature The `Stripe-Signature` header.
	 * @return WebhookEnvelope The delivery.
	 */
	private static function envelope( string $body, string $signature ): WebhookEnvelope {
		return new WebhookEnvelope(
			'stripe',
			Mode::Test,
			array(
				'content-type'     => 'application/json',
				'stripe-signature' => $signature,
			),
			$body,
			new \DateTimeImmutable( '2026-10-09 12:00:00', new \DateTimeZone( 'UTC' ) )
		);
	}
}
