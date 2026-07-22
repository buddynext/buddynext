<?php
/**
 * WP-CLI: find and repair member handles that cannot be mentioned.
 *
 * A `user_nicename` outside {@see Handle::CHARSET} cannot be produced by
 * WordPress or by BuddyNext — `sanitize_title()` strips the offending characters,
 * and BuddyNext's signup derives a login from the local part of the email. It
 * only arrives by a direct database write, which is exactly what a migration from
 * another platform does.
 *
 * The member is then silently unmentionable: the parsers stop at the first
 * foreign character, so `@name@example-com` reads as `name` followed by
 * `example-com` and neither resolves. Their profile still works, which is why the
 * fault goes unnoticed until someone reports that a member "does not come up".
 *
 * The repair normalises `user_nicename` back to what WordPress itself would have
 * written, rather than layering a BuddyNext-only override on top. That is what
 * makes it uniform: partner plugins read `user_nicename` and become correct
 * without knowing BuddyNext exists.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use WP_CLI;

/**
 * Audits and repairs unmentionable member handles.
 */
class HandleCommand {

	/**
	 * Rows scanned per chunk. A community may hold many thousands of members.
	 */
	private const CHUNK = 500;

	/**
	 * List members whose handle cannot be mentioned.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext handles check
	 *
	 * @param array $args       Positional args (unused — WP-CLI signature).
	 * @param array $assoc_args Associative args (unused).
	 * @return void
	 */
	public function check( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$rows = $this->unsafe_rows();

		if ( empty( $rows ) ) {
			WP_CLI::success( 'Every member handle can be mentioned.' );
			return;
		}

		WP_CLI::warning(
			sprintf(
				/* translators: %d: number of members. */
				_n(
					'%d member has a handle that cannot be mentioned:',
					'%d members have handles that cannot be mentioned:',
					count( $rows ),
					'buddynext'
				),
				count( $rows )
			)
		);

		foreach ( $rows as $row ) {
			$safe = Handle::make_safe( $row['user_nicename'] );

			WP_CLI::log(
				sprintf(
					'  #%-6d %-32s -> %s',
					$row['ID'],
					$row['user_nicename'],
					'' !== $safe ? $safe : '(cannot derive - set one by hand)'
				)
			);
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Run `wp buddynext handles repair --yes` to apply. Profile URLs change for these members.' );
	}

	/**
	 * Normalise unmentionable handles to WordPress's own nicename rules.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Apply the changes. Without this the command only reports what it would do,
	 * because the repair rewrites profile URLs.
	 *
	 * [--dry-run]
	 * : Report only. Implied when --yes is absent; accepted so an explicit
	 * dry run reads clearly in a script.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext handles repair
	 *     wp buddynext handles repair --yes
	 *
	 * @param array $args       Positional args (unused — WP-CLI signature).
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function repair( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		$dry_run = isset( $assoc_args['dry-run'] );
		$rows    = $this->unsafe_rows();

		if ( empty( $rows ) ) {
			WP_CLI::success( 'Every member handle can be mentioned. Nothing to repair.' );
			return;
		}

		// Dry-run unless the operator opts IN. This rewrites profile URLs, and an
		// interactive prompt is not available in every context this runs in (cron,
		// CI, a deploy hook), so the safe direction is the default one.
		if ( ! isset( $assoc_args['yes'] ) ) {
			$dry_run = true;
		}

		$repaired = 0;
		$skipped  = 0;

		foreach ( $rows as $row ) {
			$user_id = (int) $row['ID'];
			$current = (string) $row['user_nicename'];
			$safe    = Handle::make_safe( $current );

			// Nothing usable survived — an all-foreign handle. Writing an empty
			// nicename would break the member's profile URL entirely, so this is
			// reported for a human to name rather than guessed at.
			if ( '' === $safe ) {
				WP_CLI::warning( sprintf( 'User %d (%s): cannot derive a handle — set one by hand.', $user_id, $current ) );
				++$skipped;
				continue;
			}

			$safe = $this->unique_nicename( $safe, $user_id );

			if ( $dry_run ) {
				WP_CLI::log( sprintf( '  would repair %d: %s -> %s', $user_id, $current, $safe ) );
				++$repaired;
				continue;
			}

			// Through core's own API, not a direct write: wp_update_user runs the
			// same sanitisation and cache invalidation core uses, so the row ends
			// up indistinguishable from one WordPress wrote itself.
			$result = wp_update_user(
				array(
					'ID'            => $user_id,
					'user_nicename' => $safe,
				)
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( 'User %d: %s', $user_id, $result->get_error_message() ) );
				++$skipped;
				continue;
			}

			WP_CLI::log( sprintf( '  repaired %d: %s -> %s', $user_id, $current, $safe ) );
			++$repaired;
		}

		// The Members screen caches this count; a stale cache would keep warning
		// about members that were just repaired.
		if ( ! $dry_run ) {
			delete_transient( 'bn_unmentionable_handles' );
		}

		$summary = sprintf(
			'%s %d handle(s); %d skipped.',
			$dry_run ? 'Would repair' : 'Repaired',
			$repaired,
			$skipped
		);

		if ( $skipped > 0 ) {
			WP_CLI::warning( $summary );
			return;
		}

		WP_CLI::success( $summary );
	}

	/**
	 * Members whose nicename falls outside the handle contract.
	 *
	 * Filtered in PHP rather than by a SQL character class: collation decides what
	 * a range like `a-z` means in MySQL, so a REGEXP here could disagree with the
	 * PCRE the parsers actually run. The scan is chunked and reads two columns.
	 *
	 * @return array<int,array{ID:int,user_nicename:string}>
	 */
	private function unsafe_rows(): array {
		global $wpdb;

		$out     = array();
		$offset  = 0;
		$fetched = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$batch = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, user_nicename FROM {$wpdb->users} ORDER BY ID ASC LIMIT %d OFFSET %d",
					self::CHUNK,
					$offset
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$batch = (array) $batch;

			foreach ( $batch as $row ) {
				if ( ! Handle::is_safe( (string) $row['user_nicename'] ) ) {
					$out[] = array(
						'ID'            => (int) $row['ID'],
						'user_nicename' => (string) $row['user_nicename'],
					);
				}
			}

			$offset += self::CHUNK;
			$fetched = count( $batch );
		} while ( self::CHUNK === $fetched );

		return $out;
	}

	/**
	 * A nicename not already taken by a different user.
	 *
	 * Two imported handles can normalise onto the same string — `a.b@corp.com` and
	 * `a-b@corp.com` both reduce to `abcorp-com`. Without this the second write
	 * would collide, and core would silently suffix it in a way the operator never
	 * saw reported.
	 *
	 * @param string $base    Candidate nicename.
	 * @param int    $user_id User being repaired.
	 * @return string A free nicename.
	 */
	private function unique_nicename( string $base, int $user_id ): string {
		$candidate = $base;
		$n         = 2;

		while ( true ) {
			$existing = get_user_by( 'slug', $candidate );

			if ( ! $existing || (int) $existing->ID === $user_id ) {
				return $candidate;
			}

			$candidate = $base . '-' . $n;
			++$n;
		}
	}
}
