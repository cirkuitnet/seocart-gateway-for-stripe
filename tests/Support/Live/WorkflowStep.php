<?php
/**
 * WorkflowStep: the Stripe test mode workflow's steps that read what the tests printed or recorded
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

/**
 * Turns what a step's tests printed or recorded into the step's outputs, its run list and its verdict, for bin/ci/stripe-test-mode.sh.
 *
 * Each method returns the exit status of the step, writes `name=value` outputs to the stream it
 * is given, which the workflow's script points at GITHUB_OUTPUT, and says what is wrong as one
 * `::error::` line each on the messages stream. Nothing it writes holds more than counts, words
 * and ids, each run through MessageGuard::redact().
 *
 * @since 0.2.0
 */
final class WorkflowStep {

	/**
	 * Copies a text, line by line as it comes, with every value shaped like a credential blanked out.
	 *
	 * @since 0.2.0
	 *
	 * @param resource $in  Where the text comes from.
	 * @param resource $out Where it goes.
	 * @return int 0.
	 */
	public static function redact( $in, $out ): int {
		for ( $line = fgets( $in ); false !== $line; $line = fgets( $in ) ) {
			fwrite( $out, MessageGuard::redact( $line ) );
			fflush( $out );
		}

		return 0;
	}

	/**
	 * Checks the conformance suite's run against Stripe, and gives its counts as outputs.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $output   What the run printed.
	 * @param resource $outputs  Where the outputs go.
	 * @param resource $messages Where the messages go.
	 * @return int 0 when the run had the replay's tests and skips, 1 otherwise.
	 */
	public static function suite( string $output, $outputs, $messages ): int {
		$problems  = SuiteOutput::conformanceProblems( $output );
		$summaries = SuiteOutput::summaries( $output );
		$last      = end( $summaries );

		if ( false !== $last ) {
			self::write(
				$outputs,
				array(
					'tests'      => $last['tests'],
					'assertions' => $last['assertions'],
					'skipped'    => $last['skipped'],
				)
			);
		}

		self::write( $outputs, array( 'skip-set' => array() === $problems ? 'expected' : 'differs' ) );

		return self::problems( $problems, $messages );
	}

	/**
	 * Adds to the run list every PaymentIntent the conformance suite created at Stripe, read from the tapes it recorded, so the cleanup cancels those it left waiting.
	 *
	 * Recording, the suite writes a scenario's exchanges anew when the scenario ends. A tape no newer
	 * than the mark the step made before the suite is an earlier recording's: its intents are not
	 * the run's, and are left out. Times are whole seconds, and the step starts the suite in the
	 * second after the mark's, so a tape from the mark's own second is left out too. Each intent
	 * goes on the list with the store uuid its request tagged it with, which the cleanup checks
	 * against Stripe's metadata before it cancels it. A scenario that failed before its end wrote
	 * no tape, so the intents it created are not found.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $tapes    The suite's tape directory.
	 * @param string   $mark     A file the step made a second before the suite ran.
	 * @param string   $runList  The run list, added to.
	 * @param resource $messages Where the messages go.
	 * @return int 0, or 1 when the mark or the run list is missing.
	 */
	public static function harvest( string $tapes, string $mark, string $runList, $messages ): int {
		$since = is_file( $mark ) ? filemtime( $mark ) : false;

		if ( false === $since || ! is_file( $runList ) || ! is_writable( $runList ) ) {
			return self::problems( array( 'The PaymentIntents the suite created cannot be listed: the mark made before the suite, or the run list, is missing.' ), $messages );
		}

		$recorded = array();
		$files    = glob( $tapes . '/*/*.json' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( filemtime( $file ) > $since ) {
				$recorded[] = (array) json_decode( (string) file_get_contents( $file ), true );
			}
		}

		$intents = CleanupSelection::createdBy( $recorded );

		file_put_contents( $runList, implode( '', array_map( array( CleanupSelection::class, 'line' ), array_keys( $intents ), $intents ) ), FILE_APPEND | LOCK_EX );
		fwrite( $messages, MessageGuard::redact( sprintf( "The run list: %1\$d PaymentIntents the suite created, read from the %2\$d tape files the run wrote.\n", count( $intents ), count( $recorded ) ) ) );

		return 0;
	}

	/**
	 * Checks a run of a part's live tests, which must all run and pass, none skipped, and gives its counts and its report's facts as outputs.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $part     The part: three-d-secure or webhooks.
	 * @param string   $output   What the run printed.
	 * @param string   $report   The run's report.
	 * @param resource $outputs  Where the outputs go.
	 * @param resource $messages Where the messages go.
	 * @return int 0 when every live test of the part passed, 1 otherwise.
	 */
	public static function live( string $part, string $output, string $report, $outputs, $messages ): int {
		$problems = SuiteOutput::liveProblems( $output, $part );
		$last     = SuiteOutput::summaries( $output );
		$last     = end( $last );

		if ( false !== $last ) {
			self::write(
				$outputs,
				array(
					'tests'      => $last['tests'],
					'assertions' => $last['assertions'],
					'skipped'    => $last['skipped'],
				)
			);
		}

		$facts = LiveRun::facts( $report );

		self::write( $outputs, array_intersect_key( $facts, array_flip( array( 'api-versions', 'seed' ) ) ) );

		foreach ( $facts as $key => $value ) {
			fwrite( $messages, MessageGuard::redact( sprintf( "%s: %s\n", $key, $value ) ) );
		}

		return self::problems( $problems, $messages );
	}

	/**
	 * Checks the soak's size, as the workflow's input gives it.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $input    The input.
	 * @param resource $messages Where the messages go.
	 * @return int 0 for a size the soak takes, 1 otherwise.
	 */
	public static function soakSize( string $input, $messages ): int {
		try {
			SoakPlan::size( $input );
		} catch ( \InvalidArgumentException $refused ) {
			return self::problems( array( 'The soak-size input is refused: ' . $refused->getMessage() ), $messages );
		}

		return 0;
	}

	/**
	 * Judges the run from its jobs' results and outputs, and writes its summary.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $needs    The jobs' results and outputs, as `toJSON(needs)` gives them.
	 * @param string   $parts    The run's `parts` input.
	 * @param resource $summary  Where the summary goes.
	 * @param resource $messages Where the messages go.
	 * @return int 0 for a green run, 1 for a red one.
	 */
	public static function verdict( string $needs, string $parts, $summary, $messages ): int {
		$verdict = Verdict::judge( (array) json_decode( $needs, true ), $parts );

		fwrite( $summary, $verdict['summary'] );

		return self::problems( $verdict['problems'], $messages );
	}

	/**
	 * Writes outputs, one `name=value` line each.
	 *
	 * @since 0.2.0
	 *
	 * @param resource                  $outputs Where they go.
	 * @param array<string, string|int> $values  The values, by name.
	 */
	private static function write( $outputs, array $values ): void {
		foreach ( $values as $name => $value ) {
			fwrite( $outputs, MessageGuard::redact( sprintf( "%s=%s\n", $name, str_replace( array( "\r", "\n" ), ' ', (string) $value ) ) ) );
		}
	}

	/**
	 * Writes each problem as an error the workflow shows, and returns the step's status.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $problems The problems.
	 * @param resource $messages Where they go.
	 * @return int 0 for none, 1 otherwise.
	 * @phpstan-param list<string> $problems
	 */
	private static function problems( array $problems, $messages ): int {
		foreach ( $problems as $problem ) {
			fwrite( $messages, MessageGuard::redact( '::error::' . $problem . "\n" ) );
		}

		return array() === $problems ? 0 : 1;
	}
}
