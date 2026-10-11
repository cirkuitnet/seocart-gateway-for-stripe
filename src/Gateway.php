<?php
/**
 * Gateway: the Stripe payment gateway
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\ProvisionsWebhooks;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookProvisioning;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookTarget;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a failed call to SEOCart's payment service; they are never HTML.

/**
 * The Stripe payment gateway: card payments through Stripe's PaymentIntents, authorized first and captured later.
 *
 * Owns one fact: what the gateway is and does, as SEOCart asks it. describe() is its one
 * declaration: two modes, the four settings each mode keeps, the capability matrix of the account
 * countries whose Stripe accounts are certified (one row per country and currency), the
 * idempotency profile and the one host it calls. Every operation is a few lines: the parameters
 * Stripe takes, one request through StripeApi with the key the request object derived, and the
 * answer mapped by Results. Webhook deliveries are read by WebhookReader, the webhook endpoint is
 * set up by WebhookEndpoints.
 *
 * The gateway keeps nothing between calls: each request opens the secret key of the mode the
 * payment was created in, and every answer is mapped and let go. Its log lines name the operation,
 * the payment's uuid and the HTTP status, and repeat what Stripe answered only as StripeCodes
 * allows: its documented codes, and its ids in the shape of an id; never a body, a header, a
 * message or a parameter name. It moves money only in the currencies it declares.
 *
 * @since 0.1.0
 * @since 0.2.0 Became the Stripe adapter: it declares what Stripe can do and does it.
 */
final class Gateway implements PaymentGateway, ProvisionsWebhooks {

	/**
	 * The gateway's id, which an intent and the ledger record, and which the registration names.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = 'stripe';

	/**
	 * The version of SEOCart's payment contract this gateway is written against.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONTRACT = '0.2.0';

	/**
	 * The version of Stripe's API every request pins, and every webhook endpoint the gateway sets up is created with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const API_VERSION = '2026-09-30.endive';

	/**
	 * The id of the one host the gateway calls, as its request names it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const HOST_ID = 'stripe-api';

	/**
	 * The name of the setting that holds a mode's secret (or restricted) API key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SECRET_KEY = 'secret_key';

	/**
	 * The name of the setting that holds a mode's publishable key, which Stripe's script in the shopper's browser uses.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PUBLISHABLE_KEY = 'publishable_key';

	/**
	 * The countries of the Stripe accounts the gateway is certified for: the capability matrix's rows, and the values the account country setting allows.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ACCOUNT_COUNTRIES = array( 'US' );

	/**
	 * The currencies the gateway takes, for an account of each certified country.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const CURRENCIES = array( 'USD', 'GBP', 'EUR' );

	/**
	 * What the gateway does in each cell of its matrix.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const OPERATIONS = array(
		Operations::AUTHORIZE,
		Operations::CAPTURE,
		Operations::PARTIAL_CAPTURE,
		Operations::VOID,
		Operations::REFUND,
		Operations::PARTIAL_REFUND,
		Operations::SCA,
		Operations::QUERY,
		Operations::QUERY_REFUND,
		Operations::WEBHOOKS,
	);

	/**
	 * How long Stripe keeps an idempotency key, in seconds: keys may be pruned once they are 24 hours old.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const KEY_RETENTION_SECONDS = 86400;

	/**
	 * How long a PaymentIntent may take to become findable by a search, in seconds.
	 *
	 * Stripe: searchable "in less than a minute" normally, but "up to an hour behind during
	 * outages", which is when an answer is most likely lost. A not-found is believed only after it.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const SEARCH_DELAY_SECONDS = 3600;

	/**
	 * The smallest amount the gateway takes, in minor units, by account country and currency.
	 *
	 * Stripe's minimum applies after conversion into the account's settlement currency, so for a
	 * US account a charge in GBP or EUR must clear USD 0.50 once converted: 0.50 in each clears it
	 * at any plausible rate.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, int>>
	 */
	public const MINIMUM_MINOR = array(
		'US' => array(
			'USD' => 50,
			'GBP' => 50,
			'EUR' => 50,
		),
	);

