<?php
/**
 * StripeTestApi: the live tests' own requests to Stripe, beside the gateway's
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration\Live;

use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\StripeApi;
use SEOCart\GatewayForStripe\Tests\Support\Live\MessageGuard;

/**
 * Asks Stripe's test mode what the live tests check, and ends what a run leaves open: reads, a PaymentIntent's cancellation, a webhook endpoint's deletion.
 *
 * These requests are the test's, not the gateway's, so they go around WordPress's HTTP API,
 * which the conformance harness watches and refuses any request the gateway's client is not
 * sending, and around the recording transport, whose scrubber refuses what an Events answer
 * holds. They use PHP's curl, with the run's key, at the API version the adapter pins. A failure
 * names the method, the path and Stripe's status, never a body, a header or a query.
 *
 * @since 0.2.0
 */
final class StripeTestApi {

	/**
	 * How long a request may take to connect, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const CONNECT_SECONDS = 10;

	/**
	 * How long a request may take in all, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const TOTAL_SECONDS = 30;

	/**
	 * The test mode's key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Builds the client over a test mode's secret or restricted key.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the key is not a test mode's secret or restricted key.
	 *
	 * @param string $key The key.
	 */
	public function __construct( #[\SensitiveParameter] string $key ) {
		if ( 1 !== preg_match( '/^(?:sk|rk)_test_[A-Za-z0-9]+$/D', $key ) ) {
			throw new \InvalidArgumentException( 'The live tests call Stripe with a test mode\'s secret or restricted key only.' );
		}

		$this->key = $key;
	}

	/**
	 * Reads an object or a list.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $path  The path under /v1/, such as `payment_intents/pi_1`.
	 * @param array<string, mixed> $query The query, encoded as Stripe reads it.
	 * @return array<mixed> The answer.
	 */
	public function get( string $path, array $query = array() ): array {
		return $this->send( 'GET', $path, $query );
	}

	/**
	 * Sends a change.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $path   The path under /v1/, such as `payment_intents/pi_1/cancel`.
	 * @param array<string, mixed> $params The form's fields.
	 * @return array<mixed> The answer.
	 */
	public function post( string $path, array $params = array() ): array {
		return $this->send( 'POST', $path, $params );
	}

	/**
	 * Deletes an object.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The path under /v1/, such as `webhook_endpoints/we_1`.
	 * @return array<mixed> The answer.
	 */
	public function delete( string $path ): array {
		return $this->send( 'DELETE', $path, array() );
	}

	/**
	 * Reads every page of a list.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the list runs past the pages allowed.
	 *
	 * @param string               $path  The list's path, such as `events`.
	 * @param array<string, mixed> $query The query, beside `limit` and `starting_after`.
	 * @param int                  $pages The most pages read.
	 * @return list<array<mixed>> The objects, in the order Stripe lists them.
	 */
	public function all( string $path, array $query = array(), int $pages = 20 ): array {
		$objects = array();
		$after   = null;

		for ( $page = 0; $page < $pages; $page++ ) {
			$answer  = $this->get( $path, array( 'limit' => 100 ) + $query + ( null === $after ? array() : array( 'starting_after' => $after ) ) );
			$data    = array_values( array_filter( (array) ( $answer['data'] ?? array() ), 'is_array' ) );
			$objects = array_merge( $objects, $data );

			if ( true !== ( $answer['has_more'] ?? false ) || array() === $data ) {
				return $objects;
			}

			$after = (string) ( end( $data )['id'] ?? '' );
		}

		throw new \RuntimeException( MessageGuard::say( 'Stripe\'s list %1$s ran past %2$d pages.', $path, $pages ) );
	}

	/**
	 * Sends a request and decodes the answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When Stripe could not be reached or answered other than 2xx JSON: the method, path and status only.
	 *
	 * @param string               $method The method.
	 * @param string               $path   The path under /v1/.
	 * @param array<string, mixed> $params The query of a GET, or the form of a POST.
	 * @return array<mixed> The answer.
	 */
	private function send( string $method, string $path, array $params ): array {
		$encoded = array() === $params ? '' : StripeApi::encode( $params );
		$url     = StripeApi::BASE_URL . $path . ( 'GET' === $method && '' !== $encoded ? '?' . $encoded : '' );

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_errno -- the test's own requests, outside WordPress's HTTP API, which the conformance harness keeps for the gateway's.
		$curl = curl_init( $url );

		if ( false === $curl ) {
			throw new \RuntimeException( MessageGuard::say( 'A request for %1$s %2$s could not be made.', $method, $path ) );
		}

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => self::CONNECT_SECONDS,
				CURLOPT_TIMEOUT        => self::TOTAL_SECONDS,
				CURLOPT_HTTPHEADER     => array(
					'Authorization: Bearer ' . $this->key,
					'Stripe-Version: ' . Gateway::API_VERSION,
					'Content-Type: application/x-www-form-urlencoded',
				),
				CURLOPT_POSTFIELDS     => 'POST' === $method ? $encoded : null,
			)
		);

		$body   = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$failed = curl_errno( $curl );
		// phpcs:enable

		if ( 0 !== $failed || ! is_string( $body ) ) {
			throw new \RuntimeException( MessageGuard::say( 'Stripe could not be reached for %1$s %2$s (curl error %3$d).', $method, $path, $failed ) );
		}

		$answer = json_decode( $body, true );

		if ( $status < 200 || $status > 299 || ! is_array( $answer ) ) {
			$code = is_array( $answer ) && is_string( $answer['error']['code'] ?? null ) && 1 === preg_match( '/^[a-z_]{1,64}$/D', $answer['error']['code'] ) ? $answer['error']['code'] : 'none';

			throw new \RuntimeException( MessageGuard::say( 'Stripe answered %1$s %2$s with HTTP %3$d (code %4$s).', $method, $path, $status, $code ) );
		}

		return $answer;
	}
}
