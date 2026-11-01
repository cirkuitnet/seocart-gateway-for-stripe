<?php
/**
 * LiveRun: what the live tests, their cleanup and the workflow's script share of one run against Stripe
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

/**
 * Names the files one run against Stripe's test mode writes, and the installation it runs as.
 *
 * The workflow's script, bin/ci/stripe-test-mode.sh, creates the files in the runner's temporary
 * directory and names them in the environment; the live tests append to them; the cleanup and
 * the verdict read them.
 * The run list holds, per line, the Stripe id and store uuid of each PaymentIntent the run
 * created, and nothing else, so the cleanup cancels what the run left waiting. The report holds
 * `key=value` lines of what the run's summary shows: counts, the events' API versions, the
 * soak's seed; never a body, a secret or an address with a query.
 *
 * @since 0.2.0
 */
final class LiveRun {

	/**
	 * The environment variable naming the run list.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RUN_LIST = 'SEOCART_LIVE_RUN_LIST';

	/**
	 * The environment variable naming the report.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REPORT = 'SEOCART_LIVE_REPORT';

	/**
	 * The environment variable giving the soak's size, as the workflow's input gives it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SOAK_SIZE = 'SEOCART_SOAK_SIZE';

	/**
	 * The environment variable giving the soak's seed, to repeat a run; a fresh one is drawn without it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SOAK_SEED = 'SEOCART_SOAK_SEED';

	/**
	 * The environment variable naming the browser the 3-D Secure script launches, for a machine Playwright downloads none for; empty for Playwright's own.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BROWSER = 'SEOCART_LIVE_BROWSER';

	/**
	 * The repository whose name the run's installation uuid is made from.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REPOSITORY_URL = 'https://github.com/cirkuitnet/seocart-gateway-for-stripe';

	/**
	 * RFC 9562's namespace of URLs, in which the installation uuid is a name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const URL_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

	/**
	 * Returns the uuid every run's site has as its installation: the version-5 uuid of the repository's address.
	 *
	 * Every run provisions its webhook endpoint as this one installation, so the endpoint a run that
	 * crashed left behind is found as this installation's: the next run's provisioning replaces it,
	 * and the cleanup of every run's webhooks job deletes it. An endpoint of any other installation
	 * is never touched.
	 *
	 * @since 0.2.0
	 *
	 * @return string The uuid.
	 */
	public static function installUuid(): string {
		$hash = sha1( (string) hex2bin( str_replace( '-', '', self::URL_NAMESPACE ) ) . self::REPOSITORY_URL );

		return sprintf(
			'%s-%s-%04x-%04x-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			( (int) hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000,
			( (int) hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000,
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * Returns the file an environment variable names.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the variable names no file that can be written.
	 *
	 * @param string $variable The variable.
	 * @return string The file.
	 */
	public static function file( string $variable ): string {
		$file = (string) getenv( $variable );

		if ( '' === $file || ! is_writable( file_exists( $file ) ? $file : dirname( $file ) ) ) {
			throw new \RuntimeException( sprintf( 'The live tests write to the file %s names: bin/ci/stripe-test-mode.sh sets it.', $variable ) );
		}

		return $file;
	}

	/**
	 * Appends a line to the file an environment variable names, readable by its owner alone.
	 *
	 * @since 0.2.0
	 *
	 * @param string $variable The variable.
	 * @param string $line     The line, with its newline.
	 */
	public static function append( string $variable, string $line ): void {
		$file = self::file( $variable );

		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
		chmod( $file, 0600 );
	}

	/**
	 * Adds a fact to the run's report, its value made safe to print.
	 *
	 * @since 0.2.0
	 *
	 * @param string     $key   The fact, such as `api-versions`: letters, digits and `-`.
	 * @param string|int $value Its value, on one line.
	 */
	public static function report( string $key, string|int $value ): void {
		self::append( self::REPORT, MessageGuard::say( "%s=%s\n", $key, str_replace( array( "\r", "\n" ), ' ', (string) $value ) ) );
	}

	/**
	 * Reads a report: its facts, the last value of each.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The report's text.
	 * @return array<string, string> The facts, by key.
	 */
	public static function facts( string $text ): array {
		$facts = array();

		foreach ( explode( "\n", $text ) as $line ) {
			if ( 1 === preg_match( '/^([a-z][a-z0-9\-]*)=(.*)$/D', $line, $match ) ) {
				$facts[ $match[1] ] = $match[2];
			}
		}

		return $facts;
	}
}