	/**
	 * The largest amount the gateway takes, in minor units: the eight digits a PaymentIntent's amount holds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAXIMUM_MINOR = 99999999;

	/**
	 * Stripe's cancellation reason for each of SEOCart's void reasons; a reason not listed is sent as none.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const CANCELLATION_REASONS = array(
		'action_window_ended' => 'abandoned',
		'customer_request'    => 'requested_by_customer',
		'duplicate_order'     => 'duplicate',
		'fraud'               => 'fraudulent',
	);

	/**
	 * The decline code of an authorization asked with a token that is not a Stripe PaymentMethod's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INVALID_TOKEN = 'invalid_payment_token';

	/**
	 * The shape of the token an authorization may send: the id of a PaymentMethod Stripe's script created.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TOKEN = '/^pm_[A-Za-z0-9_]{1,200}\z/';

	/**
	 * The shape of an intent's uuid, the only value a search is built from.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

	/**
	 * The most pages of a PaymentIntent's refunds read when looking for one: a page holds 100.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const REFUND_PAGES = 10;

	/**
	 * What SEOCart gives this gateway: its logger, clock, HTTP client and settings.
	 *
	 * @since 0.1.0
	 *
	 * @var ExtensionContext
	 */
	private ExtensionContext $context;

	/**
	 * The requests to Stripe, built on first use.
	 *
	 * @since 0.2.0
	 *
	 * @var StripeApi|null
	 */
	private ?StripeApi $api = null;

