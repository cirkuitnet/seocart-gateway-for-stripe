<?php
/**
 * Island: the checkout's card form, Stripe's Payment Element, as a script the checkout loads
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe;

use SEOCart\Checkout\Interfaces\Storefront\PaymentIslands;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Platform\Hooks\EnqueuePaymentIslandsAction;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the card form's script, assets/island.js, to a page that shows the checkout's payment methods and offers Stripe.
 *
 * Owns one fact: the card form's script, how the checkout loads it, and what the readme discloses
 * of Stripe.js, which the script loads in the shopper's browser (stripeJs()). The script is
 * SEOCart's kind of payment island (SEOCart's docs/payment-islands.md), shipped as written: it registers with the checkout,
 * shows Stripe's Payment Element and confirms the order's payment in the shopper's browser.
 *
 * The main file calls listen() once the gateway is registered, which happens only when something
 * needs a payment gateway, so an idle request costs nothing here. A page that shows the payment
 * methods knows which it offers from the registered gateways before it fires SEOCart's action, so
 * the listener is in place by then. SEOCart enqueues the script deferred, in the footer, and only
 * on a page that offers Stripe; Stripe.js itself is loaded by the script, from Stripe, when the
 * shopper first selects Stripe.
 *
 * @since 0.2.0
 */
final class Island {

	/**
	 * The script's handle.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const HANDLE = 'seocart-gateway-for-stripe-island';

	/**
	 * The script, relative to the plugin's directory.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SCRIPT = 'assets/island.js';

	/**
	 * Listens for a page that shows the checkout's payment methods. Adding the listener again changes nothing.
	 *
	 * @since 0.2.0
	 */
	public static function listen(): void {
		add_action( EnqueuePaymentIslandsAction::NAME, array( self::class, 'enqueue' ) );
	}

	/**
	 * Adds the script to the page, under the plugin's URL and version; SEOCart enqueues it when the page offers Stripe.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentIslands $islands The page's payment islands.
	 */
	public static function enqueue( PaymentIslands $islands ): void {
		$main = self::mainFile();

		$islands->add( Gateway::ID, self::HANDLE, plugins_url( self::SCRIPT, $main ), (string) get_file_data( $main, array( 'version' => 'Version' ) )['version'] );
	}

	/**
	 * Declares Stripe.js, the service the card form loads in the shopper's browser, as the readme discloses it.
	 *
	 * The declaration is the disclosure alone: this site never calls the address, its shoppers'
	 * browsers do, so it is no host of the gateway's and the outbound HTTP client never allows it.
	 * tests/Unit/ExternalServicesTest.php renders it into readme.txt's External services section,
	 * beside the gateway's hosts, with SEOCart's own generator; tests/Unit/IslandTest.php holds the
	 * script's address to its endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @return OutboundHost The declaration.
	 */
	public static function stripeJs(): OutboundHost {
		return new OutboundHost(
			id: 'stripe-js',
			service: 'Stripe.js',
			purpose: 'Stripe.js is Stripe\'s script for taking payment details in the browser. SEOCart Gateway for Stripe loads it on the checkout page to show Stripe\'s card form, and to let the card\'s bank confirm a payment there (3D Secure).',
			endpoint: 'https://js.stripe.com/v3/',
			dataSent: 'Sent to Stripe by the shopper\'s browser, never by your site: the card details the shopper types into Stripe\'s form, and the billing address the checkout holds (name, email address, phone number and postal address). Stripe.js also collects information about the browser and device, and sets its own cookies, to prevent fraud.',
			sentWhen: 'On the checkout page, once Stripe is the payment method selected, and when the shopper places the order with it. Nothing is loaded on any other page, or before Stripe is selected.',
			termsUrl: 'https://stripe.com/legal/ssa',
			privacyUrl: 'https://stripe.com/privacy'
		);
	}

	/**
	 * Returns the plugin's main file, whose header holds its version.
	 *
	 * @since 0.2.0
	 *
	 * @return string The path.
	 */
	private static function mainFile(): string {
		return dirname( __DIR__ ) . '/seocart-gateway-for-stripe.php';
	}
}
