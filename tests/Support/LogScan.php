<?php
/**
 * LogScan: finds card data and secrets in what the gateway wrote
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support;

/**
 * Scans text for what the gateway must never write: card details, a header, a key, a signing secret, a client secret, or a piece of a body Stripe sent.
 *
 * What is scanned is everything the gateway handed its logger and every message of an exception it
 * threw. Card numbers are found as Luhn-valid runs of 13 to 19 digits, spaces allowed between them
 * and dashes not, so a uuid never reads as one.
 *
 * @since 0.2.0
 */
final class LogScan {

	/**
	 * Words that only card data, a header or a secret would bring into a line.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const WORDS = array( 'last4', 'exp_month', 'exp_year', 'fingerprint', 'Authorization', 'Bearer', 'Stripe-Signature', '_secret_', 'whsec_', 'sk_test_', 'sk_live_', 'rk_test_', 'rk_live_' );

	/**
	 * The shortest piece of a body that counts as quoting it.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const QUOTE_LENGTH = 40;

	/**
	 * Returns what a text holds that it must not.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $text    The text: log lines and exception messages, encoded.
	 * @param string[] $secrets The credential and client-secret values of the run.
	 * @param string[] $bodies  The bodies Stripe sent in the run.
	 * @return list<string> What was found; empty when the text is clean.
	 *
	 * @phpstan-param list<string> $secrets
	 * @phpstan-param list<string> $bodies
	 */
	public static function findings( string $text, array $secrets, array $bodies ): array {
		$found = array();

		foreach ( self::WORDS as $word ) {
			if ( str_contains( $text, $word ) ) {
				$found[] = "the word {$word}";
			}
		}

		foreach ( $secrets as $secret ) {
			if ( '' !== $secret && str_contains( $text, $secret ) ) {
				$found[] = 'a credential value';
			}
		}

		foreach ( $bodies as $body ) {
			$length = strlen( $body );

			for ( $start = 0; $start + self::QUOTE_LENGTH <= $length; $start += self::QUOTE_LENGTH ) {
				if ( str_contains( $text, substr( $body, $start, self::QUOTE_LENGTH ) ) ) {
					$found[] = 'a piece of a body Stripe sent';
					break;
				}
			}
		}

		if ( preg_match_all( '/\d(?:[ ]?\d){12,18}/', $text, $runs ) ) {
			foreach ( $runs[0] as $run ) {
				if ( self::isLuhn( str_replace( ' ', '', $run ) ) ) {
					$found[] = 'a card-shaped number';
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Tells whether digits pass the Luhn check.
	 *
	 * @since 0.2.0
	 *
	 * @param string $digits The digits.
	 * @return bool True when they do.
	 */
	private static function isLuhn( string $digits ): bool {
		$sum    = 0;
		$double = false;

		$last = strlen( $digits ) - 1;

		for ( $i = $last; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];

			if ( $double ) {
				$digit *= 2;
				$digit  = $digit > 9 ? $digit - 9 : $digit;
			}

			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10;
	}
}
