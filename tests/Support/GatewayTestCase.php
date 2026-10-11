<?php
/**
 * GatewayTestCase: a unit test of the gateway against the fake Stripe
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\GatewayForStripe\Gateway;

/**
 * Builds the gateway on a fake context for each test and, after each, scans everything it logged and every message it threw.
 *
 * The scan is the card-data and secrets rule applied to every test: a test of any operation fails
 * when the gateway handed its logger card details, a header, a key, a signing secret, a client
 * secret or a piece of a body Stripe sent.
 *
 * @since 0.2.0
 */
abstract class GatewayTestCase extends TestCase {

	/**
	 * What the gateway is given.
	 *
	 * @since 0.2.0
	 *
	 * @var FakeContext
	 */
	protected FakeContext $context;

	/**
	 * The gateway.
	 *
	 * @since 0.2.0
	 *
	 * @var Gateway
	 */
	protected Gateway $gateway;

	/**
	 * The messages of the exceptions the gateway threw in this test.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	protected array $thrown = array();

	/**
	 * Builds the context and the gateway; the translation functions return their text.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();

		$this->context = new FakeContext();
		$this->gateway = new Gateway( $this->context );
	}

	/**
	 * Scans what the gateway wrote and threw, then tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		$findings = LogScan::findings( $this->written(), array_merge( $this->context->credentials(), array( Fixtures::CLIENT_SECRET ) ), $this->context->stripe->served );

		Monkey\tearDown();
		parent::tearDown();

		$this->assertSame( array(), $findings, 'The gateway logged or threw what it must never write.' );
	}

	/**
	 * Runs a call that must throw GatewayUnavailable, and returns the exception.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $call The call.
	 * @return GatewayUnavailable The exception.
	 */
	protected function unavailable( \Closure $call ): GatewayUnavailable {
		try {
			$call();
		} catch ( GatewayUnavailable $unavailable ) {
			$this->thrown[] = $unavailable->getMessage();

			return $unavailable;
		}

		$this->fail( 'The gateway answered where it had no answer to give.' );
	}

	/**
	 * Returns a request's headers.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest|null $request Optional. The request. Default the last.
	 * @return array<string, string> The headers.
	 */
	protected function headers( ?OutboundRequest $request = null ): array {
		return ( $request ?? $this->context->stripe->last() )->headers;
	}

	/**
	 * Returns everything the gateway wrote in this test: every log line and every message it threw, encoded.
	 *
	 * @since 0.2.0
	 *
	 * @return string The text.
	 */
	protected function written(): string {
		return (string) json_encode( array( $this->context->log->lines, $this->thrown ), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}
}
