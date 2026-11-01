<?php
/**
 * LiveStripeCase: the plugin and the adapter run against Stripe's test mode, for the live tests
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration\Live;

use PHPUnit\Framework\AssertionFailedError;
use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Interfaces\Rest\WebhookRoute;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\Tests\Integration\StripeSuiteDeclarations;
use SEOCart\GatewayForStripe\Tests\Support\Live\CleanupSelection;
use SEOCart\GatewayForStripe\Tests\Support\Live\EventSelection;
use SEOCart\GatewayForStripe\Tests\Support\Live\ListenerThrows;
use SEOCart\GatewayForStripe\Tests\Support\Live\LiveRun;
use SEOCart\GatewayForStripe\Tests\Support\Live\MessageGuard;
use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\DeliveredValues;
use SEOCart\Payment\Application\WebhookAddress;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\KernelHooks;

// SEOCart's integration bootstrap autoloads this plugin's src/ alone: the suite's declarations, the test's Stripe client and the pure helpers are loaded by their path.
require_once dirname( __DIR__ ) . '/StripeSuiteDeclarations.php';
require_once __DIR__ . '/StripeTestApi.php';
require_once dirname( __DIR__, 2 ) . '/Support/Live/MessageGuard.php';
require_once dirname( __DIR__, 2 ) . '/Support/Live/ListenerThrows.php';
require_once dirname( __DIR__, 2 ) . '/Support/Live/LiveRun.php';
require_once dirname( __DIR__, 2 ) . '/Support/Live/EventSelection.php';
require_once dirname( __DIR__, 2 ) . '/Support/Live/CleanupSelection.php';

/**
 * Builds the plugin as SEOCart's conformance harness does, with every request of the gateway sent to Stripe's test mode, and gives the live tests what they share: placements, the bank's page in a browser, Stripe's events read back and delivered to the store's route, and the conditions every delivery must leave.
 *
 * A live test is a recording run whose tapes go to a fresh directory under the system's
 * temporary directory and are never kept: SEOCART_TAPE=record sends each request for real, and
 * SEOCART_TAPE_SETTINGS names the file of the test mode's credentials. Without them the test
 * fails, never skips, since a skipped live test would read green. bin/ci/stripe-test-mode.sh runs
 * the group: `live three-d-secure` or `live webhooks`.
 *
 * Every PaymentIntent a test creates is added to the run list, so the cleanup cancels whatever a
 * test left waiting, and what the run's summary shows goes to its report: PHPUnit allows no
 * output during a test. Every failure message is built through MessageGuard.
 *
 * The harness freezes the gateway's clock, and the adapter takes a delivery only within 300
 * seconds of it, so a delivery of a real event moves the clock to the delivery's arrival, as the
 * suite's webhook rule does.
 *
 * @since 0.2.0
 */
abstract class LiveStripeCase extends ConformanceHarness {

	use StripeSuiteDeclarations;

	/**
	 * How long the test waits for Stripe's events of its payments, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	protected const EVENTS_WAIT = 120;

	/**
	 * How long it waits between two reads of Stripe's Events list, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const EVENTS_POLL = 5;

	/**
	 * How far before a test's start its events are read from, in seconds: Stripe's `created` and this machine's clock may differ a little.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const EVENTS_MARGIN = 60;

	/**
	 * The script that completes Stripe's 3-D Secure page in a browser.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const BROWSER_SCRIPT = 'complete-3ds.mjs';

	/**
	 * What the browser script answers, besides an error's name.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const BROWSER_WORDS = array( 'succeeded', 'failed', 'no-control', 'timeout' );

	/**
	 * Stripe's published test payment method of a card whose charge is disputed once it is captured.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	protected const DISPUTED = 'pm_card_createDispute';

	/**
	 * The events of each kind of payment the test waits for: the PaymentIntent's, which every API version sends; a refund's or a dispute's event comes beside them when Stripe sends one.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<string>>
	 */
	protected const KIND_EVENTS = array(
		'captured' => array( 'payment_intent.amount_capturable_updated', 'payment_intent.succeeded' ),
		'disputed' => array( 'payment_intent.amount_capturable_updated', 'payment_intent.succeeded' ),
		'voided'   => array( 'payment_intent.amount_capturable_updated', 'payment_intent.canceled' ),
		'refunded' => array( 'payment_intent.amount_capturable_updated', 'payment_intent.succeeded' ),
		'declined' => array( 'payment_intent.payment_failed' ),
	);

