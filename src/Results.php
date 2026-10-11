<?php
/**
 * Results: what a Stripe PaymentIntent or Refund means, as SEOCart applies it
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\NextAction;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report an answer the gateway cannot read, which it turns into GatewayUnavailable; they are never HTML.

/**
 * Maps a PaymentIntent or a Refund, as Stripe answered it, to the GatewayResult SEOCart applies.
 *
 * Owns one fact: what each of Stripe's states means for a payment. One mapping serves the
 * answer to a request, the answer to a status query and a webhook delivery, so the same state
 * always means the same result. Pure: it reads only the array it is given, keeps nothing and
 * logs nothing.
 *
 * The provider object a result names is the object of that outcome: the charge for an
 * authorization (approved or declined) and for a capture, the refund for a refund, and the
 * PaymentIntent itself for a void or an expiry, since Stripe makes no object for those. The
 * PaymentIntent is always the result's provider intent. A decline carries Stripe's machine code,
 * never its message, which is written for the shopper. A request for the shopper to act says what
 * they must do: go to the bank's page Stripe names, or let Stripe's script take the step.
 *
 * Every id a result names is a Stripe id of the kind its field holds (StripeCodes::id()): a
 * PaymentIntent's for the intent, a charge's or a payment's for an authorization or a capture, a
 * refund's for a refund. A client secret handed on for the shopper's browser must be its
 * PaymentIntent's own (StripeCodes::clientSecret()). A field holding anything else makes the
 * answer one the adapter cannot read, so nothing of it reaches SEOCart.
 *
 * A state or a shape the mapping does not know is an \UnexpectedValueException, which the gateway
 * turns into GatewayUnavailable: nothing is applied from an answer the adapter cannot read.
 *
 * @since 0.2.0
 */
final class Results {

	/**
	 * The decline code of a refund Stripe cancelled.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CANCELED = 'canceled';

	/**
	 * The decline code of a PaymentIntent Stripe cancelled before it was captured: SEOCart ends the placement.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const EXPIRED = 'expired';

	/**
	 * Maps the PaymentIntent a create answered: what the authorization did.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the PaymentIntent is in a state or a shape the mapping does not know.
	 *
	 * @param array<mixed> $intent     The PaymentIntent.
	 * @param string       $intentUuid The intent it is for.
	 * @param bool         $tokenSent  Whether the request confirmed it with the shopper's payment method.
	 * @return GatewayResult The result.
	 */
	public static function authorization( array $intent, string $intentUuid, bool $tokenSent ): GatewayResult {
		return match ( self::status( $intent ) ) {
			'requires_capture', 'succeeded'                    => self::authorized( $intent, $intentUuid ),
			'requires_action'                                  => self::requiresAction( $intent, $intentUuid ),
			'requires_payment_method', 'requires_confirmation' => $tokenSent
				? self::failed( Operation::Authorize, $intent, $intentUuid, Fields::map( $intent, 'last_payment_error' ), 'requires_payment_method' )
				: self::requiresAction( $intent, $intentUuid ),
			'processing'                                       => self::waiting( Outcome::Pending, $intent, $intentUuid ),
			'canceled'                                         => self::expired( $intent, $intentUuid ),
			default                                            => throw new \UnexpectedValueException( 'A PaymentIntent in a state the adapter does not know.' ),
		};
	}

	/**
	 * Maps a PaymentIntent asked about by a status query: the latest answer, or null while it is still deciding.
	 *
	 * A PaymentIntent waiting for the shopper never expires by itself; SEOCart voids it when its
	 * window ends.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the PaymentIntent is in a state or a shape the mapping does not know.
	 *
	 * @param array<mixed> $intent     The PaymentIntent.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult|null The result; null while the shopper or Stripe has not decided.
	 */
	public static function query( array $intent, string $intentUuid ): ?GatewayResult {
		$error = Fields::map( $intent, 'last_payment_error' );

		return match ( self::status( $intent ) ) {
			'requires_capture'                                                   => self::authorized( $intent, $intentUuid ),
			'succeeded'                                                          => self::captured( $intent, $intentUuid ),
			'canceled'                                                           => self::expired( $intent, $intentUuid ),
			'requires_payment_method'                                            => array() === $error ? null : self::failed( Operation::Authorize, $intent, $intentUuid, $error, 'requires_payment_method' ),
			'requires_action', 'requires_confirmation', 'processing'            => null,
			default                                                              => throw new \UnexpectedValueException( 'A PaymentIntent in a state the adapter does not know.' ),
		};
	}

