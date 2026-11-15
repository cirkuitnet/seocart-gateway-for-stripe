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
 * The Stripe test mode workflow starts only on its schedule or by hand, has no job named ci, reads the repository and nothing more, runs each job only in this repository, gives a secret only to a step's env, never traces the shell, prints the environment or uploads a file, runs one at a time uncancelled, limits every job's time, and has as its jobs and as the choices of its parts input the parts the verdict judges and no other, and writes a job's settings file, which holds the key, only once the job has installed and built all it needs, in the workflow and in the script's steps it runs then. Each fact is planted broken, in memory, and found.
 *
 * Pins, the PHP set-up, Composer and the Node.js version are SEOCart's check of workflows'
 * (`sh ../seocart/bin/ci/check-workflows.sh .`), which the required gates run.
 *
 * Planted violations, each shown red and removed:
 * - `push:` added to the workflow's `on:` in the file: the first test fails naming the triggers;
 * - a `cli` job, then a `cli` choice of the `parts` input, added back to the file, and the verdict's
 *   `needs` or a part's `if` changed in it: the first test fails naming the jobs, the choices, the
 *   condition or the needs;
 * - in WorkflowFacts::secrets(), only the lines holding `secrets.` read, and no comment's line: the
 *   bracket form, `toJSON(secrets)`, the capitals and the shell comment are not found;
 * - in WorkflowFacts::secrets(), an expression ended at its first `}}`, quoted or not: the secrets
 *   after a quoted `}}` are not found;
 * - the checkout job's settings step put back before the step that builds and installs its site, the
 *   order before the two were split, in the file: the order test fails naming the site step.
 *
 * @since 0.2.0
 */
final class StripeTestModeWorkflowTest extends TestCase {

	/**
	 * What installs or builds code: a package manager installing or running a build, a toolchain's or a browser's set-up,
	 * the zip builder, WordPress's download or install, a plugin's install, and the script's step that builds the checkout's site.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INSTALLS = '/\b(npm|yarn|pnpm) (ci|install|add|run)\b|\bplaywright install\b|\bcomposer (install|update|require)\b|setup-php-composer|actions\/setup-node|^- \*(setup-php|setup-node|prepare-wordpress|browser-dependencies|chromium)$|stripe-test-mode\.sh site\b|build-zip\.php|download-wordpress\.sh|\b(core|plugin) install\b/';

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
	 * Tests that no job installs or builds anything once its settings file is written: neither a step of the workflow after the settings step, nor a step of bin/ci/stripe-test-mode.sh the job runs then.
	 *
	 * @since 0.2.0
	 */
	public function test_nothing_is_installed_or_built_once_the_settings_file_is_written(): void {
		$this->assertSame( array(), self::installsAfterSettings( self::lines(), self::script() ) );
	}

	/**
	 * Tests that an install or a build after a job's settings step is found, in the workflow or in the script.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider installsPlanted
	 *
	 * @param string   $expected Text of the violation expected.
	 * @param \Closure $plant    The plant: the workflow's and the script's lines in, both broken out.
	 */
	public function test_an_install_after_the_settings_file_is_found( string $expected, \Closure $plant ): void {
		[ $lines, $script ] = $plant( self::lines(), self::script() );

		$this->assertStringContainsString( $expected, implode( "\n", self::installsAfterSettings( $lines, $script ) ) );
	}

