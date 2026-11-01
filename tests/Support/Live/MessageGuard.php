<?php
/**
 * MessageGuard: keeps credentials out of what the live tests and their scripts print
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

/**
 * Builds every message the live tests fail with, refusing an argument shaped like a credential, and blanks such text out of a log.
 *
 * The Stripe test mode workflow runs in a public repository, so its log is public. A failure
 * message is built from ids, counts and Stripe's one-word codes; an argument that looks like a
 * key, a signing secret, a client secret, a receipt's address or any address with a query is
 * refused instead of printed, and the message says which kind it refused, never the value.
 * redact() applies the same shapes to text the workflow did not build, such as PHPUnit's output.
 *
 * @since 0.2.0
 */
final class MessageGuard {

	/**
	 * The shapes refused, each a regular expression matching the whole value, by what it is.
	 *
	 * An address with a query comes first: redact() blanks it whole before it blanks a secret its
	 * query may hold. Each slash after the scheme may carry the backslash JSON puts before it.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const SHAPES = array(
		'an address with a query'    => '#[a-z][a-z0-9+.\-]*:(?:\\\\?/){2}[^\s?\#"\'<>]*\?[^\s"\'<>]*#i',
		'a secret or restricted key' => '/(?<![A-Za-z0-9])(?:sk|rk)_[A-Za-z0-9_]{20,}/',
		'a publishable key'          => '/(?<![A-Za-z0-9])pk_(?:test|live)_[A-Za-z0-9_]{20,}/',
		'a webhook signing secret'   => '/whsec_[A-Za-z0-9_]*/',
		'a client secret'            => '/[A-Za-z0-9_]*_secret_[A-Za-z0-9_]*/',
		'a receipt\'s address'       => '/receipt_url/',
	);

	/**
	 * Formats a message, refusing any argument shaped like a credential.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When an argument matches a shape: the message names the shape, never the value.
	 *
	 * @param string           $format    The format, as sprintf() takes it; it is the test's own text.
	 * @param string|int|float ...$values The values.
	 * @return string The message.
	 */
	public static function say( string $format, string|int|float ...$values ): string {
		foreach ( $values as $position => $value ) {
			$shape = self::shapeOf( (string) $value );

			if ( null !== $shape ) {
				throw new \LogicException( sprintf( 'A message of the live tests was refused: its argument %1$d is shaped like %2$s, which is never printed.', $position + 1, $shape ) );
			}
		}

		return vsprintf( $format, $values );
	}

	/**
	 * Returns the shape a value matches, if any.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return string|null What it is shaped like; null for none.
	 */
	public static function shapeOf( string $value ): ?string {
		foreach ( self::SHAPES as $shape => $pattern ) {
			if ( 1 === preg_match( $pattern, $value ) ) {
				return $shape;
			}
		}

		return null;
	}

	/**
	 * Blanks out every value shaped like a credential in a text, leaving the rest as it was.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text, such as a line of PHPUnit's output.
	 * @return string The text, each such value replaced by `[redacted: <what it is>]`.
	 */
	public static function redact( string $text ): string {
		foreach ( self::SHAPES as $shape => $pattern ) {
			$text = (string) preg_replace( $pattern, '[redacted: ' . $shape . ']', $text );
		}

		return $text;
	}
}
