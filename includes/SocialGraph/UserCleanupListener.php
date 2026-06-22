<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Purge a user's relational rows when their account is deleted.
 *
 * WHY THIS EXISTS
 * ───────────────
 * BuddyNext keeps its social graph and per-user data in custom tables keyed by
 * user id (follows, connections, blocks, space memberships, hashtag follows,
 * notification prefs/rows, moderation strikes/suspensions, bookmarks, and
 * profile-field values). WordPress core knows nothing about these, so
 * `wp_delete_user()` used to leave them behind as orphans — dangling follower
 * counts, ghost connections, stale memberships, saved-post rows, and a full set
 * of profile values. This listener hooks `deleted_user` and removes every row
 * that references the gone user, in both directions, and corrects the affected
 * spaces' member counts.
 *
 * Scope is deliberately relational only — authored content (posts, comments,
 * reactions) is left to the caller / content-deletion flow, mirroring how
 * WordPress treats post reassignment separately from user deletion.
 *
 * @package BuddyNext\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\SocialGraph;

/**
 * Cleans up per-user relational rows on account deletion.
 */
class UserCleanupListener {

	/**
	 * Hook into user deletion.
	 *
	 * @return void
	 */
	public function register(): void {
		// Priority 5 so we run before WordPress finishes tearing the user down,
		// while their id is still meaningful for our lookups.
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ), 5, 1 );
	}

	/**
	 * Remove every BuddyNext relational row referencing a deleted user.
	 *
	 * @param int $user_id The user being deleted.
	 * @return void
	 */
	public function on_deleted_user( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		global $wpdb;
		$p = $wpdb->prefix;

		// Spaces the user actively belonged to — decrement their member_count
		// before the membership rows go away so directory totals stay honest.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_space_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT space_id FROM {$p}bn_space_members WHERE user_id = %d AND status = 'active'",
				$user_id
			)
		);
		foreach ( $active_space_ids as $space_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}bn_spaces SET member_count = GREATEST(1, member_count) - 1 WHERE id = %d",
					(int) $space_id
				)
			);
			wp_cache_delete( 'space_' . (int) $space_id, 'bn_spaces' );
		}

		// Relational rows in both directions.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_follows WHERE follower_id = %d OR following_id = %d", $user_id, $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_connections WHERE requester_id = %d OR recipient_id = %d", $user_id, $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_blocks WHERE blocker_id = %d OR blocked_id = %d", $user_id, $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_space_members WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_hashtag_follows WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_notification_prefs WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_notifications WHERE recipient_id = %d OR sender_id = %d", $user_id, $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_user_strikes WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_user_suspensions WHERE user_id = %d", $user_id ) );
		// Per-user data (not authored content): the member's saved posts. Without
		// this, deleting a user left orphaned bookmark rows behind.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_bookmarks WHERE user_id = %d", $user_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Profile-field values are owned by the Profile service (its table + its
		// searchable mirror/cache), so delegate rather than reach into
		// bn_profile_values here. wp_delete_user removes the usermeta mirror; this
		// clears the canonical rows that core knows nothing about.
		( new \BuddyNext\Profile\ProfileService() )->delete_user_values( $user_id );

		/**
		 * Fires after BuddyNext has purged a deleted user's relational rows, so
		 * extensions can clean their own per-user tables.
		 *
		 * @param int $user_id The deleted user id.
		 */
		do_action( 'buddynext_user_relations_purged', $user_id );
	}
}
