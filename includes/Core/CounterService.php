<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Denormalised counter update service.
 *
 * Per spec 19, BuddyNext stores pre-computed counts on parent rows to avoid
 * COUNT(*) queries on hot read paths.  This service recomputes each counter
 * from source data and writes it back to the relevant column.
 *
 * In production the recount methods are invoked asynchronously via WP-Cron
 * jobs registered by CronScheduler.  They may also be called directly for
 * small, synchronous updates (e.g. after an immediate write).
 *
 * Counters managed here:
 *   bn_follows   → wp_usermeta bn_follower_count / bn_following_count
 *   bn_connections → wp_usermeta bn_connection_count
 *   bn_reactions → bn_posts.reaction_count
 *   bn_comments  → bn_posts.comment_count
 *   bn_space_members → bn_spaces.member_count
 *   bn_post_hashtags → bn_hashtags.post_count
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Recomputes and persists denormalized counter values.
 */
class CounterService {

	// ── Follow counts ─────────────────────────────────────────────────────────

	/**
	 * Recount and store the follower / following counts for a user.
	 *
	 * Stores results in:
	 *   wp_usermeta bn_follower_count  — number of users following $user_id
	 *   wp_usermeta bn_following_count — number of users $user_id follows
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function recount_follow_counts( int $user_id ): void {
		global $wpdb;

		// Count only APPROVED follows — a pending request to a private account is
		// not a follower yet, and FollowService::follower_count()/following_count()
		// both filter status = 'approved', so the denormalised store must match or
		// the displayed count would jump on every (still-pending) request.
		$follower_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE following_id = %d AND status = 'approved'",
				$user_id
			)
		);

		$following_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND status = 'approved'",
				$user_id
			)
		);

		update_user_meta( $user_id, 'bn_follower_count', $follower_count );
		update_user_meta( $user_id, 'bn_following_count', $following_count );
	}

	/**
	 * Recount and store the accepted-connection count for a user.
	 *
	 * Mirrors recount_follow_counts for the symmetric bn_connections graph: a
	 * connection is one row shared by both peers, counted from either side.
	 * Stored in wp_usermeta bn_connection_count, read by
	 * ConnectionService::connection_count().
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function recount_connection_counts( int $user_id ): void {
		global $wpdb;

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'",
				$user_id,
				$user_id
			)
		);

		update_user_meta( $user_id, 'bn_connection_count', $count );
	}

	/**
	 * Atomically adjust an EXISTING usermeta counter by a delta, clamped at 0.
	 *
	 * Used by the follow/connection write paths to maintain bn_follower_count /
	 * bn_following_count / bn_connection_count in O(1) without re-counting the edge
	 * table. The UPDATE is a no-op when the row is absent — that is intentional: the
	 * read path lazy-recounts a missing key (which would already include this change),
	 * so seeding a partial row here would risk an off-by-one. Busts WordPress's
	 * per-user meta cache so the very next get_user_meta() sees the new value.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $meta_key Counter meta key (bn_follower_count, etc.).
	 * @param int    $delta    Signed amount to add (typically +1 / -1).
	 * @return void
	 */
	public function adjust_user_counter( int $user_id, string $meta_key, int $delta ): void {
		if ( 0 === $delta || $user_id <= 0 || '' === $meta_key ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta}
				 SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d)
				 WHERE user_id = %d AND meta_key = %s",
				$delta,
				$user_id,
				$meta_key
			)
		);

		wp_cache_delete( $user_id, 'user_meta' );
	}

	/**
	 * Reconcile bn_follower_count + bn_following_count for EVERY user with a
	 * counter row, in two set-based passes (drift self-heal).
	 *
	 * The follow counters live in usermeta (no per-user column), so this can only
	 * fix rows that already exist — but that is sufficient: the read path lazy-counts
	 * a missing key, and the write paths only touch existing rows, so a user without
	 * a row has never had a stale value displayed. Each UPDATE...LEFT JOIN carries a
	 * `WHERE meta_value <> COALESCE(...)` guard so only genuinely-drifted rows are
	 * written. Run from the daily recount job. Counts only 'approved' follows.
	 *
	 * @return void
	 */
	public function recount_all_follow_counts(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$wpdb->usermeta} um
			 LEFT JOIN (
			     SELECT following_id AS uid, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_follows
			      WHERE status = 'approved'
			      GROUP BY following_id
			 ) f ON f.uid = um.user_id
			 SET um.meta_value = COALESCE(f.cnt, 0)
			 WHERE um.meta_key = 'bn_follower_count' AND um.meta_value <> COALESCE(f.cnt, 0)"
		);

		$wpdb->query(
			"UPDATE {$wpdb->usermeta} um
			 LEFT JOIN (
			     SELECT follower_id AS uid, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_follows
			      WHERE status = 'approved'
			      GROUP BY follower_id
			 ) f ON f.uid = um.user_id
			 SET um.meta_value = COALESCE(f.cnt, 0)
			 WHERE um.meta_key = 'bn_following_count' AND um.meta_value <> COALESCE(f.cnt, 0)"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Reconcile bn_connection_count for EVERY user with a counter row in one
	 * set-based pass (drift self-heal). Same guard + lazy-row semantics as
	 * recount_all_follow_counts; counts accepted connections from either side via
	 * a UNION ALL of both endpoint columns. Run from the daily recount job.
	 *
	 * @return void
	 */
	public function recount_all_connection_counts(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$wpdb->usermeta} um
			 LEFT JOIN (
			     SELECT uid, COUNT(*) AS cnt FROM (
			         SELECT requester_id AS uid FROM {$wpdb->prefix}bn_connections WHERE status = 'accepted'
			         UNION ALL
			         SELECT recipient_id AS uid FROM {$wpdb->prefix}bn_connections WHERE status = 'accepted'
			     ) endpoints
			     GROUP BY uid
			 ) c ON c.uid = um.user_id
			 SET um.meta_value = COALESCE(c.cnt, 0)
			 WHERE um.meta_key = 'bn_connection_count' AND um.meta_value <> COALESCE(c.cnt, 0)"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── Post reaction count ───────────────────────────────────────────────────

	/**
	 * Recount and store the reaction count for a post.
	 *
	 * @param int $post_id bn_posts.id.
	 * @return void
	 */
	public function recount_post_reactions( int $post_id ): void {
		global $wpdb;

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions
				 WHERE object_type = 'post' AND object_id = %d",
				$post_id
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_posts',
			array( 'reaction_count' => $count ),
			array( 'id' => $post_id )
		);
	}

	// ── Post comment count ────────────────────────────────────────────────────

	/**
	 * Recount and store the comment count for a post.
	 *
	 * @param int $post_id bn_posts.id.
	 * @return void
	 */
	public function recount_post_comments( int $post_id ): void {
		global $wpdb;

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments
				 WHERE object_type = 'post' AND object_id = %d",
				$post_id
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_posts',
			array( 'comment_count' => $count ),
			array( 'id' => $post_id )
		);
	}

	// ── Space member count ────────────────────────────────────────────────────

	/**
	 * Recount and store the member count for a space.
	 *
	 * @param int $space_id bn_spaces.id.
	 * @return void
	 */
	public function recount_space_members( int $space_id ): void {
		global $wpdb;

		// Only 'active' rows: the live member_count counter tracks active members
		// (adjust_member_count +1 on join/approve, -1 on leave/remove/ban), so the
		// reconcile must exclude pending/invited rows or it would overcount.
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND status = 'active'",
				$space_id
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_spaces',
			array( 'member_count' => $count ),
			array( 'id' => $space_id )
		);
	}

	/**
	 * Reconcile member_count for EVERY space in one set-based pass (drift self-heal).
	 *
	 * Mirrors PostService::recount_counters: a single UPDATE...LEFT JOIN with a
	 * `WHERE col <> COALESCE(...)` guard so only genuinely-drifted rows are
	 * written. Run from the daily recount job so member_count — a hot per-event
	 * counter — self-heals instead of drifting until an admin clicks the manual
	 * recount button. Counts only 'active' members (live-counter semantics).
	 *
	 * @return void
	 */
	public function recount_all_space_members(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_spaces s
			 LEFT JOIN (
			     SELECT space_id, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_space_members
			      WHERE status = 'active'
			      GROUP BY space_id
			 ) m ON m.space_id = s.id
			 SET s.member_count = COALESCE(m.cnt, 0)
			 WHERE s.member_count <> COALESCE(m.cnt, 0)"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Reconcile post_count + follower_count for EVERY hashtag in one set-based pass.
	 *
	 * Same drift-guarded UPDATE...LEFT JOIN pattern as the space recount; run from
	 * the daily recount job so hashtag counters self-heal too.
	 *
	 * @return void
	 */
	public function recount_all_hashtag_counts(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_hashtags h
			 LEFT JOIN (
			     SELECT hashtag_id, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_post_hashtags
			      GROUP BY hashtag_id
			 ) p ON p.hashtag_id = h.id
			 SET h.post_count = COALESCE(p.cnt, 0)
			 WHERE h.post_count <> COALESCE(p.cnt, 0)"
		);

		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_hashtags h
			 LEFT JOIN (
			     SELECT hashtag_id, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_hashtag_follows
			      GROUP BY hashtag_id
			 ) f ON f.hashtag_id = h.id
			 SET h.follower_count = COALESCE(f.cnt, 0)
			 WHERE h.follower_count <> COALESCE(f.cnt, 0)"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── Hashtag post count ────────────────────────────────────────────────────

	/**
	 * Recount and store the post count for a hashtag.
	 *
	 * @param int $hashtag_id bn_hashtags.id.
	 * @return void
	 */
	public function recount_hashtag_posts( int $hashtag_id ): void {
		global $wpdb;

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_post_hashtags WHERE hashtag_id = %d",
				$hashtag_id
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_hashtags',
			array( 'post_count' => $count ),
			array( 'id' => $hashtag_id )
		);
	}

	// ── Hashtag follower count ────────────────────────────────────────────────

	/**
	 * Recount and store the follower count for a hashtag.
	 *
	 * @param int $hashtag_id bn_hashtags.id.
	 * @return void
	 */
	public function recount_hashtag_followers( int $hashtag_id ): void {
		global $wpdb;

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_hashtag_follows WHERE hashtag_id = %d",
				$hashtag_id
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_hashtags',
			array( 'follower_count' => $count ),
			array( 'id' => $hashtag_id )
		);
	}
}
