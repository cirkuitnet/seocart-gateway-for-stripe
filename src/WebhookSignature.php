<?php
/**
 * WebhookSignature: checks the Stripe-Signature header of a webhook delivery
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\WebhookReading;

/**
 * Verifies that a delivery was signed by Stripe with the endpoint's signing secret, and signed recently.
 *
 * Owns one fact: Stripe's signature scheme. The header `Stripe-Signature` holds a timestamp `t`
 * and one or more signatures; only the scheme `v1` counts (Stripe: ignore every other scheme, so a
 * weaker one cannot be substituted), and several `v1` signatures arrive while a secret is being
 * rolled. A signature is the hex HMAC-SHA256, keyed with the whole signing secret, of the
 * timestamp, a dot and the raw body, compared in constant time. The timestamp is this delivery's:
 * Stripe signs every attempt anew, so a retry days later still verifies, and the window is checked
 * on it, never on the event's own creation time. Nothing here decodes the body.
 *
 * @since 0.2.0
 */
final class WebhookSignature {

	/**
	 * How far the signed timestamp may be from the site's clock, either way, in seconds: Stripe's libraries' default.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const TOLERANCE_SECONDS = 300;

	/**
	 * The longest header read, in bytes: a timestamp and a few signatures are under 400.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const HEADER_MAX_BYTES = 4096;

	/**
	 * Checks a delivery's signature and its timestamp.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null        $header  The `Stripe-Signature` header; null when the delivery has none.
	 * @param string             $rawBody The body, byte for byte as it arrived.
	 * @param string             $secret  The endpoint's signing secret.
	 * @param \DateTimeImmutable $now     The time now, from the context's clock.
	 * @return string|null Null when the delivery is Stripe's and recent; otherwise why it is rejected:
	 *                     WebhookReading::BAD_SIGNATURE or WebhookReading::STALE.
	 */
	public static function check( ?string $header, string $rawBody, #[\SensitiveParameter] string $secret, \DateTimeImmutable $now ): ?string {
		if ( null === $header || '' === $header || strlen( $header ) > self::HEADER_MAX_BYTES ) {
			return WebhookReading::BAD_SIGNATURE;
		}

		$timestamps = array();
		$signatures = array();

		foreach ( explode( ',', $header ) as $element ) {
			$pair = explode( '=', trim( $element ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamps[] = $pair[1];
			} elseif ( 'v1' === $pair[0] && 1 === preg_match( '/^[0-9a-f]{64}\z/', $pair[1] ) ) {
				$signatures[] = $pair[1];
			}
		}

		if ( 1 !== count( $timestamps ) || 1 !== preg_match( '/^\d{1,12}\z/', $timestamps[0] ) || array() === $signatures ) {
			return WebhookReading::BAD_SIGNATURE;
		}

		$expected = hash_hmac( 'sha256', $timestamps[0] . '.' . $rawBody, $secret );
		$verified = false;

		// Every signature is compared, so the time taken does not tell which one matched.
		foreach ( $signatures as $signature ) {
			$verified = hash_equals( $expected, $signature ) || $verified;
		}

		if ( ! $verified ) {
			return WebhookReading::BAD_SIGNATURE;
		}

		return abs( $now->getTimestamp() - (int) $timestamps[0] ) > self::TOLERANCE_SECONDS ? WebhookReading::STALE : null;
	}
}