	/**
	 * Maps an authorized PaymentIntent: the approval, for the amount Stripe holds.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the PaymentIntent's amount or currency cannot be read.
	 *
	 * @param array<mixed> $intent     The PaymentIntent: `requires_capture`, or `succeeded` once captured.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult The approved authorization, named by its charge.
	 */
	public static function authorized( array $intent, string $intentUuid ): GatewayResult {
		$held = 'requires_capture' === self::status( $intent ) ? 'amount_capturable' : 'amount';

		return self::result( Operation::Authorize, Outcome::Approved, $intentUuid, self::amount( $intent, $held ), self::id( $intent, 'latest_charge', 'ch', 'py' ), self::intentId( $intent ) );
	}

	/**
	 * Maps a captured PaymentIntent: the capture, for the amount Stripe received.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the PaymentIntent is not captured, or its amount cannot be read.
	 *
	 * @param array<mixed> $intent     The PaymentIntent, `succeeded`.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult The approved capture, named by its charge.
	 */
	public static function captured( array $intent, string $intentUuid ): GatewayResult {
		if ( 'succeeded' !== self::status( $intent ) ) {
			throw new \UnexpectedValueException( 'A capture answered with a PaymentIntent that is not captured.' );
		}

		return self::result( Operation::Capture, Outcome::Approved, $intentUuid, self::amount( $intent, 'amount_received' ), self::id( $intent, 'latest_charge', 'ch', 'py' ), self::intentId( $intent ) );
	}

	/**
	 * Maps a cancelled PaymentIntent as a void: approved, named by the PaymentIntent, since Stripe makes no cancellation object.
	 *
	 * The amount is what the cancellation released: the void request's amount when SEOCart asked
	 * for the void, as the payment contract has a void report; the PaymentIntent's amount when
	 * Stripe reports a cancellation nobody here asked for.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the PaymentIntent is not cancelled, or its amount cannot be read.
	 *
	 * @param array<mixed> $intent     The PaymentIntent, `canceled`.
	 * @param string       $intentUuid The intent it is for.
	 * @param Money|null   $released   Optional. The amount the void request asked to release. Default the PaymentIntent's amount.
	 * @return GatewayResult The approved void.
	 */
	public static function voided( array $intent, string $intentUuid, ?Money $released = null ): GatewayResult {
		if ( 'canceled' !== self::status( $intent ) ) {
			throw new \UnexpectedValueException( 'A void answered with a PaymentIntent that is not cancelled.' );
		}

		$held = self::amount( $intent, 'amount' );

		if ( null !== $released && $released->currency()->code() !== $held->currency()->code() ) {
			throw new \UnexpectedValueException( 'A void answered with a PaymentIntent in another currency than the void\'s.' );
		}

		return self::result( Operation::Void, Outcome::Approved, $intentUuid, $released ?? $held, self::intentId( $intent ), self::intentId( $intent ) );
	}

	/**
	 * Maps a failed attempt on a PaymentIntent: a decline, with Stripe's code and the failed charge.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the PaymentIntent's amount or currency cannot be read.
	 *
	 * @param Operation    $operation  The operation declined.
	 * @param array<mixed> $intent     The PaymentIntent as it stands after the attempt.
	 * @param string       $intentUuid The intent it is for.
	 * @param array<mixed> $error      Stripe's error: the request's error, or the PaymentIntent's `last_payment_error`.
	 * @param string       $fallback   The code when Stripe gave none.
	 * @return GatewayResult The decline.
	 */
	public static function failed( Operation $operation, array $intent, string $intentUuid, array $error, string $fallback ): GatewayResult {
		return self::result(
			$operation,
			Outcome::Declined,
			$intentUuid,
			self::amount( $intent, 'amount' ),
			self::id( $error, 'charge', 'ch', 'py' ) ?? self::id( $intent, 'latest_charge', 'ch', 'py' ),
			self::intentId( $intent ),
			self::code( $error, $fallback )
		);
	}

