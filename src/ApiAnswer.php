<?php
/**
 * ApiAnswer: one answer from Stripe's API, already decoded
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

/**
 * An answer the adapter can act on: an object Stripe returned, or an error Stripe gave for the request itself.
 *
 * Owns one fact: what Stripe said, as data. StripeApi builds one only for a success, and for an
 * error that is an answer about the request (HTTP 400, 402 or 404 with an `error` object); every
 * other failure is a GatewayUnavailable before an answer exists. The decoded body may carry card
 * details and a client secret, so it is read field by field and never logged or kept.
 *
 * @since 0.2.0
 */
final readonly class ApiAnswer {

	/**
	 * Records the answer.
	 *
	 * @since 0.2.0
	 *
	 * @param int          $status The HTTP status.
	 * @param array<mixed> $body   The decoded body: the object, or `{ "error": { … } }`.
	 */
	public function __construct(
		public int $status,
		public array $body
	) {
	}

	/**
	 * Tells whether Stripe refused the request.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True for an error answer.
	 */
	public function isError(): bool {
		return $this->status >= 300;
	}

	/**
	 * Returns Stripe's error object.
	 *
	 * @since 0.2.0
	 *
	 * @return array<mixed> The error; empty for a success.
	 */
	public function error(): array {
		return $this->isError() ? Fields::map( $this->body, 'error' ) : array();
	}

	/**
	 * Returns the error's type: `api_error`, `card_error`, `idempotency_error` or `invalid_request_error`.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The type; null for a success or an error without one.
	 */
	public function errorType(): ?string {
		return Fields::text( $this->error(), 'type' );
	}

	/**
	 * Returns the PaymentIntent an error about a PaymentIntent carries, as it stands at Stripe.
	 *
	 * @since 0.2.0
	 *
	 * @return array<mixed> The PaymentIntent; empty when the error carries none.
	 */
	public function errorIntent(): array {
		return Fields::map( $this->error(), 'payment_intent' );
	}
}
