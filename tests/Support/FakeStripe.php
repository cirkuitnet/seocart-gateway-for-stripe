<?php
/**
 * FakeStripe: an HTTP client that answers the gateway's requests from fixtures
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support;

use SEOCart\Contracts\HttpClient;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\TransportFailure;
use SEOCart\GatewayForStripe\StripeApi;

/**
 * Stands in for SEOCart's HTTP client: answers each request with the next answer queued for its method and path, and records every request.
 *
 * A request nothing was queued for fails the test: the gateway sent something the test did not
 * expect. Every body it served is kept, so a test can check that none of it reached a log.
 *
 * @since 0.2.0
 */
final class FakeStripe implements HttpClient {

	/**
	 * Every request received, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<OutboundRequest>
	 */
	public array $requests = array();

	/**
	 * Every body served, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public array $served = array();

	/**
	 * The answers waiting, in order: method, path, then a status and body, or a transport failure's kind.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{method: string, path: string, status: int, body: string, failure: string|null}>
	 */
	private array $queue = array();

	/**
	 * Queues an answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The request's method.
	 * @param string $path   The request's path under StripeApi::BASE_URL, without its query.
	 * @param int    $status The HTTP status.
	 * @param string $body   The body.
	 * @return self The client.
	 */
	public function answer( string $method, string $path, int $status, string $body ): self {
		$this->queue[] = array(
			'method'  => $method,
			'path'    => $path,
			'status'  => $status,
			'body'    => $body,
			'failure' => null,
		);

		return $this;
	}

	/**
	 * Queues a request that gets no answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The request's method.
	 * @param string $path   The request's path.
	 * @param string $kind   The TransportFailure kind.
	 * @return self The client.
	 */
	public function fail( string $method, string $path, string $kind ): self {
		$this->queue[] = array(
			'method'  => $method,
			'path'    => $path,
			'status'  => 0,
			'body'    => '',
			'failure' => $kind,
		);

		return $this;
	}

	/**
	 * Answers a request with the first answer queued for its method and path.
	 *
	 * @since 0.2.0
	 *
	 * @throws TransportFailure  When the answer queued is a failure.
	 * @throws \LogicException   When nothing was queued for the request.
	 *
	 * @param OutboundRequest $request The request.
	 * @return OutboundResponse The answer.
	 */
	public function send( OutboundRequest $request ): OutboundResponse {
		$this->requests[] = $request;
		$path             = self::pathOf( $request );

		foreach ( $this->queue as $index => $queued ) {
			if ( $queued['method'] !== $request->method || $queued['path'] !== $path ) {
				continue;
			}

			array_splice( $this->queue, $index, 1 );

			if ( null !== $queued['failure'] ) {
				throw new TransportFailure( $queued['failure'], 'api.stripe.com' );
			}

			$this->served[] = $queued['body'];

			return new OutboundResponse( $queued['status'], array( 'content-type' => 'application/json' ), $queued['body'] );
		}

		throw new \LogicException( "The gateway sent {$request->method} {$path}, which the test did not expect." );
	}

	/**
	 * Returns every request as `METHOD path`, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The requests.
	 */
	public function sent(): array {
		return array_map( static fn( OutboundRequest $request ): string => $request->method . ' ' . self::pathOf( $request ), $this->requests );
	}

	/**
	 * Returns the last request.
	 *
	 * @since 0.2.0
	 *
	 * @return OutboundRequest The request.
	 */
	public function last(): OutboundRequest {
		return $this->requests[ count( $this->requests ) - 1 ];
	}

	/**
	 * Returns the parameters of a request: its form body, or its query.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest|null $request Optional. The request. Default the last.
	 * @return array<mixed> The parameters, decoded.
	 */
	public function parameters( ?OutboundRequest $request = null ): array {
		$request = $request ?? $this->last();
		$encoded = $request->body ?? (string) parse_url( $request->url, PHP_URL_QUERY );

		parse_str( $encoded, $parameters );

		return $parameters;
	}

	/**
	 * Tells whether every queued answer was asked for.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the queue is empty.
	 */
	public function isDrained(): bool {
		return array() === $this->queue;
	}

	/**
	 * Returns a request's path under the API's base, without its query.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest $request The request.
	 * @return string The path.
	 */
	private static function pathOf( OutboundRequest $request ): string {
		$url = explode( '?', $request->url, 2 )[0];

		return str_starts_with( $url, StripeApi::BASE_URL ) ? substr( $url, strlen( StripeApi::BASE_URL ) ) : $url;
	}
}
