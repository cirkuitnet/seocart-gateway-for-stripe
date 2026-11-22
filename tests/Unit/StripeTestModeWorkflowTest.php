<?php
/**
 * Tests the Stripe test mode workflow's facts, as SEOCart's checks of a live workflow read them
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\StripeRun;
use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tests\Conformance\Payment\Live\SettingsLast;
use SEOCart\Tests\Conformance\Payment\Live\WorkflowFacts;

/**
 * The Stripe test mode workflow keeps every fact SEOCart's WorkflowFacts reads with the plugin's declaration (tests/live.php): it starts only on its schedule or by hand, has no job named ci, reads the repository and nothing more, runs each job only in this repository, gives a secret only to the env of a step that needs it, never traces the shell, prints the environment or uploads a file, runs one at a time uncancelled, limits every job's time, has as its jobs and as the choices of its parts input the parts the declaration states, and checks SEOCart out before its verdict. Each job writes its settings file, which holds the key, after everything that installs or builds, and runs nothing after it but the steps SettingsLast's closed lists allow: SEOCart's suite and live steps, and Stripe's configure, checkout and cleanup. The soak-size step gives the size the soak takes to the job's later steps. The clean-up passes, saying why, when SEOCart was never checked out. No class of SEOCart's live workflow is left copied in the plugin's tests.
 *
 * Pins, the PHP set-up, Composer, the Node.js version and the reference to SEOCart's action are
 * SEOCart's check of workflows' (`sh ../seocart/bin/ci/check-workflows.sh .`), which the required
 * gates run.
 *
 * Planted violations, each shown red and removed:
 * - `push:` added to the workflow's `on:` in the file: the first test fails naming the triggers;
 * - the three-d-secure job's settings step moved before its set-up action, in the file: the closed
 *   list fails naming the action;
 * - `npx playwright test` put back in the script's checkout(), in the file: the closed list fails
 *   naming the command;
 * - the soak-size step's check of the size removed: 99 is written to the job's environment;
 * - the script's clean-up without SEOCart's lib no longer told apart: the clean-up fails there;
 * - tests/Support/Live/Verdict.php restored: the copies' test fails naming it.
 *
 * @since 0.2.0
 */
final class StripeTestModeWorkflowTest extends TestCase {

	/**
	 * The script's steps a job may run after its settings step.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const STEPS = array( 'configure', 'checkout', 'cleanup', 'cleanup endpoints' );

	/**
	 * The calls of the script that name Stripe, allowed only as written: the gateway's configuration, and the site's PaymentIntents read for the run list.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const EXACT = array(
		'site_wp seocart gateway configure stripe --mode=test --from-file="$settings" --no-webhooks --user=admin',
		'site_wp db query "SELECT provider_intent_id, uuid FROM ${prefix}seocart_payment_intents WHERE gateway_id = \'stripe\' AND provider_intent_id LIKE \'pi\\\\_%\'" --skip-column-names',
	);

	/**
	 * Tests that the workflow as committed keeps every fact.
	 *
	 * @since 0.2.0
	 */
	public function test_the_workflow_keeps_every_fact(): void {
		$this->assertSame( array(), WorkflowFacts::violations( self::declaration(), self::lines() ) );
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
		$found = implode( "\n", WorkflowFacts::violations( self::declaration(), $plant( self::lines() ) ) );

		$this->assertStringContainsString( $expected, $found );
	}

	/**
	 * Tests that no job runs anything once its settings file is written but SEOCart's suite and live steps and the script's steps the lists allow, in the workflow and in the shell those steps reach.
	 *
	 * @since 0.2.0
	 */
	public function test_nothing_but_the_listed_steps_runs_once_the_settings_file_is_written(): void {
		$this->assertSame( array(), self::rule()->violations( SettingsLast::sources( self::declaration() ) ) );
	}

	/**
	 * Tests that a step after a job's settings step that the lists do not allow is found, in the workflow or in the script.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider afterSettingsPlanted
	 *
	 * @param string   $expected Text of the violation expected.
	 * @param \Closure $plant    The plant: the four texts the rule reads in, broken out.
	 */
	public function test_a_step_the_lists_do_not_allow_after_the_settings_file_is_found( string $expected, \Closure $plant ): void {
		$found = implode( "\n", self::rule()->violations( $plant( SettingsLast::sources( self::declaration() ) ) ) );

		$this->assertStringContainsString( $expected, $found );
	}

