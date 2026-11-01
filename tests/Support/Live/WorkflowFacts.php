<?php
/**
 * WorkflowFacts: what the Stripe test mode workflow must be, read from its text
 *
 * @package SEOCart\GatewayForStripe
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForStripe\Tests\Support\Live;

/**
 * Reads the Stripe test mode workflow line by line, as SEOCart's check of workflows reads them, and says what it breaks.
 *
 * The workflow holds the test account's restricted key, and the repository is public, so its
 * shape is checked on every pull request: it starts only by hand or on its schedule, so it can
 * never be one of the checks a merge waits for; it reads the repository and nothing more; each
 * job runs only in this repository, as a fork has no secrets; a secret reaches only the step that
 * needs it, through that step's `env:`; nothing traces the shell, prints the environment or uploads
 * a file; one run at a time uses the account, and none is cancelled midway; and every job has a
 * time limit. The workflow is plain YAML in a known layout, two spaces deeper per level as the
 * repository's files are written, so its lines are read by their indentation; nothing parses it.
 *
 * @since 0.2.0
 */
final class WorkflowFacts {

	/**
	 * The repository the jobs run in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REPOSITORY = 'cirkuitnet/seocart-gateway-for-stripe';

	/**
	 * The events that start the workflow.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const TRIGGERS = array( 'schedule', 'workflow_dispatch' );

	/**
	 * The concurrency group every run shares.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONCURRENCY_GROUP = 'stripe-test-mode';

	/**
	 * Text no line may hold: a trace of the shell, a dump of the environment, an upload.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const FORBIDDEN = array( 'upload-artifact', 'set -x', 'printenv' );

	/**
	 * Returns what the workflow breaks.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The workflow's lines.
	 * @return list<string> Each fact broken, with the line it is broken on where there is one; none when the workflow keeps them all.
	 * @phpstan-param list<string> $lines
	 */
	public static function violations( array $lines ): array {
		$lines = array_map( static fn( string $line ): string => rtrim( $line, "\r\n" ), $lines );

		return array_merge(
			self::triggers( $lines ),
			self::jobs( $lines ),
			self::permissions( $lines ),
			self::secrets( $lines ),
			self::forbidden( $lines ),
			self::concurrency( $lines )
		);
	}

	/**
	 * Checks that the workflow starts on its schedule and by hand, and on nothing else.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function triggers( array $lines ): array {
		$events = array_keys( self::children( $lines, self::topLevel( $lines, 'on' ) ) );

		sort( $events );

		return self::TRIGGERS === $events ? array() : array( sprintf( 'The workflow starts on %1$s, not on exactly %2$s.', array() === $events ? 'nothing it lists as a block' : implode( ', ', $events ), implode( ', ', self::TRIGGERS ) ) );
	}

	/**
	 * Checks each job: not named `ci`, guarded by the repository, with a time limit.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function jobs( array $lines ): array {
		$problems = array();
		$jobs     = self::children( $lines, self::topLevel( $lines, 'jobs' ) );

		if ( array() === $jobs ) {
			return array( 'The workflow has no jobs.' );
		}

		foreach ( $jobs as $job => $at ) {
			$keys = self::children( $lines, $at );

			if ( 'ci' === $job ) {
				$problems[] = sprintf( 'Line %d: a job is named ci, the name the required checks begin with.', $at + 1 );
			}

			if ( ! isset( $keys['if'] ) || ! str_contains( $lines[ $keys['if'] ], sprintf( "github.repository == '%s'", self::REPOSITORY ) ) ) {
				$problems[] = sprintf( 'Line %1$d: the job %2$s does not run only in %3$s.', $at + 1, $job, self::REPOSITORY );
			}

			if ( ! isset( $keys['timeout-minutes'] ) ) {
				$problems[] = sprintf( 'Line %1$d: the job %2$s has no timeout-minutes.', $at + 1, $job );
			}
		}

		return $problems;
	}

	/**
	 * Checks that every permission the workflow asks for is to read the repository's contents, and that it asks for that at the top.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function permissions( array $lines ): array {
		$problems = array();

		foreach ( $lines as $at => $line ) {
			if ( 1 !== preg_match( '/^(\s*)permissions:\s*(.*)$/', $line, $match ) || self::isComment( $line ) ) {
				continue;
			}

			$granted = self::children( $lines, $at );

			if ( '' !== $match[2] || array( 'contents' ) !== array_keys( $granted ) || 'contents: read' !== trim( $lines[ $granted['contents'] ] ) ) {
				$problems[] = sprintf( 'Line %d: the permissions are not exactly `contents: read`.', $at + 1 );
			}
		}

		if ( null === self::topLevel( $lines, 'permissions' ) ) {
			$problems[] = 'The workflow states no permissions at the top.';
		}

		return $problems;
	}

	/**
	 * Checks that a secret is only ever the value of an entry of a step's `env:`, written `NAME: ${{ secrets.NAME }}`.
	 *
	 * Every expression that names the `secrets` context counts, however it reads it: `secrets.NAME`,
	 * `secrets['NAME']`, the whole context in `toJSON(secrets)`, in any letter case, and an
	 * expression spread over lines. A line that looks like a comment counts too: inside a `run:`
	 * script it is the shell's comment, and GitHub expands the expression in it all the same. An
	 * expression ends, as GitHub reads it, at the first `}}` outside a quoted string, so a `}}`
	 * inside a string cannot end it early and leave the rest unread.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function secrets( array $lines ): array {
		$problems = array();
		$text     = implode( "\n", $lines );

		// An expression runs to the first two closing braces outside a single-quoted string. A
		// quote doubled inside a string, its escape, reads as two strings back to back.
		preg_match_all( '/\$\{\{(?:\'[^\']*+\'|[^\'}]++|\}(?!\}))*+\}\}/', $text, $expressions, PREG_OFFSET_CAPTURE );

		foreach ( $expressions[0] as list( $expression, $offset ) ) {
			if ( 1 !== preg_match( '/\bsecrets\b/i', $expression ) ) {
				continue;
			}

			$at   = substr_count( $text, "\n", 0, $offset );
			$env  = self::parent( $lines, $at );
			$step = null === $env ? null : self::parent( $lines, $env );

			if (
				1 !== preg_match( '/^\s+[A-Z][A-Z0-9_]*: \$\{\{ secrets\.[A-Z][A-Z0-9_]* \}\}$/D', $lines[ $at ] )
				|| null === $env || 'env:' !== trim( $lines[ $env ] )
				|| null === $step || ! str_starts_with( ltrim( $lines[ $step ] ), '- ' )
			) {
				$problems[ $at ] = sprintf( 'Line %d: a secret is used other than as the value of an entry of a step\'s env.', $at + 1 );
			}
		}

		return array_values( $problems );
	}

	/**
	 * Checks that no line traces the shell, prints the environment or uploads a file.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function forbidden( array $lines ): array {
		$problems = array();

		foreach ( $lines as $at => $line ) {
			foreach ( self::FORBIDDEN as $text ) {
				if ( str_contains( $line, $text ) ) {
					$problems[] = sprintf( 'Line %1$d holds `%2$s`.', $at + 1, $text );
				}
			}
		}

		return $problems;
	}

	/**
	 * Checks that every run shares one concurrency group, and none is cancelled by the next.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function concurrency( array $lines ): array {
		$keys  = self::children( $lines, self::topLevel( $lines, 'concurrency' ) );
		$value = static fn( string $key ): string => isset( $keys[ $key ] ) ? trim( (string) preg_replace( '/^[^:]*:/', '', $lines[ $keys[ $key ] ] ) ) : '';

		return self::CONCURRENCY_GROUP === $value( 'group' ) && 'false' === $value( 'cancel-in-progress' )
			? array()
			: array( sprintf( 'The workflow\'s concurrency is not the group %s with cancel-in-progress: false.', self::CONCURRENCY_GROUP ) );
	}

	/**
	 * Finds a key at the top of the workflow.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param string   $key   The key.
	 * @return int|null The key's line; null when there is none.
	 * @phpstan-param list<string> $lines
	 */
	private static function topLevel( array $lines, string $key ): ?int {
		foreach ( $lines as $at => $line ) {
			if ( 1 === preg_match( '/^["\']?' . preg_quote( $key, '/' ) . '["\']?:/', $line ) ) {
				return $at;
			}
		}

		return null;
	}

