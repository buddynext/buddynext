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

		$follower_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE following_id = %d",
				$user_id
			)
		);

		$following_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
				$user_id
			)
		);

		update_user_meta( $user_id, 'bn_follower_count', $follower_count );
		update_user_meta( $user_id, 'bn_following_count', $following_count );
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
