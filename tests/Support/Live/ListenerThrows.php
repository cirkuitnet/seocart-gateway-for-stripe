<?php
/**
 * ListenerThrows: what a listener of an action threw, kept so a failing live test can say it
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

/**
 * Wraps the listeners of an action so that what one throws is kept, its class and its message with every credential-shaped text redacted, and then thrown on unchanged.
 *
 * SEOCart's gateway registry catches what a listener of its registration action throws and
 * reports the exception's class alone, since a message may quote a credential, so a live test
 * that fails on a refused registration would say no more than "threw LogicException". The live
 * tests wrap that action's listeners with this before SEOCart builds a registry, and a failing
 * test names what was kept (LiveStripeCase::onNotSuccessfulTest()). The message passes through
 * MessageGuard::redact(): a key, a signing secret, a client secret or an address with a query is
 * never kept.
 *
 * Pure: it rewrites the callbacks WordPress keeps for an action (WP_Hook::$callbacks: by
 * priority, then by id, each with its `function` and `accepted_args`), and runs without
 * WordPress.
 *
 * @since 0.2.0
 */
final class ListenerThrows {

	/**
	 * What a wrapped listener last threw, redacted; null when none did since it was taken.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private static ?string $kept = null;

	/**
	 * Wraps one listener.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $listener The listener.
	 */
	private function __construct( private readonly \Closure $listener ) {
	}

	/**
	 * Returns an action's callbacks with every listener wrapped; a listener wrapped already is left as it is, so wrapping again changes nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int|string, mixed> $callbacks The action's callbacks, as WP_Hook::$callbacks holds them.
	 * @return array<int|string, mixed> The callbacks, each priority and id in its place.
	 */
	public static function wrap( array $callbacks ): array {
		foreach ( $callbacks as $priority => $listeners ) {
			if ( is_array( $listeners ) ) {
				$callbacks[ $priority ] = array_map( self::wrapped( ... ), $listeners );
			}
		}

		return $callbacks;
	}

	/**
	 * Returns what a wrapped listener threw since this was last asked, and forgets it.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The exception's class and redacted message; null when none threw.
	 */
	public static function taken(): ?string {
		$kept       = self::$kept;
		self::$kept = null;

		return $kept;
	}

	/**
	 * Runs the listener, keeping what it throws before throwing it on.
	 *
	 * @since 0.2.0
	 *
	 * @throws \Throwable What the listener threw, unchanged.
	 *
	 * @param mixed ...$arguments What the action passes.
	 * @return mixed What the listener returns.
	 */
	public function __invoke( mixed ...$arguments ): mixed {
		try {
			return ( $this->listener )( ...$arguments );
		} catch ( \Throwable $thrown ) {
			self::$kept = get_class( $thrown ) . ': ' . MessageGuard::redact( $thrown->getMessage() );

			throw $thrown;
		}
	}

	/**
	 * Wraps one entry of the callbacks, when it holds a listener not wrapped yet.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $entry An entry: its `function` and `accepted_args`.
	 * @return mixed The entry.
	 */
	private static function wrapped( mixed $entry ): mixed {
		if ( ! is_array( $entry ) || ! isset( $entry['function'] ) || $entry['function'] instanceof self || ! is_callable( $entry['function'] ) ) {
			return $entry;
		}

		$entry['function'] = new self( \Closure::fromCallable( $entry['function'] ) );

		return $entry;
	}
}
