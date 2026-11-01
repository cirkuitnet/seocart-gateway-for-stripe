<?php
/**
 * Tests the check of the conformance suite's run against Stripe's test mode
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\SuiteOutput;

/**
 * The run against Stripe passes the check only when the unit group passed and the adapter's test ran its 17 tests with exactly the two skips the replay has, each for the scenario Stripe's test mode cannot produce; a part's live tests pass it only when exactly the part's tests ran and passed.
 *
 * Planted violations, each shown red and removed:
 * - in SuiteOutput::conformanceProblems(), the skip set's check removed: the output with a third skip passes;
 * - SuiteOutput::SKIPPED without rule 17: the expected output fails, and the output with rule 17 run passes;
 * - in SuiteOutput::liveProblems(), the part's count not checked: a three-d-secure run of 3 tests passes.
 *
 * @since 0.2.0
 */
final class LiveSuiteOutputTest extends TestCase {

	/**
	 * Tests that the output of a run as the replay runs passes, with its counts read.
	 *
	 * @since 0.2.0
	 */
	public function test_the_expected_run_passes(): void {
		$output = self::output();

		$this->assertSame( array(), SuiteOutput::conformanceProblems( $output ) );
		$this->assertSame( array( 17, 307, 2 ), array( SuiteOutput::summaries( $output )[1]['tests'], SuiteOutput::summaries( $output )[1]['assertions'], SuiteOutput::summaries( $output )[1]['skipped'] ) );
		$this->assertSame( array_keys( SuiteOutput::SKIPPED ), array_keys( SuiteOutput::skipped( $output ) ) );
	}

	/**
	 * Tests that a third skip fails the check.
	 *
	 * @since 0.2.0
	 */
	public function test_a_third_skip_fails(): void {
		$output = self::output(
			array(
				'test_rule_09_expiry'                    => 'expires',
				'test_rule_10_webhooks_verified_first'   => null,
				'test_rule_17_void_answers_the_approval' => 'requires_action_completed',
			)
		);

		$this->assertContains( 'The skipped tests are test_rule_09_expiry, test_rule_10_webhooks_verified_first, test_rule_17_void_answers_the_approval, not exactly test_rule_09_expiry, test_rule_17_void_answers_the_approval.', SuiteOutput::conformanceProblems( $output ) );
	}

	/**
	 * Tests that rule 17 run, rather than skipped, fails the check.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_17_run_fails(): void {
		$this->assertContains( 'The skipped tests are test_rule_09_expiry, not exactly test_rule_09_expiry, test_rule_17_void_answers_the_approval.', SuiteOutput::conformanceProblems( self::output( array( 'test_rule_09_expiry' => 'expires' ) ) ) );
	}

	/**
	 * Tests that a declared skip for another reason, a failure, or a unit group that failed, fails the check.
	 *
	 * @since 0.2.0
	 */
	public function test_another_reason_a_failure_or_a_failed_unit_group_fails(): void {
		$this->assertContains(
			'The test test_rule_17_void_answers_the_approval was skipped for another reason than the scenario requires_action_completed, which Stripe\'s test mode cannot produce.',
			SuiteOutput::conformanceProblems(
				self::output(
					array(
						'test_rule_09_expiry' => 'expires',
						'test_rule_17_void_answers_the_approval' => null,
					)
				)
			)
		);

		$failed = str_replace( 'Tests: 17, Assertions: 307, Skipped: 2.', 'Tests: 17, Assertions: 290, Failures: 1, Skipped: 2.', self::output() );
		$this->assertContains( 'The adapter\'s conformance test has 1 failures, 0 errors, 0 incomplete, 0 risky and 0 warnings.', SuiteOutput::conformanceProblems( $failed ) );

		$unit = str_replace( 'OK (40 tests, 120 assertions)', "FAILURES!\nTests: 40, Assertions: 119, Failures: 1.", self::output() );
		$this->assertContains( 'The unit group did not pass whole.', SuiteOutput::conformanceProblems( $unit ) );

		$this->assertSame( array( 'The run printed 1 PHPUnit summaries, not 2: the unit group and the adapter\'s conformance test.' ), SuiteOutput::conformanceProblems( 'OK (40 tests, 120 assertions)' ) );
	}

