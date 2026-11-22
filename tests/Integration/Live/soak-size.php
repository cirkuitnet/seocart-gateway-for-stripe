<?php
/**
 * Checks the soak's size, as the Stripe test mode workflow's soak-size input gives it, and gives it to the job's later steps
 *
 * Usage: php soak-size.php <size>
 *
 * A size the soak takes (SoakPlan::size()) is added to the file GITHUB_ENV names as
 * `SEOCART_SOAK_SIZE=<size>`, so every later step of the job has it: the webhooks job's live tests
 * run after its settings step, in a step that takes no env of its own. Outside GitHub Actions it
 * goes to the standard output. Any other size is refused, and nothing is written.
 * bin/ci/stripe-test-mode.sh runs it as its soak-size step, before the settings file is written.
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForStripe\Tests\Support\Live\SoakPlan;
use SEOCart\GatewayForStripe\Tests\Support\Live\StripeRun;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// The step runs before the job's set-up, on the runner's PHP, with no autoloader: both classes are required by path.
require_once dirname( __DIR__, 2 ) . '/Support/Live/SoakPlan.php';
require_once dirname( __DIR__, 2 ) . '/Support/Live/StripeRun.php';

try {
	$seocart_gateway_for_stripe_size = SoakPlan::size( $argv[1] ?? '' );
} catch ( \InvalidArgumentException $seocart_gateway_for_stripe_refused ) {
	fwrite( STDOUT, '::error::The soak-size input is refused: ' . $seocart_gateway_for_stripe_refused->getMessage() . "\n" );
	exit( 1 );
}

$seocart_gateway_for_stripe_env = fopen( '' === (string) getenv( 'GITHUB_ENV' ) ? 'php://stdout' : (string) getenv( 'GITHUB_ENV' ), 'ab' );

if ( false === $seocart_gateway_for_stripe_env || false === fwrite( $seocart_gateway_for_stripe_env, sprintf( "%s=%d\n", StripeRun::SOAK_SIZE, $seocart_gateway_for_stripe_size ) ) ) {
	fwrite( STDERR, "soak-size: the job's environment file cannot be written.\n" );
	exit( 1 );
}

exit( 0 );
