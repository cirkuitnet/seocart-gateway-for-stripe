<?php
/**
 * Tests when the gateway says it can take a payment
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForStripe\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The gateway's isAvailable() narrows the matrix by the publishable key's mode and Stripe's smallest and largest amounts, and is
 * never looser than the matrix.
 *
 * Planted violation, shown red and removed: isAvailable() returning true whatever the currency: the
 * never-looser test fails for JPY.
 *
 * @since 0.2.0
 */
final class AvailabilityTest extends GatewayTestCase {

	/**
	 * Tests a payment of 10.00 in each currency, for a US account in test mode.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_in_usd_gbp_or_eur_is_available(): void {
		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			$this->assertTrue( $this->gateway->isAvailable( self::payment( 1000, $code ) ), $code );
		}
	}

	/**
	 * Tests the publishable key: it must be a key of the payment's mode.
	 *
	 * @since 0.2.0
	 */
	public function test_the_publishable_key_must_be_the_modes(): void {
		$this->assertFalse( $this->gateway->isAvailable( self::payment( 1000, 'USD', Mode::Test, array( 'publishable_key' => 'pk_live_x' ) ) ) );
		$this->assertTrue( $this->gateway->isAvailable( self::payment( 1000, 'USD', Mode::Live, array( 'publishable_key' => 'pk_live_x' ) ) ) );
		$this->assertFalse( $this->gateway->isAvailable( self::payment( 1000, 'USD', Mode::Test, array( 'publishable_key' => null ) ) ) );
		$this->assertFalse( $this->gateway->isAvailable( self::payment( 1000, 'USD', Mode::Test, array( 'publishable_key' => 'sk_test_x' ) ) ), 'A secret key is no publishable key.' );
	}

	/**
	 * Tests the smallest and largest amounts.
	 *
	 * @since 0.2.0
	 */
	public function test_the_amount_must_be_one_stripe_takes(): void {
		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			$this->assertFalse( $this->gateway->isAvailable( self::payment( 49, $code ) ), "49 minor units of {$code}" );
			$this->assertTrue( $this->gateway->isAvailable( self::payment( 50, $code ) ), "50 minor units of {$code}" );
			$this->assertTrue( $this->gateway->isAvailable( self::payment( 99999999, $code ) ), "the largest {$code}" );
			$this->assertFalse( $this->gateway->isAvailable( self::payment( 100000000, $code ) ), "one more than the largest {$code}" );
		}
	}

	/**
	 * Tests that the gateway never takes a payment its matrix does not allow, over currencies and account countries.
	 *
	 * @since 0.2.0
	 */
	public function test_availability_is_never_looser_than_the_matrix(): void {
		$matrix = $this->gateway->describe()->matrix;

		foreach ( array( 'USD', 'GBP', 'EUR', 'JPY', 'CAD' ) as $code ) {
			foreach ( array( 'US', 'GB', null ) as $country ) {
				$payment = self::payment( 1000, $code, Mode::Test, array( 'account_country' => $country ) );

				if ( ! $matrix->available( $payment ) ) {
					$this->assertFalse( $this->gateway->isAvailable( $payment ), "{$code} for " . ( $country ?? 'no country' ) );
				}
			}
		}
	}

	/**
	 * Tests an amount in another currency than the payment's.
	 *
	 * @since 0.2.0
	 */
	public function test_an_amount_in_another_currency_is_not_available(): void {
		$payment = new AvailabilityContext( Currency::of( 'USD' ), Money::of( 1000, Currency::of( 'EUR' ) ), 'US', 'storefront', Mode::Test, self::account() );

		$this->assertFalse( $this->gateway->isAvailable( $payment ) );
	}

	/**
	 * Builds a payment's availability context.
	 *
	 * @since 0.2.0
	 *
	 * @param int                            $minor   The amount, in minor units.
	 * @param string                         $code    The currency.
	 * @param Mode                           $mode    Optional. The mode. Default test.
	 * @param array<string, int|string|null> $account Optional. Settings to change. Default none.
	 * @return AvailabilityContext The context.
	 */
	private static function payment( int $minor, string $code, Mode $mode = Mode::Test, array $account = array() ): AvailabilityContext {
		return new AvailabilityContext( Currency::of( $code ), Money::of( $minor, Currency::of( $code ) ), 'US', 'storefront', $mode, $account + self::account() );
	}

	/**
	 * Returns the account settings of a configured test mode.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|string|null> The settings that are not credentials.
	 */
	private static function account(): array {
		return array(
			'publishable_key' => 'pk_test_unitTestKey',
			'account_country' => 'US',
		);
	}
}
