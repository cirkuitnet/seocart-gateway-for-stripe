<?php
/**
 * StripeApi: every request the gateway sends to Stripe
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
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\TransportFailure;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a failed call to SEOCart's payment service; they are never HTML.

/**
 * Sends one request to Stripe's API, through the HTTP client SEOCart gives the gateway, and says what came back.
 *
 * Owns one fact: how a request to Stripe is made. Every request names the declared host, carries
 * the secret key of the mode it is about, opened for this request only, and pins Stripe's API
 * version; a request that moves money also carries the idempotency key its request object derived.
 * Before anything is sent, the key must be a key of that mode, so a live key saved in the test
 * mode never reaches Stripe from a test payment.
 *
 * An answer the gateway can act on is returned as an ApiAnswer: a success, or Stripe's error about
 * the request itself (HTTP 400, 402 or 404). Anything else is a GatewayUnavailable: no answer, an
 * answer that is not JSON, a key Stripe refuses, a request still running under the same key, a
 * rate limit or a fault at Stripe. SEOCart then applies nothing and asks again later.
 *
 * The log lines say which request and which status, and of an error only its type and code when
 * they are values Stripe documents (StripeCodes): never a header, a body, a message, a parameter
 * name or any other string Stripe wrote, since a request carries the key and an answer may carry
 * card details or a client secret.
 *
 * @since 0.2.0
 */
final class StripeApi {

	/**
	 * Where every path is resolved: Stripe's API, version 1.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BASE_URL = 'https://api.stripe.com/v1/';

	/**
	 * The statuses of an error Stripe gives about the request itself, which the operation reads.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	private const REQUEST_ERRORS = array( 400, 402, 404 );

	/**
	 * What SEOCart gives the gateway: its HTTP client, its settings, its logger.
	 *
	 * @since 0.2.0
	 *
	 * @var ExtensionContext
	 */
	private ExtensionContext $context;

