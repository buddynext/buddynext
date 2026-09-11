<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Poll voting and results service.
 *
 * Manages vote recording for poll posts. Each user may cast exactly one vote
 * per poll (enforced by a UNIQUE KEY on bn_poll_votes). Votes also increment
 * the denormalised vote_count on the relevant bn_poll_options row.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use WP_Error;

/**
 * Handles poll voting and result reads.
 */
class PollService {

	/**
	 * Object-cache group + TTL for poll results.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'buddynext_polls';

	/**
	 * Poll-results TTL in seconds (bust on every vote keeps it fresh).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 600;

	/**
	 * Evict the cached results for a poll. Called from every vote.
	 *
	 * @param int $post_id Poll post ID.
	 * @param int $user_id Voting user (0 to skip the per-viewer vote cache).
	 * @return void
	 */
	private function invalidate_results( int $post_id, int $user_id = 0 ): void {
		wp_cache_delete( "results_{$post_id}", self::CACHE_GROUP );
		if ( $user_id > 0 ) {
			wp_cache_delete( "uservote_{$user_id}_{$post_id}", self::CACHE_GROUP );
		}
	}

	/**
	 * Cast or switch a vote for an option in a poll.
	 *
	 * If the user has already voted for a different option, the old vote is
	 * removed and the new vote inserted (vote switching). If they click the
	 * same option they already voted for, the vote is removed (toggle off).
	 *
	 * Returns WP_Error('not_a_poll') if the post is not a poll.
	 *
	 * @param int $user_id   Voting user.
	 * @param int $post_id   Poll post ID.
	 * @param int $option_id Option to vote for.
	 * @return true|WP_Error
	 */
	public function vote( int $user_id, int $post_id, int $option_id ): bool|WP_Error {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Verify the post is a poll.
		$type = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT type FROM {$wpdb->prefix}bn_posts WHERE id = %d",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 'poll' !== $type ) {
			return new WP_Error(
				'not_a_poll',
				__( 'This post is not a poll.', 'buddynext' )
			);
		}

		// Reject votes once the poll's deadline has passed. end_date is stored on
		// the option rows (the same poll-level value on each); compare the max
		// against the DB's UTC clock, matching how the deadline is stored (UTC).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$is_closed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_poll_options
				 WHERE post_id = %d AND end_date IS NOT NULL AND end_date <= UTC_TIMESTAMP()",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $is_closed > 0 ) {
			return new WP_Error(
				'poll_closed',
				__( 'This poll has closed and is no longer accepting votes.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Filter poll-vote data before it is written.
		 *
		 * Return modified data to alter the choice, or a WP_Error to reject the vote.
		 *
		 * @param array $data    Vote data (user_id, post_id, option_id).
		 * @param int   $user_id Voting user ID.
		 */
		$filtered = apply_filters(
			'buddynext_poll_vote_before_save',
			array(
				'user_id'   => $user_id,
				'post_id'   => $post_id,
				'option_id' => $option_id,
			),
			$user_id
		);
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		$option_id = (int) ( $filtered['option_id'] ?? $option_id );

		// The option must belong to THIS poll. Without this, a crafted option_id
		// from another poll sails through: bn_poll_votes and the vote_count UPDATE
		// below both key on option_id alone, so a foreign option inflates the other
		// poll's tally. Reject before any write.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_in_poll = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_poll_options WHERE id = %d AND post_id = %d",
				$option_id,
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 1 !== $option_in_poll ) {
			return new WP_Error(
				'invalid_option',
				__( 'That option does not belong to this poll.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Fetch existing vote (returns option_id or null).
		$existing_option_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->prefix}bn_poll_votes
				 WHERE post_id = %d AND user_id = %d",
				$post_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null !== $existing_option_id ) {
			// Remove previous vote and decrement its count — only when THIS request
			// actually removed the row, so concurrent toggles can't each subtract
			// from the same vote.
			$removed = $wpdb->delete(
				$wpdb->prefix . 'bn_poll_votes',
				array(
					'post_id' => $post_id,
					'user_id' => $user_id,
				),
				array( '%d', '%d' )
			);
			if ( $removed > 0 ) {
				// Scope the decrement by post_id, exactly like the increment below.
				// $existing_option_id is read straight off the user's stored vote row,
				// which — for a row poisoned before the vote() guard landed — can be a
				// foreign poll's option. Without the post_id clause, changing or
				// toggling off such a vote decremented the OTHER poll's counter. With
				// it, a poisoned option (post_id != this poll) matches nothing and the
				// foreign counter is left alone; the bad vote row is still removed. The
				// increment already carried AND post_id, so this closes the last
				// unscoped write.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}bn_poll_options
						 SET vote_count = GREATEST(1, vote_count) - 1
						 WHERE id = %d AND post_id = %d",
						(int) $existing_option_id,
						$post_id
					)
				);
			}

			// Same option clicked again → toggle off, we're done.
			if ( (int) $existing_option_id === $option_id ) {
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				PostService::flush_cache( $post_id );
				$this->invalidate_results( $post_id, $user_id );
				return true;
			}
		}