	/**
	 * Maps a refused request that carries no PaymentIntent: a decline of the amount asked, with Stripe's code.
	 *
	 * @since 0.2.0
	 *
	 * @param Operation    $operation  The operation refused.
	 * @param string       $intentUuid The intent it is for.
	 * @param Money        $amount     The amount the request asked for.
	 * @param string|null  $intentId   The PaymentIntent, when the request named one.
	 * @param array<mixed> $error      Stripe's error.
	 * @param string       $fallback   The code when Stripe gave none.
	 * @return GatewayResult The decline, naming no object.
	 */
	public static function refused( Operation $operation, string $intentUuid, Money $amount, ?string $intentId, array $error, string $fallback ): GatewayResult {
		return self::result( $operation, Outcome::Declined, $intentUuid, $amount, null, $intentId, self::code( $error, $fallback ) );
	}

	/**
	 * Maps a PaymentIntent Stripe cancelled while SEOCart waited for it: an authorization declined `expired`.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the PaymentIntent's amount or currency cannot be read.
	 *
	 * @param array<mixed> $intent     The PaymentIntent, `canceled`.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult The decline, named by the PaymentIntent.
	 */
	public static function expired( array $intent, string $intentUuid ): GatewayResult {
		return self::result( Operation::Authorize, Outcome::Declined, $intentUuid, self::amount( $intent, 'amount' ), self::intentId( $intent ), self::intentId( $intent ), self::EXPIRED );
	}

	/**
	 * Maps a search that found no PaymentIntent for the intent: an authorization declined `not_found`.
	 *
	 * SEOCart believes it only once the intent is older than the gateway's search delay.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent searched for.
	 * @param Money  $amount     The intent's amount.
	 * @return GatewayResult The decline, naming no object.
	 */
	public static function notFound( string $intentUuid, Money $amount ): GatewayResult {
		return self::result( Operation::Authorize, Outcome::Declined, $intentUuid, $amount, null, null, PaymentGateway::NOT_FOUND );
	}

	/**
	 * Maps a Refund: approved while Stripe is giving the money back or has, declined once it failed or was cancelled.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the Refund is in a state or a shape the mapping does not know.
	 *
	 * @param array<mixed> $refund     The Refund.
	 * @param string       $intentUuid The intent it is for; empty when Stripe names only its PaymentIntent.
	 * @return GatewayResult The result, named by the Refund.
	 */
	public static function refund( array $refund, string $intentUuid ): GatewayResult {
		$code = match ( self::status( $refund ) ) {
			'succeeded', 'pending', 'requires_action' => null,
			'failed'                                  => StripeCodes::refundFailure( Fields::text( $refund, 'failure_reason' ) ) ?? 'failed',
			'canceled'                                => self::CANCELED,
			default                                   => throw new \UnexpectedValueException( 'A Refund in a state the adapter does not know.' ),
		};
		$refundId = self::id( $refund, 'id', 're', 'pyr' ) ?? throw new \UnexpectedValueException( 'A Refund without an id.' );

		return self::result( Operation::Refund, null === $code ? Outcome::Approved : Outcome::Declined, $intentUuid, self::amount( $refund, 'amount' ), $refundId, self::id( $refund, 'payment_intent', 'pi' ), $code );
	}

