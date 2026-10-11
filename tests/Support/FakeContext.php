<?php
/**
 * FakeContext: what SEOCart gives the gateway, for unit tests
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\HttpClient;
use SEOCart\Contracts\Logger;
use SEOCart\Contracts\Payment\CredentialUnavailable;
use SEOCart\Contracts\Payment\GatewaySettings;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Support\Clock;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The context a unit test builds the gateway with: the fake Stripe client, a recording logger, a frozen clock and settings per mode.
 *
 * The settings hold fake keys of the right mode for each mode, which a test may replace; every
 * credential read is counted by mode and name.
 *
 * @since 0.2.0
 */
final class FakeContext implements ExtensionContext {

	/**
	 * The fake secret key of the test mode: the shape of a test key, nothing more.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TEST_KEY = 'sk_test_unitTestKey';

	/**
	 * The fake secret key of the live mode.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIVE_KEY = 'rk_live_unitTestKey';

	/**
	 * The fake webhook signing secret of the test mode.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TEST_SIGNING_SECRET = 'whsec_unitTestSecret';

	/**
	 * The fake webhook signing secret of the live mode.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIVE_SIGNING_SECRET = 'whsec_unitLiveSecret';

	/**
	 * The fake Stripe.
	 *
	 * @since 0.2.0
	 *
	 * @var FakeStripe
	 */
	public FakeStripe $stripe;

	/**
	 * The logger.
	 *
	 * @since 0.2.0
	 *
	 * @var RecordingLogger
	 */
	public RecordingLogger $log;

	/**
	 * The clock.
	 *
	 * @since 0.2.0
	 *
	 * @var FrozenClock
	 */
	public FrozenClock $clock;

	/**
	 * The settings of each mode, by mode and then name: values and credentials alike.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $settings;

	/**
	 * Every credential read, as `mode/name`, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public array $secretReads = array();

	/**
	 * Builds the context, with the clock at a fixed instant.
	 *
	 * @since 0.2.0
	 */
	public function __construct() {
		$this->stripe   = new FakeStripe();
		$this->log      = new RecordingLogger();
		$this->clock    = new FrozenClock( new \DateTimeImmutable( '2026-10-09 12:00:00', new \DateTimeZone( 'UTC' ) ) );
		$this->settings = array(
			'test' => array(
				'secret_key'      => self::TEST_KEY,
				'publishable_key' => 'pk_test_unitTestKey',
				'webhook_secret'  => self::TEST_SIGNING_SECRET,
				'account_country' => 'US',
			),
			'live' => array(
				'secret_key'      => self::LIVE_KEY,
				'publishable_key' => 'pk_live_unitTestKey',
				'webhook_secret'  => self::LIVE_SIGNING_SECRET,
				'account_country' => 'US',
			),
		);
	}

	/**
	 * Returns the extension's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string `stripe`.
	 */
	public function extensionId(): string {
		return 'stripe';
	}

	/**
	 * Returns the contract version.
	 *
	 * @since 0.2.0
	 *
	 * @return string PaymentGateway::CONTRACT_VERSION.
	 */
	public function contractVersion(): string {
		return PaymentGateway::CONTRACT_VERSION;
	}

	/**
	 * Returns the recording logger.
	 *
	 * @since 0.2.0
	 *
	 * @return Logger The logger.
	 */
	public function logger(): Logger {
		return $this->log;
	}

	/**
	 * Returns the frozen clock.
	 *
	 * @since 0.2.0
	 *
	 * @return Clock The clock.
	 */
	public function clock(): Clock {
		return $this->clock;
	}

	/**
	 * Returns the fake Stripe.
	 *
	 * @since 0.2.0
	 *
	 * @return HttpClient The client.
	 */
	public function http(): HttpClient {
		return $this->stripe;
	}

	/**
	 * Returns a mode's settings, read from this context when asked.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return GatewaySettings The settings.
	 */
	public function settings( Mode $mode ): GatewaySettings {
		$context = $this;

		return new class( $context, $mode ) implements GatewaySettings {

			/**
			 * Records which context and mode to read.
			 *
			 * @param FakeContext $context The context.
			 * @param Mode        $mode    The mode.
			 */
			public function __construct( private FakeContext $context, private Mode $mode ) {
			}

			/**
			 * Returns a setting.
			 *
			 * @param string $name The name.
			 * @return string|null The value.
			 */
			public function value( string $name ): ?string {
				return $this->context->settings[ $this->mode->value ][ $name ] ?? null;
			}

			/**
			 * Returns a credential, counting the read.
			 *
			 * @throws CredentialUnavailable When it is not set.
			 *
			 * @param string $name The name.
			 * @return string The credential.
			 */
			public function secret( string $name ): string {
				$this->context->secretReads[] = $this->mode->value . '/' . $name;

				return $this->context->settings[ $this->mode->value ][ $name ] ?? throw new CredentialUnavailable( CredentialUnavailable::MISSING, $name );
			}
		};
	}

	/**
	 * Returns the credential values of both modes, which no log line may hold.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The values.
	 */
	public function credentials(): array {
		$values = array();

		foreach ( $this->settings as $settings ) {
			$values[] = $settings['secret_key'] ?? '';
			$values[] = $settings['webhook_secret'] ?? '';
		}

		return array_values( array_filter( $values ) );
	}
}
