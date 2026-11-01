<?php
/**
 * Tests what the cleanup after a Stripe test mode run may cancel or delete
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForStripe\Tests\Support\Live\CleanupSelection;
use SEOCart\GatewayForStripe\Tests\Support\Live\WorkflowStep;
use SEOCart\GatewayForStripe\WebhookEndpoints;

/**
 * The cleanup cancels only the run's PaymentIntents that still wait, and deletes only the endpoints tagged with the run's installation: a planted intent or endpoint of anyone else is never picked. Every intent the conformance suite creates reaches the run list, read from the tapes it recorded, and only from those.
 *
 * Planted violations, each shown red and removed:
 * - in CleanupSelection::intentsToCancel(), the run list's check removed: the planted foreign intent is cancelled;
 * - in CleanupSelection::endpointsToDelete(), the installation's check removed: the planted endpoint of another installation is deleted;
 * - in CleanupSelection::createdIn(), the intent inside a declined answer's error not read: the declined intents the recorded suite left are missing;
 * - in WorkflowStep::harvest(), the mark's check removed: the tape recorded before the mark is read;
 * - in WorkflowStep::harvest(), a tape as old as the mark read (`<` for `<=`): the tape from the mark's own second is listed.
 *
 * @since 0.2.0
 */
final class LiveCleanupSelectionTest extends TestCase {

	/**
	 * The run's installation uuid.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INSTALL = 'a3c1f0de-5b2e-5c4d-9e8f-0a1b2c3d4e5f';

	/**
	 * The directories the test made, removed when it ends.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $directories = array();

	/**
	 * Tests that only the run's waiting intents are picked.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_runs_waiting_intents_are_cancelled(): void {
		$runList = CleanupSelection::runList(
			CleanupSelection::line( 'pi_waiting', '00000000-0000-5000-8000-000000000001' )
			. CleanupSelection::line( 'pi_done', '00000000-0000-5000-8000-000000000002' )
			. CleanupSelection::line( 'pi_capturable', '00000000-0000-5000-8000-000000000003' )
			. CleanupSelection::line( 'pi_retagged', '00000000-0000-5000-8000-000000000004' )
			. "a line of another shape\n"
		);
		$intents = array(
			self::intent( 'pi_waiting', '00000000-0000-5000-8000-000000000001', 'requires_action' ),
			self::intent( 'pi_done', '00000000-0000-5000-8000-000000000002', 'succeeded' ),
			self::intent( 'pi_capturable', '00000000-0000-5000-8000-000000000003', 'requires_capture' ),
			self::intent( 'pi_retagged', '99999999-0000-5000-8000-000000000004', 'requires_action' ),
			self::intent( 'pi_planted_foreign', '00000000-0000-5000-8000-000000000001', 'requires_payment_method' ),
		);

		$this->assertCount( 4, $runList );
		$this->assertSame( array( 'pi_waiting', 'pi_capturable' ), CleanupSelection::intentsToCancel( $runList, $intents ) );
	}

	/**
	 * Tests that only the endpoints tagged with the run's installation are picked.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_installations_endpoints_are_deleted(): void {
		$endpoints = array(
			array(
				'id'       => 'we_ours',
				'metadata' => array( WebhookEndpoints::TAG_INSTALL => self::INSTALL ),
			),
			array(
				'id'       => 'we_planted_other_installation',
				'metadata' => array( WebhookEndpoints::TAG_INSTALL => 'b3c1f0de-5b2e-5c4d-9e8f-0a1b2c3d4e5f' ),
			),
			array(
				'id'       => 'we_planted_untagged',
				'metadata' => array(),
			),
			array( 'id' => 'we_planted_no_metadata' ),
		);

		$this->assertSame( array( 'we_ours' ), CleanupSelection::endpointsToDelete( $endpoints, self::INSTALL, WebhookEndpoints::TAG_INSTALL ) );
	}

	/**
	 * Tests, on the committed tapes of the suite recorded against Stripe, that every PaymentIntent the suite created is listed, and that the cleanup cancels exactly those it left waiting.
	 *
	 * Each intent's state at Stripe is taken as the tapes last saw it. The suite leaves four
	 * intents asking for 3-D Secure, one authorized and never captured, and three declined, each
	 * waiting for another payment method.
	 *
	 * @since 0.2.0
	 */
	public function test_the_recorded_suite_lists_every_intent_it_created_and_those_left_waiting_are_cancelled(): void {
		$tapes   = dirname( __DIR__ ) . '/tapes';
		$listed  = self::listed( $tapes, $this->directory(), 1 );
		$answers = self::lastAnswers( $tapes );
		$waiting = array();

		foreach ( CleanupSelection::intentsToCancel( $listed, array_values( $answers ) ) as $id ) {
			$waiting[ $answers[ $id ]['status'] ][] = $answers[ $id ]['scenario'];
		}

		ksort( $waiting );

		$waiting = array_map(
			static function ( array $scenarios ): array {
				sort( $scenarios );

				return $scenarios;
			},
			$waiting
		);

		$this->assertCount( 32, $listed );
		$this->assertSame( array(), array_diff_key( $answers, $listed ), 'An intent the tapes hold is not on the run list.' );
		$this->assertSame(
			array(
				'requires_action'         => array( 'rule02-cell-eur-us', 'rule02-cell-gbp-us', 'rule02-cell-usd-us', 'rule11-next-action-usd-us' ),
				'requires_capture'        => array( 'rule08-not-found-usd-us' ),
				'requires_payment_method' => array( 'rule05-amounts-usd-us', 'rule08-query-answers-usd-us', 'rule13-logs-usd-us' ),
			),
			$waiting
		);
	}