	/**
	 * Maps a complete list of a PaymentIntent's refunds that holds none under the refund's uuid: a refund declined `not_found`.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $intentUuid The intent.
	 * @param Money       $amount     The refund's amount, as asked.
	 * @param string|null $intentId   The PaymentIntent.
	 * @return GatewayResult The decline, naming no object.
	 */
	public static function refundNotFound( string $intentUuid, Money $amount, ?string $intentId ): GatewayResult {
		return self::result( Operation::Refund, Outcome::Declined, $intentUuid, $amount, null, $intentId, PaymentGateway::NOT_FOUND );
	}

	/**
	 * Returns an object's `status`.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When it has none.
	 *
	 * @param array<mixed> $source The PaymentIntent or Refund.
	 * @return string The status.
	 */
	public static function status( array $source ): string {
		return Fields::text( $source, 'status' ) ?? throw new \UnexpectedValueException( 'A Stripe object without a status.' );
	}

	/**
	 * Reads an amount of an object, in the object's currency.
	 *
	 * Stripe counts amounts in the currency's smallest unit, as SEOCart does for USD, GBP and EUR.
	 * Only a currency the gateway declares (Gateway::CURRENCIES) is read: an answer in any other is
	 * not one of this gateway's payments.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the amount is missing or negative, or the currency is not one the gateway declares.
	 *
	 * @param array<mixed> $source The PaymentIntent or Refund.
	 * @param string       $field  The amount's field, such as `amount_received`.
	 * @return Money The amount.
	 */
	public static function amount( array $source, string $field ): Money {
		$minor = Fields::integer( $source, $field );
		$code  = Fields::text( $source, 'currency' );

		if ( null === $minor || $minor < 0 || null === $code || 1 !== preg_match( '/^[a-z]{3}\z/', $code ) ) {
			throw new \UnexpectedValueException( 'A Stripe object without a readable amount and currency.' );
		}

		if ( ! in_array( strtoupper( $code ), Gateway::CURRENCIES, true ) ) {
			throw new \UnexpectedValueException( 'A Stripe object in a currency the gateway does not take.' );
		}

		try {
			return Money::of( $minor, Currency::of( strtoupper( $code ) ) );
		} catch ( \InvalidArgumentException $unknown ) {
			throw new \UnexpectedValueException( 'A Stripe object in a currency SEOCart does not know.', 0, $unknown );
		}
	}

	/**
	 * Returns the PaymentIntent's id.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When it holds no PaymentIntent's id.
	 *
	 * @param array<mixed> $intent The PaymentIntent.
	 * @return string The id.
	 */
	public static function intentId( array $intent ): string {
		return self::id( $intent, 'id', 'pi' ) ?? throw new \UnexpectedValueException( 'A PaymentIntent without an id.' );
	}

	/**
	 * Builds a result of this gateway, turning a value the contract refuses into an answer the gateway cannot read.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the contract refuses the result, such as an approved refund of nothing.
	 *
	 * @param Operation       $operation        The operation.
	 * @param Outcome         $outcome          The outcome.
	 * @param string          $intentUuid       The intent.
	 * @param Money           $amount           The amount.
	 * @param string|null     $providerObjectId The object of the outcome.
	 * @param string|null     $providerIntentId The PaymentIntent.
	 * @param string|null     $errorCode        Optional. The decline's code. Default none.
	 * @param NextAction|null $nextAction       Optional. What the shopper must do, for a request to act. Default none.
	 * @return GatewayResult The result.
	 */
	private static function result( Operation $operation, Outcome $outcome, string $intentUuid, Money $amount, ?string $providerObjectId, ?string $providerIntentId, ?string $errorCode = null, ?NextAction $nextAction = null ): GatewayResult {
		try {
			return new GatewayResult( Gateway::ID, $operation, $outcome, $intentUuid, $amount, $providerObjectId, $providerIntentId, $errorCode, null, $nextAction );
		} catch ( \InvalidArgumentException $refused ) {
			throw new \UnexpectedValueException( 'An answer the payment contract does not accept as a result.', 0, $refused );
		}
	}

