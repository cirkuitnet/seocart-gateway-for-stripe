<?php
/**
 * Tests that SEOCart accepts the gateway when it asks for its gateways
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Integration;

use SEOCart\GatewayForStripe\Gateway;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Application\CredentialState;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use WP_UnitTestCase;

/**
 * Runs on SEOCart's integration bootstrap, with this extension loaded and then SEOCart, as
 * WordPress loads active plugins. SEOCart's own registry fires the registration action the first
 * time it is asked for a gateway, so asking for this one proves the main file's hook is the one
 * SEOCart fires, and that SEOCart accepts the gateway it builds: a gateway it refuses is logged
 * and left out.
 *
 * @since 0.1.0
 * @since 0.2.0 The gateway declares settings: unconfigured, it is registered but not offered.
 */
final class RegistersWithSEOCartTest extends WP_UnitTestCase {

	/**
	 * Tests that the gateway is registered with its declaration, written against a contract SEOCart supports, and not offered while unconfigured.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Reads the declaration SEOCart keeps; unconfigured, the gateway is not offered.
	 */
	public function test_seocart_registers_the_gateway_and_does_not_offer_it_unconfigured(): void {
		$registry = Kernel::container()->get( Gateways::class );
		$declared = $registry->descriptor( Gateway::ID );

		$this->assertSame( array(), $registry->refused(), 'SEOCart refused a registration.' );
		$this->assertContains( Gateway::ID, $registry->ids() );
		$this->assertSame( 'Stripe', $declared->label() );
		$this->assertSame( array( Mode::Test, Mode::Live ), $declared->modes );
		$this->assertTrue( $registry->supportsContract( $declared->contract ), "SEOCart does not support the contract {$declared->contract} this gateway is written against." );
		$this->assertSame( ( new Gateway( $registry->context( Gateway::ID ) ) )->webhookEvents(), $registry->webhookEvents( Gateway::ID ), 'SEOCart took the events the endpoint subscribes to.' );
		$this->assertSame( CredentialState::Missing, $registry->credentials( Gateway::ID, Mode::Test ) );

		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			$this->assertNull( $registry->availableMode( Gateway::ID, Money::of( 1000, Currency::of( $code ) ), 'US', 'storefront' ), "SEOCart must not offer the gateway in {$code} unconfigured." );
		}
	}
}