	/**
	 * The ledger each kind of payment leaves, row by row: operation, result, and whether it moved money.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<string>>
	 */
	protected const KIND_LEDGER = array(
		'captured' => array( 'authorize:approved:1', 'capture:approved:1' ),
		'disputed' => array( 'authorize:approved:1', 'capture:approved:1' ),
		'voided'   => array( 'authorize:approved:1', 'void:approved:1' ),
		'refunded' => array( 'authorize:approved:1', 'capture:approved:1', 'refund:approved:1' ),
		'declined' => array( 'authorize:declined:1' ),
	);

	/**
	 * The directory of this run's tapes, made on first use.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private static ?string $tapes = null;

	/**
	 * The test's own requests to Stripe.
	 *
	 * @since 0.2.0
	 *
	 * @var StripeTestApi
	 */
	protected StripeTestApi $stripe;

	/**
	 * When the test started, by the machine's clock.
	 *
	 * @since 0.2.0
	 *
	 * @var \DateTimeImmutable
	 */
	protected \DateTimeImmutable $startedAt;

	/**
	 * Returns the id the plugin registers the gateway under.
	 *
	 * @since 0.2.0
	 *
	 * @return string The gateway's id.
	 */
	protected function gatewayId(): string {
		return Gateway::ID;
	}

	/**
	 * Returns this run's tape directory: a fresh one under the system's temporary directory, readable by its owner alone, never kept.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the directory cannot be made.
	 *
	 * @return string The directory.
	 */
	protected function tapes(): string {
		if ( null === self::$tapes ) {
			$directory = sys_get_temp_dir() . '/stripe-live-' . bin2hex( random_bytes( 8 ) );

			if ( ! mkdir( $directory, 0700 ) ) {
				throw new \RuntimeException( 'The live tests could not make their tape directory.' );
			}

			self::$tapes = $directory;
		}

		return self::$tapes;
	}

	/**
	 * Refuses to run without the recording's settings, then builds the plugin with every request of the gateway sent to Stripe's test mode.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		if ( RecordingTransport::RECORD !== getenv( RecordingTransport::ENVIRONMENT ) ) {
			self::fail( sprintf( 'The live tests send every request to Stripe\'s test mode: run them with %1$s=%2$s and the test mode\'s credentials in a file %3$s names, as bin/ci/stripe-test-mode.sh does.', RecordingTransport::ENVIRONMENT, RecordingTransport::RECORD, ConformanceHarness::SETTINGS_FILE ) );
		}

		$settings = $this->recordingSettings();

		LiveRun::file( LiveRun::RUN_LIST );
		LiveRun::file( LiveRun::REPORT );

		$this->stripe    = new StripeTestApi( (string) ( $settings[ Gateway::SECRET_KEY ] ?? '' ) );
		$this->startedAt = new \DateTimeImmutable( '@' . ( time() - self::EVENTS_MARGIN ) );

		self::keepRegistrationThrows();

		parent::set_up();
	}

	/**
	 * Names, beside a failed test's own message, what a listener of SEOCart's registration action threw, when one did: SEOCart's registry reports its class alone.
	 *
	 * @since 0.2.0
	 *
	 * @throws \Throwable The test's failure, its message completed when a listener threw.
	 *
	 * @param \Throwable $t The test's failure.
	 */
	protected function onNotSuccessfulTest( \Throwable $t ): void {
		$thrown = ListenerThrows::taken();

		if ( null !== $thrown ) {
			$message = $t->getMessage() . ' ' . MessageGuard::say( 'A listener of the registration action threw %s', $thrown );

			throw $t instanceof AssertionFailedError ? new AssertionFailedError( $message, 0, $t ) : new \LogicException( $message, 0, $t );
		}

		parent::onNotSuccessfulTest( $t );
	}

