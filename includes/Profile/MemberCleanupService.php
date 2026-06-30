<?php
/**
 * Canonical member-cleanup service.
 *
 * ONE source of truth for purging a member's BuddyNext footprint, shared by the
 * hard-delete path (SocialGraph\UserCleanupListener on `deleted_user`) and the
 * GDPR eraser (Privacy\PrivacyTools). Both used to carry their own overlapping —
 * and disagreeing — purge lists, each missing tables the other cleaned. They now
 * both call purge_user_relations(), so a member can never linger half-deleted in
 * search / presence / counts.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Purges every user-keyed BuddyNext row for a removed/erased member.
 */
class MemberCleanupService {

	/**
	 * Purge all of a member's relational rows across every BuddyNext table.
	 *
	 * Decrements the denormalised `bn_spaces.member_count` for the member's active
	 * spaces before their membership rows go away, captures the posts they reacted
	 * to so `reaction_count` can be reconciled after, then deletes every user-keyed
	 * row AND hard-deletes their authored posts + comments (standard GDPR erasure —
	 * uniform across both delete paths; content is never reassigned/kept). Finally
	 * fires the canonical `buddynext_purge_user_data` extension event so addons clean
	 * their own per-user tables on the SAME signal regardless of how the member was
	 * removed.
	 *
	 * @param int    $user_id Member being removed.
	 * @param string $context 'delete' (admin delete) | 'gdpr-erase' (privacy eraser).
	 *                        Both hard-delete; the value is passed through to the
	 *                        buddynext_purge_user_data event for addon context only.
	 * @return bool True when at least one row was removed.
	 */
	public function purge_user_relations( int $user_id, string $context = 'delete' ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$p       = $wpdb->prefix;
		$removed = false;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Decrement member_count for the spaces the member actively belonged to,
		// BEFORE the membership rows are deleted, so directory totals stay honest.
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

		// Posts the member reacted to — captured before the reactions are deleted so
		// the denormalised reaction_count can be reconciled afterward (deleting the
		// rows alone would leave those counters drifted high).
		$reaction_post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT object_id FROM {$p}bn_reactions WHERE user_id = %d AND object_type = 'post'",
				$user_id
			)
		);

		// Follow + connection peers whose denormalised counters must drop by one when
		// this member's edges are deleted below — captured BEFORE the rows go away.
		// The member's OWN counters live in their bn_* usermeta, wiped by the sweep.
		$peers_lose_follower   = $wpdb->get_col( $wpdb->prepare( "SELECT following_id FROM {$p}bn_follows WHERE follower_id = %d AND status = 'approved'", $user_id ) );
		$peers_lose_following  = $wpdb->get_col( $wpdb->prepare( "SELECT follower_id FROM {$p}bn_follows WHERE following_id = %d AND status = 'approved'", $user_id ) );
		$peers_lose_connection = $wpdb->get_col( $wpdb->prepare( "SELECT CASE WHEN requester_id = %d THEN recipient_id ELSE requester_id END FROM {$p}bn_connections WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'", $user_id, $user_id, $user_id ) );

		// The complete user-keyed table set. Two-direction relations match either
		// side; search-index removal covers both the member's own entry and every
		// row they authored.
		$queries = array(
			$wpdb->prepare( "DELETE FROM {$p}bn_follows WHERE follower_id = %d OR following_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_connections WHERE requester_id = %d OR recipient_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_blocks WHERE blocker_id = %d OR blocked_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_space_members WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_space_bans WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_hashtag_follows WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_notification_prefs WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_notifications WHERE recipient_id = %d OR sender_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_user_strikes WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_user_suspensions WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_appeals WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_bookmarks WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_reactions WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_poll_votes WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_shares WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_member_type_assignments WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_presence WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_search_index WHERE author_id = %d OR ( object_type = 'member' AND object_id = %d )", $user_id, $user_id ),
		);
		foreach ( $queries as $sql ) {
			if ( (int) $wpdb->query( $sql ) > 0 ) {
				$removed = true;
			}
		}

		// Authored posts + comments are hard-deleted with the member — standard GDPR
		// erasure: the person's content goes with the person, on BOTH the admin-delete
		// and the GDPR-eraser paths (it is never reassigned to a tombstone). They are
		// deliberately kept out of the user-keyed delete set above so this runs through
		// PostService::delete, which cascades each post's child rows (reactions,
		// comments, poll options/votes, shares, hashtag links) — a bare table DELETE
		// would orphan those. Comments the member left on OTHERS' posts are then removed
		// and those posts' comment_count reconciled.
		$own_post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}bn_posts WHERE user_id = %d", $user_id ) );
		$commented_on = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM {$p}bn_comments WHERE user_id = %d AND object_type = 'post'", $user_id ) );
		$post_service = buddynext_service( 'post_service' );
		if ( is_object( $post_service ) ) {
			foreach ( $own_post_ids as $pid ) {
				$post_service->delete( (int) $pid, $user_id );
			}
		}
		if ( (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_comments WHERE user_id = %d", $user_id ) ) > 0 || ! empty( $own_post_ids ) ) {
			$removed = true;
		}
		if ( ! empty( $commented_on ) && is_object( $post_service ) && method_exists( $post_service, 'recount_counters' ) ) {
			$post_service->recount_counters( array_map( 'intval', $commented_on ) );
		}

		// Sweep every bn_* usermeta row. A no-op on hard delete (core already removed
		// the user's meta), but the eraser keeps an anonymised user row, so this is
		// what actually wipes their bn_* meta footprint there.
		$deleted_meta = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( 'bn_' ) . '%'
			)
		);
		if ( $deleted_meta > 0 ) {
			$removed = true;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Profile-field values + their searchable mirror/cache are owned by the
		// Profile service — delegate rather than reach into bn_profile_values here.
		( new ProfileService() )->delete_user_values( $user_id );

		// Reconcile reaction_count on the posts the member had reacted to.
		if ( ! empty( $reaction_post_ids ) ) {
			$post_service = buddynext_service( 'post_service' );
			if ( is_object( $post_service ) && method_exists( $post_service, 'recount_counters' ) ) {
				$post_service->recount_counters( array_map( 'intval', $reaction_post_ids ) );
			}
		}

		// Decrement the denormalised follow/connection counters for every peer whose
		// edge with this member was just removed (one edge each, so -1 is exact). This
		// fixes the source of truth immediately; the short-TTL count caches and the
		// daily reconcile (recount_all_*) cover the cached read layer.
		$counters = buddynext_service( 'counters' );
		if ( is_object( $counters ) ) {
			foreach ( $peers_lose_follower as $peer ) {
				$counters->adjust_user_counter( (int) $peer, 'bn_follower_count', -1 );
			}
			foreach ( $peers_lose_following as $peer ) {
				$counters->adjust_user_counter( (int) $peer, 'bn_following_count', -1 );
			}
			foreach ( $peers_lose_connection as $peer ) {
				$counters->adjust_user_counter( (int) $peer, 'bn_connection_count', -1 );
			}
		}

		clean_user_cache( $user_id );

		/**
		 * Fires after BuddyNext has purged a removed member's relational rows.
		 *
		 * The canonical member-cleanup contract: fires on BOTH the hard-delete and
		 * the GDPR-erase path so an addon cleans its own per-user tables on ONE
		 * signal, regardless of how the member was removed.
		 *
		 * @param int    $user_id The removed member's id.
		 * @param string $context 'delete' | 'gdpr-erase'.
		 */
		do_action( 'buddynext_purge_user_data', $user_id, $context );

		return $removed;
	}
}
