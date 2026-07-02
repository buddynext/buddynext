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

		// ONE canonical purge, shared with the GDPR eraser (Privacy\PrivacyTools):
		// every user-keyed table, the member_count decrement, the reaction_count
		// reconcile, and the buddynext_purge_user_data extension event. This listener
		// previously carried its own purge list that disagreed with — and missed
		// tables the eraser cleaned (member-type assignments, presence, search index,
		// space bans, appeals, shares, reactions, poll votes); both now defer here.
		( new \BuddyNext\Profile\MemberCleanupService() )->purge_user_relations( $user_id, 'delete' );

		/**
		 * Back-compat: the original delete-only event. Prefer the canonical
		 * `buddynext_purge_user_data` (fired by MemberCleanupService on BOTH the
		 * delete and the GDPR-erase path). Retained so existing listeners keep firing.
		 *
		 * @param int $user_id The deleted user id.
		 */
		do_action( 'buddynext_user_relations_purged', $user_id );
	}
}
