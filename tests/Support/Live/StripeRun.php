<?php
/**
 * StripeRun: what the live tests, their cleanup and the workflow's script share of one run against Stripe, beside SEOCart's
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tests\Conformance\Payment\Live\MessageGuard;

/**
 * Names the soak's environment and the installation a run against Stripe's test mode runs as, and gives the live tests the plugin's declaration and its guard.
 *
 * The run's files, its report and the guard are SEOCart's (tests/Conformance/Payment/Live), built
 * from this plugin's tests/live.php. What is Stripe's alone is here: the soak's size, which the
 * workflow's soak-size step checks and gives to the webhooks job, and its seed; and the installation
 * every run's webhook endpoint is set up as.
 *
 * @since 0.2.0
 */
final class StripeRun {

	/**
	 * The environment variable giving the soak's size, as the workflow's soak-size step checked it.
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
	 * Where GitHub's addresses of repositories begin: the repository's `owner/name` follows.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const GITHUB = 'https://github.com/';

	/**
	 * RFC 9562's namespace of URLs, in which the installation uuid is a name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const URL_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

	/**
	 * Returns the uuid every run's site has as its installation: the version-5 uuid of the repository's address on GitHub, the repository the declaration derives from composer.json.
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
		$hash = sha1( (string) hex2bin( str_replace( '-', '', self::URL_NAMESPACE ) ) . self::GITHUB . self::declaration()->repository() );

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
	 * Returns the plugin's declaration of its live workflow, tests/live.php.
	 *
	 * @since 0.2.0
	 *
	 * @return LiveDeclaration The declaration.
	 */
	public static function declaration(): LiveDeclaration {
		return LiveDeclaration::load( dirname( __DIR__, 3 ) );
	}

	/**
	 * Returns the guard every message of the live tests is built through, with Stripe's shapes.
	 *
	 * @since 0.2.0
	 *
	 * @return MessageGuard The guard.
	 */
	public static function guard(): MessageGuard {
		return self::declaration()->guard();
	}
}
