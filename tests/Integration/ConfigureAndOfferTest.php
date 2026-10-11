<?php
/**
 * Tests that SEOCart offers the gateway once its test credentials are configured, and what configuring sends Stripe
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\Tests\Integration\Support\StripeAtTransport;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\WebhookAddress;
use SEOCart\Payment\Infrastructure\Cli\GatewayCommand;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Payment\GatewayCommandRecorder;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

require_once __DIR__ . '/Support/StripeAtTransport.php';

/**
 * Runs on SEOCart's integration bootstrap with this extension loaded, as WordPress loads it: SEOCart's own registry
 * registers the gateway through the main file's hook, and SEOCart's own `wp seocart gateway configure` writes the
 * settings from a file only its owner can read, with a fake test key, then sets up the webhook endpoint through the
 * gateway and SEOCart's real HTTP client; Stripe is answered at `pre_http_request`, so nothing reaches the network.
 *
 * Before configure, and with the endpoint skipped (no signing secret), SEOCart does not offer the gateway; after it,
 * it offers it in test mode in USD, GBP and EUR for a US account. A country Stripe accounts are not certified for is
 * refused by configure. A live set-up while Safe Mode is on never reaches Stripe. A capture of an authorized payment
 * goes through SEOCart's payment service to Stripe outside any transaction, with the test key and the derived key.
 *
 * Planted violation, shown red and removed: provisionWebhooks() returning an endpoint without creating one (no
 * signing secret saved): the gateway is never offered, and the offer test fails.
 *
 * @since 0.2.0
 */
final class ConfigureAndOfferTest extends PaymentTestCase {

	/**
	 * The fake test key the settings file gives: the shape of a test key, nothing more.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TEST_KEY = 'sk_test_integrationKey';

	/**
	 * The installation uuid the planted boot record carries.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INSTALL = '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b';

	/**
	 * The production wiring, with the test's data keys.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * Stripe.
	 *
	 * @since 0.2.0
	 *
	 * @var StripeAtTransport
	 */
	private StripeAtTransport $stripe;

	/**
	 * The settings files the test wrote.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $files = array();

	/**
	 * Plants the boot record, builds the kernel and answers Stripe.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		GatewayKernel::deleteOptions();

		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn(): BootRecord => KernelTestCase::installedRecord() );

		$this->kernel = GatewayKernel::over( $this->db, $this->reporter(), $this->publisherOver( $this->db ) );
		$this->stripe = new StripeAtTransport( fn(): int => $this->db->depth() );
	}

	/**
	 * Deletes the files, the boot record, the data keys and the gateway's document.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		$wpdb->delete( $wpdb->options, array( 'option_name' => BootOption::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removes the record the test planted.
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests the offer before and after configure, and what configure sent Stripe.
	 *
	 * @since 0.2.0
	 */
	public function test_the_gateway_is_offered_in_usd_gbp_and_eur_once_its_test_credentials_are_configured_and_not_before(): void {
		$gateways = $this->kernel->get( Gateways::class );

		$this->assertSame( array(), $gateways->refused(), 'SEOCart accepted the gateway.' );
		$this->assertContains( Gateway::ID, $gateways->ids() );

		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			$this->assertNull( $gateways->availableMode( Gateway::ID, Money::of( 1000, Currency::of( $code ) ), 'US', 'storefront' ), "Not offered in {$code} before configure." );
		}