	/**
	 * Returns one plant per way a job could install, build or run what the lists do not allow once its settings file is written.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each violation expected and its plant, by name.
	 */
	public static function afterSettingsPlanted(): array {
		$webhooks  = '/^              run: sh seocart\/bin\/ci\/extension-live.sh live ext webhooks$/';
		$workflow  = static fn( \Closure $plant ): \Closure => static fn( array $sources ): array => array( 'workflow' => $plant( $sources['workflow'] ) ) + $sources;
		$script    = static fn( \Closure $plant ): \Closure => static fn( array $sources ): array => array( 'script' => $plant( $sources['script'] ) ) + $sources;
		$not_steps = 'runs, after its settings step, a command that is not one of the script\'s steps';

		return array(
			'the settings before the site is built' => array( 'The job checkout ' . $not_steps . ': `run: sh seocart/bin/ci/extension-live.sh site ext`.', $workflow( static fn( array $lines ): array => self::before( $lines, '/^            - name: Build SEOCart, and install a site/', '            - *settings' ) ) ),
			'a dependency installed after them'     => array( 'The job webhooks ' . $not_steps . ': `run: composer install --working-dir=seocart`.', $workflow( static fn( array $lines ): array => self::after( $lines, $webhooks, '            - run: composer install --working-dir=seocart' ) ) ),
			'the set-up after them, by its anchor'  => array( 'The job webhooks uses an action after its settings step: `uses: ./seocart/.github/actions/extension-live-setup`.', $workflow( static fn( array $lines ): array => self::after( $lines, $webhooks, '            - *setup' ) ) ),
			'an action after them'                  => array( 'The job checkout uses an action after its settings step: `uses: actions/setup-node@820762786026740c76f36085b0efc47a31fe5020`.', $workflow( static fn( array $lines ): array => self::after( $lines, '/^              run: sh ext\/bin\/ci\/stripe-test-mode.sh configure$/', '            - uses: actions/setup-node@820762786026740c76f36085b0efc47a31fe5020 # v7.0.0' ) ) ),
			'a step run after them that installs'   => array( 'The step stripe-test-mode.sh configure, which the job checkout runs after its settings step, runs in configure() a command not on the list: `npm ci`.', $script( static fn( array $lines ): array => self::after( $lines, '/^configure\(\) \{$/', "\t(cd \"\$core\" && npm ci)" ) ) ),
			'a helper of such a step that builds'   => array( 'The step stripe-test-mode.sh checkout, which the job checkout runs after its settings step, runs in harvest_site() a command not on the list: `php "$core/bin/build-zip.php" --plugin="$ext"`.', $script( static fn( array $lines ): array => self::after( $lines, '/^harvest_site\(\) \{$/', "\tphp \"\$core/bin/build-zip.php\" --plugin=\"\$ext\"" ) ) ),
		);
	}