	/**
	 * Maps a PaymentIntent that waits for the shopper: the customer must act, and what they must do.
	 *
	 * A PaymentIntent confirmed with a return address whose bank must confirm the payment names the
	 * bank's page (`next_action.redirect_to_url`): the shopper goes there, and comes back to the
	 * return address. Any other step, and a PaymentIntent the browser has not confirmed yet, is taken
	 * by Stripe's script in the browser, with the PaymentIntent's client secret as its handle.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the PaymentIntent names neither an https page nor a client secret, names a client secret that is not its own, or its amount cannot be read.
	 *
	 * @param array<mixed> $intent     The PaymentIntent.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult The request for the customer to act.
	 */
	private static function requiresAction( array $intent, string $intentUuid ): GatewayResult {
		$intentId = self::intentId( $intent );
		$page     = Fields::text( $intent, 'next_action', 'redirect_to_url', 'url' );
		$handle   = Fields::text( $intent, 'client_secret' );

		if ( null !== $handle && null === StripeCodes::clientSecret( $handle, $intentId ) ) {
			throw new \UnexpectedValueException( 'A PaymentIntent waiting for the shopper names a client secret that is not its own.' );
		}

		try {
			if ( null !== $page && str_starts_with( $page, 'https://' ) ) {
				$action = NextAction::redirect( $page );
			} elseif ( null !== $handle ) {
				$action = NextAction::sdk( $handle );
			} else {
				throw new \UnexpectedValueException( 'A PaymentIntent waiting for the shopper names neither a page to send them to nor a client secret.' );
			}
		} catch ( \InvalidArgumentException $refused ) {
			throw new \UnexpectedValueException( 'A PaymentIntent waiting for the shopper names a step the payment contract does not accept.', 0, $refused );
		}

		return self::result( Operation::Authorize, Outcome::RequiresAction, $intentUuid, self::amount( $intent, 'amount' ), null, $intentId, null, $action );
	}

	/**
	 * Builds an answer that moves no money: the customer must act, or Stripe is still deciding.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the PaymentIntent's amount or currency cannot be read.
	 *
	 * @param Outcome      $outcome    RequiresAction or Pending.
	 * @param array<mixed> $intent     The PaymentIntent.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult The answer, naming no object.
	 */
	private static function waiting( Outcome $outcome, array $intent, string $intentUuid ): GatewayResult {
		return self::result( Operation::Authorize, $outcome, $intentUuid, self::amount( $intent, 'amount' ), null, self::intentId( $intent ) );
	}

	/**
	 * Returns the Stripe id a field holds, when it is an id of a kind the field may hold (StripeCodes::id()).
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the field holds anything else, an empty string, a number or an object included: an answer naming another kind of object, or something that is no id, is not read.
	 *
	 * @param array<mixed> $source   The object.
	 * @param string       $field    The field, such as `latest_charge`.
	 * @param string       ...$kinds The prefixes the field may hold, such as `ch` and `py`.
	 * @return string|null The id; null when the field is missing or JSON null.
	 */
	private static function id( array $source, string $field, string ...$kinds ): ?string {
		if ( ! Fields::has( $source, $field ) ) {
			return null;
		}

		return StripeCodes::id( Fields::text( $source, $field ), ...$kinds ) ?? throw new \UnexpectedValueException( sprintf( 'A Stripe object whose %s is not an id of the kind it holds.', $field ) );
	}

	/**
	 * Returns Stripe's machine code from an error: the issuer's decline code first, then Stripe's error code, each only when Stripe documents it.
	 *
	 * A code field can hold anything; what is not one of Stripe's documented codes (StripeCodes)
	 * never reaches a result, the ledger or a log line.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $error    The error, or a PaymentIntent's last payment error.
	 * @param string       $fallback The code when the error gives none Stripe documents.
	 * @return string The code.
	 */
	private static function code( array $error, string $fallback ): string {
		return StripeCodes::declineCode( Fields::text( $error, 'decline_code' ) )
			?? StripeCodes::errorCode( Fields::text( $error, 'code' ) )
			?? $fallback;
	}
}
