<?php
/**
 * CleanupSelection: what the cleanup after a Stripe test mode run may cancel or delete
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

/**
 * Picks the run's own objects a cleanup ends: its PaymentIntents still open, and the webhook endpoints of its installation.
 *
 * A PaymentIntent cannot be deleted, only cancelled, and only while it waits; one the run left
 * waiting would hold its amount on the test card. A webhook endpoint counts against the sixteen an
 * account may hold. The test account is shared, so the cleanup touches only what the run made: an
 * intent on the run list, whose uuid Stripe's metadata agrees with, and an endpoint tagged with the
 * run's installation uuid.
 *
 * @since 0.2.0
 */
final class CleanupSelection {

	/**
	 * The statuses of a PaymentIntent that waits, which Stripe lets a cancellation end.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const OPEN = array( 'requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture' );

	/**
	 * The path of Stripe's API that creates a PaymentIntent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CREATE_PATH = '/v1/payment_intents';

	/**
	 * The shape of Stripe's PaymentIntent id, as a regular expression's part.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INTENT_ID = 'pi_[A-Za-z0-9]{1,64}';

	/**
	 * The shape of the store's uuid of an intent, as a regular expression's part.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

	/**
	 * Reads the run list: one line per intent the run created, Stripe's id then the store's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The list's text.
	 * @return array<string, string> Each store uuid, by Stripe's PaymentIntent id; a line of another shape is left out.
	 */
	public static function runList( string $text ): array {
		$intents = array();

		foreach ( explode( "\n", $text ) as $line ) {
			if ( 1 === preg_match( '/^(' . self::INTENT_ID . ') (' . self::UUID . ')$/D', trim( $line ), $match ) ) {
				$intents[ $match[1] ] = $match[2];
			}
		}

		return $intents;
	}

	/**
	 * Returns the PaymentIntent a recorded exchange created at Stripe: Stripe's id from the answer, and the store's uuid the request tagged it with.
	 *
	 * Only a request that creates a PaymentIntent counts. Stripe answers a declined one 402, with
	 * the intent it created inside the error, waiting for another payment method, so that answer
	 * counts too.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $exchange A tape's exchange: the request, and Stripe's answer.
	 * @return array{0: string, 1: string}|null Stripe's id and the store's uuid; null when the exchange created no PaymentIntent.
	 */
	public static function createdIn( array $exchange ): ?array {
		$request  = is_array( $exchange['request'] ?? null ) ? $exchange['request'] : array();
		$response = is_array( $exchange['response'] ?? null ) ? $exchange['response'] : array();

		if ( 'POST' !== ( $request['method'] ?? null ) || self::CREATE_PATH !== ( $request['path'] ?? null ) ) {
			return null;
		}

		parse_str( is_string( $request['body'] ?? null ) ? $request['body'] : '', $sent );

		$answer = json_decode( is_string( $response['body'] ?? null ) ? $response['body'] : '', true );
		$answer = is_array( $answer ) ? $answer : array();
		$intent = is_array( $answer['error']['payment_intent'] ?? null ) ? $answer['error']['payment_intent'] : $answer;
		$id     = $intent['id'] ?? null;
		$uuid   = is_array( $sent['metadata'] ?? null ) ? ( $sent['metadata']['intent_uuid'] ?? null ) : null;

		if ( ! is_string( $id ) || ! is_string( $uuid ) || 1 !== preg_match( '/^' . self::INTENT_ID . '$/D', $id ) || 1 !== preg_match( '/^' . self::UUID . '$/D', $uuid ) ) {
			return null;
		}

		return array( $id, $uuid );
	}

	/**
	 * Returns the PaymentIntents recorded exchanges created at Stripe, a declined one included, as the run list holds them.
	 *
	 * The store keeps no Stripe id for a declined authorization, so Stripe's answer to the request
	 * that created the intent is where a test learns it.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $exchanges The exchanges, each in a tape's shape.
	 * @return array<string, string> Each store uuid, by Stripe's PaymentIntent id.
	 */
	public static function createdBy( array $exchanges ): array {
		$intents = array();

		foreach ( $exchanges as $exchange ) {
			$created = self::createdIn( $exchange );

			if ( null !== $created ) {
				$intents[ $created[0] ] = $created[1];
			}
		}

		return $intents;
	}

	/**
	 * Returns the run list's line for an intent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentId   Stripe's PaymentIntent id.
	 * @param string $intentUuid The store's uuid of the intent.
	 * @return string The line, with its newline.
	 */
	public static function line( string $intentId, string $intentUuid ): string {
		return $intentId . ' ' . $intentUuid . "\n";
	}

	/**
	 * Returns the ids of the run's PaymentIntents to cancel: those on the run list that still wait.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $runList The run's intents: each store uuid, by Stripe's id.
	 * @param list<array<mixed>>    $intents The PaymentIntents as Stripe answers them now.
	 * @return list<string> The ids.
	 */
	public static function intentsToCancel( array $runList, array $intents ): array {
		$ids = array();

		foreach ( $intents as $intent ) {
			$id     = $intent['id'] ?? null;
			$tagged = $intent['metadata']['intent_uuid'] ?? null;

			if ( is_string( $id ) && isset( $runList[ $id ] ) && $tagged === $runList[ $id ] && in_array( $intent['status'] ?? null, self::OPEN, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Returns the ids of the webhook endpoints to delete: those tagged with the installation's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $endpoints   The account's webhook endpoints, as Stripe lists them.
	 * @param string             $installUuid The installation's uuid.
	 * @param string             $tag         The metadata key of the installation's uuid.
	 * @return list<string> The ids.
	 */
	public static function endpointsToDelete( array $endpoints, string $installUuid, string $tag ): array {
		$ids = array();

		foreach ( $endpoints as $endpoint ) {
			$id     = $endpoint['id'] ?? null;
			$tagged = $endpoint['metadata'][ $tag ] ?? null;

			if ( is_string( $id ) && str_starts_with( $id, 'we_' ) && $tagged === $installUuid ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
