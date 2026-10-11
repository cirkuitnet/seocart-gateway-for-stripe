<?php
/**
 * RecordingLogger: a logger that keeps every line, for tests
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support;

use SEOCart\Contracts\Logger;

/**
 * Keeps every line the gateway writes, as SEOCart's logger receives it, before any redaction.
 *
 * Holding the lines unredacted is the point: a test proves the gateway never hands the logger
 * card data or a secret, rather than relying on SEOCart's redactor to remove it.
 *
 * @since 0.2.0
 */
final class RecordingLogger implements Logger {

	/**
	 * Every line, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{level: string, code: string, message: string, context: array<mixed>}>
	 */
	public array $lines = array();

	/**
	 * Keeps a debug line.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	public function debug( string $code, string $message, array $context = array() ): void {
		$this->keep( 'debug', $code, $message, $context );
	}

	/**
	 * Keeps an info line.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	public function info( string $code, string $message, array $context = array() ): void {
		$this->keep( 'info', $code, $message, $context );
	}

	/**
	 * Keeps a warning line.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	public function warning( string $code, string $message, array $context = array() ): void {
		$this->keep( 'warning', $code, $message, $context );
	}

	/**
	 * Keeps an error line.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	public function error( string $code, string $message, array $context = array() ): void {
		$this->keep( 'error', $code, $message, $context );
	}

	/**
	 * Returns the codes written, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The codes.
	 */
	public function codes(): array {
		return array_map( static fn( array $line ): string => $line['code'], $this->lines );
	}

	/**
	 * Returns the context of the first line with a code.
	 *
	 * @since 0.2.0
	 *
	 * @param string $code The code.
	 * @return array<mixed>|null The context; null when no line has the code.
	 */
	public function contextOf( string $code ): ?array {
		foreach ( $this->lines as $line ) {
			if ( $code === $line['code'] ) {
				return $line['context'];
			}
		}

		return null;
	}

	/**
	 * Keeps a line.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $level   The level.
	 * @param string       $code    The code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	private function keep( string $level, string $code, string $message, array $context ): void {
		$this->lines[] = array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
			'context' => $context,
		);
	}
}
