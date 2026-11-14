<?php
/**
 * Tests that a page offering Stripe loads the checkout's card form, once the gateway is registered
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration;

use SEOCart\Checkout\Interfaces\Storefront\PaymentIslands;
use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\GatewayForStripe\Gateway;
use SEOCart\GatewayForStripe\Island;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Platform\Hooks\EnqueuePaymentIslandsAction;
use SEOCart\Platform\Kernel\Kernel;
use WP_UnitTestCase;

/**
 * Runs on SEOCart's integration bootstrap, with this extension loaded and then SEOCart. The main file's registration, run as
 * SEOCart runs it, has the card form listen for SEOCart's islands action; SEOCart firing the action for a page that offers Stripe
 * then enqueues the script under the plugin's URL and version, deferred and in the footer, and for a page that does not, nothing.
 *
 * The registration is fired in the test itself, with a registry of the test's: the hooks a test adds are removed after it, while
 * SEOCart's own registry registers its gateways once a process, perhaps in an earlier test.
 *
 * Planted violation, shown red and removed: the main file's registration without its call to Island::listen(): the script is
 * not registered.
 *
 * @since 0.2.0
 */
final class IslandEnqueueTest extends WP_UnitTestCase {

	/**
	 * Removes the script the test enqueued.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		wp_dequeue_script( Island::HANDLE );
		wp_deregister_script( Island::HANDLE );

		parent::tear_down();
	}

	/**
	 * Tests that the islands action, fired after the registration for a page that offers Stripe, enqueues the card form deferred, in the footer, under the plugin's URL and version.
	 *
	 * @since 0.2.0
	 */
	public function test_a_page_offering_stripe_loads_the_card_form_deferred(): void {
		$this->assertSame( array( Gateway::ID ), $this->registerAsSEOCartDoes() );

		$this->fireIslandsAction( array( Gateway::ID ) );

		$script = wp_scripts()->registered[ Island::HANDLE ] ?? self::fail( 'The card form was not registered.' );
		$main   = dirname( __DIR__, 2 ) . '/seocart-gateway-for-stripe.php';

		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', (string) file_get_contents( $main ), $version ) );
		$this->assertTrue( wp_script_is( Island::HANDLE, 'enqueued' ) );
		$this->assertSame( plugins_url( 'assets/island.js', $main ), $script->src, 'The script is the plugin\'s own, under its URL.' );
		$this->assertStringEndsWith( '/assets/island.js', $script->src );
		$this->assertSame( array( $version[1], array() ), array( $script->ver, $script->deps ) );
		$this->assertSame( 'defer', wp_scripts()->get_data( Island::HANDLE, 'strategy' ), 'Deferred: it runs once the page is parsed.' );
		$this->assertSame( 1, wp_scripts()->get_data( Island::HANDLE, 'group' ), 'Printed in the footer.' );
	}

	/**
	 * Tests that a page that does not offer Stripe loads nothing of it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_page_not_offering_stripe_loads_nothing(): void {
		$this->registerAsSEOCartDoes();

		$this->fireIslandsAction( array() );

		$this->assertFalse( wp_script_is( Island::HANDLE, 'registered' ) );
	}

	/**
	 * Fires SEOCart's registration action with a registry of the test's, as SEOCart fires it with its own, and returns the ids registered.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The ids.
	 */
	private function registerAsSEOCartDoes(): array {
		$context  = $this->createStub( ExtensionContext::class );
		$registry = new class( $context ) implements GatewayRegistry {

			/**
			 * The ids registered.
			 *
			 * @var list<string>
			 */
			public array $ids = array();

			/**
			 * Creates the registry.
			 *
			 * @param ExtensionContext $context The context every gateway is given.
			 */
			public function __construct( private ExtensionContext $context ) {
			}

			/**
			 * Supports every contract.
			 *
			 * @param string $writtenAgainst The version.
			 * @return bool True.
			 */
			public function supportsContract( string $writtenAgainst ): bool {
				return true;
			}

			/**
			 * Returns the context.
			 *
			 * @param string $gatewayId The gateway's id.
			 * @return ExtensionContext The context.
			 */
			public function context( string $gatewayId ): ExtensionContext {
				return $this->context;
			}

			/**
			 * Records the id.
			 *
			 * @param PaymentGateway $gateway The gateway.
			 */
			public function register( PaymentGateway $gateway ): void {
				$this->ids[] = $gateway->describe()->id;
			}
		};

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SEOCart's own action, named by its declaration.
		do_action( GatewayRegistry::ACTION, $registry );

		return $registry->ids;
	}

	/**
	 * Fires SEOCart's islands action for a page that offers the methods given, with SEOCart's own registry of gateways.
	 *
	 * @since 0.2.0
	 *
	 * @param array $offered The ids of the methods the page offers.
	 *
	 * @phpstan-param list<string> $offered
	 */
	private function fireIslandsAction( array $offered ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SEOCart's own action, named by its declaration.
		do_action( EnqueuePaymentIslandsAction::NAME, new PaymentIslands( Kernel::container()->get( Gateways::class ), $offered ) );
	}
}
