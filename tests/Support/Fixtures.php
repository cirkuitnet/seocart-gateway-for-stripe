<?php
/**
 * Fixtures: Stripe's answers and deliveries, as written in tests/fixtures/stripe
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support;

/**
 * Loads a fixture, changes it where a test needs another state, and signs a delivery as Stripe does.
 *
 * The fixtures are written by hand from Stripe's documented shapes (see tests/fixtures/stripe/README.md).
 *
 * @since 0.2.0
 */
final class Fixtures {

	/**
	 * The intent every PaymentIntent fixture was created for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INTENT_UUID = '0199c0de-0000-7000-8000-00000000a001';

	/**
	 * The refund every Refund fixture was asked with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUND_UUID = '0199c0de-0000-7000-8000-00000000b001';

	/**
	 * The PaymentIntent of the fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INTENT_ID = 'pi_3Fixture0001';

	/**
	 * The charge of the fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CHARGE_ID = 'ch_3Fixture0001';

	/**
	 * The client secret of the fixtures' PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLIENT_SECRET = 'pi_3Fixture0001_secret_fixtureClientSecret';

	/**
	 * Returns a fixture's text.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When there is no such fixture.
	 *
	 * @param string $name The fixture's name, without `.json`.
	 * @return string The text.
	 */
	public static function text( string $name ): string {
		$text = file_get_contents( dirname( __DIR__ ) . '/fixtures/stripe/' . $name . '.json' );

		if ( false === $text ) {
			throw new \LogicException( "No fixture {$name}." );
		}

		return $text;
	}

	/**
	 * Returns a fixture decoded.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The fixture's name.
	 * @return array<mixed> The object.
	 */
	public static function object( string $name ): array {
		return json_decode( self::text( $name ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Returns a fixture with some of its fields changed, encoded.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $name    The fixture's name.
	 * @param array<mixed> $changes The fields to replace, nested as in the object; a null replaces with null.
	 * @return string The changed object, encoded.
	 */
	public static function with( string $name, array $changes ): string {
		return self::encode( array_replace_recursive( self::object( $name ), $changes ) );
	}

	/**
	 * Encodes an object as Stripe sends one.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data The object.
	 * @return string The JSON.
	 */
	public static function encode( array $data ): string {
		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	}

	/**
	 * Wraps objects in a list answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $url     The list's `url`.
	 * @param list<array<mixed>> $objects The objects.
	 * @param bool               $hasMore Optional. Whether more pages follow. Default false.
	 * @param string             $kind    Optional. `list`, or `search_result` for a search. Default `list`.
	 * @return string The answer, encoded.
	 */
	public static function listOf( string $url, array $objects, bool $hasMore = false, string $kind = 'list' ): string {
		return self::encode(
			array(
				'object'   => $kind,
				'url'      => $url,
				'has_more' => $hasMore,
				'data'     => $objects,
			)
		);
	}

	/**
	 * Returns the `Stripe-Signature` header Stripe sends with a body, signed with a secret at a time.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body      The body.
	 * @param string $secret    The signing secret.
	 * @param int    $timestamp The delivery's timestamp.
	 * @return string The header's value.
	 */
	public static function signature( string $body, string $secret, int $timestamp ): string {
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}
}
