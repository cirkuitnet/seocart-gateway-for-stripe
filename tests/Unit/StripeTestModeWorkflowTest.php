<?php
/**
 * Tests the shape of the Stripe test mode workflow, which holds the test account's key
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\WorkflowFacts;

/**
 * The Stripe test mode workflow starts only on its schedule or by hand, has no job named ci, reads the repository and nothing more, runs each job only in this repository, gives a secret only to a step's env, never traces the shell, prints the environment or uploads a file, runs one at a time uncancelled, and limits every job's time. Each fact is planted broken, in memory, and found.
 *
 * Pins, the PHP set-up, Composer and the Node.js version are SEOCart's check of workflows'
 * (`sh ../seocart/bin/ci/check-workflows.sh .`), which the required gates run.
 *
 * Planted violations, each shown red and removed:
 * - `push:` added to the workflow's `on:` in the file: the first test fails naming the triggers;
 * - in WorkflowFacts::secrets(), only the lines holding `secrets.` read, and no comment's line: the
 *   bracket form, `toJSON(secrets)`, the capitals and the shell comment are not found;
 * - in WorkflowFacts::secrets(), an expression ended at its first `}}`, quoted or not: the secrets
 *   after a quoted `}}` are not found.
 *
 * @since 0.2.0
 */
final class StripeTestModeWorkflowTest extends TestCase {

	/**
	 * Tests that the workflow as committed keeps every fact.
	 *
	 * @since 0.2.0
	 */
	public function test_the_workflow_keeps_every_fact(): void {
		$this->assertSame( array(), WorkflowFacts::violations( self::lines() ) );
	}

	/**
	 * Tests that each fact, planted broken, is found.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider plants
	 *
	 * @param string   $expected Text of the violation expected.
	 * @param \Closure $plant    The plant: the lines in, the lines broken out.
	 */
	public function test_each_fact_planted_broken_is_found( string $expected, \Closure $plant ): void {
		$found = implode( "\n", WorkflowFacts::violations( $plant( self::lines() ) ) );

		$this->assertStringContainsString( $expected, $found );
	}

	/**
	 * Returns one plant per fact.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each violation expected and its plant, by name.
	 */
	public static function plants(): array {
		return array(
			'a push trigger'                => array( 'The workflow starts on push, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    push:' ) ),
			'a pull request trigger'        => array( 'The workflow starts on pull_request, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    pull_request:' ) ),
			'a job named ci'                => array( 'a job is named ci', static fn( array $lines ): array => self::replaced( $lines, '/^    cli:$/', '    ci:' ) ),
			'a wider permission'            => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    contents: read$/', '    issues: write' ) ),
			'a job writing everything'      => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    verdict:$/', '        permissions: write-all' ) ),
			'a job without the guard'       => array( 'the job three-d-secure does not run only in', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*three-d-secure/', "        if: contains(fromJSON('[\"all\", \"three-d-secure\"]'), inputs.parts || 'all')" ) ),
			'a secret in a run'             => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: echo "${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}"' ) ),
			'a secret in a job\'s env'      => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^            STRIPE_CLI_TELEMETRY_OPTOUT:/', '            STRIPE_API_KEY: ${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}' ) ),
			'a secret in an action\'s with' => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  persist-credentials: false$/', '                  token: ${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}' ) ),
			'a secret in brackets in a run' => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: echo "${{ secrets[\'STRIPE_TEST_RESTRICTED_KEY\'] }}"' ) ),
			'a secret in brackets in env'   => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  STRIPE_API_KEY: /', '                  STRIPE_API_KEY: ${{ secrets[\'STRIPE_TEST_RESTRICTED_KEY\'] }}' ) ),
			'every secret as JSON'          => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  STRIPE_TEST_MODE_NEEDS: /', '                  STRIPE_TEST_MODE_NEEDS: ${{ toJSON(secrets) }}' ) ),
			'a secret in capitals'          => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: echo "${{ SECRETS.STRIPE_TEST_RESTRICTED_KEY }}"' ) ),
			'a secret in a shell comment'   => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  if \[ -n "\$CORE_REF" \]; then$/', '                  # ${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}' ) ),
			'a secret over two lines'       => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: echo "${{' ), '/^              run: echo "\$\{\{$/', '                  secrets.STRIPE_TEST_RESTRICTED_KEY }}"' ) ),
			'a secret after a quoted }}'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: echo "${{ format(\'}}{0}\', secrets.STRIPE_TEST_RESTRICTED_KEY) }}"' ) ),
			'a quoted }} past an escape'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: echo "${{ format(\'it\'\'s }}{0}\', secrets.STRIPE_TEST_RESTRICTED_KEY) }}"' ) ),
			'a traced shell'                => array( 'holds `set -x`', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: set -x; sh ext/bin/ci/stripe-test-mode.sh heads' ) ),
			'the environment printed'       => array( 'holds `printenv`', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: printenv' ) ),
			'an upload'                     => array( 'holds `upload-artifact`', static fn( array $lines ): array => self::after( $lines, '/^            - \*checkout-extension$/', '            - uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02 # v4.6.2' ) ),
			'a run cancelled by the next'   => array( 'with cancel-in-progress: false', static fn( array $lines ): array => self::replaced( $lines, '/^    cancel-in-progress: false$/', '    cancel-in-progress: true' ) ),
			'another concurrency group'     => array( 'is not the group stripe-test-mode', static fn( array $lines ): array => self::replaced( $lines, '/^    group: stripe-test-mode$/', '    group: ${{ github.workflow }}-${{ github.ref }}' ) ),
			'a job without a time limit'    => array( 'the job verdict has no timeout-minutes', static fn( array $lines ): array => self::without( $lines, '/^        timeout-minutes: 5$/' ) ),
		);
	}

	/**
	 * Returns the workflow's lines.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The lines.
	 */
	private static function lines(): array {
		$lines = file( dirname( __DIR__, 2 ) . '/.github/workflows/stripe-test-mode.yml', FILE_IGNORE_NEW_LINES );

		self::assertIsArray( $lines, 'The workflow cannot be read.' );

		return $lines;
	}

	/**
	 * Inserts a line after the first that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line after which it goes.
	 * @param string   $line    The line.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function after( array $lines, string $pattern, string $line ): array {
		$at = self::find( $lines, $pattern );

		array_splice( $lines, $at + 1, 0, array( $line ) );

		return $lines;
	}

	/**
	 * Replaces the first line that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line replaced.
	 * @param string   $line    The line put in its place.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function replaced( array $lines, string $pattern, string $line ): array {
		$lines[ self::find( $lines, $pattern ) ] = $line;

		return $lines;
	}

	/**
	 * Removes the first line that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line removed.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function without( array $lines, string $pattern ): array {
		array_splice( $lines, self::find( $lines, $pattern ), 1 );

		return $lines;
	}

	/**
	 * Finds the first line that matches, failing the test when none does: a plant must break the file.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The pattern.
	 * @return int The line's index.
	 * @phpstan-param list<string> $lines
	 */
	private static function find( array $lines, string $pattern ): int {
		foreach ( $lines as $at => $line ) {
			if ( 1 === preg_match( $pattern, $line ) ) {
				return $at;
			}
		}

		self::fail( sprintf( 'The plant found no line matching %s.', $pattern ) );
	}
}
