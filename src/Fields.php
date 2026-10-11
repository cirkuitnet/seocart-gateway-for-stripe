<?php
/**
 * Fields: typed reads of a decoded Stripe object
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
 * Reads one value out of a Stripe object decoded from JSON, by its path, with the type the adapter expects.
 *
 * Owns one fact: how a field the adapter needs is read from an answer it does not trust to be
 * well formed. A field that is missing, or of another type, reads as null (or an empty list), so
 * each caller decides what an absent value means instead of PHP raising a warning.
 *
 * @since 0.2.0
 */
final class Fields {

	/**
	 * Tells whether a field holds a value: false when it is missing or JSON null, true for anything else, an empty string, a number or an object included.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data    The decoded object.
	 * @param string       ...$path The keys from the object down to the field.
	 * @return bool True when the field holds a value.
	 */
	public static function has( array $data, string ...$path ): bool {
		return null !== self::at( $data, $path );
	}

	/**
	 * Returns a text field: a non-empty string, or null.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data   The decoded object.
	 * @param string       ...$path The keys from the object down to the field.
	 * @return string|null The text; null when it is missing, empty or not text.
	 */
	public static function text( array $data, string ...$path ): ?string {
		$value = self::at( $data, $path );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Returns a whole-number field.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data   The decoded object.
	 * @param string       ...$path The keys from the object down to the field.
	 * @return int|null The number; null when it is missing or not a whole number.
	 */
	public static function integer( array $data, string ...$path ): ?int {
		$value = self::at( $data, $path );

		return is_int( $value ) ? $value : null;
	}

	/**
	 * Returns a true-or-false field.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data   The decoded object.
	 * @param string       ...$path The keys from the object down to the field.
	 * @return bool|null The flag; null when it is missing or not a boolean.
	 */
	public static function flag( array $data, string ...$path ): ?bool {
		$value = self::at( $data, $path );

		return is_bool( $value ) ? $value : null;
	}

	/**
	 * Returns an object field, such as a PaymentIntent's metadata or its last payment error.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data   The decoded object.
	 * @param string       ...$path The keys from the object down to the field.
	 * @return array<mixed> The object; empty when it is missing, null or not an object.
	 */
	public static function map( array $data, string ...$path ): array {
		$value = self::at( $data, $path );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Returns a list of objects, such as a list answer's `data`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data   The decoded object.
	 * @param string       ...$path The keys from the object down to the field.
	 * @return list<array<mixed>> The objects, in order; anything in the list that is not an object is left out.
	 */
	public static function objects( array $data, string ...$path ): array {
		$value = self::at( $data, $path );

		return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
	}

	/**
	 * Returns a list of texts, such as a webhook endpoint's `enabled_events`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data   The decoded object.
	 * @param string       ...$path The keys from the object down to the field.
	 * @return list<string> The texts, in order; anything in the list that is not text is left out.
	 */
	public static function texts( array $data, string ...$path ): array {
		$value = self::at( $data, $path );

		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Follows a path of keys into a decoded object.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data The decoded object.
	 * @param string[]     $path The keys.
	 * @return mixed The value; null when a key along the path is missing.
	 *
	 * @phpstan-param list<string> $path
	 */
	private static function at( array $data, array $path ): mixed {
		$value = $data;

		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return null;
			}

			$value = $value[ $key ];
		}

		return $value;
	}
}