	/**
	 * Tests that only the tapes recorded after the mark are read, and of their exchanges only those that created a PaymentIntent, a declined one included.
	 *
	 * @since 0.2.0
	 */
	public function test_only_intents_created_in_tapes_recorded_after_the_mark_are_listed(): void {
		$tapes = $this->directory();

		self::tape( $tapes . '/earlier/1.json', self::created( 200, 'pi_earlier', '00000000-0000-5000-8000-000000000011' ), 1000 );
		self::tape( $tapes . '/run/1.json', self::created( 200, 'pi_run', '00000000-0000-5000-8000-000000000012' ), 3000 );
		self::tape( $tapes . '/run/2.json', self::created( 402, 'pi_declined', '00000000-0000-5000-8000-000000000013' ), 3000 );
		self::tape(
			$tapes . '/run/3.json',
			array(
				'request'  => array(
					'method' => 'POST',
					'path'   => '/v1/payment_intents/pi_run/capture',
					'body'   => '',
				),
				'response' => array(
					'status' => 200,
					'body'   => '{"id": "pi_run", "status": "succeeded"}',
				),
			),
			3000
		);

		$this->assertSame(
			array(
				'pi_run'      => '00000000-0000-5000-8000-000000000012',
				'pi_declined' => '00000000-0000-5000-8000-000000000013',
			),
			self::listed( $tapes, $this->directory(), 2000 )
		);
	}

	/**
	 * Tests that a tape from the mark's own second is left out and one from the second after it is listed: a file's time is read in whole seconds.
	 *
	 * @since 0.2.0
	 */
	public function test_a_tape_from_the_marks_own_second_is_not_listed(): void {
		$tapes = $this->directory();

		self::tape( $tapes . '/earlier/1.json', self::created( 200, 'pi_marksSecond', '00000000-0000-5000-8000-000000000021' ), 2000 );
		self::tape( $tapes . '/run/1.json', self::created( 200, 'pi_nextSecond', '00000000-0000-5000-8000-000000000022' ), 2001 );

		$this->assertSame(
			array( 'pi_nextSecond' => '00000000-0000-5000-8000-000000000022' ),
			self::listed( $tapes, $this->directory(), 2000 )
		);
	}

	/**
	 * Tests that the run list is not added to without the mark made before the suite.
	 *
	 * @since 0.2.0
	 */
	public function test_without_its_mark_nothing_is_listed(): void {
		$work     = $this->directory();
		$messages = fopen( 'php://memory', 'w+b' );

		touch( $work . '/run-list' );

		$this->assertIsResource( $messages );
		$this->assertSame( 1, WorkflowStep::harvest( dirname( __DIR__ ) . '/tapes', $work . '/no-mark', $work . '/run-list', $messages ) );
		$this->assertSame( '', file_get_contents( $work . '/run-list' ) );
	}