	/**
	 * Builds the gateway with its context. Reads nothing and sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ExtensionContext $context What SEOCart gives this gateway: `SEOCart\Contracts\Payment\GatewayRegistry::context()`.
	 */
	public function __construct( ExtensionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Returns the gateway's one declaration, built from constants: no read, no request, no WordPress call.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Declares the modes, the settings, the matrix, the idempotency profile and the host.
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	public function describe(): GatewayDescriptor {
		return new GatewayDescriptor(
			self::ID,
			static fn(): string => __( 'Stripe', 'seocart-gateway-for-stripe' ),
			GatewayDescriptor::TYPE_PAYMENTS,
			self::CONTRACT,
			array( Mode::Test, Mode::Live ),
			self::settings(),
			self::matrix(),
			new IdempotencyProfile( self::KEY_RETENTION_SECONDS, true, self::SEARCH_DELAY_SECONDS ),
			array( self::host() )
		);
	}

	/**
	 * Tells whether the gateway can take a payment: the mode's publishable key is a key of that mode, and the amount is one Stripe takes.
	 *
	 * SEOCart asks only once the mode is configured and the matrix allows the currency for the
	 * account's country; this only narrows that. Pure: it reads the context and nothing else.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Narrows by the publishable key's mode and Stripe's smallest and largest amounts.
	 *
	 * @param AvailabilityContext $context The payment.
	 * @return bool True when the gateway can take it.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		$publishable = $context->account[ self::PUBLISHABLE_KEY ] ?? null;
		$country     = $context->account[ GatewayDescriptor::ACCOUNT_COUNTRY ] ?? null;
		$minimum     = is_string( $country ) ? ( self::MINIMUM_MINOR[ $country ][ $context->currency->code() ] ?? null ) : null;
		$minor       = $context->amount->minorUnits();

		return is_string( $publishable )
			&& StripeApi::isKeyOf( $context->mode, $publishable, array( 'pk' ) )
			&& $context->amount->currency()->equals( $context->currency )
			&& null !== $minimum
			&& $minor >= $minimum
			&& $minor <= self::MAXIMUM_MINOR;
	}

	/**
	 * Creates the payment's PaymentIntent, for capture later, and confirms it with the shopper's payment method when the request carries one.
	 *
	 * Without a token, the PaymentIntent waits for Stripe's script in the shopper's browser to
	 * confirm it. The idempotency key is the intent's uuid, so the same authorization asked again
	 * is the same PaymentIntent.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks Stripe.
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on.
	 *
	 * @param PaymentRequest $request The intent and the customer's payment token.
	 * @return GatewayResult Stripe's answer, not yet applied.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		$this->requireCurrency( 'authorize', $request->amount );

		$token = $request->paymentToken;

		if ( null !== $token && 1 !== preg_match( self::TOKEN, $token ) ) {
			return $this->declined( 'authorize', Results::refused( Operation::Authorize, $request->intentUuid, $request->amount, null, array(), self::INVALID_TOKEN ) );
		}

		$answer = $this->api()->post( $request->mode, 'payment_intents', self::intentParameters( $request ), $request->idempotencyKey() );

		return $this->mapped(
			'authorize',
			$request->amount,
			function () use ( $request, $answer, $token ): GatewayResult {
				if ( ! $answer->isError() ) {
					$intent = $this->ownIntent( 'authorize', $answer->body, $request->intentUuid );
					$result = Results::authorization( $intent, $request->intentUuid, null !== $token );

					return Outcome::Declined === $result->outcome ? $this->declined( 'authorize', $result ) : $result;
				}

				if ( 402 !== $answer->status ) {
					$this->noteRefused( 'authorize', $answer );
				}

				$intent = $answer->errorIntent();

				return $this->declined(
					'authorize',
					array() === $intent
						? Results::refused( Operation::Authorize, $request->intentUuid, $request->amount, null, $answer->error(), 'card_declined' )
						: Results::failed( Operation::Authorize, $this->ownIntent( 'authorize', $intent, $request->intentUuid ), $request->intentUuid, $answer->error(), 'card_declined' )
				);
			}
		);
	}

	/**
	 * Captures the authorized amount asked for, all of it or less; Stripe releases the rest.
	 *
	 * The amount is always sent, so Stripe captures exactly what SEOCart asks. A PaymentIntent
	 * captured already answers that capture again.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks Stripe.
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on, or the intent has no PaymentIntent.
	 *
	 * @param CaptureRequest $request The intent and the amount.
	 * @return GatewayResult Stripe's answer, not yet applied.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$this->requireCurrency( 'capture', $request->amount );

		$intentId = $this->knownIntentId( 'capture', $request->providerIntentId );
		$answer   = $this->api()->post( $request->mode, StripeApi::path( 'payment_intents', $intentId, 'capture' ), array( 'amount_to_capture' => $request->amount->minorUnits() ), $request->idempotencyKey() );

		return $this->mapped(
			'capture',
			$request->amount,
			function () use ( $request, $answer, $intentId ): GatewayResult {
				if ( ! $answer->isError() ) {
					return Results::captured( $this->ownIntent( 'capture', $answer->body, $request->intentUuid ), $request->intentUuid );
				}

				$intent = $answer->errorIntent();

				if ( array() === $intent ) {
					return $this->declined( 'capture', Results::refused( Operation::Capture, $request->intentUuid, $request->amount, $intentId, $answer->error(), 'capture_refused' ) );
				}

				$intent = $this->ownIntent( 'capture', $intent, $request->intentUuid );

				return 'succeeded' === Results::status( $intent )
					? Results::captured( $intent, $request->intentUuid )
					: $this->declined( 'capture', Results::failed( Operation::Capture, $intent, $request->intentUuid, $answer->error(), 'capture_refused' ) );
			}
		);
	}

	/**
	 * Cancels the PaymentIntent, releasing what it holds.
	 *
	 * An approved void reports the request's amount: what the cancellation released. A
	 * PaymentIntent the shopper completed and SEOCart captured before the cancel reached Stripe
	 * answers the authorization's approval instead; one cancelled already answers that void again.
	 * Without Stripe's id, the PaymentIntent is found by the intent's uuid first.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on, no PaymentIntent was found, the id recorded or found is not a PaymentIntent's, or it is being processed.
	 *
	 * @param VoidRequest $request The intent and why it is voided.
	 * @return GatewayResult Stripe's answer, not yet applied.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		$intentId = $this->knownIntentId( 'void', $request->providerIntentId ?? $this->searchedIntentId( $request->mode, $request->intentUuid ) );
		$reason   = self::CANCELLATION_REASONS[ $request->reason ] ?? null;
		$answer   = $this->api()->post( $request->mode, StripeApi::path( 'payment_intents', $intentId, 'cancel' ), null === $reason ? array() : array( 'cancellation_reason' => $reason ), $request->idempotencyKey() );

		return $this->mapped(
			'void',
			$request->amount,
			function () use ( $request, $answer ): GatewayResult {
				if ( ! $answer->isError() ) {
					return Results::voided( $this->ownIntent( 'void', $answer->body, $request->intentUuid ), $request->intentUuid, $request->amount );
				}

				$intent = $answer->errorIntent();

				if ( array() === $intent ) {
					$this->refusedRequest( 'void', $answer );
				}

				$intent = $this->ownIntent( 'void', $intent, $request->intentUuid );

				return match ( Results::status( $intent ) ) {
					'succeeded' => Results::authorized( $intent, $request->intentUuid ),
					'canceled'  => Results::voided( $intent, $request->intentUuid, $request->amount ),
					default     => $this->unexpected( 'void', StripeCodes::intentStatus( Results::status( $intent ) ) ?? 'unknown_status', Results::intentId( $intent ) ),
				};
			}
		);
	}

	/**
	 * Gives back part or all of the captured amount, tagged with the refund's uuid so it can be found again.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks Stripe.
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on, or the intent has no PaymentIntent.
	 *
	 * @param GatewayRefund $request The intent, the amount and the refund's uuid.
	 * @return GatewayResult Stripe's answer, not yet applied: an approval names Stripe's refund.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$this->requireCurrency( 'refund', $request->amount );

		$intentId = $this->knownIntentId( 'refund', $request->providerIntentId );
		$answer   = $this->api()->post(
			$request->mode,
			'refunds',
			array(
				'payment_intent' => $intentId,
				'amount'         => $request->amount->minorUnits(),
				'metadata'       => array( 'refund_uuid' => $request->refundUuid ),
			),
			$request->idempotencyKey()
		);

		return $this->mapped(
			'refund',
			$request->amount,
			function () use ( $request, $answer, $intentId ): GatewayResult {
				$result = $answer->isError()
					? Results::refused( Operation::Refund, $request->intentUuid, $request->amount, $intentId, $answer->error(), 'refund_refused' )
					: Results::refund( $answer->body, $request->intentUuid );

				return Outcome::Declined === $result->outcome ? $this->declined( 'refund', $result ) : $result;
			}
		);
	}

	/**
	 * Finds a refund by its uuid among the PaymentIntent's refunds, which Stripe lists at once, without a search's delay.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks Stripe.
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on, the recorded id is not a PaymentIntent's, or any refund Stripe lists is not named by a refund's id (every one is checked before any is compared).
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null The refund's result; a refund declined `not_found` when the complete
	 *                            list holds none under the uuid; null when the intent has no
	 *                            PaymentIntent, or the list could not be read to its end.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		if ( null === $request->providerIntentId ) {
			return null;
		}

		$intentId = $this->knownIntentId( 'query_refund', $request->providerIntentId );
		$after    = null;

		for ( $page = 0; $page < self::REFUND_PAGES; $page++ ) {
			$query  = array(
				'payment_intent' => $intentId,
				'limit'          => 100,
			);
			$answer = $this->api()->get( $request->mode, 'refunds', null === $after ? $query : $query + array( 'starting_after' => $after ) );

			if ( $answer->isError() ) {
				return null;
			}

			$refunds = Fields::objects( $answer->body, 'data' );
			$ids     = array_map( fn( array $refund ): string => StripeCodes::id( Fields::text( $refund, 'id' ), 're', 'pyr' ) ?? $this->unexpected( 'query_refund', 'not_a_refund', $intentId ), $refunds );

			foreach ( $refunds as $refund ) {
				if ( Fields::text( $refund, 'metadata', 'refund_uuid' ) === $request->refundUuid ) {
					return $this->mapped( 'query_refund', $request->amount, static fn(): GatewayResult => Results::refund( $refund, $request->intentUuid ) );
				}
			}

			if ( true !== Fields::flag( $answer->body, 'has_more' ) ) {
				return Results::refundNotFound( $request->intentUuid, $request->amount, $intentId );
			}

			if ( array() === $ids ) {
				return null;
			}

			$after = $ids[ count( $ids ) - 1 ];
		}

		return null;
	}

	/**
	 * Asks where a payment stands: by Stripe's id when SEOCart has it, otherwise by a search on the intent's uuid.
	 *
	 * Only a search answers not-found, and SEOCart believes it only once the intent is older than
	 * the search delay; a PaymentIntent Stripe's id names cannot vanish, so its absence is no answer.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks Stripe.
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on, or the recorded id is not a PaymentIntent's.
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The latest answer; an authorization declined `not_found` or `expired`;
	 *                            null while it is still deciding, or when a search found more than one.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		if ( null !== $query->providerIntentId ) {
			$answer = $this->api()->get( $query->mode, StripeApi::path( 'payment_intents', $this->knownIntentId( 'query', $query->providerIntentId ) ) );

			if ( $answer->isError() ) {
				$this->refusedRequest( 'query', $answer );
			}

			return $this->mapped( 'query', $query->amount, fn(): ?GatewayResult => Results::query( $this->ownIntent( 'query', $answer->body, $query->intentUuid ), $query->intentUuid ) );
		}

		$found = $this->search( $query->mode, $query->intentUuid );

		if ( array() === $found ) {
			return Results::notFound( $query->intentUuid, $query->amount );
		}

		if ( count( $found ) > 1 ) {
			return null;
		}

		return $this->mapped( 'query', $query->amount, fn(): ?GatewayResult => Results::query( $this->ownIntent( 'query', $found[0], $query->intentUuid ), $query->intentUuid ) );
	}

	/**
	 * Reads a delivery Stripe sent to SEOCart's webhook: verified first, then parsed.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Reads it (WebhookReader).
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading What it says, or why it was rejected or ignored.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		return ( new WebhookReader( $this->context ) )->read( $envelope );
	}

	/**
	 * Returns the event types the gateway's webhook endpoint subscribes to: exactly those WebhookReader reads.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The event types.
	 */
	public function webhookEvents(): array {
		return array_keys( WebhookReader::EVENTS );
	}

	/**
	 * Sets up the Stripe webhook endpoint of this site and a mode (WebhookEndpoints).
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe could not be asked, or refused a step.
	 *
	 * @param WebhookTarget $target The endpoint this site needs.
	 * @return WebhookProvisioning What was done, with the new signing secret when one was created.
	 */
	public function provisionWebhooks( WebhookTarget $target ): WebhookProvisioning {
		return ( new WebhookEndpoints( $this->api() ) )->provision( $target );
	}

	/**
	 * Returns the requests to Stripe, built on first use.
	 *
	 * @since 0.2.0
	 *
	 * @return StripeApi The requests.
	 */
	private function api(): StripeApi {
		return $this->api ??= new StripeApi( $this->context );
	}

	/**
	 * Returns the parameters of the PaymentIntent an authorization creates.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The authorization.
	 * @return array<string, mixed> The parameters, in the order they are sent.
	 */
	private static function intentParameters( PaymentRequest $request ): array {
		$parameters = array(
			'amount'                       => $request->amount->minorUnits(),
			'currency'                     => strtolower( $request->amount->currency()->code() ),
			'capture_method'               => 'manual',
			'allowed_payment_method_types' => array( 'card' ),
			'metadata'                     => array(
				'intent_uuid'  => $request->intentUuid,
				'order_number' => $request->orderNumber,
			),
		);

		if ( null === $request->paymentToken ) {
			return $parameters;
		}

		$parameters['confirm']        = true;
		$parameters['payment_method'] = $request->paymentToken;

		if ( null !== $request->returnUrl ) {
			$parameters['return_url'] = $request->returnUrl;
		}

		return $parameters;
	}

	/**
	 * Refuses, before any request is built, to move money in a currency the gateway does not declare.
	 *
	 * SEOCart refuses such an operation itself, before it asks the gateway (its capability
	 * matrix); this keeps a direct call from sending Stripe a currency the matrix never offered.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the amount is in another currency than CURRENCIES names.
	 *
	 * @param string $operation The operation, for the log.
	 * @param Money  $amount    The amount the request would move.
	 */
	private function requireCurrency( string $operation, Money $amount ): void {
		$code = $amount->currency()->code();

		if ( in_array( $code, self::CURRENCIES, true ) ) {
			return;
		}

		$this->context->logger()->warning(
			'stripe.currency_refused',
			'The gateway was asked to move money in a currency it does not take, so nothing was sent.',
			array(
				'operation' => $operation,
				'currency'  => $code,
			)
		);

		throw new GatewayUnavailable( sprintf( 'The Stripe gateway takes %1$s, not %2$s.', implode( ', ', self::CURRENCIES ), $code ) );
	}

	/**
	 * Returns the PaymentIntent an operation is for, as SEOCart recorded it, when it has the shape of a PaymentIntent's id.
	 *
	 * The id comes from SEOCart's ledger; what is not a PaymentIntent's id, such as a charge's id or
	 * a card number, is refused before any request is built, so it never reaches Stripe or a log line.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the intent has none (SEOCart only captures or refunds an authorized intent, which has one), or when the id is not a PaymentIntent's.
	 *
	 * @param string      $operation The operation, for the log.
	 * @param string|null $intentId  The PaymentIntent's id, as SEOCart recorded it.
	 * @return string The id.
	 */
	private function knownIntentId( string $operation, ?string $intentId ): string {
		if ( null === $intentId ) {
			return $this->unexpected( $operation, 'no_payment_intent', null );
		}

		return StripeCodes::id( $intentId, 'pi' ) ?? $this->unexpected( $operation, 'not_a_payment_intent', null );
	}

	/**
	 * Finds the one PaymentIntent created for an intent, by a search on its uuid.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on, or the search found none or more than one.
	 *
	 * @param Mode   $mode       The intent's mode.
	 * @param string $intentUuid The intent's uuid.
	 * @return string|null The id the PaymentIntent holds, for the caller to check (knownIntentId()); null when it holds none.
	 */
	private function searchedIntentId( Mode $mode, string $intentUuid ): ?string {
		$found = $this->search( $mode, $intentUuid );

		if ( 1 !== count( $found ) ) {
			return $this->unexpected( 'void', array() === $found ? 'no_payment_intent' : 'several_payment_intents', null );
		}

		return Fields::text( $found[0], 'id' );
	}

	/**
	 * Searches the PaymentIntents created for an intent, by the uuid in their metadata.
	 *
	 * A search may lag a creation by a minute, or by an hour during an outage at Stripe; SEOCart
	 * accounts for it before it believes a search that found nothing.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer, or the uuid is not one.
	 *
	 * @param Mode   $mode       The intent's mode.
	 * @param string $intentUuid The intent's uuid.
	 * @return list<array<mixed>> The PaymentIntents found.
	 */
	private function search( Mode $mode, string $intentUuid ): array {
		if ( 1 !== preg_match( self::UUID, $intentUuid ) ) {
			return $this->unexpected( 'search', 'not_a_uuid', null );
		}

		$answer = $this->api()->get(
			$mode,
			'payment_intents/search',
			array(
				'query' => sprintf( 'metadata["intent_uuid"]:"%s"', $intentUuid ),
				'limit' => 10,
			)
		);

		if ( $answer->isError() ) {
			$this->refusedRequest( 'search', $answer );
		}

		$found = Fields::objects( $answer->body, 'data' );

		if ( count( $found ) > 1 ) {
			$this->context->logger()->warning(
				'stripe.search_ambiguous',
				'A search found more than one PaymentIntent for one payment, so the gateway cannot say which stands.',
				array(
					'intent_uuid' => $intentUuid,
					'count'       => count( $found ),
				)
			);
		}

		return $found;
	}

	/**
	 * Returns a PaymentIntent Stripe answered with, after checking it is the one SEOCart created for this intent.
	 *
	 * A wrong id recorded on an intent must not capture, cancel or report someone else's payment.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When it is not a PaymentIntent, or its metadata names another intent.
	 *
	 * @param string       $operation  The operation, for the log.
	 * @param array<mixed> $intent     The object Stripe answered with.
	 * @param string       $intentUuid The intent the request was for.
	 * @return array<mixed> The PaymentIntent.
	 */
	private function ownIntent( string $operation, array $intent, string $intentUuid ): array {
		if ( 'payment_intent' === Fields::text( $intent, 'object' ) && Fields::text( $intent, 'metadata', 'intent_uuid' ) === $intentUuid ) {
			return $intent;
		}

		$this->context->logger()->error(
			'stripe.foreign_intent',
			'Stripe answered with a PaymentIntent that was not created for this payment, so nothing was applied.',
			array(
				'operation'          => $operation,
				'intent_uuid'        => $intentUuid,
				'provider_intent_id' => StripeCodes::id( Fields::text( $intent, 'id' ), 'pi' ),
			)
		);

		throw new GatewayUnavailable( 'Stripe answered with a PaymentIntent that was not created for this payment.' );
	}

	/**
	 * Maps an answer, turning a shape or a state the mapping does not know, or a result in another currency than the request's, into GatewayUnavailable.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the answer cannot be read.
	 *
	 * @template T of GatewayResult|null
	 *
	 * @param string   $operation The operation, for the log.
	 * @param Money    $asked     The request's amount: a result in another currency is not this request's.
	 * @param \Closure $map       Maps the answer.
	 * @return GatewayResult|null What the mapping returned.
	 *
	 * @phpstan-param \Closure(): T $map
	 * @phpstan-return T
	 */
	private function mapped( string $operation, Money $asked, \Closure $map ): ?GatewayResult {
		try {
			$result = $map();

			self::requireCurrencyOf( $result, $asked );

			return $result;
		} catch ( \UnexpectedValueException $unreadable ) {
			$this->context->logger()->warning(
				'stripe.unexpected_answer',
				'Stripe answered in a shape or a state the gateway does not read, so nothing was applied.',
				array(
					'operation' => $operation,
					'problem'   => get_class( $unreadable ),
				)
			);

			throw new GatewayUnavailable( 'Stripe answered in a shape or a state the gateway does not read.', 0, $unreadable );
		}
	}

	/**
	 * Refuses a result in another currency than the request's: it is not this request's answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the result's currency is not the request's.
	 *
	 * @param GatewayResult|null $result The result; null for none.
	 * @param Money              $asked  The request's amount.
	 */
	private static function requireCurrencyOf( ?GatewayResult $result, Money $asked ): void {
		if ( null !== $result && $result->amount->currency()->code() !== $asked->currency()->code() ) {
			throw new \UnexpectedValueException( 'Stripe answered in another currency than the request\'s.' );
		}
	}

	/**
	 * Logs a decline, and returns it.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $operation The operation.
	 * @param GatewayResult $result    The decline.
	 * @return GatewayResult The same decline.
	 */
	private function declined( string $operation, GatewayResult $result ): GatewayResult {
		$this->context->logger()->info(
			'stripe.declined',
			'Stripe declined a request.',
			array(
				'operation'          => $operation,
				'intent_uuid'        => $result->intentUuid,
				'provider_intent_id' => StripeCodes::id( $result->providerIntentId, 'pi' ),
				'provider_object_id' => StripeCodes::id( $result->providerObjectId ),
				'error_code'         => StripeCodes::word( $result->errorCode ),
			)
		);

		return $result;
	}

	/**
	 * Stops a request Stripe refused that has no meaning SEOCart applies, such as a cancel of an unknown PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable Always.
	 *
	 * @param string    $operation The operation.
	 * @param ApiAnswer $answer    Stripe's error.
	 * @return never
	 */
	private function refusedRequest( string $operation, ApiAnswer $answer ): never {
		$this->noteRefused( $operation, $answer );

		throw new GatewayUnavailable( sprintf( 'Stripe refused the %1$s request: HTTP %2$d.', $operation, $answer->status ) );
	}

	/**
	 * Logs a request Stripe refused as malformed or unknown: a request the gateway should not have sent as it did.
	 *
	 * @since 0.2.0
	 *
	 * @param string    $operation The operation.
	 * @param ApiAnswer $answer    Stripe's error.
	 */
	private function noteRefused( string $operation, ApiAnswer $answer ): void {
		$this->context->logger()->error(
			'stripe.request_refused',
			'Stripe refused a request the gateway sent, so nothing was applied.',
			array(
				'operation'   => $operation,
				'http_status' => $answer->status,
				'error_type'  => StripeCodes::errorType( $answer->errorType() ),
				'error_code'  => StripeCodes::errorCode( Fields::text( $answer->error(), 'code' ) ),
			)
		);
	}

	/**
	 * Stops an operation that met a state it cannot act on.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable Always.
	 *
	 * @param string      $operation The operation.
	 * @param string      $state     What was met: a word of the gateway's, such as `no_payment_intent`, or a status Stripe documents.
	 * @param string|null $intentId  The PaymentIntent, when there is one.
	 * @return never
	 */
	private function unexpected( string $operation, string $state, ?string $intentId ): never {
		$this->context->logger()->warning(
			'stripe.unexpected_state',
			'The gateway met a state it cannot act on, so nothing was applied.',
			array(
				'operation'          => $operation,
				'status'             => $state,
				'provider_intent_id' => StripeCodes::id( $intentId, 'pi' ),
			)
		);

		throw new GatewayUnavailable( sprintf( 'The Stripe %1$s met a state it cannot act on: %2$s.', $operation, $state ) );
	}

	/**
	 * Declares the settings each mode keeps.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The settings.
	 */
	private static function settings(): array {
		return array(
			new FieldSpec(
				name: self::SECRET_KEY,
				type: FieldType::String,
				description: 'The Stripe account\'s secret or restricted API key for this mode, which every request to Stripe carries.',
				label: static fn(): string => __( 'Secret key', 'seocart-gateway-for-stripe' ),
				example: 'rk_test_example',
				max_length: 255,
				privacy: Privacy::Secret
			),
			new FieldSpec(
				name: self::PUBLISHABLE_KEY,
				type: FieldType::String,
				description: 'The Stripe account\'s publishable key for this mode, which Stripe\'s script in the shopper\'s browser uses.',
				label: static fn(): string => __( 'Publishable key', 'seocart-gateway-for-stripe' ),
				example: 'pk_test_example',
				max_length: 255
			),
			new FieldSpec(
				name: GatewayDescriptor::WEBHOOK_SECRET,
				type: FieldType::String,
				description: 'The signing secret of the webhook endpoint Stripe delivers this mode\'s events to.',
				label: static fn(): string => __( 'Webhook signing secret', 'seocart-gateway-for-stripe' ),
				example: 'whsec_example',
				max_length: 255,
				privacy: Privacy::Secret
			),
			new FieldSpec(
				name: GatewayDescriptor::ACCOUNT_COUNTRY,
				type: FieldType::String,
				description: 'The country of the Stripe account, which selects what the gateway can do.',
				label: static fn(): string => __( 'Stripe account country', 'seocart-gateway-for-stripe' ),
				example: self::ACCOUNT_COUNTRIES[0],
				allowed: self::ACCOUNT_COUNTRIES,
				max_length: 2
			),
		);
	}

	/**
	 * Declares the capability matrix: every operation, in every currency, for an account of every certified country.
	 *
	 * @since 0.2.0
	 *
	 * @return CapabilityMatrix The matrix.
	 */
	private static function matrix(): CapabilityMatrix {
		$rows = array();

		foreach ( self::ACCOUNT_COUNTRIES as $country ) {
			foreach ( self::CURRENCIES as $currency ) {
				$rows[] = new MatrixRow( Currency::of( $currency ), $country, self::OPERATIONS );
			}
		}

		return new CapabilityMatrix( $rows );
	}

	/**
	 * Declares the one service the gateway calls, as the plugin's readme discloses it.
	 *
	 * @since 0.2.0
	 *
	 * @return OutboundHost The host.
	 */
	private static function host(): OutboundHost {
		return new OutboundHost(
			id: self::HOST_ID,
			service: 'Stripe',
			purpose: 'Stripe is a payment processing service. SEOCart Gateway for Stripe uses its API to authorize, capture, cancel and refund the store\'s card payments, to look up a payment whose answer was lost, and to set up the webhook endpoint Stripe reports payment events to.',
			endpoint: 'https://api.stripe.com/v1/*',
			dataSent: 'The order\'s amount and currency, its order number and SEOCart\'s reference for the payment, the payment method token that Stripe\'s own form created in the shopper\'s browser, the address Stripe returns the shopper to after a bank check, refund amounts and references, and this site\'s webhook address. No card number, name, address or email address.',
			sentWhen: 'When a customer places an order paid with Stripe; when an order\'s payment is captured, cancelled or refunded; when SEOCart checks a payment whose answer it did not receive; and when a store manager saves the Stripe settings. Nothing is sent before Stripe is configured.',
			termsUrl: 'https://stripe.com/legal/ssa',
			privacyUrl: 'https://stripe.com/privacy',
			timeoutSeconds: 30
		);
	}
}
