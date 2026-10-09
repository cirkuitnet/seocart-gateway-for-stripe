<?php
/**
 * SEOCart Gateway for Stripe
 *
 * @package           SEOCart\GatewayForStripe
 * @license           GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       SEOCart Gateway for Stripe
 * Plugin URI:        https://github.com/cirkuitnet/seocart-gateway-for-stripe
 * Description:       SEOCart Gateway for Stripe connects the SEOCart store plugin to Stripe.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  seocart
 * Author:            SEOCart
 * Author URI:        https://github.com/cirkuitnet
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       seocart-gateway-for-stripe
 */

/*
 * This file declares the plugin, registers its class autoloader and registers it with SEOCart,
 * and does nothing else. WordPress loads it before SEOCart, because "seocart-gateway-for-stripe/" sorts before
 * "seocart/" in the list of active plugins, so nothing here may use a SEOCart class, function or
 * constant. An idle request may cost this extension this one file and one hook: no translation
 * call, no database access and no object construction happens at file scope.
 */

defined( 'ABSPATH' ) || exit;

/*
 * A PSR-4 autoloader for the `SEOCart\GatewayForStripe\` namespace, kept in this file so that an idle request
 * loads no other. Requiring plain class-name segments keeps a crafted name from traversing
 * outside src/.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'SEOCart\GatewayForStripe\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );

		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

/*
 * The registration with SEOCart: one hook, named by its string. SEOCart fires this action, with
 * its gateway registry, the first time something needs a payment gateway, and never on a request
 * that needs none. The name is written out because this file runs before SEOCart is loaded;
 * tests/Unit/RegistrationTest.php holds it equal to the constant SEOCart declares it in.
 */
add_action(
	'seocart_register_payment_gateways',
	static function ( \SEOCart\Contracts\Payment\GatewayRegistry $registry ): void {
		$registry->register( new \SEOCart\GatewayForStripe\Gateway( $registry->context( \SEOCart\GatewayForStripe\Gateway::ID ) ) );
	}
);
