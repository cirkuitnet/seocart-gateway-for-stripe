<?php
/**
 * Tests that what a listener of the registration action throws is kept for a failing live test, without a credential
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\ListenerThrows;

/**
 * A wrapped listener runs as before and its exception is thrown on unchanged, while its class and its message, every credential-shaped text redacted, are kept once; wrapping twice wraps once.
 *
 * The callbacks are shaped as WordPress keeps an action's (by priority, then by id, each with its
 * `function` and `accepted_args`), and every key is made up as the test runs.
 *
 * Planted violations, each shown red and removed: the message kept without MessageGuard::redact()
 * (the made-up key is kept); the wrapped-already check taken out of wrapped() (a second wrap makes
 * a second wrapper); the exception swallowed instead of thrown on (nothing is thrown).
 *
 * @since 0.2.0
 */
final class LiveListenerThrowsTest extends TestCase {

	/**
	 * Forgets what an earlier test kept.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		ListenerThrows::taken();
	}

	/**
	 * Tests that a listener's exception is thrown on as it was, and kept named by its class and its message, a key in it redacted.
	 *
	 * @since 0.2.0
	 */
	public function test_what_a_listener_throws_is_thrown_on_and_kept_without_a_key(): void {
		$key       = 'rk_test_' . bin2hex( random_bytes( 24 ) );
		$thrown    = new \LogicException( 'The gateway stripe was given no context; its key ' . $key . ' was never read.' );
		$callbacks = ListenerThrows::wrap(
			self::callbacks(
				static function () use ( $thrown ): void {
					throw $thrown;
				}
			)
		);

		try {
			self::listenerOf( $callbacks )( 'the registry' );
			$this->fail( 'The listener\'s exception was not thrown on.' );
		} catch ( \LogicException $caught ) {
			$this->assertSame( $thrown, $caught );
		}

		$kept = ListenerThrows::taken();

		$this->assertSame( 'LogicException: The gateway stripe was given no context; its key [redacted: a secret or restricted key] was never read.', $kept );
		$this->assertStringNotContainsString( $key, (string) $kept );
		$this->assertNull( ListenerThrows::taken(), 'What was kept is given once.' );
	}

	/**
	 * Tests that a listener that returns is given the action's arguments, answers as it did, and leaves nothing kept.
	 *
	 * @since 0.2.0
	 */
	public function test_a_listener_that_returns_runs_as_before_and_keeps_nothing(): void {
		$callbacks = ListenerThrows::wrap( self::callbacks( static fn( string $registry, int $count ): string => $registry . ' x' . $count ) );

		$this->assertSame( 'the registry x2', self::listenerOf( $callbacks )( 'the registry', 2 ) );
		$this->assertNull( ListenerThrows::taken() );
	}

	/**
	 * Tests that wrapping callbacks wrapped already changes nothing, and that every priority, id and argument count stays in its place, an entry without a listener untouched.
	 *
	 * @since 0.2.0
	 */
	public function test_wrapping_twice_wraps_once_and_keeps_every_entry_in_place(): void {
		$callbacks             = self::callbacks( static fn(): string => 'registered' );
		$callbacks[5]['other'] = array(
			'function'      => 'not_a_function_of_this_test',
			'accepted_args' => 1,
		);

		$once  = ListenerThrows::wrap( $callbacks );
		$twice = ListenerThrows::wrap( $once );

		$this->assertSame( array( 10, 5 ), array_keys( $twice ) );
		$this->assertSame( array( 'listener' ), array_keys( (array) $twice[10] ) );
		$this->assertSame( 1, $twice[10]['listener']['accepted_args'] );
		$this->assertInstanceOf( ListenerThrows::class, $once[10]['listener']['function'] );
		$this->assertSame( $once[10]['listener']['function'], $twice[10]['listener']['function'], 'A second wrap made a second wrapper.' );
		$this->assertSame( $callbacks[5], $twice[5], 'An entry that holds no listener was changed.' );
		$this->assertSame( 'registered', self::listenerOf( $twice )() );
	}

	/**
	 * Returns an action's callbacks holding one listener, at the default priority.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $listener The listener.
	 * @return array<int|string, mixed> The callbacks.
	 */
	private static function callbacks( \Closure $listener ): array {
		return array(
			10 => array(
				'listener' => array(
					'function'      => $listener,
					'accepted_args' => 1,
				),
			),
		);
	}

	/**
	 * Returns the listener the callbacks hold at the default priority.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int|string, mixed> $callbacks The callbacks.
	 * @return callable The listener.
	 */
	private static function listenerOf( array $callbacks ): callable {
		$listener = $callbacks[10]['listener']['function'] ?? null;

		self::assertIsCallable( $listener );

		return $listener;
	}
}