		// Insert the new vote with INSERT IGNORE (the one_vote_per_user UNIQUE key
		// rejects a concurrent duplicate) and increment the option only when THIS
		// request actually created the row — preventing inflated vote counts under
		// concurrent requests.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_poll_votes (post_id, option_id, user_id)
				 VALUES (%d, %d, %d)",
				$post_id,
				$option_id,
				$user_id
			)
		);
		if ( $inserted > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_poll_options
					 SET vote_count = vote_count + 1
					 WHERE id = %d AND post_id = %d",
					$option_id,
					$post_id
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		/**
		 * Fires after a poll vote is cast or switched.
		 *
		 * Does NOT fire on toggle-off (clicking the same option to remove a
		 * vote) — that path returns early above. Vote switches (different
		 * option than previous) fire once, after the new vote is inserted.
		 *
		 * @param int $post_id   Poll post ID.
		 * @param int $option_id Option the user voted for.
		 * @param int $user_id   Voting user.
		 */
		do_action( 'buddynext_poll_voted', $post_id, $option_id, $user_id );

		PostService::flush_cache( $post_id );
		$this->invalidate_results( $post_id, $user_id );

		return true;
	}

	/**
	 * Return the options and vote counts for a poll.
	 *
	 * @param int $post_id Poll post ID.
	 * @return array[] Array of option rows: id, option_text, display_order, vote_count.
	 */
	public function results( int $post_id ): array {
		$hit = wp_cache_get( "results_{$post_id}", self::CACHE_GROUP );
		if ( is_array( $hit ) ) {
			return $hit;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, option_text, display_order, vote_count
				 FROM {$wpdb->prefix}bn_poll_options
				 WHERE post_id = %d
				 ORDER BY display_order ASC",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array_map(
			fn( $r ) => array(
				'id'            => (int) $r['id'],
				'option_text'   => $r['option_text'],
				'display_order' => (int) $r['display_order'],
				'vote_count'    => (int) $r['vote_count'],
			),
			(array) $rows
		);
		wp_cache_set( "results_{$post_id}", $out, self::CACHE_GROUP, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Return the option ID the user voted for, or null if they have not voted.
	 *
	 * @param int $user_id User to check.
	 * @param int $post_id Poll post ID.
	 * @return int|null Option ID or null.
	 */
	public function user_vote( int $user_id, int $post_id ): ?int {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return null;
		}

		// Per-viewer cache (0 = "checked, no vote"); primed en masse by
		// user_votes_map() so the SSR feed does not run one query per poll card,
		// and busted on every vote via invalidate_results().
		$key    = "uservote_{$user_id}_{$post_id}";
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return 0 === (int) $cached ? null : (int) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->prefix}bn_poll_votes
				 WHERE post_id = %d AND user_id = %d",
				$post_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_set( $key, null !== $option_id ? (int) $option_id : 0, self::CACHE_GROUP, self::CACHE_TTL );

		return null !== $option_id ? (int) $option_id : null;
	}

	/**
	 * Batch-fetch a viewer's poll votes across many posts in ONE query.
	 *
	 * Returns a map of post_id => option_id for poll posts the viewer has voted on.
	 * Replaces calling user_vote() per feed card (the per-card N+1).
	 *
	 * @param int   $user_id  Viewer.
	 * @param int[] $post_ids Poll post IDs to look up.
	 * @return array<int,int> post_id => option_id (absent when not voted).
	 */
	public function user_votes_map( int $user_id, array $post_ids ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

		if ( 0 === $user_id || empty( $post_ids ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$params       = array_merge( array( $user_id ), $post_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, option_id FROM {$wpdb->prefix}bn_poll_votes
				 WHERE user_id = %d AND post_id IN ( {$placeholders} )",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['post_id'] ] = (int) $row['option_id'];
		}

		// Warm the per-viewer cache for every requested id (hit or miss), so a
		// later user_vote() during SSR render is a free cache hit — the whole
		// page's poll votes cost this one query.
		foreach ( $post_ids as $pid ) {
			wp_cache_set( "uservote_{$user_id}_{$pid}", $map[ $pid ] ?? 0, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $map;
	}

	/**
	 * Reconcile every poll's denormalised counters and purge cross-linked votes.
	 *
	 * Owner-facing repair for polls corrupted before the vote() option-ownership
	 * guard landed: a vote cast with a foreign poll's option id left a
	 * cross-linked bn_poll_votes row and, on older builds, an inflated vote_count
	 * on the other poll's option. Both are fixed here in two set-based passes so
	 * the operation is scale-safe (no per-poll loop) and needs no schema
	 * migration — the owner runs it from Tools when a poll's percentages look
	 * wrong. Replaces the one-off cleanup migration the RFT verdict asked for,
	 * per the no-DB-migrations-pre-release rule.
	 *
	 * @since 1.2.0
	 *
	 * @return array{deleted:int, recounted:int} Vote rows purged and option rows reconciled.
	 */
	public function recount_all_poll_votes(): array {
		global $wpdb;

		// (1) Purge cross-linked votes: any vote row whose (option_id, post_id)
		// pair does not name a real option in that poll. Such a row counts toward
		// no legitimate option and wrongly occupies the voter's one-vote slot on a
		// poll they never truly voted in.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// Capture the (user_id, post_id) pairs BEFORE the delete so the per-viewer
		// "you voted" cache (uservote_{uid}_{pid}) can be evicted for the very voters
		// this purge removes — otherwise a purged voter keeps seeing "you voted" for
		// up to the Redis TTL, and a poll whose options were ALL deleted never enters
		// the pass-2 recount loop (it selects from bn_poll_options), so its results
		// cache would never be touched either (card 10264292330).
		// ponytail: loads all orphan pairs into memory; orphans are a data anomaly a
		// repair run mops up, not a steady-state volume, so this is bounded in
		// practice. Page it if a site ever proves otherwise.
		$orphans = (array) $wpdb->get_results(
			"SELECT v.user_id, v.post_id FROM {$wpdb->prefix}bn_poll_votes v
			 LEFT JOIN {$wpdb->prefix}bn_poll_options o
			        ON o.id = v.option_id AND o.post_id = v.post_id
			 WHERE o.id IS NULL",
			ARRAY_A
		);

		$deleted = (int) $wpdb->query(
			"DELETE v FROM {$wpdb->prefix}bn_poll_votes v
			 LEFT JOIN {$wpdb->prefix}bn_poll_options o
			        ON o.id = v.option_id AND o.post_id = v.post_id
			 WHERE o.id IS NULL"
		);

		// Evict the purged voters' per-viewer vote cache and the affected polls'
		// result cache. results_{pid} is repeated for polls that DO reach pass-2, but
		// wp_cache_delete is idempotent and this is the only path that covers polls
		// with zero surviving options.
		foreach ( $orphans as $orphan ) {
			$o_uid = (int) ( $orphan['user_id'] ?? 0 );
			$o_pid = (int) ( $orphan['post_id'] ?? 0 );
			if ( $o_pid > 0 ) {
				wp_cache_delete( "results_{$o_pid}", self::CACHE_GROUP );
				if ( $o_uid > 0 ) {
					wp_cache_delete( "uservote_{$o_uid}_{$o_pid}", self::CACHE_GROUP );
				}
			}
		}

		// (2) Reconcile every option's vote_count from the surviving, correctly
		// linked votes. Done in bounded PAGES of polls rather than one whole-table
		// UPDATE with a per-row correlated subquery: on a site with thousands of
		// polls that single statement was a long write lock run synchronously in an
		// admin-post request, with a plausible PHP timeout (card 10264292330). Each
		// page's UPDATE is scoped to its post_id set, so no statement locks the
		// whole table, and only the polls actually touched have their result cache
		// invalidated — replacing the site-wide wp_cache_flush() that dropped Redis
		// and everything else on one admin click.
		// ponytail: bounded loop in-request; move to Action Scheduler fan-out if a
		// site ever has enough polls that even the paged loop times out.
		$recounted = 0;
		$batch     = 500;
		$offset    = 0;
		do {
			$post_ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT post_id FROM {$wpdb->prefix}bn_poll_options
						 ORDER BY post_id LIMIT %d OFFSET %d",
						$batch,
						$offset
					)
				)
			);
			$found    = count( $post_ids );
			if ( 0 === $found ) {
				break;
			}

			// $post_ids are ints from the DB; safe to inline as an IN() list.
			$in         = implode( ',', $post_ids );
			$recounted += (int) $wpdb->query(
				"UPDATE {$wpdb->prefix}bn_poll_options o
				 SET o.vote_count = (
				     SELECT COUNT(*) FROM {$wpdb->prefix}bn_poll_votes v
				     WHERE v.option_id = o.id AND v.post_id = o.post_id
				 )
				 WHERE o.post_id IN ({$in})"
			);

			// Invalidate only these polls' result caches (see clear_cache()).
			foreach ( $post_ids as $pid ) {
				wp_cache_delete( "results_{$pid}", self::CACHE_GROUP );
			}

			$offset += $batch;
		} while ( $found === $batch );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'deleted'   => $deleted,
			'recounted' => $recounted,
		);
	}
}