	/**
	 * Wraps the listeners of SEOCart's registration action, the plugin's own among them, so a failing test can name what one threw, and forgets what an earlier test kept.
	 *
	 * Done before SEOCart's harness builds a registry. WordPress's copy of the hooks, which each
	 * test's end puts back, then holds the wrapped listeners, and a listener wrapped already is left
	 * as it is.
	 *
	 * @since 0.2.0
	 */
	private static function keepRegistrationThrows(): void {
		global $wp_filter;

		$hook = $wp_filter[ GatewayRegistry::ACTION ] ?? null;

		if ( $hook instanceof \WP_Hook ) {
			$hook->callbacks = ListenerThrows::wrap( $hook->callbacks );
		}

		ListenerThrows::taken();
	}

	/**
	 * Places an order of one unit, paid through Stripe with a test payment method, as a shopper places it.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $token A Stripe test payment method, such as `pm_card_visa`.
	 * @param string|null $code  A promotion code to plant and apply first; null for none.
	 * @param int         $units How many units.
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, intent_id: string|null, outcome: string, next_action: array<string, mixed>|null, variant: int, cart_id: int} The placement.
	 *
	 * @throws CodedException When the placement is refused for another reason than a decline.
	 */
	protected function placeLive( string $token, ?string $code = null, int $units = 1 ): array {
		$variant = $this->sellable( 10, self::PRICE );
		$cart    = $this->startCart( array( $variant => $units ) );

		if ( null !== $code ) {
			$this->plantPromotion( $code );
			$cart = $this->service->applyPromotionCode( $code, $cart->version, self::guest() );
		}

		// The checkout is written once, through the plugin's own registry, where the gateway registers with its context: a registry of the stub alone (readyCart()'s) gives none.
		$this->writeCheckout( $cart->version, Gateway::ID );

		$lastIntent = (int) $this->db->fetchValue( 'SELECT COALESCE(MAX(id), 0) FROM %i', $this->table( PaymentTables::INTENTS ) );

		try {
			$answer = $this->freshPlacement()->place( $this->placeInput( 'live-' . $variant, $token ), self::guest() );
		} catch ( CodedException $declined ) {
			// A declined payment is answered as a refusal that names its order.
			if ( CheckoutError::PaymentDeclined !== $declined->errorCode() ) {
				throw $declined;
			}

			$answer = array(
				'order_uuid'  => (string) ( $declined->details()['order_uuid'] ?? '' ),
				'outcome'     => 'declined',
				'next_action' => null,
			);
		} finally {
			// Whatever became of the placement, even a failure thrown, Stripe may hold its intent.
			$this->listIntentsAfter( $lastIntent );
		}

		$orderId = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), (string) $answer['order_uuid'] );
		$uuid    = (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 1', $this->table( PaymentTables::INTENTS ), $orderId );
		$id      = $this->intentRow( $uuid )['provider_intent_id'];

		return array(
			'order_uuid'  => (string) $answer['order_uuid'],
			'order_id'    => $orderId,
			'intent_uuid' => $uuid,
			'intent_id'   => $id,
			'outcome'     => (string) $answer['outcome'],
			'next_action' => is_array( $answer['next_action'] ?? null ) ? $answer['next_action'] : null,
			'variant'     => $variant,
			'cart_id'     => $cart->id,
		);
	}

	/**
	 * Adds to the run list every intent the store recorded after a row that has Stripe's id: those a placement made, so the cleanup cancels any it left waiting.
	 *
	 * @since 0.2.0
	 *
	 * @param int $lastIntent The id of the last intent row before the placement; 0 for none.
	 */
	private function listIntentsAfter( int $lastIntent ): void {
		$rows = $this->db->fetchAll( 'SELECT uuid, provider_intent_id FROM %i WHERE id > %d AND provider_intent_id IS NOT NULL ORDER BY id', $this->table( PaymentTables::INTENTS ), $lastIntent );

		foreach ( $rows as $row ) {
			LiveRun::append( LiveRun::RUN_LIST, CleanupSelection::line( (string) $row['provider_intent_id'], (string) $row['uuid'] ) );
		}
	}

	/**
	 * Places a payment with Stripe's card that always asks for 3-D Secure, and checks that it waits for the shopper.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $code A promotion code to plant and apply first; null for none.
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, intent_id: string|null, outcome: string, next_action: array<string, mixed>|null, variant: int, cart_id: int} The placement.
	 */
	protected function placeThreeDSecure( ?string $code = null ): array {
		$placed = $this->placeLive( self::REQUIRES_ACTION, $code );

		$this->assertSame( 'requires_action', $placed['outcome'], MessageGuard::say( 'The 3-D Secure payment answered %s, not a request to act.', $placed['outcome'] ) );
		$this->assertNotNull( $placed['intent_id'], 'The waiting payment has no PaymentIntent at Stripe.' );

		return $placed;
	}

	/**
	 * Places a payment of a kind, of two units, and makes the store's own calls the kind needs: a person's capture, void or refund.
	 *
	 * @since 0.2.0
	 *
	 * @param string $kind   captured, disputed, voided, refunded or declined.
	 * @param bool   $partly For a refunded payment, whether one unit of the two is refunded rather than all.
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, intent_id: string|null, outcome: string, next_action: array<string, mixed>|null, variant: int, cart_id: int, kind: string} The placement, with its kind.
	 */
	protected function placeKind( string $kind, bool $partly = false ): array {
		$token = match ( $kind ) {
			'declined' => self::DECLINED,
			'disputed' => self::DISPUTED,
			default    => self::APPROVED,
		};
		$placed = $this->placeLive( $token, null, 2 ) + array( 'kind' => $kind );

		$this->assertSame( 'declined' === $kind ? 'declined' : 'approved', $placed['outcome'], MessageGuard::say( 'A %1$s payment answered %2$s.', $kind, $placed['outcome'] ) );
		$this->assertNotNull( $placed['intent_id'], MessageGuard::say( 'A %s payment has no PaymentIntent at Stripe.', $kind ) );

		if ( in_array( $kind, array( 'captured', 'disputed', 'refunded' ), true ) ) {
			$this->capture( $placed['intent_uuid'] );
		}

		if ( 'voided' === $kind ) {
			$this->void( $placed['intent_uuid'] );
		}

		if ( 'refunded' === $kind ) {
			$this->refund( $placed['order_uuid'], $partly ? 1 : 2, false );
		}

		return $placed;
	}

	/**
	 * Reads an order's ledger as `operation:result:applied`, row by row.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> The rows.
	 */
	protected function ledgerOf( int $orderId ): array {
		return array_map( static fn( array $row ): string => $row[0] . ':' . $row[1] . ':' . $row[2], $this->ledgerShape( $orderId ) );
	}

	/**
	 * Returns a PaymentIntent as Stripe answers it now.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentId Stripe's id.
	 * @return array<mixed> The PaymentIntent.
	 */
	protected function stripeIntent( string $intentId ): array {
		return $this->stripe->get( 'payment_intents/' . $intentId );
	}

	/**
	 * Completes Stripe's 3-D Secure page in a browser, retried once when the page showed no control or did not finish in time.
	 *
	 * The script is handed the page's address, the store's return origin and the control to press
	 * as one JSON line on its standard input, never as an argument a process list shows; it answers
	 * one word, and nothing it or the browser printed otherwise is kept.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>|null $nextAction What the store asked the shopper to do.
	 * @param string                    $control    `authorize` or `fail`.
	 * @return string What the script answered: one of BROWSER_WORDS, or an error's name.
	 */
	protected function completeInBrowser( ?array $nextAction, string $control ): string {
		$url = is_array( $nextAction ) && is_string( $nextAction['url'] ?? null ) ? $nextAction['url'] : '';

		$this->assertStringStartsWith( 'https://', $url, 'The placement asked for no redirect to the bank\'s page.' );

		$home  = (array) wp_parse_url( home_url( '/' ) );
		$input = (string) wp_json_encode(
			array(
				'url'          => $url,
				'returnOrigin' => sprintf( '%s://%s%s', $home['scheme'] ?? 'http', $home['host'] ?? '', isset( $home['port'] ) ? ':' . $home['port'] : '' ),
				'control'      => $control,
				'browser'      => (string) getenv( LiveRun::BROWSER ),
			)
		);

		$word = $this->runBrowser( $input );

		return in_array( $word, array( 'no-control', 'timeout' ), true ) ? $this->runBrowser( $input ) : $word;
	}

	/**
	 * Runs the browser script once.
	 *
	 * @since 0.2.0
	 *
	 * @param string $input The JSON line it reads.
	 * @return string Its one word; `unreadable` when it printed anything else.
	 */
	private function runBrowser( string $input ): string {
		$script  = dirname( __DIR__, 2 ) . '/browser/' . self::BROWSER_SCRIPT;
		$process = proc_open( array( 'node', $script ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'file', '/dev/null', 'w' ) ), $pipes, dirname( $script ) );

		$this->assertIsResource( $process, 'The browser script could not be started.' );

		fwrite( $pipes[0], $input . "\n" );
		fclose( $pipes[0] );

		$printed = (string) stream_get_contents( $pipes[1] );

		fclose( $pipes[1] );
		proc_close( $process );

		$word = trim( $printed );

		return 1 === preg_match( '/^[A-Za-z][A-Za-z\-]{0,39}$/D', $word ) ? $word : 'unreadable';
	}

	/**
	 * Reads Stripe's events of the run's intents from the test's start, waiting until each intent has the events expected of it.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string>       $intents  The intents: each store uuid, by Stripe's id.
	 * @param array<string, list<string>> $expected The event types each intent must have, by Stripe's id.
	 * @return list<array<mixed>> The run's events, oldest first.
	 */
	protected function eventsOf( array $intents, array $expected ): array {
		$deadline = time() + self::EVENTS_WAIT;

		while ( true ) {
			$all     = $this->stripe->all(
				'events',
				array(
					'created' => array( 'gte' => $this->startedAt->getTimestamp() ),
					'types'   => $this->gateways()->webhookEvents( Gateway::ID ),
				)
			);
			$ours    = EventSelection::ours( array_reverse( $all ), $intents );
			$missing = self::missingEvents( $ours, $expected );

			if ( array() === $missing ) {
				return $ours;
			}

			if ( time() >= $deadline ) {
				self::fail( MessageGuard::say( 'Within %1$d seconds Stripe listed no %2$s.', self::EVENTS_WAIT, implode( '; ', $missing ) ) );
			}

			sleep( self::EVENTS_POLL );
		}
	}

	/**
	 * Names the events still missing.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>>          $events   The events read.
	 * @param array<string, list<string>> $expected The types each intent must have, by Stripe's id.
	 * @return list<string> Each type missing, with its intent.
	 */
	private static function missingEvents( array $events, array $expected ): array {
		$byIntent = EventSelection::byIntent( $events );
		$missing  = array();

		foreach ( $expected as $intent => $types ) {
			$seen = array_map( static fn( array $event ): string => (string) ( $event['type'] ?? '' ), $byIntent[ $intent ] ?? array() );

			foreach ( array_diff( $types, $seen ) as $type ) {
				$missing[] = $type . ' of ' . $intent;
			}
		}

		return $missing;
	}

	/**
	 * Tells whether an event was written at the API version the adapter pins, which Stripe's account default may not be.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $event The event.
	 * @return bool True when it is the pinned version.
	 */
	protected static function atThePin( array $event ): bool {
		return Gateway::API_VERSION === ( $event['api_version'] ?? null );
	}

	/**
	 * Delivers a real event through the store's webhook route, as WordPress dispatches it, signed as Stripe signs it.
	 *
	 * The route is built as the kernel builds it, with its clock at the arrival, and the gateway's
	 * clock is moved there too: the adapter verifies a signature within 300 seconds of its clock.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed>       $event     The event, as Stripe's Events list gave it.
	 * @param \DateTimeImmutable $arrives   When it arrives.
	 * @param string             $secret    The secret it is signed with.
	 * @param int                $signedAgo How long before its arrival it was signed, in seconds.
	 * @return array{status: int, code: string|null} The route's status, and the code of a refusal.
	 */
	protected function deliver( array $event, \DateTimeImmutable $arrives, #[\SensitiveParameter] string $secret, int $signedAgo = 0 ): array {
		$body    = (string) wp_json_encode( $event );
		$headers = $this->signDelivery( array( 'content-type' => 'application/json; charset=utf-8' ), $body, $secret, $arrives->modify( sprintf( '-%d seconds', $signedAgo ) ) );
		$route   = new WebhookRoute( fn(): ReceiveWebhook => $this->kernel->get( ReceiveWebhook::class ), $this->kernel->get( ErrorTranslator::class ), FrozenClock::at( $arrives->format( \DateTimeInterface::ATOM ) ), $this->kernel->get( Reporter::class ) );
		$request = new \WP_REST_Request( 'POST', '/' . WebhookAddress::NAMESPACE . WebhookAddress::path( Gateway::ID, Mode::Test ) );
		$server  = $_SERVER;

		$request->set_body( $body );

		foreach ( $headers as $header => $value ) {
			$request->set_header( (string) $header, (string) $value );
		}

		$this->gatewayClock->setTo( $arrives );
		KernelHooks::detach( 'rest_api_init' );
		add_action( 'rest_api_init', array( $route, 'register' ) );
		self::discardRestServer();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		try {
			$response = rest_do_request( $request );
		} finally {
			remove_action( 'rest_api_init', array( $route, 'register' ) );
			self::discardRestServer();
			$_SERVER = $server; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the request globals put back as they were.
		}

		$data = (array) $response->get_data();

		return array(
			'status' => $response->get_status(),
			'code'   => isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : null,
		);
	}

	/**
	 * Returns the signing secret of the run: the one the settings file holds, which the gateway was configured with.
	 *
	 * @since 0.2.0
	 *
	 * @return string The secret.
	 */
	protected function runSecret(): string {
		return (string) ( $this->recordingSettings()['webhook_secret'] ?? '' );
	}

	/**
	 * Asserts what every delivery of real events must leave, whatever the version Stripe wrote them at: one decided receipt per event delivered genuinely, no second money row of an order for one operation, no order flagged for a person, a ledger the store's doctor finds sound, and no stock hold left.
	 *
	 * @since 0.2.0
	 *
	 * @param int[]    $orderIds The run's orders.
	 * @param string[] $eventIds The events delivered genuinely.
	 * @phpstan-param list<int> $orderIds
	 * @phpstan-param list<string> $eventIds
	 */
	protected function assertSafe( array $orderIds, array $eventIds ): void {
		$receipts = array();

		foreach ( $eventIds as $eventId ) {
			$receipts[ $eventId ] = $this->db->fetchAll( 'SELECT result FROM %i WHERE provider = %s AND mode = %s AND event_id = %s', $this->table( WebhookReceiptTables::RECEIPTS ), Gateway::ID, Mode::Test->value, DeliveredValues::eventId( $eventId ) );
		}

		foreach ( $receipts as $eventId => $rows ) {
			$this->assertSame( 1, count( $rows ), MessageGuard::say( 'The event %1$s has %2$d receipts, not one.', $eventId, count( $rows ) ) );
			$this->assertNotNull( $rows[0]['result'] ?? null, MessageGuard::say( 'The receipt of the event %s is undecided.', $eventId ) );
		}

		$ids = implode( ',', array_map( 'intval', $orderIds ) );

		if ( '' !== $ids ) {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the order ids are integers, joined above.
			$doubled = $this->db->fetchAll( 'SELECT order_id, operation, COUNT(*) AS c FROM %i WHERE order_id IN (' . $ids . ') GROUP BY order_id, operation HAVING COUNT(*) > 1', $this->table( PaymentTables::TRANSACTIONS ) );
			$flagged = $this->db->fetchAll( 'SELECT id FROM %i WHERE id IN (' . $ids . ') AND has_unreconciled_money = 1', $this->table( OrderTables::ORDERS ) );
			$held    = (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE order_id IN (' . $ids . ')', $this->table( InventoryTables::HOLDS ) );
			// phpcs:enable

			$this->assertSame( array(), $doubled, MessageGuard::say( 'An order has two money rows of one operation: %s.', implode( ', ', array_map( static fn( array $row ): string => $row['order_id'] . ' ' . $row['operation'], $doubled ) ) ) );
			$this->assertSame( array(), $flagged, MessageGuard::say( 'Orders were flagged for a person: %s.', implode( ', ', array_column( $flagged, 'id' ) ) ) );
			$this->assertSame( 0, $held, 'A stock hold of the run\'s orders is left.' );
		}

		$critical = array_values( (array) preg_grep( '/^Critical/', $this->kernel->get( PaymentLedgerCheck::class )->run()->findings ) );

		$this->assertSame( array(), $critical, 'The store\'s doctor found drift between the ledger and what it projects.' );
	}

	/**
	 * Counts what a delivery was answered, for the report: the route's status and the code of a refusal.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, int>                    $tally   The tally so far.
	 * @param array{status: int, code: string|null} $answer  The route's answer.
	 * @param array<mixed>                          $event   The event.
	 * @return array<string, int> The tally.
	 */
	protected static function tallied( array $tally, array $answer, array $event ): array {
		$key           = sprintf( '%s@%s:%d%s', (string) ( $event['type'] ?? 'none' ), (string) ( $event['api_version'] ?? 'none' ), $answer['status'], null === $answer['code'] ? '' : ' ' . $answer['code'] );
		$tally[ $key ] = ( $tally[ $key ] ?? 0 ) + 1;

		ksort( $tally );

		return $tally;
	}

	/**
	 * Returns the event types to wait for, by Stripe's id of each placement.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{intent_id: string|null, kind: string}> $placed The placements.
	 * @return array<string, list<string>> The types, by Stripe's id.
	 */
	protected static function expectedEvents( array $placed ): array {
		$expected = array();

		foreach ( $placed as $placement ) {
			$expected[ (string) $placement['intent_id'] ] = self::KIND_EVENTS[ $placement['kind'] ];
		}

		return $expected;
	}

	/**
	 * Returns the run's intents, each store uuid by Stripe's id.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{intent_id: string|null, intent_uuid: string}> $placed The placements.
	 * @return array<string, string> The uuids, by Stripe's id.
	 */
	protected static function intentsOf( array $placed ): array {
		$intents = array();

		foreach ( $placed as $placement ) {
			$intents[ (string) $placement['intent_id'] ] = $placement['intent_uuid'];
		}

		return $intents;
	}

	/**
	 * Asserts that each placement's ledger is the one its kind leaves: the conditions of events written at the API version the adapter pins.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{order_id: int, kind: string}> $placed The placements.
	 */
	protected function assertLedgersAsTheirKinds( array $placed ): void {
		foreach ( $placed as $placement ) {
			$this->assertSame( self::KIND_LEDGER[ $placement['kind'] ], $this->ledgerOf( $placement['order_id'] ), MessageGuard::say( 'The ledger of a %1$s payment, order %2$d.', $placement['kind'], $placement['order_id'] ) );
		}
	}

	/**
	 * Writes a tally to the run's report, on one line.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $key   The fact's name.
	 * @param array<string, int> $tally The tally.
	 */
	protected static function reportTally( string $key, array $tally ): void {
		LiveRun::report( $key, implode( ', ', array_map( static fn( string $name, int $count ): string => $name . ' x' . $count, array_keys( $tally ), $tally ) ) );
	}

	/**
	 * Discards the REST server, so the next dispatch builds one with the routes registered afresh.
	 *
	 * @since 0.2.0
	 */
	private static function discardRestServer(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- resets core's REST server global: the next dispatch builds a new server and fires rest_api_init again.
		$wp_rest_server = null;
	}
}
