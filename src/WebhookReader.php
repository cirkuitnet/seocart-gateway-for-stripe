<?php
/**
 * WebhookReader: reads a delivery Stripe sent to SEOCart's webhook
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;

/**
 * Verifies a delivery, then reads it: a payment's result, an event SEOCart does not act on, or a rejection.
 *
 * Owns one fact: what each Stripe event the gateway subscribes to means (EVENTS, which is also the
 * list of events its webhook endpoint is set up with). In order, and nothing of the body is read
 * before the first two pass: the signature, with the signing secret of the mode the delivery was
 * addressed to, opened for this delivery; the signed timestamp against the context's clock; then
 * the body is decoded and must be an event; then the event's own `livemode` must be the address's
 * mode. A delivery that fails any of these is rejected with a reason word and nothing else of it.
 *
 * The intent a PaymentIntent event is about is the one SEOCart created it for, from its metadata;
 * a PaymentIntent without that metadata belongs to another integration on the same Stripe account
 * and is ignored, as is a Refund that names no PaymentIntent (a refund another integration made of a
 * charge without one), so Stripe is not asked to deliver either again. A Refund names only its
 * PaymentIntent, so a refund's result carries an empty
 * intent uuid and the PaymentIntent, which SEOCart resolves, and the refund's own uuid when SEOCart
 * asked for it. Disputes are read and ignored, so SEOCart can count them and warn.
 *
 * Nothing Stripe wrote reaches SEOCart unchecked: the event's id must be a Stripe event's id, an
 * event of a type the gateway does not read is passed on under the type `unhandled_type`, never the
 * type Stripe wrote, and the ids a result names are checked by Results. A delivery that fails a
 * check is rejected as malformed.
 *
 * @since 0.2.0
 */
final class WebhookReader {

	/**
	 * Why a verified delivery is rejected: its event is of the other mode than the address it was sent to.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIVEMODE_MISMATCH = 'livemode_mismatch';

	/**
	 * Why an event is ignored: it is about a dispute.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DISPUTE = 'dispute';

	/**
	 * Why an event is ignored: its PaymentIntent was not created by SEOCart, or its Refund names no PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_OURS = 'not_ours';

	/**
	 * Why an event is ignored, and the type it is passed on under: its type is not one the gateway reads, and the type Stripe wrote is never passed on.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNHANDLED_TYPE = 'unhandled_type';

	/**
	 * Why an event is ignored: the PaymentIntent it reports holds nothing to capture.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOTHING_CAPTURABLE = 'nothing_capturable';

	/**
	 * The authorization reading of a PaymentIntent event.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const AUTHORIZATION = 'authorization';

	/**
	 * The capture reading of a PaymentIntent event.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CAPTURE = 'capture';

	/**
	 * The void reading of a PaymentIntent event.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VOID = 'void';

	/**
	 * The decline reading of a PaymentIntent event.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DECLINE = 'decline';

	/**
	 * The refund reading of a Refund event.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUND = 'refund';

	/**
	 * Every event type the gateway subscribes to, and how it is read: the one list of the events the webhook endpoint is set up with.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const EVENTS = array(
		'payment_intent.amount_capturable_updated' => self::AUTHORIZATION,
		'payment_intent.succeeded'                 => self::CAPTURE,
		'payment_intent.canceled'                  => self::VOID,
		'payment_intent.payment_failed'            => self::DECLINE,
		'refund.created'                           => self::REFUND,
		'refund.updated'                           => self::REFUND,
		'refund.failed'                            => self::REFUND,
		'charge.dispute.created'                   => self::DISPUTE,
		'charge.dispute.updated'                   => self::DISPUTE,
		'charge.dispute.closed'                    => self::DISPUTE,
		'charge.dispute.funds_withdrawn'           => self::DISPUTE,
		'charge.dispute.funds_reinstated'          => self::DISPUTE,
	);

	/**
	 * The shape of a uuid SEOCart wrote into a Stripe object's metadata.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

	/**
	 * What SEOCart gives the gateway: its settings, clock and logger.
	 *
	 * @since 0.2.0
	 *
	 * @var ExtensionContext
	 */
	private ExtensionContext $context;

