<?php
/**
 * Tests the set-up of this site's Stripe webhook endpoint
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookProvisioning;
use SEOCart\Contracts\Payment\WebhookTarget;
use SEOCart\GatewayForStripe\Tests\Support\Fixtures;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;

/**
 * The gateway's provisionWebhooks() lists every endpoint; only one tagged with this installation and mode at this site's URL is this
 * site's. The one whose secret SEOCart holds is reused while it is enabled, on the pinned API version and subscribed to
 * exactly the events; every other one of this site's is deleted and one is created, tagged, with the gateway's events
 * and the pinned version. Endpoints of other sites and modes are never touched; this installation's at another URL are
 * counted.
 *
 * Planted violations, each shown red and removed:
 * - an endpoint matched by its URL alone: another installation's endpoint at the same URL is deleted;
 * - an endpoint's id taken without its kind: the endpoint-id test fails.
 *
 * @since 0.2.0
 */
final class ProvisioningTest extends GatewayTestCase {

	/**
	 * This site's installation uuid.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INSTALL = '0199c0de-0000-7000-8000-0000000000e1';

	/**
	 * This site's webhook route for the test mode.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const URL = 'https://shop.example/wp-json/seocart/v1/webhooks/stripe/test';

	/**
	 * A key, as a field Stripe answers could hold it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY = 'sk_test_leakedInAnAnswer';

	/**
	 * Tests the first set-up: nothing listed, one created with its URL, events, version and tags.
	 *
	 * @since 0.2.0
	 */
	public function test_an_endpoint_is_created_when_the_site_has_none(): void {
		$this->listed();
		$this->context->stripe->answer( 'POST', 'webhook_endpoints', 200, Fixtures::text( 'webhook-endpoint-created' ) );

		$done = $this->gateway->provisionWebhooks( $this->target( false ) );

		$this->assertSame( array( 'GET webhook_endpoints', 'POST webhook_endpoints' ), $this->context->stripe->sent() );
		$this->assertSame(
			array(
				'url'            => self::URL,
				'enabled_events' => $this->gateway->webhookEvents(),
				'api_version'    => '2026-09-30.endive',
				'description'    => 'SEOCart (test)',
				'metadata'       => array(
					'seocart_install' => self::INSTALL,
					'seocart_mode'    => 'test',
				),
			),
			$this->context->stripe->parameters()
		);
		$this->assertArrayNotHasKey( 'Idempotency-Key', $this->headers() );
		$this->assertSame( array( WebhookProvisioning::CREATED, 'we_1Fixture0009', 'whsec_createdFixtureSecret', array(), 0 ), self::summary( $done ) );
	}

	/**
	 * Tests the endpoint whose secret SEOCart holds, still matching: reused, nothing written.
	 *
	 * @since 0.2.0
	 */
	public function test_the_endpoint_whose_secret_is_held_is_reused(): void {
		$this->listed( $this->endpoint( 'we_1Held0000001' ) );

		$done = $this->gateway->provisionWebhooks( $this->target( true, 'we_1Held0000001' ) );

		$this->assertSame( array( 'GET webhook_endpoints' ), $this->context->stripe->sent() );
		$this->assertSame( array( WebhookProvisioning::REUSED, 'we_1Held0000001', null, array(), 0 ), self::summary( $done ) );
	}