	/**
	 * Returns the keys directly under a line: those of the first indentation deeper than it, up to the next line no deeper than it.
	 *
	 * A list item's key counts as its own (`- name:` is the key `name`).
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param int|null $at    The line; null for none, which has no keys.
	 * @return array<string, int> Each key's line, by key.
	 * @phpstan-param list<string> $lines
	 */
	private static function children( array $lines, ?int $at ): array {
		if ( null === $at ) {
			return array();
		}

		$depth = self::indent( $lines[ $at ] );
		$first = null;
		$keys  = array();

		$total = count( $lines );

		for ( $next = $at + 1; $next < $total; $next++ ) {
			if ( self::isBlank( $lines[ $next ] ) ) {
				continue;
			}

			$width = self::indent( $lines[ $next ] );

			if ( $width <= $depth ) {
				break;
			}

			$first ??= $width;

			if ( $width === $first && 1 === preg_match( '/^\s*(?:-\s+)?["\']?([A-Za-z0-9_.\-]+)["\']?:/', $lines[ $next ], $match ) ) {
				$keys[ $match[1] ] ??= $next;
			}
		}

		return $keys;
	}

	/**
	 * Returns the line a line belongs to: the nearest before it that is less indented.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param int      $at    The line.
	 * @return int|null The parent's line; null at the top.
	 * @phpstan-param list<string> $lines
	 */
	private static function parent( array $lines, int $at ): ?int {
		$width = self::indent( $lines[ $at ] );

		for ( $before = $at - 1; $before >= 0; $before-- ) {
			if ( ! self::isBlank( $lines[ $before ] ) && self::indent( $lines[ $before ] ) < $width ) {
				return $before;
			}
		}

		return null;
	}

	/**
	 * Returns a line's indentation: its leading spaces, so a list item's keys after its dash sit deeper than the dash.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line.
	 * @return int The width.
	 */
	private static function indent( string $line ): int {
		return strlen( $line ) - strlen( ltrim( $line, ' ' ) );
	}

	/**
	 * Tells whether a line says nothing: empty, or a comment.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line.
	 * @return bool True for a blank line or a comment.
	 */
	private static function isBlank( string $line ): bool {
		return '' === trim( $line ) || self::isComment( $line );
	}

	/**
	 * Tells whether a line is a comment.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line.
	 * @return bool True when its first character other than a space is `#`.
	 */
	private static function isComment( string $line ): bool {
		return str_starts_with( ltrim( $line ), '#' );
	}
}
