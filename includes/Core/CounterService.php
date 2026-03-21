<?php
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

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d",
				$space_id
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_spaces',
			array( 'member_count' => $count ),
			array( 'id' => $space_id )
		);
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
