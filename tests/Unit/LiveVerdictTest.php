<?php
/**
 * Tests the closing verdict of the Stripe test mode run
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\Verdict;

/**
 * A run is green when every job its parts selected succeeded, the suite's job reported its counts and the replay's skips, and red otherwise; a part not selected is skipped and stays green; the table names both heads.
 *
 * Planted violations, each shown red and removed:
 * - in Verdict::judge(), a skipped job counted as a success: the run whose selected job skipped is green;
 * - in Verdict::judge(), the counts' check removed: the run without the suite's counts is green.
 *
 * @since 0.2.0
 */
final class LiveVerdictTest extends TestCase {

	/**
	 * The adapter's head the jobs report.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EXT = '7390d37aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * SEOCart's head the jobs report.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CORE = 'f466a06912bab32012167a0e5173495def8c6b5c';

	/**
	 * Tests that a run whose every job succeeded is green, with both heads and the counts in its table.
	 *
	 * @since 0.2.0
	 */
	public function test_every_job_succeeded_is_green(): void {
		$verdict = Verdict::judge( self::needs(), 'all' );

		$this->assertTrue( $verdict['green'], implode( ' ', $verdict['problems'] ) );
		$this->assertStringContainsString( '`' . self::EXT . '`', $verdict['summary'] );
		$this->assertStringContainsString( '`' . self::CORE . '`', $verdict['summary'] );
		$this->assertStringContainsString( '17 tests, 307 assertions, 2 skipped', $verdict['summary'] );
		$this->assertStringNotContainsString( 'CLI', $verdict['summary'], 'No proof of the run belongs to the CLI.' );
		$this->assertTrue( Verdict::judge( self::needs(), '' )['green'], 'A scheduled run, which gives no parts, runs all.' );
	}

	/**
	 * Tests that a selected job that skipped, failed or is missing makes the run red.
	 *
	 * @since 0.2.0
	 */
	public function test_a_selected_job_skipped_or_failed_is_red(): void {
		$skipped = Verdict::judge( self::needs( array( 'three-d-secure' => 'skipped' ) ), 'all' );
		$this->assertFalse( $skipped['green'] );
		$this->assertSame( array( 'The job three-d-secure was selected and ended skipped.' ), $skipped['problems'] );

		$this->assertFalse( Verdict::judge( self::needs( array( 'webhooks' => 'failure' ) ), 'webhooks' )['green'] );

		$needs = self::needs();
		unset( $needs['webhooks'] );
		$this->assertSame( array( 'The job webhooks was selected and ended missing.' ), Verdict::judge( $needs, 'all' )['problems'] );
	}

	/**
	 * Tests that the parts not selected are skipped and the run stays green.
	 *
	 * @since 0.2.0
	 */
	public function test_a_part_not_selected_is_skipped_and_green(): void {
		$needs = self::needs(
			array(
				'live-conformance' => 'skipped',
				'three-d-secure'   => 'skipped',
			)
		);

		$this->assertTrue( Verdict::judge( $needs, 'webhooks' )['green'] );
		$this->assertFalse( Verdict::judge( $needs, 'all' )['green'] );
		$this->assertFalse( Verdict::judge( $needs, 'everything' )['green'], 'An unknown part is refused.' );
		$this->assertSame( array( 'The parts input `cli` names no part: all, conformance, three-d-secure, webhooks.' ), Verdict::judge( self::needs(), 'cli' )['problems'], 'The CLI is not a part.' );
	}

	/**
	 * Tests that the suite's job without its counts, or with other skips, makes the run red.
	 *
	 * @since 0.2.0
	 */
	public function test_the_suites_counts_missing_or_its_skips_differing_is_red(): void {
		$needs = self::needs();
		unset( $needs['live-conformance']['outputs']['tests'] );
		$this->assertSame( array( 'The job live-conformance reported no count of tests.' ), Verdict::judge( $needs, 'all' )['problems'] );

		$needs = self::needs();
		$needs['live-conformance']['outputs']['skip-set'] = 'differs';
		$this->assertSame( array( 'The job live-conformance skipped other tests than the two the replay skips.' ), Verdict::judge( $needs, 'conformance' )['problems'] );
	}

	/**
	 * Returns the jobs' results and outputs, as `toJSON(needs)` gives them.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $results Results that differ from success, by job.
	 * @return array<string, array{result: string, outputs: array<string, string>}> The jobs.
	 */
	private static function needs( array $results = array() ): array {
		$needs = array();

		foreach ( array_keys( Verdict::JOBS ) as $job ) {
			$needs[ $job ] = array(
				'result'  => $results[ $job ] ?? 'success',
				'outputs' => array(
					'ext-head'  => self::EXT,
					'core-head' => self::CORE,
				),
			);
		}

		$needs['live-conformance']['outputs'] += array(
			'tests'      => '17',
			'assertions' => '307',
			'skipped'    => '2',
			'skip-set'   => 'expected',
			'nonce'      => 'r123456789.1',
		);

		return $needs;
	}
}
