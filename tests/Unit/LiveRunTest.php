<?php
/**
 * Tests what the live tests, their cleanup and the workflow's script share of a run
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\LiveRun;

/**
 * The run's installation is the version-5 uuid of the repository's address, the same on every run; a fact reported is one safe line, and a report reads back its last value of each fact.
 *
 * @since 0.2.0
 */
final class LiveRunTest extends TestCase {

	/**
	 * Tests the installation uuid: RFC 9562's version-5 uuid of the repository's address in the URL namespace, its version and variant bits set.
	 *
	 * @since 0.2.0
	 */
	public function test_the_installation_is_the_version_5_uuid_of_the_repository(): void {
		$uuid = LiveRun::installUuid();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid );
		$this->assertSame( $uuid, LiveRun::installUuid() );
		// The first 32 bits are those of SHA-1 over the namespace's 16 bytes and the name.
		$this->assertSame( substr( sha1( (string) hex2bin( '6ba7b8119dad11d180b400c04fd430c8' ) . LiveRun::REPOSITORY_URL ), 0, 8 ), substr( $uuid, 0, 8 ) );
	}

	/**
	 * Tests that a report keeps one line per fact, and reads back the last value of each.
	 *
	 * @since 0.2.0
	 */
	public function test_a_report_is_written_and_read_back(): void {
		$file = tempnam( sys_get_temp_dir(), 'live-report-' );

		$this->assertIsString( $file );
		putenv( LiveRun::REPORT . '=' . $file );

		try {
			LiveRun::report( 'seed', 42 );
			LiveRun::report( 'api-versions', "2020-08-27 x3\nmore" );
			LiveRun::report( 'seed', 43 );

			$this->assertSame( 0600, fileperms( $file ) & 0777 );
			$this->assertSame(
				array(
					'seed'         => '43',
					'api-versions' => '2020-08-27 x3 more',
				),
				LiveRun::facts( (string) file_get_contents( $file ) )
			);
		} finally {
			putenv( LiveRun::REPORT );
			unlink( $file );
		}
	}
}
