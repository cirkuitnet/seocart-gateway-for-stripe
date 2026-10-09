<?php
/**
 * Bootstrap for the unit tests of SEOCart Gateway for Stripe
 *
 * The extension has no development dependencies of its own. SEOCart's unit bootstrap, in the
 * SEOCart checkout beside this one, defines a placeholder ABSPATH and loads SEOCart's
 * development autoloader (PHPUnit, Brain Monkey, SEOCart and its test support); this file adds
 * the extension's own namespace to that autoloader. The integration suite runs on SEOCart's
 * integration bootstrap instead (sh ../seocart/bin/ci/extension.sh integration .).
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

require dirname( __DIR__, 2 ) . '/seocart/tests/bootstrap-unit.php';

/**
 * SEOCart's development autoloader, which the line above registered.
 *
 * @var \Composer\Autoload\ClassLoader $seocart_gateway_for_stripe_loader
 */
$seocart_gateway_for_stripe_loader = require dirname( __DIR__, 2 ) . '/seocart/vendor/autoload.php';
$seocart_gateway_for_stripe_loader->addPsr4( 'SEOCart\GatewayForStripe\\', dirname( __DIR__ ) . '/src/' );