	/**
	 * Tests each reason an endpoint of this site is replaced: no secret held, another endpoint held, other events, disabled, another version.
	 *
	 * @since 0.2.0
	 */
	public function test_an_endpoint_that_cannot_be_reused_is_replaced(): void {
		$cases = array(
			'secret not held' => array( $this->endpoint( 'we_1Old00000001' ), false, null ),
			'another held'    => array( $this->endpoint( 'we_1Old00000001' ), true, 'we_1Gone0000001' ),
			'other events'    => array( $this->endpoint( 'we_1Old00000001', array( 'enabled_events' => array( 'payment_intent.succeeded' ) ) ), true, 'we_1Old00000001' ),
			'disabled'        => array( $this->endpoint( 'we_1Old00000001', array( 'status' => 'disabled' ) ), true, 'we_1Old00000001' ),
			'another version' => array( $this->endpoint( 'we_1Old00000001', array( 'api_version' => '2026-08-26.dahlia' ) ), true, 'we_1Old00000001' ),
		);

		foreach ( $cases as $case => list( $endpoint, $held, $id ) ) {
			$this->context->stripe->requests = array();
			$this->listed( $endpoint );
			$this->context->stripe->answer( 'DELETE', 'webhook_endpoints/we_1Old00000001', 200, Fixtures::text( 'webhook-endpoint-deleted' ) );
			$this->context->stripe->answer( 'POST', 'webhook_endpoints', 200, Fixtures::text( 'webhook-endpoint-created' ) );

			$done = $this->gateway->provisionWebhooks( $this->target( $held, $id ) );

			$this->assertSame( array( 'GET webhook_endpoints', 'DELETE webhook_endpoints/we_1Old00000001', 'POST webhook_endpoints' ), $this->context->stripe->sent(), $case );
			$this->assertSame( array( WebhookProvisioning::REPLACED, 'we_1Fixture0009', 'whsec_createdFixtureSecret', array( 'we_1Old00000001' ), 0 ), self::summary( $done ), $case );
		}
	}

	/**
	 * Tests that endpoints of other sites, other modes and other integrations are never touched, and this installation's elsewhere is counted.
	 *
	 * @since 0.2.0
	 */
	public function test_only_this_sites_endpoints_are_touched(): void {
		$this->listed(
			$this->endpoint( 'we_1Held0000001' ),
			$this->endpoint( 'we_1OtherInstall', array( 'metadata' => array( 'seocart_install' => '0199c0de-0000-7000-8000-0000000000e2' ) ) ),
			$this->endpoint( 'we_1OtherMode01', array( 'metadata' => array( 'seocart_mode' => 'live' ) ) ),
			$this->endpoint( 'we_untagged', array( 'metadata' => array() ), false ),
			$this->endpoint( 'we_1Copy0000001', array( 'url' => 'https://copy.example/wp-json/seocart/v1/webhooks/stripe/test' ) ),
			$this->endpoint( 'we_duplicate' )
		);
		$this->context->stripe->answer( 'DELETE', 'webhook_endpoints/we_duplicate', 404, Fixtures::text( 'error-404' ) );

		$done = $this->gateway->provisionWebhooks( $this->target( true, 'we_1Held0000001' ) );

		$this->assertSame( array( 'GET webhook_endpoints', 'DELETE webhook_endpoints/we_duplicate' ), $this->context->stripe->sent(), 'Only this site\'s stale duplicate is deleted; one already gone counts as deleted.' );
		$this->assertSame( array( WebhookProvisioning::REUSED, 'we_1Held0000001', null, array( 'we_duplicate' ), 1 ), self::summary( $done ) );
	}

	/**
	 * Tests a list of more than one page.
	 *
	 * @since 0.2.0
	 */
	public function test_every_page_of_endpoints_is_read(): void {
		$this->context->stripe->answer( 'GET', 'webhook_endpoints', 200, Fixtures::listOf( '/v1/webhook_endpoints', array( $this->endpoint( 'we_1PageOne0001', array( 'metadata' => array( 'seocart_install' => 'other' ) ) ) ), true ) );
		$this->listed( $this->endpoint( 'we_1Held0000001' ) );

		$done = $this->gateway->provisionWebhooks( $this->target( true, 'we_1Held0000001' ) );

		$this->assertSame( 'we_1PageOne0001', $this->context->stripe->parameters()['starting_after'] );
		$this->assertSame( WebhookProvisioning::REUSED, $done->outcome );
	}

