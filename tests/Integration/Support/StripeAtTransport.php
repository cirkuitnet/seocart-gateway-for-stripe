<?php
/**
 * StripeAtTransport: Stripe's API, answered at `pre_http_request`, for integration tests
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration\Support;

use SEOCart\GatewayForStripe\StripeApi;

/**
 * Answers the gateway's requests to Stripe after SEOCart's own HTTP client has checked them, so nothing reaches the network.
 *
 * It keeps webhook endpoints as Stripe does (a list shows no secret; a creation answers with one),
 * answers any other request from a queue, and records every request: its method, path, headers,
 * parameters and the transaction depth it was sent at.
 *
 * @since 0.2.0
 */
final class StripeAtTransport {

	/**
	 * The endpoints Stripe holds, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $endpoints = array();

	/**
	 * Every request received, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{method: string, path: string, headers: array<string, string>, parameters: array<mixed>, depth: int}>
	 */
	public array $requests = array();

	/**
	 * The answers waiting for other requests: method and path, then status and body.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{method: string, path: string, status: int, body: string}>
	 */
	private array $queue = array();

	/**
	 * How many endpoints were created, to name the next.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $created = 0;

	/**
	 * Starts answering.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $depth Returns the open transaction levels of the test's connection (int).
	 */
	public function __construct( private \Closure $depth ) {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Queues the answer to a request that is not about webhook endpoints.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The method.
	 * @param string $path   The path under the API's base.
	 * @param int    $status The status.
	 * @param string $body   The body.
	 */
	public function queue( string $method, string $path, int $status, string $body ): void {
		$this->queue[] = array(
			'method' => $method,
			'path'   => $path,
			'status' => $status,
			'body'   => $body,
		);
	}

	/**
	 * Answers a request to Stripe; leaves any other to WordPress. Filters `pre_http_request`.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $pre  What an earlier filter answered.
	 * @param mixed  $args The request's arguments.
	 * @param string $url  The URL.
	 * @return mixed The answer, in the shape WordPress's HTTP API returns; `$pre` for another host.
	 */
	public function answer( mixed $pre, mixed $args, string $url ): mixed {
		if ( ! str_starts_with( $url, StripeApi::BASE_URL ) ) {
			return $pre;
		}

		$args   = (array) $args;
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$parts  = explode( '?', substr( $url, strlen( StripeApi::BASE_URL ) ), 2 );
		parse_str( (string) ( 'GET' === $method ? ( $parts[1] ?? '' ) : ( $args['body'] ?? '' ) ), $parameters );

		$this->requests[] = array(
			'method'     => $method,
			'path'       => $parts[0],
			'headers'    => (array) ( $args['headers'] ?? array() ),
			'parameters' => $parameters,
			'depth'      => (int) ( $this->depth )(),
		);

		if ( str_starts_with( $parts[0], 'webhook_endpoints' ) ) {
			return $this->endpointsAnswer( $method, $parts[0], $parameters );
		}

		foreach ( $this->queue as $index => $queued ) {
			if ( $queued['method'] === $method && $queued['path'] === $parts[0] ) {
				array_splice( $this->queue, $index, 1 );

				return self::response( $queued['status'], $queued['body'] );
			}
		}

		return self::response( 500, '{"error":{"type":"api_error","message":"The test queued no answer."}}' );
	}

	/**
	 * Returns the requests as `METHOD path`, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The requests.
	 */
	public function sent(): array {
		return array_map( static fn( array $request ): string => $request['method'] . ' ' . $request['path'], $this->requests );
	}

	/**
	 * Answers the webhook endpoints API: list, create, delete.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $method     The method.
	 * @param string       $path       The path.
	 * @param array<mixed> $parameters The parameters.
	 * @return array<string, mixed> The answer.
	 */
	private function endpointsAnswer( string $method, string $path, array $parameters ): array {
		if ( 'GET' === $method ) {
			$listed = array_values( array_map( static fn( array $endpoint ): array => array_diff_key( $endpoint, array( 'secret' => true ) ), $this->endpoints ) );

			return self::response(
				200,
				(string) wp_json_encode(
					array(
						'object'   => 'list',
						'url'      => '/v1/webhook_endpoints',
						'has_more' => false,
						'data'     => $listed,
					)
				)
			);
		}

		if ( 'DELETE' === $method ) {
			$id = substr( $path, strlen( 'webhook_endpoints/' ) );
			unset( $this->endpoints[ $id ] );

			return self::response(
				200,
				(string) wp_json_encode(
					array(
						'id'      => $id,
						'object'  => 'webhook_endpoint',
						'deleted' => true,
					)
				)
			);
		}

		$id = sprintf( 'we_1Fixture%04d', ++$this->created );

		$this->endpoints[ $id ] = array(
			'id'             => $id,
			'object'         => 'webhook_endpoint',
			'api_version'    => (string) ( $parameters['api_version'] ?? '' ),
			'enabled_events' => array_values( (array) ( $parameters['enabled_events'] ?? array() ) ),
			'metadata'       => (array) ( $parameters['metadata'] ?? array() ),
			'status'         => 'enabled',
			'url'            => (string) ( $parameters['url'] ?? '' ),
			'secret'         => 'whsec_integration' . $this->created . wp_generate_password( 12, false ),
		);

		return self::response( 200, (string) wp_json_encode( $this->endpoints[ $id ] ) );
	}

	/**
	 * Builds an answer in the shape WordPress's HTTP API returns.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 * @return array<string, mixed> The answer.
	 */
	private static function response( int $status, string $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