	/**
	 * Lists, as the conformance step does, the intents of the tapes recorded from a time on.
	 *
	 * @since 0.2.0
	 *
	 * @param string $tapes The tapes.
	 * @param string $work  A directory for the mark and the run list.
	 * @param int    $since When the mark was made.
	 * @return array<string, string> The run list as the cleanup reads it.
	 */
	private static function listed( string $tapes, string $work, int $since ): array {
		$messages = fopen( 'php://memory', 'w+b' );

		self::assertIsResource( $messages );
		touch( $work . '/mark', $since );
		touch( $work . '/run-list' );
		self::assertSame( 0, WorkflowStep::harvest( $tapes, $work . '/mark', $work . '/run-list', $messages ) );

		return CleanupSelection::runList( (string) file_get_contents( $work . '/run-list' ) );
	}

	/**
	 * Returns every PaymentIntent the tapes hold, each as Stripe last answered it, with its scenario.
	 *
	 * @since 0.2.0
	 *
	 * @param string $tapes The tapes.
	 * @return array<string, array<mixed>> The intents, by Stripe's id.
	 */
	private static function lastAnswers( string $tapes ): array {
		$answers = array();

		foreach ( (array) glob( $tapes . '/*', GLOB_ONLYDIR ) as $scenario ) {
			$files = (array) glob( $scenario . '/*.json' );

			natsort( $files );

			foreach ( $files as $file ) {
				$exchange = (array) json_decode( (string) file_get_contents( $file ), true );
				$answer   = json_decode( (string) ( $exchange['response']['body'] ?? '' ), true );
				$intent   = is_array( $answer ) ? ( $answer['error']['payment_intent'] ?? $answer ) : null;

				if ( is_array( $intent ) && 'payment_intent' === ( $intent['object'] ?? null ) ) {
					$answers[ (string) $intent['id'] ] = array( 'scenario' => basename( $scenario ) ) + $intent;
				}
			}
		}

		return $answers;
	}

	/**
	 * Returns a recorded exchange that created a PaymentIntent: approved, or declined inside the error of a 402.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $status Stripe's status: 200 or 402.
	 * @param string $id     The intent's id.
	 * @param string $uuid   The store's uuid the request tagged it with.
	 * @return array<string, mixed> The exchange.
	 */
	private static function created( int $status, string $id, string $uuid ): array {
		$intent = array(
			'id'       => $id,
			'object'   => 'payment_intent',
			'status'   => 200 === $status ? 'requires_capture' : 'requires_payment_method',
			'metadata' => array( 'intent_uuid' => $uuid ),
		);

		return array(
			'request'  => array(
				'method' => 'POST',
				'path'   => CleanupSelection::CREATE_PATH,
				'body'   => http_build_query(
					array(
						'amount'   => 3000,
						'metadata' => array( 'intent_uuid' => $uuid ),
					)
				),
			),
			'response' => array(
				'status' => $status,
				'body'   => (string) json_encode( 200 === $status ? $intent : array( 'error' => array( 'payment_intent' => $intent ) ) ),
			),
		);
	}

	/**
	 * Writes a tape's exchange, recorded at a time.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $file     The file.
	 * @param array<mixed> $exchange The exchange.
	 * @param int          $at       When it was recorded.
	 */
	private static function tape( string $file, array $exchange, int $at ): void {
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0700, true );
		}

		file_put_contents( $file, (string) json_encode( $exchange ) );
		touch( $file, $at );
	}

	/**
	 * Returns a fresh directory, removed when the test ends.
	 *
	 * @since 0.2.0
	 *
	 * @return string The directory.
	 */
	private function directory(): string {
		$directory = sys_get_temp_dir() . '/stripe-cleanup-test-' . bin2hex( random_bytes( 6 ) );

		mkdir( $directory, 0700 );
		$this->directories[] = $directory;

		return $directory;
	}

	/**
	 * Removes the directories the test made.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		foreach ( $this->directories as $directory ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );

			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getPathname() );
				} else {
					unlink( $file->getPathname() );
				}
			}

			rmdir( $directory );
		}

		parent::tearDown();
	}

	/**
	 * Returns a PaymentIntent as Stripe answers it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id     Its id.
	 * @param string $uuid   The intent uuid it is tagged with.
	 * @param string $status Its status.
	 * @return array<string, mixed> The PaymentIntent.
	 */
	private static function intent( string $id, string $uuid, string $status ): array {
		return array(
			'id'       => $id,
			'status'   => $status,
			'metadata' => array( 'intent_uuid' => $uuid ),
		);
	}
}