	/**
	 * Tests the failures: nothing written after a failed list; a creation refused after a deletion; a creation without its secret.
	 *
	 * @since 0.2.0
	 */
	public function test_a_failed_step_is_unavailable_and_says_which(): void {
		$this->context->stripe->answer( 'GET', 'webhook_endpoints', 500, Fixtures::text( 'error-500' ) );
		$this->unavailable( fn() => $this->gateway->provisionWebhooks( $this->target( false ) ) );
		$this->assertSame( array( 'GET webhook_endpoints' ), $this->context->stripe->sent() );

		$this->listed( $this->endpoint( 'we_1Old00000001' ) );
		$this->context->stripe->answer( 'DELETE', 'webhook_endpoints/we_1Old00000001', 200, Fixtures::text( 'webhook-endpoint-deleted' ) );
		$this->context->stripe->answer( 'POST', 'webhook_endpoints', 400, Fixtures::text( 'error-400-resource-missing' ) );
		$refused = $this->unavailable( fn() => $this->gateway->provisionWebhooks( $this->target( false ) ) );
		$this->assertSame( 'Creating the Stripe webhook endpoint failed: HTTP 400.', $refused->getMessage() );

		$created = Fixtures::object( 'webhook-endpoint-created' );
		unset( $created['secret'] );
		$this->listed();
		$this->context->stripe->answer( 'POST', 'webhook_endpoints', 200, Fixtures::encode( $created ) );
		$this->unavailable( fn() => $this->gateway->provisionWebhooks( $this->target( false ) ) );
	}

	/**
	 * Tests endpoints named by something that is not a webhook endpoint's id: one of this site's to delete, one just created, the last of a page; each is refused, and none is sent or written.
	 *
	 * @since 0.2.0
	 */
	public function test_an_endpoint_named_by_something_else_is_refused(): void {
		$this->listed( $this->endpoint( 'we_bad' ) );
		$this->unavailable( fn() => $this->gateway->provisionWebhooks( $this->target( false ) ) );

		$this->listed();
		$this->context->stripe->answer( 'POST', 'webhook_endpoints', 200, Fixtures::with( 'webhook-endpoint-created', array( 'id' => self::KEY ) ) );
		$this->unavailable( fn() => $this->gateway->provisionWebhooks( $this->target( false ) ) );

		$this->context->stripe->answer( 'GET', 'webhook_endpoints', 200, Fixtures::listOf( '/v1/webhook_endpoints', array( $this->endpoint( self::KEY, array(), false ) ), true ) );
		$this->unavailable( fn() => $this->gateway->provisionWebhooks( $this->target( false ) ) );

		$this->assertSame( array( 'GET webhook_endpoints', 'GET webhook_endpoints', 'POST webhook_endpoints', 'GET webhook_endpoints' ), $this->context->stripe->sent() );
		$this->assertStringNotContainsString( self::KEY, $this->written() );
	}

	/**
	 * Queues a one-page list of endpoints.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> ...$endpoints The endpoints.
	 */
	private function listed( array ...$endpoints ): void {
		$this->context->stripe->answer( 'GET', 'webhook_endpoints', 200, Fixtures::listOf( '/v1/webhook_endpoints', array_values( $endpoints ) ) );
	}

	/**
	 * Builds an endpoint of this site, from the fixture, changed as asked.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $id      The endpoint's id.
	 * @param array<mixed> $changes Optional. Fields to replace. Default none.
	 * @param bool         $tagged  Optional. Whether to tag it with this installation and mode first. Default true.
	 * @return array<mixed> The endpoint.
	 */
	private function endpoint( string $id, array $changes = array(), bool $tagged = true ): array {
		$endpoint                   = Fixtures::object( 'webhook-endpoint' );
		$endpoint['id']             = $id;
		$endpoint['url']            = self::URL;
		$endpoint['enabled_events'] = $this->gateway->webhookEvents();
		$endpoint['metadata']       = $tagged
			? array(
				'seocart_install' => self::INSTALL,
				'seocart_mode'    => 'test',
			)
			: array();

		return array_replace_recursive( $endpoint, $changes );
	}

	/**
	 * Builds the target core asks for.
	 *
	 * @since 0.2.0
	 *
	 * @param bool        $held       Whether SEOCart holds an endpoint's secret.
	 * @param string|null $endpointId Optional. That endpoint. Default none.
	 * @return WebhookTarget The target.
	 */
	private function target( bool $held, ?string $endpointId = null ): WebhookTarget {
		return new WebhookTarget( self::URL, Mode::Test, self::INSTALL, $this->gateway->webhookEvents(), $held, $endpointId );
	}

	/**
	 * Returns what a provisioning did, as a list.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookProvisioning $done The provisioning.
	 * @return list<mixed> Its outcome, endpoint, secret, removed ids and count elsewhere.
	 */
	private static function summary( WebhookProvisioning $done ): array {
		return array( $done->outcome, $done->endpointId, $done->signingSecret, $done->removed, $done->elsewhere );
	}
}
