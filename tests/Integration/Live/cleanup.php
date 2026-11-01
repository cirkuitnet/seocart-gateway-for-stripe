<?php
/**
 * Ends what a Stripe test mode run left at Stripe, and checks nothing of it is left open
 *
 * Usage: php cleanup.php <settings file> <run list> [endpoints]
 *
 * Cancels every PaymentIntent on the run list that still waits and, given `endpoints`, deletes
 * every webhook endpoint of the run's installation; then reads them all again: the cleanup fails
 * when one is still open or still there, or when Stripe could not be asked. Only the webhooks job
 * sets up an endpoint, and the jobs run at the same time, so only its cleanup is given
 * `endpoints`: another job's would delete the endpoint while the webhook tests use it. Nothing the
 * run did not create is touched (CleanupSelection). Prints counts and ids only.
 * bin/ci/stripe-test-mode.sh runs it at the end of every job that called Stripe, whatever became
 * of the job, and then removes the settings file and the run list.
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForStripe\Tests\Integration\Live\StripeTestApi;
use SEOCart\GatewayForStripe\Tests\Support\Live\CleanupSelection;
use SEOCart\GatewayForStripe\Tests\Support\Live\LiveRun;
use SEOCart\GatewayForStripe\WebhookEndpoints;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// The unit tests' bootstrap: SEOCart's development autoloader and this plugin's src/ and tests/.
require dirname( __DIR__, 2 ) . '/bootstrap.php';

if ( ! in_array( $argv[3] ?? '', array( '', 'endpoints' ), true ) ) {
	fwrite( STDERR, "usage: php cleanup.php <settings file> <run list> [endpoints]\n" );
	exit( 2 );
}

$seocart_gateway_for_stripe_settings  = (array) json_decode( is_readable( $argv[1] ?? '' ) ? (string) file_get_contents( $argv[1] ) : '', true );
$seocart_gateway_for_stripe_run_list  = CleanupSelection::runList( is_readable( $argv[2] ?? '' ) ? (string) file_get_contents( $argv[2] ) : '' );
$seocart_gateway_for_stripe_endpoints = 'endpoints' === ( $argv[3] ?? '' );

if ( ! is_string( $seocart_gateway_for_stripe_settings['secret_key'] ?? null ) ) {
	printf( "cleanup: no settings file, so the run made no request to Stripe; %d intents on the run list.\n", count( $seocart_gateway_for_stripe_run_list ) );
	exit( array() === $seocart_gateway_for_stripe_run_list ? 0 : 1 );
}

$seocart_gateway_for_stripe_api      = new StripeTestApi( $seocart_gateway_for_stripe_settings['secret_key'] );
$seocart_gateway_for_stripe_failures = array();

/**
 * Reads the run's PaymentIntents as Stripe holds them now and, when asked, the run's installation's webhook endpoints.
 *
 * @since 0.2.0
 *
 * @param StripeTestApi         $api       The client.
 * @param array<string, string> $runList   The run's intents.
 * @param bool                  $endpoints Whether to read the webhook endpoints too; none are read otherwise.
 * @param string[]              $failures  Each request Stripe did not answer, to which this adds.
 * @return array{list<array<mixed>>, list<string>} The intents, and the ids of the installation's endpoints.
 * @phpstan-param list<string> $failures
 */
function seocart_gateway_for_stripe_read_back( StripeTestApi $api, array $runList, bool $endpoints, array &$failures ): array {
	$intents = array();
	$ours    = array();

	foreach ( array_keys( $runList ) as $id ) {
		try {
			$intents[] = $api->get( 'payment_intents/' . $id );
		} catch ( \RuntimeException $failed ) {
			$failures[] = $failed->getMessage();
		}
	}

	if ( $endpoints ) {
		try {
			$ours = CleanupSelection::endpointsToDelete( $api->all( 'webhook_endpoints' ), LiveRun::installUuid(), WebhookEndpoints::TAG_INSTALL );
		} catch ( \RuntimeException $failed ) {
			$failures[] = $failed->getMessage();
		}
	}

	return array( $intents, $ours );
}

list( $seocart_gateway_for_stripe_intents, $seocart_gateway_for_stripe_delete ) = seocart_gateway_for_stripe_read_back( $seocart_gateway_for_stripe_api, $seocart_gateway_for_stripe_run_list, $seocart_gateway_for_stripe_endpoints, $seocart_gateway_for_stripe_failures );

$seocart_gateway_for_stripe_cancel = CleanupSelection::intentsToCancel( $seocart_gateway_for_stripe_run_list, $seocart_gateway_for_stripe_intents );

foreach ( $seocart_gateway_for_stripe_cancel as $seocart_gateway_for_stripe_id ) {
	try {
		$seocart_gateway_for_stripe_api->post( 'payment_intents/' . $seocart_gateway_for_stripe_id . '/cancel' );
	} catch ( \RuntimeException $seocart_gateway_for_stripe_failed ) {
		$seocart_gateway_for_stripe_failures[] = $seocart_gateway_for_stripe_failed->getMessage();
	}
}

foreach ( $seocart_gateway_for_stripe_delete as $seocart_gateway_for_stripe_id ) {
	try {
		$seocart_gateway_for_stripe_api->delete( 'webhook_endpoints/' . $seocart_gateway_for_stripe_id );
	} catch ( \RuntimeException $seocart_gateway_for_stripe_failed ) {
		$seocart_gateway_for_stripe_failures[] = $seocart_gateway_for_stripe_failed->getMessage();
	}
}

list( $seocart_gateway_for_stripe_intents, $seocart_gateway_for_stripe_left ) = seocart_gateway_for_stripe_read_back( $seocart_gateway_for_stripe_api, $seocart_gateway_for_stripe_run_list, $seocart_gateway_for_stripe_endpoints, $seocart_gateway_for_stripe_failures );

$seocart_gateway_for_stripe_open = CleanupSelection::intentsToCancel( $seocart_gateway_for_stripe_run_list, $seocart_gateway_for_stripe_intents );

printf(
	"cleanup: %1\$d intents on the run list, %2\$d cancelled; afterwards %3\$d intents still open (%4\$s). %5\$s\n",
	count( $seocart_gateway_for_stripe_run_list ),
	count( $seocart_gateway_for_stripe_cancel ),
	count( $seocart_gateway_for_stripe_open ),
	implode( ', ', $seocart_gateway_for_stripe_open ),
	$seocart_gateway_for_stripe_endpoints
		? sprintf( "%1\$d webhook endpoints of the run's installation deleted, %2\$d left.", count( $seocart_gateway_for_stripe_delete ), count( $seocart_gateway_for_stripe_left ) )
		: 'Webhook endpoints are left to the webhooks job, which alone sets one up.'
);

foreach ( $seocart_gateway_for_stripe_failures as $seocart_gateway_for_stripe_failure ) {
	printf( "::error::%s\n", $seocart_gateway_for_stripe_failure ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line script's message, built by MessageGuard.
}

exit( array() === $seocart_gateway_for_stripe_failures && array() === $seocart_gateway_for_stripe_open && array() === $seocart_gateway_for_stripe_left ? 0 : 1 );
