<?php
/**
 * Tests, against Stripe's test mode, the set-up of the store's webhook endpoint
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration\Live;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookProvisioning;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\Tests\Support\Live\CleanupSelection;
use SEOCart\GatewayForStripe\Tests\Support\Live\LiveRun;
use SEOCart\GatewayForStripe\Tests\Support\Live\MessageGuard;
use SEOCart\GatewayForStripe\WebhookEndpoints;
use SEOCart\Payment\Application\GatewayConfiguration;
use SEOCart\Payment\Application\GatewayConfigured;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\GatewaySettingsDeclaration;
use SEOCart\Payment\Application\WebhookAddress;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\Payment\GatewayKernel;

require_once __DIR__ . '/LiveStripeCase.php';

/**
 * Saving the gateway's settings with the webhook step sets up the store's endpoint at Stripe through SEOCart's own caller, as `wp seocart gateway configure` does: created, tagged as this installation's, subscribed to the events the adapter reads, at the API version it pins, its signing secret sealed in the gateway's settings; saved again, it is reused.
 *
 * Every run sets up the endpoint as one installation, whose uuid is made from the repository's
 * address, so an endpoint a crashed run left is this installation's: provisioning replaces it, and
 * the cleanup deletes it. The test deletes the endpoint it made, and then no endpoint of the
 * installation is left. Stripe may refuse the test site's address, which is `http` on
 * `example.org`; the test then says so in the run's report and sets up an `https` address on
 * `example.com` instead.
 *
 * @since 0.2.0
 *
 * @group stripe-live
 */
final class EndpointProvisioningTest extends LiveStripeCase {

	/**
	 * The address the endpoint is set up at when Stripe refuses the test site's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const FALLBACK_ORIGIN = 'https://example.com';

	/**
	 * Tests that the endpoint is created as this installation's, reused when saved again, and removed.
	 *
	 * @since 0.2.0
	 */
	public function test_the_endpoint_is_created_tagged_reused_and_removed(): void {
		$this->begin( 'live-webhook-endpoint' );

		$first = $this->configureWithWebhooks();

		if ( GatewayConfigured::FAILED === $first->webhooks ) {
			LiveRun::report( 'endpoint-address', 'refused for the test site; set up on ' . self::FALLBACK_ORIGIN );
			add_filter( 'rest_url', array( self::class, 'onFallbackOrigin' ) );
			$first = $this->configureWithWebhooks();
		}

		$this->assertContains( $first->webhooks, array( WebhookProvisioning::CREATED, WebhookProvisioning::REPLACED ), MessageGuard::say( 'Setting up the endpoint ended %s.', $first->webhooks ) );
		$this->assertNotNull( $first->endpointId );

		$second = $this->configureWithWebhooks();
		$this->end();

		$this->assertSame( array( WebhookProvisioning::REUSED, $first->endpointId ), array( $second->webhooks, $second->endpointId ), 'Saved again, the endpoint was not reused.' );

		$endpoint = $this->stripe->get( 'webhook_endpoints/' . $first->endpointId );
		$events   = (array) ( $endpoint['enabled_events'] ?? array() );
		$expected = $this->gateways()->webhookEvents( Gateway::ID );

		sort( $events );
		sort( $expected );

		$this->assertSame( array( LiveRun::installUuid(), Mode::Test->value ), array( $endpoint['metadata'][ WebhookEndpoints::TAG_INSTALL ] ?? null, $endpoint['metadata'][ WebhookEndpoints::TAG_MODE ] ?? null ), 'The endpoint is not tagged as this installation\'s test mode.' );
		$this->assertSame( $expected, $events, 'The endpoint is not subscribed to exactly the events the adapter reads.' );
		$this->assertSame( Gateway::API_VERSION, $endpoint['api_version'] ?? null, 'The endpoint is not at the API version the adapter pins.' );
		$this->assertSame( WebhookAddress::url( Gateway::ID, Mode::Test ), $endpoint['url'] ?? null, 'The endpoint is not at the store\'s webhook address.' );
		$this->assertSealed();

		$this->stripe->delete( 'webhook_endpoints/' . $first->endpointId );

		$this->assertSame( array(), CleanupSelection::endpointsToDelete( $this->stripe->all( 'webhook_endpoints' ), LiveRun::installUuid(), WebhookEndpoints::TAG_INSTALL ), 'An endpoint of this installation is left at Stripe.' );
	}

	/**
	 * Moves the store's REST address to the fallback origin.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url The address WordPress built.
	 * @return string The same path on the fallback origin.
	 */
	public static function onFallbackOrigin( string $url ): string {
		$parts = (array) wp_parse_url( $url );

		return self::FALLBACK_ORIGIN . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * Saves the gateway's test credentials with the webhook step, through SEOCart's own caller, as this run's installation.
	 *
	 * The caller is built as the kernel builds it, but for the installation's uuid, which is the
	 * run's: every run is the same installation at Stripe.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayConfigured What was saved, and what became of the endpoint.
	 */
	private function configureWithWebhooks(): GatewayConfigured {
		$values = $this->recordingSettings();

		unset( $values[ GatewayDescriptor::WEBHOOK_SECRET ] );

		$configuration = new GatewayConfiguration(
			$this->kernel->get( Gateways::class ),
			$this->kernel->get( SettingsStore::class ),
			$this->kernel->get( SecretVault::class ),
			$this->kernel->get( TransactionManager::class ),
			$this->kernel->get( Authorizer::class ),
			static fn(): string => LiveRun::installUuid(),
			$this->kernel->get( Reporter::class )
		);

		return $configuration->configure( Gateway::ID, Mode::Test, $values, true, $this->userGranted( SettingsService::SECRETS_CAPABILITY ) );
	}

	/**
	 * Asserts that the endpoint's signing secret is kept sealed in the gateway's settings, and opens to a signing secret.
	 *
	 * @since 0.2.0
	 */
	private function assertSealed(): void {
		$name   = GatewaySettingsDeclaration::storedName( Gateway::ID, Mode::Test, GatewayDescriptor::WEBHOOK_SECRET );
		$stored = (string) ( GatewayKernel::storedDocument( Gateway::ID )['values'][ $name ] ?? '' );
		$opened = (string) $this->kernel->get( SecretVault::class )->reveal( $name );

		$this->assertNotSame( '', $stored, 'The endpoint\'s signing secret is not kept.' );
		$this->assertFalse( str_contains( $stored, 'whsec_' ), 'The endpoint\'s signing secret is kept in the clear.' );
		$this->assertTrue( str_starts_with( $opened, 'whsec_' ), 'The kept secret does not open to a signing secret.' );
	}
}
