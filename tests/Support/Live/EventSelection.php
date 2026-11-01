<?php
/**
 * EventSelection: picks the run's own events out of everything a shared Stripe account sent
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

/**
 * Keeps the events of the run's own payments, read back from Stripe's Events list, and tallies their API versions.
 *
 * The test account is shared: its Events list holds every integration's events. An event is the
 * run's when its object is one of the run's PaymentIntents, named by the intent uuid the adapter
 * tags it with and by Stripe's id, both on the run list; or when it is a refund or a dispute of one
 * of them. Nothing else is delivered, so another integration's event never reaches the store, and
 * no assertion counts it.
 *
 * @since 0.2.0
 */
final class EventSelection {

	/**
	 * Returns the run's own events, in the order given.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>>    $events  The events, as Stripe's Events list answers them.
	 * @param array<string, string> $intents The run's intents: each store uuid, by Stripe's PaymentIntent id.
	 * @return list<array<mixed>> The run's events.
	 */
	public static function ours( array $events, array $intents ): array {
		return array_values( array_filter( $events, static fn( array $event ): bool => self::isOurs( $event, $intents ) ) );
	}

	/**
	 * Tells whether an event is about one of the run's intents.
	 *
	 * A PaymentIntent's event must carry both the run's Stripe id and the uuid the run gave it: an
	 * id alone could be a typing mistake on the run list, and a uuid alone another site's
	 * PaymentIntent tagged by the same seed. A refund's or a dispute's names the PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed>          $event   The event.
	 * @param array<string, string> $intents The run's intents: each store uuid, by Stripe's PaymentIntent id.
	 * @return bool True when it is the run's.
	 */
	public static function isOurs( array $event, array $intents ): bool {
		$object = $event['data']['object'] ?? null;

		if ( ! is_array( $object ) || ! is_string( $object['object'] ?? null ) ) {
			return false;
		}

		if ( 'payment_intent' === $object['object'] ) {
			$id   = $object['id'] ?? null;
			$uuid = $object['metadata']['intent_uuid'] ?? null;

			return is_string( $id ) && isset( $intents[ $id ] ) && $intents[ $id ] === $uuid;
		}

		$intent = $object['payment_intent'] ?? null;

		return in_array( $object['object'], array( 'refund', 'dispute' ), true ) && is_string( $intent ) && isset( $intents[ $intent ] );
	}

	/**
	 * Counts the events by the API version Stripe wrote each one at.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $events The events.
	 * @return array<string, int> How many of each version, by version, sorted; `none` for an event without one.
	 */
	public static function apiVersions( array $events ): array {
		$versions = array();

		foreach ( $events as $event ) {
			$version              = is_string( $event['api_version'] ?? null ) ? $event['api_version'] : 'none';
			$versions[ $version ] = ( $versions[ $version ] ?? 0 ) + 1;
		}

		ksort( $versions );

		return $versions;
	}

	/**
	 * Returns the run's events of each intent, in the order Stripe created them, by the intent's Stripe id.
	 *
	 * Stripe's `created` counts whole seconds, so events of one second keep the order they are
	 * given in: Stripe's Events list answers newest first, and the caller gives them reversed.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $events The run's events, oldest first.
	 * @return array<string, list<array<mixed>>> Each intent's events, oldest first, by its PaymentIntent id.
	 */
	public static function byIntent( array $events ): array {
		// usort() keeps the given order of equal elements (PHP 8.0 and later).
		usort( $events, static fn( array $one, array $other ): int => (int) ( $one['created'] ?? 0 ) <=> (int) ( $other['created'] ?? 0 ) );

		$byIntent = array();

		foreach ( $events as $event ) {
			$object = (array) ( $event['data']['object'] ?? array() );
			$intent = 'payment_intent' === ( $object['object'] ?? null ) ? $object['id'] ?? null : $object['payment_intent'] ?? null;

			if ( is_string( $intent ) ) {
				$byIntent[ $intent ][] = $event;
			}
		}

		return $byIntent;
	}
}
