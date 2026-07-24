<?php
/**
 * BuddyNext — repair for member handles that cannot be mentioned.
 *
 * A `user_nicename` holding characters the mention parsers stop at — an email
 * address, typically, written straight into the column by a migration — makes
 * that member silently unmentionable. BuddyNext never writes such a value
 * ({@see \BuddyNext\Profile\Handle} for why); it only arrives by import.
 *
 * This is the ONE implementation of finding and repairing them. The WP-CLI
 * command, the admin warning's count, and the admin "Repair" button all call it,
 * so the three entry points cannot drift — the same rows are found and normalised
 * the same way whichever door the owner comes through.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

/**
 * Finds and normalises unmentionable member handles.
 */
final class HandleRepair {

	/**
	 * Rows scanned per chunk. A community may hold many thousands of members.
	 */
	private const CHUNK = 500;

	/**
	 * Transient the Members screen caches the count in.
	 */
	public const COUNT_CACHE = 'bn_unmentionable_handles';

	/**
	 * Members whose nicename falls outside the handle contract.
	 *
	 * Filtered in PHP rather than by a SQL character class: collation decides what
	 * a range like `a-z` means in MySQL, so a REGEXP alone could disagree with the
	 * PCRE the parsers actually run. SQL narrows to candidates, PHP decides.
	 *
	 * @param int $limit Hard ceiling on rows returned (0 = no limit).
	 * @return array<int,array{ID:int,user_nicename:string}>
	 */
	public function find_unsafe( int $limit = 0 ): array {
		global $wpdb;

		$out     = array();
		$offset  = 0;
		$fetched = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$batch = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, user_nicename FROM {$wpdb->users}
					 WHERE user_nicename REGEXP '[^a-zA-Z0-9_-]'
					 ORDER BY ID ASC LIMIT %d OFFSET %d",
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
					if ( $limit > 0 && count( $out ) >= $limit ) {
						return $out;
					}
				}
			}

			$offset += self::CHUNK;
			$fetched = count( $batch );
		} while ( self::CHUNK === $fetched );

		return $out;
	}

	/**
	 * How many members cannot be mentioned, cached.
	 *
	 * The condition only changes on import or repair, so re-scanning a large
	 * roster on every admin render — for an answer that is almost always zero —
	 * would be wasteful. Both writers of the underlying data clear this key.
	 *
	 * @param bool $fresh Skip the cache and recount.
	 * @return int
	 */
	public function count_unsafe( bool $fresh = false ): int {
		if ( ! $fresh ) {
			$cached = get_transient( self::COUNT_CACHE );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		$count = count( $this->find_unsafe() );
		set_transient( self::COUNT_CACHE, $count, 12 * HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Normalise every unmentionable handle to WordPress's own nicename rules.
	 *
	 * Writes through `wp_update_user()`, not a direct query, so each row ends up
	 * indistinguishable from one core wrote itself — same sanitisation, same cache
	 * invalidation. A handle with nothing usable left (`@@@`) is SKIPPED, never
	 * written as an empty nicename, which would break the member's profile URL.
	 *
	 * @param bool $dry_run Report what would change without writing.
	 * @return array{repaired:int,skipped:int,changes:array<int,array{id:int,from:string,to:string}>,skips:array<int,array{id:int,from:string}>}
	 */
	public function repair_all( bool $dry_run = false ): array {
		$repaired = 0;
		$skipped  = 0;
		$changes  = array();
		$skips    = array();

		foreach ( $this->find_unsafe() as $row ) {
			$user_id = (int) $row['ID'];
			$current = (string) $row['user_nicename'];
			$safe    = Handle::make_safe( $current );

			if ( '' === $safe ) {
				++$skipped;
				$skips[] = array(
					'id'   => $user_id,
					'from' => $current,
				);
				continue;
			}

			$safe = $this->unique_nicename( $safe, $user_id );

			if ( ! $dry_run ) {
				$result = wp_update_user(
					array(
						'ID'            => $user_id,
						'user_nicename' => $safe,
					)
				);

				if ( is_wp_error( $result ) ) {
					++$skipped;
					$skips[] = array(
						'id'   => $user_id,
						'from' => $current,
					);
					continue;
				}
			}

			++$repaired;
			$changes[] = array(
				'id'   => $user_id,
				'from' => $current,
				'to'   => $safe,
			);
		}

		if ( ! $dry_run ) {
			delete_transient( self::COUNT_CACHE );
		}

		return array(
			'repaired' => $repaired,
			'skipped'  => $skipped,
			'changes'  => $changes,
			'skips'    => $skips,
		);
	}

	/**
	 * A nicename not already taken by a different user.
	 *
	 * Two imported handles can normalise onto the same string — `a.b@corp.com` and
	 * `a-b@corp.com` both reduce to `abcorp-com`. Without this the second write
	 * would collide and core would silently suffix it where nobody saw.
	 *
	 * @param string $base    Candidate nicename.
	 * @param int    $user_id User being repaired.
	 * @return string
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