		$command = new GatewayCommandRecorder( $this->kernel );

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ), implode( "\n", $command->lines ) );
		$this->assertSame( array( 'GET webhook_endpoints', 'POST webhook_endpoints' ), $this->stripe->sent() );

		foreach ( $this->stripe->requests as $request ) {
			$this->assertSame( 0, $request['depth'], 'Stripe is called outside any transaction.' );
			$this->assertSame( 'Bearer ' . self::TEST_KEY, $request['headers']['Authorization'] ?? null );
			$this->assertSame( Gateway::API_VERSION, $request['headers']['Stripe-Version'] ?? null );
		}

		$created = $this->stripe->requests[1]['parameters'];

		$this->assertSame( WebhookAddress::url( Gateway::ID, Mode::Test ), $created['url'] );
		$this->assertSame( ( new Gateway( $gateways->context( Gateway::ID ) ) )->webhookEvents(), $created['enabled_events'] );
		$this->assertSame(
			array(
				'seocart_install' => self::INSTALL,
				'seocart_mode'    => 'test',
			),
			$created['metadata']
		);
		$this->assertSame( $this->stripe->endpoints['we_1Fixture0001']['secret'], $gateways->context( Gateway::ID )->settings( Mode::Test )->secret( GatewayDescriptor::WEBHOOK_SECRET ), 'The signing secret saved is the one Stripe gave.' );

		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			$this->assertSame( Mode::Test, $gateways->availableMode( Gateway::ID, Money::of( 1000, Currency::of( $code ) ), 'US', 'storefront' ), "Offered in {$code} after configure." );
		}

		$this->assertNull( $gateways->availableMode( Gateway::ID, Money::of( 10, Currency::of( 'USD' ) ), 'US', 'storefront' ), 'Not for less than Stripe takes.' );
		$this->assertNull( $gateways->availableMode( Gateway::ID, Money::of( 1000, Currency::of( 'JPY' ) ), 'US', 'storefront' ), 'Not in a currency the matrix does not declare.' );
	}

	/**
	 * Tests settings saved with the endpoint skipped and no signing secret: not offered.
	 *
	 * @since 0.2.0
	 */
	public function test_without_a_signing_secret_the_gateway_is_not_offered(): void {
		$command = new GatewayCommandRecorder( $this->kernel );

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test, array( 'webhooks' => false ) ), implode( "\n", $command->lines ) );
		$this->assertSame( array(), $this->stripe->requests );
		$this->assertNull( $this->kernel->get( Gateways::class )->availableMode( Gateway::ID, Money::of( 1000, Currency::of( 'USD' ) ), 'US', 'storefront' ) );
	}

	/**
	 * Tests an account country the gateway is not certified for: refused by configure, nothing written, nothing sent.
	 *
	 * @since 0.2.0
	 */
	public function test_an_account_country_not_certified_is_refused(): void {
		$command = new GatewayCommandRecorder( $this->kernel );

		$this->assertSame( GatewayCommand::EXIT_FAILED, $this->configure( $command, Mode::Test, array(), array( GatewayDescriptor::ACCOUNT_COUNTRY => 'GB' ) ) );
		$this->assertSame( array(), $this->stripe->requests );
		$this->assertSame( array(), GatewayKernel::storedDocument( Gateway::ID ) );
	}

	/**
	 * Tests that a live set-up while Safe Mode is on never reaches Stripe: SEOCart refuses it first.
	 *
	 * @since 0.2.0
	 */
	public function test_a_live_set_up_under_safe_mode_never_calls_stripe(): void {
		$safe    = GatewayKernel::request( $this->kernel, $this->db, $this->reporter(), $this->publisherOver( $this->db ), array( SafeMode::class => static fn( Container $c ): SafeMode => new SafeMode( $c->get( BootOption::class ), $c->get( Clock::class ), true ) ) );
		$command = new GatewayCommandRecorder( $safe );

		$this->assertSame(
			GatewayCommand::EXIT_FAILED,
			$this->configure(
				$command,
				Mode::Live,
				array(),
				array(
					'secret_key'      => 'sk_live_integrationKey',
					'publishable_key' => 'pk_live_integrationKey',
				)
			)
		);
		$this->assertStringContainsString( 'safe_mode', $command->last() );
		$this->assertSame( array(), $this->stripe->requests, 'No live call.' );
	}

	/**
	 * Tests a capture through SEOCart's payment service: one request, outside any transaction, with the test key and the derived key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_reaches_stripe_outside_any_transaction_with_the_derived_key(): void {
		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( new GatewayCommandRecorder( $this->kernel ), Mode::Test ) );

		$payments = $this->kernel->get( PaymentService::class );
		$uuid     = GatewayKernel::openIntent( $this->kernel, Gateway::ID, Mode::Test );
		$row      = $this->intentRow( $uuid );
		$intent   = array(
			'id'                => 'pi_3Integration01',
			'object'            => 'payment_intent',
			'amount'            => (int) $row['amount_minor'],
			'amount_capturable' => (int) $row['amount_minor'],
			'amount_received'   => 0,
			'currency'          => strtolower( (string) $row['currency'] ),
			'latest_charge'     => 'ch_3Integration01',
			'livemode'          => false,
			'metadata'          => array(
				'intent_uuid'  => $uuid,
				'order_number' => self::ORDER_NUMBER,
			),
			'status'            => 'requires_capture',
		);
		$this->stripe->queue( 'POST', 'payment_intents', 200, (string) wp_json_encode( $intent ) );
		$this->stripe->queue(
			'POST',
			'payment_intents/pi_3Integration01/capture',
			200,
			(string) wp_json_encode(
				array(
					'status'          => 'succeeded',
					'amount_received' => $intent['amount'],
				) + $intent
			)
		);

		$authorized = $payments->authorize( $uuid, array( PaymentService::PAYMENT_TOKEN => 'pm_card_visa' ), self::ORDER_UUID, self::ORDER_NUMBER );
		$this->db->transaction( fn() => $payments->applyGatewayResult( $authorized, self::system() ) );
		$payments->capture( $uuid, $this->userWithRole( 'administrator' ) );

		$this->assertSame( array( Operation::Authorize, Outcome::Approved, 'ch_3Integration01' ), array( $authorized->operation, $authorized->outcome, $authorized->providerObjectId ) );
		$this->assertSame( 'POST payment_intents/pi_3Integration01/capture', $this->stripe->sent()[3] ?? null );
		$this->assertSame( 0, $this->stripe->requests[3]['depth'] );
		$this->assertSame( $uuid . ':capture', $this->stripe->requests[3]['headers']['Idempotency-Key'] ?? null );
		$this->assertSame( 'Bearer ' . self::TEST_KEY, $this->stripe->requests[3]['headers']['Authorization'] ?? null );
		$this->assertSame( 'captured', $this->intentRow( $uuid )['status'] ?? null, 'SEOCart applied the capture.' );
	}

	/**
	 * Runs configure for a mode as an administrator, from a file only its owner can read.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayCommandRecorder     $command The command.
	 * @param Mode                       $mode    The mode.
	 * @param array<string, string|bool> $options Optional. Further options. Default none.
	 * @param array<string, string>      $values  Optional. Settings to give besides, or instead of, the usual ones. Default none.
	 * @return int The exit code.
	 */
	private function configure( GatewayCommandRecorder $command, Mode $mode, array $options = array(), array $values = array() ): int {
		$file = (string) wp_tempnam( 'seocart-stripe' );

		$this->files[] = $file;

		file_put_contents(
			$file,
			(string) wp_json_encode(
				$values + array(
					'secret_key'                       => self::TEST_KEY,
					'publishable_key'                  => 'pk_test_integrationKey',
					GatewayDescriptor::ACCOUNT_COUNTRY => 'US',
				)
			)
		);
		chmod( $file, 0600 );

		return $command->run(
			array( 'configure', Gateway::ID ),
			$options + array(
				'mode'      => $mode->value,
				'from-file' => $file,
			),
			$this->userWithRole( 'administrator' )->userId()
		);
	}
}
