<?php
/**
 * Verdict: the Stripe test mode run's closing table, and whether the run is green
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

/**
 * Reads the results and outputs of the run's jobs and says, for each thing the run proves against Stripe, which job proves it and how it went.
 *
 * A job the run's `parts` input selected must succeed: a skipped one would read green and prove
 * nothing. A job not selected is expected skipped. The suite's job must report its counts, and the
 * skips the replay has, no other.
 *
 * @since 0.2.0
 */
final class Verdict {

	/**
	 * Each job, by name, with the `parts` value that selects it beside `all`.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const JOBS = array(
		'live-conformance' => 'conformance',
		'three-d-secure'   => 'three-d-secure',
		'webhooks'         => 'webhooks',
	);

	/**
	 * What the run proves, each with the job that proves it.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const PROOFS = array(
		'The conformance suite, every request sent to Stripe\'s test mode' => 'live-conformance',
		'3-D Secure completed in a browser on Stripe\'s test page, then resumed' => 'three-d-secure',
		'A shopper who never acts: voided at the window\'s end, cancelled at Stripe `abandoned`' => 'three-d-secure',
		'Real events read back from Stripe, delivered before, after and twice, and soaked' => 'webhooks',
		'Webhook endpoint set up at Stripe, reused, and removed' => 'webhooks',
	);

	/**
	 * Judges a run.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $needs The jobs' results and outputs, as `toJSON(needs)` gives them.
	 * @param string       $parts The run's `parts` input; empty for a scheduled run, which runs all.
	 * @return array{green: bool, problems: list<string>, summary: string} Whether the run is green, why not, and the table for the run's summary.
	 */
	public static function judge( array $needs, string $parts ): array {
		$parts    = '' === $parts ? 'all' : $parts;
		$problems = array();

		if ( 'all' !== $parts && ! in_array( $parts, self::JOBS, true ) ) {
			$problems[] = sprintf( 'The parts input `%s` names no part: all, %s.', MessageGuard::redact( $parts ), implode( ', ', self::JOBS ) );
		}

		foreach ( self::JOBS as $job => $part ) {
			$result  = self::result( $needs, $job );
			$enabled = 'all' === $parts || $part === $parts;

			if ( $enabled && 'success' !== $result ) {
				$problems[] = sprintf( 'The job %1$s was selected and ended %2$s.', $job, $result );
			}
		}

		if ( 'success' === self::result( $needs, 'live-conformance' ) ) {
			$outputs = self::outputs( $needs, 'live-conformance' );

			foreach ( array( 'tests', 'assertions', 'skipped' ) as $count ) {
				if ( 1 !== preg_match( '/^\d+$/D', $outputs[ $count ] ?? '' ) ) {
					$problems[] = sprintf( 'The job live-conformance reported no count of %s.', $count );
				}
			}

			if ( 'expected' !== ( $outputs['skip-set'] ?? '' ) ) {
				$problems[] = 'The job live-conformance skipped other tests than the two the replay skips.';
			}
		}

		return array(
			'green'    => array() === $problems,
			'problems' => $problems,
			'summary'  => self::summary( $needs, $parts, $problems ),
		);
	}

	/**
	 * Writes the table for the run's summary.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $needs    The jobs' results and outputs.
	 * @param string       $parts    The parts the run selected.
	 * @param string[]     $problems Why the run is red; none when it is green.
	 * @return string The Markdown.
	 * @phpstan-param list<string> $problems
	 */
	private static function summary( array $needs, string $parts, array $problems ): string {
		$lines = array( '## Stripe test mode: ' . ( array() === $problems ? 'green' : 'red' ), '', sprintf( 'Parts: `%s`', MessageGuard::redact( $parts ) ), '', '| What is proved | Job | Result | Counts |', '| --- | --- | --- | --- |' );

		foreach ( self::PROOFS as $proof => $job ) {
			$lines[] = sprintf( '| %1$s | %2$s | %3$s | %4$s |', $proof, $job, self::result( $needs, $job ), self::counts( self::outputs( $needs, $job ) ) );
		}

		$lines[] = '';
		$lines[] = '| Job | Adapter | SEOCart | Run facts |';
		$lines[] = '| --- | --- | --- | --- |';

		foreach ( array_keys( self::JOBS ) as $job ) {
			$outputs = self::outputs( $needs, $job );
			$lines[] = sprintf( '| %1$s | %2$s | %3$s | %4$s |', $job, self::head( $outputs['ext-head'] ?? '' ), self::head( $outputs['core-head'] ?? '' ), self::facts( $outputs ) );
		}

		if ( array() !== $problems ) {
			$lines[] = '';

			foreach ( $problems as $problem ) {
				$lines[] = '- ' . $problem;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Returns a job's result: success, failure, cancelled or skipped; `missing` when the run has no such job.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $needs The jobs' results and outputs.
	 * @param string       $job   The job.
	 * @return string The result.
	 */
	private static function result( array $needs, string $job ): string {
		$result = $needs[ $job ]['result'] ?? null;

		return is_string( $result ) && 1 === preg_match( '/^[a-z_]{1,20}$/D', $result ) ? $result : 'missing';
	}

	/**
	 * Returns a job's outputs, each a short line of text.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $needs The jobs' results and outputs.
	 * @param string       $job   The job.
	 * @return array<string, string> The outputs, by name.
	 */
	private static function outputs( array $needs, string $job ): array {
		$outputs = array();

		foreach ( (array) ( $needs[ $job ]['outputs'] ?? array() ) as $name => $value ) {
			if ( is_string( $value ) ) {
				$outputs[ (string) $name ] = MessageGuard::redact( str_replace( array( "\n", '|' ), ' ', $value ) );
			}
		}

		return $outputs;
	}

	/**
	 * Describes a job's counts.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $outputs The job's outputs.
	 * @return string For example `17 tests, 307 assertions, 2 skipped`; empty without counts.
	 */
	private static function counts( array $outputs ): string {
		$counts = array();

		foreach ( array( 'tests', 'assertions', 'skipped' ) as $count ) {
			if ( isset( $outputs[ $count ] ) && '' !== $outputs[ $count ] ) {
				$counts[] = $outputs[ $count ] . ' ' . $count;
			}
		}

		return implode( ', ', $counts );
	}

	/**
	 * Describes a job's facts of the run: the nonce, the soak's seed, the events' API versions.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $outputs The job's outputs.
	 * @return string The facts it reported.
	 */
	private static function facts( array $outputs ): string {
		$facts = array();

		foreach ( array( 'nonce', 'seed', 'api-versions' ) as $fact ) {
			if ( isset( $outputs[ $fact ] ) && '' !== $outputs[ $fact ] ) {
				$facts[] = $fact . ': ' . $outputs[ $fact ];
			}
		}

		return implode( '; ', $facts );
	}

	/**
	 * Shows a commit, or says it is not known.
	 *
	 * @since 0.2.0
	 *
	 * @param string $head The commit, as `git rev-parse` prints it.
	 * @return string The commit in code, or `not reported`.
	 */
	private static function head( string $head ): string {
		return 1 === preg_match( '/^[0-9a-f]{40}$/D', $head ) ? '`' . $head . '`' : 'not reported';
	}
}