	/**
	 * Tests that a run of the live tests passes only when every test ran and passed: a skip, a failure or no test at all fails it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_live_run_with_a_skip_or_a_failure_fails(): void {
		$this->assertSame( array(), SuiteOutput::liveProblems( "PHPUnit 9.6.36\n\n....  4 / 4 (100%)\n\nOK (4 tests, 60 assertions)\n", 'three-d-secure' ) );
		$this->assertSame( array(), SuiteOutput::liveProblems( "PHPUnit 9.6.36\n\n...  3 / 3 (100%)\n\nOK (3 tests, 90 assertions)\n", 'webhooks' ) );
		$this->assertNotSame( array(), SuiteOutput::liveProblems( "OK, but incomplete, skipped, or risky tests!\nTests: 4, Assertions: 40, Skipped: 1.\n", 'three-d-secure' ) );
		$this->assertNotSame( array(), SuiteOutput::liveProblems( "FAILURES!\nTests: 4, Assertions: 40, Failures: 1.\n", 'three-d-secure' ) );
		$this->assertNotSame( array(), SuiteOutput::liveProblems( "No tests executed!\n", 'three-d-secure' ) );
	}

	/**
	 * Tests that a part's run of one test fewer, or one more, than the part holds fails, however green PHPUnit called it; and that an unknown part fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_live_run_of_another_count_than_the_parts_fails(): void {
		$this->assertSame( array( 'The live tests of three-d-secure ran 3 tests, not 4.' ), SuiteOutput::liveProblems( "OK (3 tests, 45 assertions)\n", 'three-d-secure' ) );
		$this->assertSame( array( 'The live tests of webhooks ran 2 tests, not 3.' ), SuiteOutput::liveProblems( "OK (2 tests, 60 assertions)\n", 'webhooks' ) );
		$this->assertSame( array( 'The live tests of webhooks ran 4 tests, not 3.' ), SuiteOutput::liveProblems( "OK (4 tests, 60 assertions)\n", 'webhooks' ) );
		$this->assertSame( array( 'The live tests have no part conformance: three-d-secure, webhooks.' ), SuiteOutput::liveProblems( "OK (17 tests, 307 assertions)\n", 'conformance' ) );
	}

	/**
	 * Returns what `extension.sh conformance . payments` prints, as PHPUnit 9 prints it, with the skips given.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string|null>|null $skips Each skipped test, by name, with the scenario its reason names, or null for another reason; null for the replay's two.
	 * @return string The output.
	 */
	private static function output( ?array $skips = null ): string {
		$skips ??= SuiteOutput::SKIPPED;
		$text    = "> @test:unit --group conformance --fail-on-empty-test-suite\nPHPUnit 9.6.36 by Sebastian Bergmann and contributors.\n\n" . str_repeat( '.', 40 ) . "  40 / 40 (100%)\n\nTime: 00:01.234, Memory: 40.00 MB\n\nOK (40 tests, 120 assertions)\n";
		$text   .= "> @php tests/Conformance/Payment/integration.php\nPHPUnit 9.6.36 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP 8.4.27\n\n";
		$text   .= str_repeat( '.', 17 - count( $skips ) ) . str_repeat( 'S', count( $skips ) ) . "  17 / 17 (100%)\n\nTime: 00:30.000, Memory: 80.00 MB\n\n";
		$text   .= sprintf( "There %s %d skipped test%s:\n\n", 1 === count( $skips ) ? 'was' : 'were', count( $skips ), 1 === count( $skips ) ? '' : 's' );
		$number  = 0;

		foreach ( $skips as $test => $scenario ) {
			$reason = null === $scenario ? 'Rule 10: a webhook delivery is verified before it is read: skipped: the gateway declares no webhooks.' : sprintf( 'Rule 0: the rule: skipped: the provider cannot produce the scenario %s in test mode: a reason.', $scenario );
			$text  .= sprintf( "%1\$d) SEOCart\\GatewayForStripe\\Tests\\Integration\\ConformanceTest::%2\$s\n%3\$s\n\n", ++$number, $test, $reason );
		}

		return $text . sprintf( "OK, but incomplete, skipped, or risky tests!\nTests: 17, Assertions: 307, Skipped: %d.\n", count( $skips ) );
	}
}