	/**
	 * Returns one plant per way an install or a build could come after a settings file.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each violation expected and its plant, by name.
	 */
	public static function installsPlanted(): array {
		return array(
			'the settings before the site is built' => array( 'The job checkout installs or builds after its settings step: `run: sh ext/bin/ci/stripe-test-mode.sh site`.', static fn( array $lines, array $script ): array => array( self::after( $lines, '/^            - \*browser-unit$/', '            - *settings' ), $script ) ),
			'a dependency installed after them'     => array( 'The job webhooks installs or builds after its settings step: `- run: composer install --working-dir=seocart`.', static fn( array $lines, array $script ): array => array( self::after( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh live webhooks$/', '            - run: composer install --working-dir=seocart' ), $script ) ),
			'a step run after them that installs'   => array( 'The step configure, which the job checkout runs after its settings step, installs or builds: `(cd "$core" && npm ci)`.', static fn( array $lines, array $script ): array => array( $lines, self::after( $script, '/^configure\(\) \{$/', "\t(cd \"\$core\" && npm ci)" ) ) ),
			'a helper of such a step that builds'   => array( 'The step checkout, which the job checkout runs after its settings step, installs or builds: `php "$core/bin/build-zip.php" --plugin="$ext"`.', static fn( array $lines, array $script ): array => array( $lines, self::after( $script, '/^harvest_site\(\) \{$/', "\tphp \"\$core/bin/build-zip.php\" --plugin=\"\$ext\"" ) ) ),
		);
	}

	/**
	 * Tests that a workflow whose expressions cannot be read is a violation, not a pass.
	 *
	 * The regular expression gives up, and returns false, when PCRE's backtrack limit is lowered and
	 * its JIT is off. A pattern already compiled in this process keeps its JIT, so a PHP process of
	 * its own reads the workflow with both settings from its first use of the pattern.
	 *
	 * @since 0.2.0
	 */
	public function test_expressions_that_cannot_be_read_are_a_violation(): void {
		$support = __DIR__ . '/../Support/Live/';
		$code    = sprintf(
			'require %1$s; require %2$s; require %3$s; echo implode( "\n", \%4$s::violations( file( %5$s, FILE_IGNORE_NEW_LINES ) ) );',
			var_export( $support . 'MessageGuard.php', true ),
			var_export( $support . 'Verdict.php', true ),
			var_export( $support . 'WorkflowFacts.php', true ),
			WorkflowFacts::class,
			var_export( dirname( __DIR__, 2 ) . '/.github/workflows/stripe-test-mode.yml', true )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- a PHP process of its own, run with fixed settings.
		exec( escapeshellarg( PHP_BINARY ) . ' -d pcre.jit=0 -d pcre.backtrack_limit=10 -d max_execution_time=30 -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$this->assertStringContainsString( 'The expressions of the workflow could not be read', implode( "\n", $output ) );
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
			'a job named ci'                => array( 'a job is named ci', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    ci:' ) ),
			'a wider permission'            => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    contents: read$/', '    issues: write' ) ),
			'a job writing everything'      => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    verdict:$/', '        permissions: write-all' ) ),
			'a job without the guard'       => array( 'the job three-d-secure does not run only in', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*three-d-secure/', "        if: contains(fromJSON('[\"all\", \"three-d-secure\"]'), inputs.parts || 'all')" ) ),
			'a secret in a run'             => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: echo "${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}"' ) ),
			'a secret in a job\'s env'      => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::after( $lines, '/^        timeout-minutes: 30$/', '        env:' ), '/^        env:$/', '            STRIPE_API_KEY: ${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}' ) ),
			'a secret in an action\'s with' => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  persist-credentials: false$/', '                  token: ${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}' ) ),
			'a secret in brackets in a run' => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh heads$/', '              run: echo "${{ secrets[\'STRIPE_TEST_RESTRICTED_KEY\'] }}"' ) ),
			'a secret in brackets in env'   => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  STRIPE_TEST_RESTRICTED_KEY: /', '                  STRIPE_TEST_RESTRICTED_KEY: ${{ secrets[\'STRIPE_TEST_RESTRICTED_KEY\'] }}' ) ),
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
			'a cli job'                     => array( 'The jobs are checkout, cli, live-conformance, three-d-secure, verdict, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    cli:' ) ),
			'a job removed'                 => array( 'The jobs are checkout, live-conformance, three-d-secure, verdict, not exactly', static fn( array $lines ): array => self::without( $lines, '/^    webhooks:$/' ) ),
			'a cli choice'                  => array( 'The parts input offers all, checkout, cli, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli' ) ),
			'a part without a choice'       => array( 'The parts input offers all, checkout, conformance, three-d-secure, not exactly', static fn( array $lines ): array => self::without( $lines, '/^                    - webhooks$/' ) ),
			'a verdict missing a need'      => array( 'the verdict needs live-conformance, three-d-secure, not exactly checkout, live-conformance, three-d-secure, webhooks.', static fn( array $lines ): array => self::replaced( $lines, '/^        needs: /', '        needs: [live-conformance, three-d-secure]' ) ),
			'a condition without all'       => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition on another part'   => array( 'the job three-d-secure has no condition fromJSON(\'["all", "three-d-secure"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"three-d-secure"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"all\", \"cli\"]'), inputs.parts || 'all')" ) ),
			'a condition on no part'        => array( 'the job live-conformance has no condition fromJSON(\'["all", "conformance"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"conformance"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe'" ) ),
			'a verdict job removed'         => array( 'The jobs are checkout, live-conformance, three-d-secure, webhooks, not exactly checkout, live-conformance, three-d-secure, verdict, webhooks.', static fn( array $lines ): array => self::without( $lines, '/^    verdict:$/' ) ),
			'a verdict without always'      => array( 'its if must hold always()', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe'" ) ),
			'a cli choice with a comment'   => array( 'The parts input offers all, checkout, cli, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli # back' ) ),
			'a condition with no fallback'  => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts)" ) ),
			'a condition and false'         => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all') && false" ) ),
			'a condition false first'       => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: false && github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a negated condition'           => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && !contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition in a comment'      => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' # contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a continued condition'         => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::after( $lines, '/^        if: .*"webhooks"/', '            && false' ) ),
			'a verdict that never runs'     => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: always() && github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && false" ) ),
			'always only in a comment'      => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' # always()" ) ),
			'a choice with a space'         => array( 'The parts input offers - cli session, all, checkout, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli session' ) ),
			'a choice with a hash'          => array( 'The parts input offers all, checkout, conformance, three-d-secure, webhooks#2, not exactly', static fn( array $lines ): array => self::replaced( $lines, '/^                    - webhooks$/', '                    - webhooks#2' ) ),
		);
	}

	/**
	 * Returns every install or build that runs once a job's settings file is written: a step of the job after its settings step that installs or builds, and a line that does in a step of bin/ci/stripe-test-mode.sh the job runs after it, or in a function of the script that step calls.
	 *
	 * The settings file holds the test account's key, so it is written last: a package's install
	 * script, a build or a downloaded browser runs with the file readable otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines  The workflow's lines.
	 * @param string[] $script The lines of bin/ci/stripe-test-mode.sh.
	 * @return list<string> The violations; none when nothing installs or builds then.
	 * @phpstan-param list<string> $lines
	 * @phpstan-param list<string> $script
	 */
	private static function installsAfterSettings( array $lines, array $script ): array {
		$violations = array();
		$steps      = array();
		$job        = null;
		$after      = false;

		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^    ([a-z][a-z0-9-]*):$/', $line, $match ) ) {
				$job   = $match[1];
				$after = false;
				continue;
			}

			$text = trim( $line );

			if ( null === $job || '' === $text || str_starts_with( $text, '#' ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^- [*&]settings$/', $text ) ) {
				$after = true;
				continue;
			}

			if ( $after && 1 === preg_match( self::INSTALLS, $text ) ) {
				$violations[] = sprintf( 'The job %1$s installs or builds after its settings step: `%2$s`.', $job, $text );
			}

			if ( $after && 1 === preg_match( '/stripe-test-mode\.sh ([a-z-]+)/', $text, $match ) ) {
				$steps[ $match[1] ][ $job ] = true;
			}
		}

		$functions = self::functions( $script );
		$dispatch  = array();

		foreach ( $script as $line ) {
			if ( 1 === preg_match( '/^\t([a-z-]+)\) ([a-z_]+)\b/', $line, $match ) ) {
				$dispatch[ $match[1] ] = $match[2];
			}
		}

		foreach ( $steps as $step => $jobs ) {
			foreach ( self::reached( $dispatch[ $step ] ?? '', $functions ) as $function ) {
				foreach ( $functions[ $function ] as $line ) {
					$text = trim( $line );

					if ( '' === $text || str_starts_with( $text, '#' ) || 1 !== preg_match( self::INSTALLS, $text ) ) {
						continue;
					}

					foreach ( array_keys( $jobs ) as $job ) {
						$violations[] = sprintf( 'The step %1$s, which the job %2$s runs after its settings step, installs or builds: `%3$s`.', $step, $job, $text );
					}
				}
			}
		}

		return $violations;
	}

	/**
	 * Returns the script's functions: each one's lines, by its name.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $script The script's lines.
	 * @return array<string, list<string>> The functions.
	 * @phpstan-param list<string> $script
	 */
	private static function functions( array $script ): array {
		$functions = array();
		$name      = null;

		foreach ( $script as $line ) {
			if ( 1 === preg_match( '/^([a-z_]+)\(\) \{$/', $line, $match ) ) {
				$name               = $match[1];
				$functions[ $name ] = array();
			} elseif ( '}' === $line ) {
				$name = null;
			} elseif ( null !== $name ) {
				$functions[ $name ][] = $line;
			}
		}

		return $functions;
	}

	/**
	 * Returns a function of the script and every function of the script it calls, at any depth: a
	 * name in a command's place, at a line's start or after `;`, `&&`, `||`, `|`, `(` or `$(`, not a
	 * word of a message.
	 *
	 * @since 0.2.0
	 *
	 * @param string                      $first     The function's name.
	 * @param array<string, list<string>> $functions The script's functions.
	 * @return list<string> The names.
	 */
	private static function reached( string $first, array $functions ): array {
		$reached = array();
		$queue   = isset( $functions[ $first ] ) ? array( $first ) : array();

		while ( array() !== $queue ) {
			$name      = array_shift( $queue );
			$reached[] = $name;

			preg_match_all( '/(?:^|[;&|(])\s*([a-z_]+)\b/m', implode( "\n", $functions[ $name ] ), $words );

			foreach ( array_unique( $words[1] ) as $word ) {
				if ( isset( $functions[ $word ] ) && ! in_array( $word, $reached, true ) && ! in_array( $word, $queue, true ) ) {
					$queue[] = $word;
				}
			}
		}

		return $reached;
	}

	/**
	 * Returns the lines of bin/ci/stripe-test-mode.sh.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The lines.
	 */
	private static function script(): array {
		$lines = file( dirname( __DIR__, 2 ) . '/bin/ci/stripe-test-mode.sh', FILE_IGNORE_NEW_LINES );

		self::assertIsArray( $lines, 'The script cannot be read.' );

		return $lines;
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