	/**
	 * Builds the client. Sends nothing and reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ExtensionContext $context What SEOCart gives the gateway.
	 */
	public function __construct( ExtensionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Sends a POST: creates or changes an object.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on.
	 *
	 * @param Mode         $mode           The mode whose key the request carries.
	 * @param string       $path           The path under BASE_URL, built by path().
	 * @param array<mixed> $params         The parameters, form-encoded by encode().
	 * @param string|null  $idempotencyKey Optional. The key Stripe replays the first answer for. Default none.
	 * @return ApiAnswer The answer.
	 */
	public function post( Mode $mode, string $path, array $params, ?string $idempotencyKey = null ): ApiAnswer {
		return $this->send( $mode, 'POST', $path, self::encode( $params ), $idempotencyKey );
	}

	/**
	 * Sends a GET: reads an object or a list.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on.
	 *
	 * @param Mode         $mode  The mode whose key the request carries.
	 * @param string       $path  The path under BASE_URL, built by path().
	 * @param array<mixed> $query Optional. The query parameters. Default none.
	 * @return ApiAnswer The answer.
	 */
	public function get( Mode $mode, string $path, array $query = array() ): ApiAnswer {
		return $this->send( $mode, 'GET', array() === $query ? $path : $path . '?' . self::encode( $query ), null, null );
	}

	/**
	 * Sends a DELETE.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on.
	 *
	 * @param Mode   $mode The mode whose key the request carries.
	 * @param string $path The path under BASE_URL, built by path().
	 * @return ApiAnswer The answer.
	 */
	public function delete( Mode $mode, string $path ): ApiAnswer {
		return $this->send( $mode, 'DELETE', $path, null, null );
	}

	/**
	 * Builds a path from its segments, each a word of Stripe's paths or a Stripe id (StripeCodes::pathSegment()), so no value can change where a request goes or carry anything else into it.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable Before any request is built, when a segment is neither, such as a card number or an id with a slash.
	 *
	 * @param string ...$segments The segments, such as `payment_intents`, the PaymentIntent's id and `capture`.
	 * @return string The path, such as `payment_intents/pi_3MtwBwLkdIwHu7ix28a3tqPa/capture`.
	 */
	public static function path( string ...$segments ): string {
		foreach ( $segments as $segment ) {
			if ( null === StripeCodes::pathSegment( $segment ) ) {
				throw new GatewayUnavailable( 'A Stripe request was asked for with a reference that is neither a word of Stripe\'s paths nor a Stripe id, so nothing was sent.' );
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Encodes parameters as Stripe reads a form: nested keys in brackets, lists indexed from 0, true and false as words.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $params The parameters, in the order they are sent.
	 * @return string The encoded body or query.
	 */
	public static function encode( array $params ): string {
		return http_build_query( self::words( $params ), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Tells whether a key is one of a mode's keys, by Stripe's prefixes: `sk_test_`, `rk_live_`, `pk_test_` and so on.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode     $mode  The mode.
	 * @param string   $key   The key.
	 * @param string[] $kinds The kinds of key accepted: `sk` (secret), `rk` (restricted), `pk` (publishable).
	 * @return bool True when the key starts with one of the kinds and the mode.
	 *
	 * @phpstan-param list<string> $kinds
	 */
	public static function isKeyOf( Mode $mode, #[\SensitiveParameter] string $key, array $kinds ): bool {
		foreach ( $kinds as $kind ) {
			if ( str_starts_with( $key, $kind . '_' . $mode->value . '_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sends a request and classifies the answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When Stripe gave no answer the gateway can act on.
	 *
	 * @param Mode        $mode           The mode whose key the request carries.
	 * @param string      $method         The HTTP method.
	 * @param string      $target         The path under BASE_URL, with its query for a GET.
	 * @param string|null $body           The encoded body; null for none.
	 * @param string|null $idempotencyKey The idempotency key; null for none.
	 * @return ApiAnswer The answer.
	 */
	private function send( Mode $mode, string $method, string $target, ?string $body, ?string $idempotencyKey ): ApiAnswer {
		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secretKey( $mode ),
			'Stripe-Version' => Gateway::API_VERSION,
		);

		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		}

		if ( null !== $idempotencyKey ) {
			$headers['Idempotency-Key'] = $idempotencyKey;
		}

		$request = array(
			'method' => $method,
			'path'   => self::loggedPath( $target ),
			'mode'   => $mode->value,
		);

		try {
			$response = $this->context->http()->send( new OutboundRequest( Gateway::HOST_ID, $method, self::BASE_URL . $target, $headers, $body ) );
		} catch ( TransportFailure $failure ) {
			$this->context->logger()->warning( 'stripe.unavailable', 'A request to Stripe got no answer.', $request + array( 'failure' => $failure->kind ) );

			throw new GatewayUnavailable( 'A request to Stripe got no answer.', 0, $failure );
		}

		return $this->answer( $response, $request );
	}

	/**
	 * Classifies an answer: one the operation reads, or a GatewayUnavailable.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the answer is not one the gateway can act on.
	 *
	 * @param OutboundResponse          $response The answer.
	 * @param array<string, int|string> $request  The request's method, path and mode, for the log.
	 * @return ApiAnswer The answer.
	 */
	private function answer( OutboundResponse $response, array $request ): ApiAnswer {
		try {
			$body = $response->json();
		} catch ( \UnexpectedValueException $unreadable ) {
			$this->context->logger()->warning( 'stripe.unavailable', 'Stripe answered with a body that is not JSON.', $request + array( 'http_status' => $response->status ) );

			throw new GatewayUnavailable( sprintf( 'Stripe answered HTTP %d with a body that could not be read.', $response->status ), 0, $unreadable );
		}

		$answer = new ApiAnswer( $response->status, $body );

		if ( $response->status >= 200 && $response->status < 300 ) {
			return $answer;
		}

		$type = $answer->errorType();
		$line = $request + array(
			'http_status' => $response->status,
			'error_type'  => StripeCodes::errorType( $type ),
			'error_code'  => StripeCodes::errorCode( Fields::text( $answer->error(), 'code' ) ),
		);

		if ( in_array( $response->status, self::REQUEST_ERRORS, true ) && array() !== $answer->error() && 'idempotency_error' !== $type ) {
			return $answer;
		}

		if ( 'idempotency_error' === $type && 409 !== $response->status ) {
			$this->context->logger()->warning( 'stripe.idempotency_mismatch', 'Stripe refused a request whose idempotency key was used before with other parameters; the first request\'s answer stands at Stripe.', $line );
		} elseif ( 401 === $response->status || 403 === $response->status ) {
			$this->context->logger()->error( 'stripe.unauthorized', 'Stripe refused the secret key saved for this mode.', $line );
		} else {
			$this->context->logger()->warning( 'stripe.unavailable', 'Stripe could not answer the request.', $line );
		}

		throw new GatewayUnavailable( sprintf( 'Stripe answered HTTP %d.', $response->status ) );
	}

	/**
	 * Opens the secret key of a mode, for one request, and checks that it is a key of that mode.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the key saved for the mode is a key of another mode; the
	 *                            CredentialUnavailable SEOCart throws when it cannot be opened is one too.
	 *
	 * @param Mode $mode The mode.
	 * @return string The key.
	 */
	private function secretKey( Mode $mode ): string {
		$key = $this->context->settings( $mode )->secret( Gateway::SECRET_KEY );

		if ( ! self::isKeyOf( $mode, $key, array( 'sk', 'rk' ) ) ) {
			$this->context->logger()->error( 'stripe.key_mode_mismatch', 'The secret key saved for a mode is not a key of that mode, so nothing was sent.', array( 'mode' => $mode->value ) );

			throw new GatewayUnavailable( sprintf( 'The Stripe setting %1$s of the %2$s mode is not a %2$s key.', Gateway::SECRET_KEY, $mode->value ) );
		}

		return $key;
	}

	/**
	 * Returns a request's path as a log line may hold it: without its query, and with `{id}` for any segment that is neither a word of Stripe's paths nor a Stripe id.
	 *
	 * A path built by path() never holds such a segment; this keeps a path written any other way from carrying one into the log.
	 *
	 * @since 0.2.0
	 *
	 * @param string $target The path under BASE_URL, with its query for a GET.
	 * @return string The path, such as `payment_intents/pi_3MtwBwLkdIwHu7ix28a3tqPa/capture`.
	 */
	private static function loggedPath( string $target ): string {
		$segments = explode( '/', explode( '?', $target, 2 )[0] );

		foreach ( $segments as $index => $segment ) {
			if ( null === StripeCodes::pathSegment( $segment ) ) {
				$segments[ $index ] = '{id}';
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Writes true and false as the words Stripe reads, through nested parameters.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $params The parameters.
	 * @return array<mixed> The same parameters, booleans as `true` and `false`.
	 */
	private static function words( array $params ): array {
		foreach ( $params as $name => $value ) {
			if ( is_bool( $value ) ) {
				$params[ $name ] = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) ) {
				$params[ $name ] = self::words( $value );
			}
		}

		return $params;
	}
}
