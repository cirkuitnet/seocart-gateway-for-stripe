<?php
/**
 * Runs one of the Stripe test mode workflow's PHP steps, for bin/ci/stripe-test-mode.sh
 *
 * Usage:
 *   php workflow-step.php redact                    copies the standard input to the output, credentials blanked out
 *   php workflow-step.php suite <output>            checks the conformance suite's run against Stripe
 *   php workflow-step.php harvest <tapes> <mark> <run list>
 *                                                   adds the PaymentIntents the suite created to the run list,
 *                                                   from the tapes it recorded after the mark was made
 *   php workflow-step.php live <part> <output> <report>
 *                                                   checks a run of a part's live tests
 *   php workflow-step.php soak-size <size>          checks the soak's size
 *   php workflow-step.php verdict                   judges the run: the jobs' results and outputs in
 *                                                   STRIPE_TEST_MODE_NEEDS, the parts in STRIPE_TEST_MODE_PARTS
 *
 * Outputs go to the file GITHUB_OUTPUT names, and the verdict's table to the one
 * GITHUB_STEP_SUMMARY names; to the standard output where neither is set. Needs no WordPress and
 * no SEOCart: the verdict's job checks out the extension alone.
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForStripe\Tests\Support\Live\WorkflowStep;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

foreach ( array( 'MessageGuard', 'LiveRun', 'CleanupSelection', 'SoakPlan', 'SuiteOutput', 'Verdict', 'WorkflowStep' ) as $seocart_gateway_for_stripe_class ) {
	require_once dirname( __DIR__, 2 ) . '/Support/Live/' . $seocart_gateway_for_stripe_class . '.php';
}

$seocart_gateway_for_stripe_outputs = fopen( '' === (string) getenv( 'GITHUB_OUTPUT' ) ? 'php://stdout' : (string) getenv( 'GITHUB_OUTPUT' ), 'ab' );
$seocart_gateway_for_stripe_summary = fopen( '' === (string) getenv( 'GITHUB_STEP_SUMMARY' ) ? 'php://stdout' : (string) getenv( 'GITHUB_STEP_SUMMARY' ), 'ab' );
$seocart_gateway_for_stripe_read    = static fn( int $position ): string => is_readable( $argv[ $position ] ?? '' ) ? (string) file_get_contents( $argv[ $position ] ) : '';

if ( false === $seocart_gateway_for_stripe_outputs || false === $seocart_gateway_for_stripe_summary ) {
	fwrite( STDERR, "workflow-step: the outputs or the summary cannot be written.\n" );
	exit( 1 );
}

if ( ! in_array( $argv[1] ?? '', array( 'redact', 'suite', 'harvest', 'live', 'soak-size', 'verdict' ), true ) ) {
	fwrite( STDERR, "usage: php workflow-step.php redact | suite <output> | harvest <tapes> <mark> <run list> | live <part> <output> <report> | soak-size <size> | verdict\n" );
	exit( 2 );
}

exit(
	match ( $argv[1] ) {
		'redact'    => WorkflowStep::redact( STDIN, STDOUT ),
		'suite'     => WorkflowStep::suite( $seocart_gateway_for_stripe_read( 2 ), $seocart_gateway_for_stripe_outputs, STDOUT ),
		'harvest'   => WorkflowStep::harvest( $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', STDOUT ),
		'live'      => WorkflowStep::live( $argv[2] ?? '', $seocart_gateway_for_stripe_read( 3 ), $seocart_gateway_for_stripe_read( 4 ), $seocart_gateway_for_stripe_outputs, STDOUT ),
		'soak-size' => WorkflowStep::soakSize( $argv[2] ?? '', STDOUT ),
		'verdict'   => WorkflowStep::verdict( (string) getenv( 'STRIPE_TEST_MODE_NEEDS' ), (string) getenv( 'STRIPE_TEST_MODE_PARTS' ), $seocart_gateway_for_stripe_summary, STDOUT ),
	}
);