	/**
	 * Tests that a workflow whose expressions cannot be read is a violation, not a pass.
	 *
	 * The regular expression gives up, and returns false, when PCRE's backtrack limit is lowered and
	 * its JIT is off. A pattern already compiled in this process keeps its JIT, so a PHP process of
	 * its own reads the workflow with both settings from its first use of the pattern, SEOCart's live
	 * classes and the declaration required by path.
	 *
	 * @since 0.2.0
	 */
	public function test_expressions_that_cannot_be_read_are_a_violation(): void {
		$code = sprintf(
			'foreach ( glob( %1$s ) as $class ) { require_once $class; } echo implode( "\n", \%2$s::violations( \%3$s::load( %4$s ), file( %5$s, FILE_IGNORE_NEW_LINES ) ) );',
			var_export( dirname( (string) ( new \ReflectionClass( WorkflowFacts::class ) )->getFileName() ) . '/[A-Z]*.php', true ),
			WorkflowFacts::class,
			LiveDeclaration::class,
			var_export( dirname( __DIR__, 2 ), true ),
			var_export( self::workflow(), true )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- a PHP process of its own, run with fixed settings.
		exec( escapeshellarg( PHP_BINARY ) . ' -d pcre.jit=0 -d pcre.backtrack_limit=10 -d max_execution_time=30 -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$this->assertStringContainsString( 'The expressions of the workflow could not be read', implode( "\n", $output ) );
	}

	/**
	 * Tests that the soak-size step writes a size the soak takes to the job's environment file, and refuses any other, writing nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_soak_size_reaches_the_jobs_environment_only_when_the_soak_takes_it(): void {
		$file = tempnam( sys_get_temp_dir(), 'github-env-' );

		$this->assertIsString( $file );

		try {
			$this->assertSame( array( 0, array() ), self::soakSize( $file, '12' ) );
			$this->assertSame( StripeRun::SOAK_SIZE . "=12\n", file_get_contents( $file ) );
			$this->assertSame( array( 1, array( '::error::The soak-size input is refused: The soak\'s size is a whole number from 7 to 60.' ) ), self::soakSize( $file, '99' ) );
			$this->assertSame( StripeRun::SOAK_SIZE . "=12\n", file_get_contents( $file ) );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * Tests that the clean-up, which runs whatever became of the job's steps, passes and says why when SEOCart was never checked out, and that every other step still fails there.
	 *
	 * The script runs from a folder of the test's own, as ext/bin/ci/stripe-test-mode.sh with no
	 * seocart/ beside it, as when the secrets' check, the choice of SEOCart's commit or its checkout
	 * failed: the settings step comes after that checkout, so nothing was sent to Stripe.
	 *
	 * @since 0.2.0
	 */
	public function test_the_cleanup_passes_without_seocart_and_every_other_step_fails(): void {
		$root = sys_get_temp_dir() . '/stripe-test-mode-no-seocart-' . bin2hex( random_bytes( 8 ) );
		$said = 'cleanup: SEOCart was not checked out, so no settings file was written and nothing was sent to Stripe.';

		try {
			mkdir( $root . '/ext/bin/ci', 0700, true );
			mkdir( $root . '/temp', 0700 );
			copy( dirname( __DIR__, 2 ) . '/' . self::declaration()->script(), $root . '/ext/' . self::declaration()->script() );

			foreach ( array( 'cleanup', 'cleanup endpoints', 'settings', 'soak-size', 'configure', 'checkout' ) as $step ) {
				list( $status, $printed ) = self::step( $root, $step );

				$this->assertSame( array( str_starts_with( $step, 'cleanup' ), str_starts_with( $step, 'cleanup' ) ), array( 0 === $status, str_contains( $printed, $said ) ), $step . ': ' . $printed );
			}
		} finally {
			self::remove( $root );
		}
	}

	/**
	 * Tests that no file of the plugin's tests declares a class of SEOCart's live workflow: a copy left behind would drift from SEOCart's.
	 *
	 * @since 0.2.0
	 */
	public function test_no_class_of_seocarts_live_workflow_is_left_copied(): void {
		$this->assertSame( array(), WorkflowFacts::copies( dirname( __DIR__, 2 ) ) );
	}

	/**
	 * Returns one plant per fact.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each violation expected and its plant, by name.
	 */
	public static function plants(): array {
		$heads = '/^              run: sh seocart\/bin\/ci\/extension-live.sh heads ext$/';

		return array(
			'a push trigger'                 => array( 'The workflow starts on push, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    push:' ) ),
			'a pull request trigger'         => array( 'The workflow starts on pull_request, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    pull_request:' ) ),
			'a job named ci'                 => array( 'a job is named ci', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    ci:' ) ),
			'a wider permission'             => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    contents: read$/', '    issues: write' ) ),
			'a job writing everything'       => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    verdict:$/', '        permissions: write-all' ) ),
			'a job without the guard'        => array( 'the job three-d-secure does not run only in', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*three-d-secure/', "        if: contains(fromJSON('[\"all\", \"three-d-secure\"]'), inputs.parts || 'all')" ) ),
			'a secret in a run'              => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}"' ) ),
			'a secret in a job\'s env'       => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::after( $lines, '/^        timeout-minutes: 30$/', '        env:' ), '/^        env:$/', '            STRIPE_API_KEY: ${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}' ) ),
			'a secret in an action\'s with'  => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  persist-credentials: false$/', '                  token: ${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}' ) ),
			'a secret in brackets in a run'  => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ secrets[\'STRIPE_TEST_RESTRICTED_KEY\'] }}"' ) ),
			'a secret in brackets in env'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  STRIPE_TEST_RESTRICTED_KEY: /', '                  STRIPE_TEST_RESTRICTED_KEY: ${{ secrets[\'STRIPE_TEST_RESTRICTED_KEY\'] }}' ) ),
			'every secret as JSON'           => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  SEOCART_LIVE_NEEDS: /', '                  SEOCART_LIVE_NEEDS: ${{ toJSON(secrets) }}' ) ),
			'a secret in capitals'           => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ SECRETS.STRIPE_TEST_RESTRICTED_KEY }}"' ) ),
			'a secret in a shell comment'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  if \[ -n "\$CORE_REF" \]; then$/', '                  # ${{ secrets.STRIPE_TEST_RESTRICTED_KEY }}' ) ),
			'a secret over two lines'        => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::replaced( $lines, $heads, '              run: echo "${{' ), '/^              run: echo "\$\{\{$/', '                  secrets.STRIPE_TEST_RESTRICTED_KEY }}"' ) ),
			'a secret after a quoted }}'     => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ format(\'}}{0}\', secrets.STRIPE_TEST_RESTRICTED_KEY) }}"' ) ),
			'a quoted }} past an escape'     => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ format(\'it\'\'s }}{0}\', secrets.STRIPE_TEST_RESTRICTED_KEY) }}"' ) ),
			'a traced shell'                 => array( 'holds `set -x`', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: set -x; sh seocart/bin/ci/extension-live.sh heads ext' ) ),
			'the environment printed'        => array( 'holds `printenv`', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: printenv' ) ),
			'an upload'                      => array( 'holds `upload-artifact`', static fn( array $lines ): array => self::after( $lines, '/^            - \*checkout-extension$/', '            - uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02 # v4.6.2' ) ),
			'a run cancelled by the next'    => array( 'with cancel-in-progress: false', static fn( array $lines ): array => self::replaced( $lines, '/^    cancel-in-progress: false$/', '    cancel-in-progress: true' ) ),
			'another concurrency group'      => array( 'is not the group stripe-test-mode', static fn( array $lines ): array => self::replaced( $lines, '/^    group: stripe-test-mode$/', '    group: ${{ github.workflow }}-${{ github.ref }}' ) ),
			'a job without a time limit'     => array( 'the job verdict has no timeout-minutes', static fn( array $lines ): array => self::without( $lines, '/^        timeout-minutes: 5$/' ) ),
			'a cli job'                      => array( 'The jobs are checkout, cli, live-conformance, three-d-secure, verdict, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    cli:' ) ),
			'a job removed'                  => array( 'The jobs are checkout, live-conformance, three-d-secure, verdict, not exactly', static fn( array $lines ): array => self::without( $lines, '/^    webhooks:$/' ) ),
			'a cli choice'                   => array( 'The parts input offers all, checkout, cli, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli' ) ),
			'a part without a choice'        => array( 'The parts input offers all, checkout, conformance, three-d-secure, not exactly', static fn( array $lines ): array => self::without( $lines, '/^                    - webhooks$/' ) ),
			'a verdict missing a need'       => array( 'the verdict needs live-conformance, three-d-secure, not exactly checkout, live-conformance, three-d-secure, webhooks.', static fn( array $lines ): array => self::replaced( $lines, '/^        needs: /', '        needs: [live-conformance, three-d-secure]' ) ),
			'a condition without all'        => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition on another part'    => array( 'the job three-d-secure has no condition fromJSON(\'["all", "three-d-secure"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"three-d-secure"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"all\", \"cli\"]'), inputs.parts || 'all')" ) ),
			'a condition on no part'         => array( 'the job live-conformance has no condition fromJSON(\'["all", "conformance"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"conformance"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe'" ) ),
			'a verdict job removed'          => array( 'The jobs are checkout, live-conformance, three-d-secure, webhooks, not exactly checkout, live-conformance, three-d-secure, verdict, webhooks.', static fn( array $lines ): array => self::without( $lines, '/^    verdict:$/' ) ),
			'a verdict without always'       => array( 'its if must hold always()', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe'" ) ),
			'a cli choice with a comment'    => array( 'The parts input offers all, checkout, cli, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli # back' ) ),
			'a condition with no fallback'   => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts)" ) ),
			'a condition and false'          => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all') && false" ) ),
			'a condition false first'        => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: false && github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a negated condition'            => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && !contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition in a comment'       => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' # contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a continued condition'          => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::after( $lines, '/^        if: .*"webhooks"/', '            && false' ) ),
			'a verdict that never runs'      => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: always() && github.repository == 'cirkuitnet/seocart-gateway-for-stripe' && false" ) ),
			'always only in a comment'       => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-stripe' # always()" ) ),
			'a choice with a space'          => array( 'The parts input offers - cli session, all, checkout, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli session' ) ),
			'a choice with a hash'           => array( 'The parts input offers all, checkout, conformance, three-d-secure, webhooks#2, not exactly', static fn( array $lines ): array => self::replaced( $lines, '/^                    - webhooks$/', '                    - webhooks#2' ) ),
			'another repository checked out' => array( 'the verdict does not check SEOCart (cirkuitnet/seocart) out before its step', static fn( array $lines ): array => self::replaced( $lines, '/^                  repository: cirkuitnet\/seocart$/', '                  repository: cirkuitnet/seocart-fork' ) ),
		);
	}

	/**
	 * Runs the soak-size step's PHP entry as the step does, in a process of its own.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The job's environment file.
	 * @param string $size The soak-size input.
	 * @return array{int, list<string>} Its exit status and what it printed.
	 */
	private static function soakSize( string $file, string $size ): array {
		$output = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- the step's entry, run as the step runs it.
		exec( 'GITHUB_ENV=' . escapeshellarg( $file ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/Integration/Live/soak-size.php' ) . ' ' . escapeshellarg( $size ) . ' 2>&1', $output, $status );

		return array( $status, $output );
	}

	/**
	 * Runs one step of the script copied into a folder of the test's own, its temporary directory in that folder.
	 *
	 * @since 0.2.0
	 *
	 * @param string $root The folder, holding the script as ext/bin/ci/stripe-test-mode.sh.
	 * @param string $step The step and its argument.
	 * @return array{int, string} Its exit status and what it printed.
	 */
	private static function step( string $root, string $step ): array {
		$output = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- the script's own step, in a folder of the test's own.
		exec( sprintf( 'env RUNNER_TEMP=%1$s sh %2$s %3$s 2>&1', escapeshellarg( $root . '/temp' ), escapeshellarg( $root . '/ext/' . self::declaration()->script() ), implode( ' ', array_map( 'escapeshellarg', explode( ' ', $step ) ) ) ), $output, $status );

		return array( $status, implode( "\n", $output ) );
	}

	/**
	 * Removes a file or a folder of the test's own, and everything in it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The file or folder.
	 */
	private static function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $name ) {
				self::remove( $path . '/' . $name );
			}

			rmdir( $path );
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Returns the plugin's declaration of its live workflow.
	 *
	 * @since 0.2.0
	 *
	 * @return LiveDeclaration The declaration.
	 */
	private static function declaration(): LiveDeclaration {
		return LiveDeclaration::load( dirname( __DIR__, 2 ) );
	}

	/**
	 * Returns SEOCart's rule of the settings file, with the script's steps and calls this workflow runs after it.
	 *
	 * @since 0.2.0
	 *
	 * @return SettingsLast The rule.
	 */
	private static function rule(): SettingsLast {
		return new SettingsLast( self::declaration(), self::STEPS, self::EXACT );
	}

	/**
	 * Returns the workflow's file.
	 *
	 * @since 0.2.0
	 *
	 * @return string The file.
	 */
	private static function workflow(): string {
		return dirname( __DIR__, 2 ) . '/.github/workflows/' . self::declaration()->workflow();
	}

	/**
	 * Returns the workflow's lines.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The lines.
	 */
	private static function lines(): array {
		$lines = file( self::workflow(), FILE_IGNORE_NEW_LINES );

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
		array_splice( $lines, self::find( $lines, $pattern ) + 1, 0, array( $line ) );

		return $lines;
	}

	/**
	 * Inserts a line before the first that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line before which it goes.
	 * @param string   $line    The line.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function before( array $lines, string $pattern, string $line ): array {
		array_splice( $lines, self::find( $lines, $pattern ), 0, array( $line ) );

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