	/**
	 * Builds the reader. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ExtensionContext $context What SEOCart gives the gateway.
	 */
	public function __construct( ExtensionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Reads one delivery: verified first, then decoded, then mapped.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery, as SEOCart received it.
	 * @return WebhookReading What it says, or why it was rejected or ignored.
	 */
	public function read( WebhookEnvelope $envelope ): WebhookReading {
		$rejection = WebhookSignature::check(
			$envelope->headers['stripe-signature'] ?? null,
			$envelope->rawBody,
			$this->context->settings( $envelope->mode )->secret( GatewayDescriptor::WEBHOOK_SECRET ),
			$this->context->clock()->now()
		);

		if ( null !== $rejection ) {
			return WebhookReading::rejected( $rejection );
		}

		$event = self::event( $envelope->rawBody );

		if ( null === $event ) {
			return WebhookReading::rejected( WebhookReading::MALFORMED );
		}

		if ( ( Mode::Live === $envelope->mode ) !== $event['livemode'] ) {
			return WebhookReading::rejected( self::LIVEMODE_MISMATCH );
		}

		try {
			return $this->reading( $event );
		} catch ( \UnexpectedValueException ) {
			return WebhookReading::rejected( WebhookReading::MALFORMED );
		}
	}

	/**
	 * Maps a verified event of the right mode.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When its object is not what its type says.
	 *
	 * @param array{id: string, type: string, created: int, livemode: bool, data: array{object: array<mixed>}} $event The event.
	 * @return WebhookReading The reading.
	 */
	private function reading( array $event ): WebhookReading {
		$id     = $event['id'];
		$type   = $event['type'];
		$at     = ( new \DateTimeImmutable( '@' . $event['created'] ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$object = $event['data']['object'];
		$kind   = self::EVENTS[ $type ] ?? null;

		if ( self::REFUND === $kind ) {
			return $this->refund( $id, $type, $at, $object );
		}

		if ( self::DISPUTE === $kind ) {
			return WebhookReading::ignored( $id, $type, self::DISPUTE, $at );
		}

		if ( null === $kind ) {
			return WebhookReading::ignored( $id, self::UNHANDLED_TYPE, self::UNHANDLED_TYPE, $at );
		}

		if ( 'payment_intent' !== Fields::text( $object, 'object' ) ) {
			throw new \UnexpectedValueException( 'A PaymentIntent event whose object is not a PaymentIntent.' );
		}

		$intentUuid = Fields::text( $object, 'metadata', 'intent_uuid' );

		if ( null === $intentUuid || 1 !== preg_match( self::UUID, $intentUuid ) ) {
			return WebhookReading::ignored( $id, $type, self::NOT_OURS, $at );
		}

		if ( self::AUTHORIZATION === $kind && ( 'requires_capture' !== Results::status( $object ) || ( Fields::integer( $object, 'amount_capturable' ) ?? 0 ) <= 0 ) ) {
			return WebhookReading::ignored( $id, $type, self::NOTHING_CAPTURABLE, $at );
		}

		if ( self::VOID === $kind ) {
			$this->context->logger()->info(
				'stripe.intent_canceled',
				'Stripe reports a PaymentIntent cancelled.',
				array(
					'intent_uuid'         => $intentUuid,
					'provider_intent_id'  => StripeCodes::id( Results::intentId( $object ), 'pi' ),
					'cancellation_reason' => StripeCodes::cancellationReason( Fields::text( $object, 'cancellation_reason' ) ),
				)
			);
		}

		$result = match ( $kind ) {
			self::AUTHORIZATION => Results::authorized( $object, $intentUuid ),
			self::CAPTURE       => Results::captured( $object, $intentUuid ),
			self::VOID          => Results::voided( $object, $intentUuid ),
			default             => Results::failed( Operation::Authorize, $object, $intentUuid, Fields::map( $object, 'last_payment_error' ), 'payment_failed' ),
		};

		return WebhookReading::result( $id, $type, $at, $result );
	}

	/**
	 * Maps a Refund event: the refund's result, for the PaymentIntent it names, with the refund's uuid when SEOCart asked for it.
	 *
	 * A refund that failed after it was given is reported in the log as well, for a person: the
	 * failure may reach a refund SEOCart already recorded.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the object is not a Refund, or the Refund cannot be read.
	 *
	 * @param string             $id     The event's id.
	 * @param string             $type   The event's type.
	 * @param \DateTimeImmutable $at     When Stripe says the event happened.
	 * @param array<mixed>       $refund The Refund.
	 * @return WebhookReading The reading.
	 */
	private function refund( string $id, string $type, \DateTimeImmutable $at, array $refund ): WebhookReading {
		if ( 'refund' !== Fields::text( $refund, 'object' ) ) {
			throw new \UnexpectedValueException( 'A Refund event whose object is not a Refund.' );
		}

		if ( null === StripeCodes::id( Fields::text( $refund, 'payment_intent' ), 'pi' ) ) {
			return WebhookReading::ignored( $id, $type, self::NOT_OURS, $at );
		}

		$result     = Results::refund( $refund, '' );
		$refundUuid = Fields::text( $refund, 'metadata', 'refund_uuid' );

		if ( Outcome::Declined === $result->outcome ) {
			$this->context->logger()->warning(
				'stripe.refund_failed',
				'Stripe reports a refund that failed or was cancelled; if SEOCart recorded it as given, a person must settle it.',
				array(
					'provider_object_id' => StripeCodes::id( $result->providerObjectId, 're', 'pyr' ),
					'provider_intent_id' => StripeCodes::id( $result->providerIntentId, 'pi' ),
					'error_code'         => StripeCodes::word( $result->errorCode ),
				)
			);
		}

		return WebhookReading::result( $id, $type, $at, $result, null !== $refundUuid && 1 === preg_match( self::UUID, $refundUuid ) ? $refundUuid : null );
	}

	/**
	 * Decodes a verified body into an event, or null when it is not one: its id must be a Stripe event's id (StripeCodes::id()).
	 *
	 * @since 0.2.0
	 *
	 * @param string $rawBody The body.
	 * @return array{id: string, type: string, created: int, livemode: bool, data: array{object: array<mixed>}}|null The event.
	 */
	private static function event( string $rawBody ): ?array {
		try {
			$event = json_decode( $rawBody, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException ) {
			return null;
		}

		if ( ! is_array( $event ) || 'event' !== Fields::text( $event, 'object' ) ) {
			return null;
		}

		$id      = StripeCodes::id( Fields::text( $event, 'id' ), 'evt' );
		$type    = Fields::text( $event, 'type' );
		$created = Fields::integer( $event, 'created' );
		$live    = Fields::flag( $event, 'livemode' );
		$object  = Fields::map( $event, 'data', 'object' );

		if ( null === $id || null === $type || null === $created || $created < 0 || null === $live || array() === $object ) {
			return null;
		}

		return array(
			'id'       => $id,
			'type'     => $type,
			'created'  => $created,
			'livemode' => $live,
			'data'     => array( 'object' => $object ),
		);
	}
}
