<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Follow relationship service.
 *
 * Manages one-directional follow relationships between users. All reads are
 * cache-backed (group: buddynext_follows, TTL: 10 min); writes invalidate
 * the relevant cache keys. The buddynext_user_followed action is only fired
 * when a new row is inserted (not on duplicate follows).
 *
 * @package BuddyNext\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\SocialGraph;

use WP_Error;

/**
 * Handles follow, unfollow, and follow-graph queries.
 */
class FollowService {

	/**
	 * Cache group for all follow data.
	 */
	private const CACHE_GROUP = 'buddynext_follows';

	/**
	 * Cache TTL in seconds (10 minutes).
	 */
	private const CACHE_TTL = 600;

	/**
	 * user_meta key holding the per-user "private account" toggle. When set
	 * to a truthy value, follow attempts land as `pending` and must be
	 * approved by the owner before the follower sees protected content.
	 */
	public const PRIVATE_META = 'bn_account_private';

	/**
	 * Return true when the target user has marked their account private.
	 *
	 * @param int $user_id Target user.
	 * @return bool
	 */
	public function is_private_account( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::PRIVATE_META, true );
	}

	/**
	 * Follow a user.
	 *
	 * A duplicate follow is silently ignored (INSERT IGNORE). When the
	 * target account is private the row is stored with status='pending'
	 * and the buddynext_follow_requested action fires instead of
	 * buddynext_user_followed — the relationship doesn't count as
	 * "following" until the owner approves it.
	 *
	 * @param int $follower_id  ID of the user doing the following.
	 * @param int $following_id ID of the user being followed.
	 * @return true|WP_Error True on success; WP_Error on self-follow attempt.
	 */
	public function follow( int $follower_id, int $following_id ): true|WP_Error {
		if ( $follower_id === $following_id ) {
			return new WP_Error(
				'cannot_follow_self',
				__( 'A user cannot follow themselves.', 'buddynext' )
			);
		}

		// Honour the target's who_can_follow preference (and block) via the
		// canonical privacy gate — previously this preference was never consulted.
		$privacy = function_exists( 'buddynext_service' ) ? buddynext_service( 'privacy' ) : null;
		if ( $privacy && method_exists( $privacy, 'can_follow' ) && ! $privacy->can_follow( $follower_id, $following_id ) ) {
			return new WP_Error(
				'follow_not_allowed',
				__( 'This member does not allow follows from you.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		global $wpdb;

		// Enforce block guard: refuse the follow if either party has blocked the other.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$block = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blocker_id
				 FROM {$wpdb->prefix}bn_blocks
				 WHERE ( ( blocker_id = %d AND blocked_id = %d )
				      OR ( blocker_id = %d AND blocked_id = %d ) )
				   AND type = 'block'
				 LIMIT 1",
				$follower_id,
				$following_id,
				$following_id,
				$follower_id
			)
		);

		if ( null !== $block ) {
			return new WP_Error(
				'blocked',
				__( 'Cannot follow a blocked user.', 'buddynext' )
			);
		}

		$status = $this->is_private_account( $following_id ) ? 'pending' : 'approved';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_follows (follower_id, following_id, status)
				 VALUES (%d, %d, %s)",
				$follower_id,
				$following_id,
				$status
			)
		);
		$inserted = $wpdb->rows_affected > 0;

		$this->invalidate_follow_cache( $follower_id, $following_id );

		if ( $inserted && 'pending' === $status ) {
			/**
			 * Fires when a follow request lands on a private account.
			 *
			 * @param int $follower_id  Requester.
			 * @param int $following_id Owner whose approval is required.
			 */
			do_action( 'buddynext_follow_requested', $follower_id, $following_id );

			return true;
		}

		if ( $inserted ) {
			/**
			 * Fires after a new follow relationship is created.
			 *
			 * @param int $follower_id  ID of the follower.
			 * @param int $following_id ID of the user being followed.
			 */
			do_action( 'buddynext_user_followed', $follower_id, $following_id );

			/**
			 * Fires from the followee's perspective when they gain a follower.
			 *
			 * Mirror of `buddynext_user_followed` with the argument order
			 * flipped so gamification plugins can award the recipient
			 * (followee) without swapping parameters.
			 *
			 * @param int $followee_id ID of the user being followed (recipient).
			 * @param int $follower_id ID of the new follower (actor).
			 */
			do_action( 'buddynext_follower_gained', $following_id, $follower_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$follow_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows
					 WHERE follower_id = %d AND status = 'approved'",
					$follower_id
				)
			);

			if ( 1 === $follow_count ) {
				/**
				 * Fires the first time a user follows anyone.
				 *
				 * Distinct lifecycle event used by onboarding flows (welcome
				 * drips, "you've started exploring" milestones). Fires
				 * exactly once per user — on the row that brings their
				 * follow count to 1.
				 *
				 * @param int $follower_id  The user whose first follow this is.
				 * @param int $following_id The user being followed.
				 */
				do_action( 'buddynext_user_followed_first_time', $follower_id, $following_id );
			}
		}

		return true;
	}

	/**
	 * Remove a follow relationship.
	 *
	 * @param int $follower_id  ID of the user doing the unfollowing.
	 * @param int $following_id ID of the user being unfollowed.
	 * @return bool True when a row was deleted, false if no relationship existed.
	 */
	public function unfollow( int $follower_id, int $following_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_follows',
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
			),
			array( '%d', '%d' )
		);

		$deleted = $wpdb->rows_affected > 0;

		$this->invalidate_follow_cache( $follower_id, $following_id );

		if ( $deleted ) {
			/**
			 * Fires after a follow relationship is removed.
			 *
			 * Only fires when a row was actually deleted, so listeners
			 * (notifications, webhooks, email) never run for a no-op unfollow.
			 *
			 * @param int $follower_id  ID of the unfollowing user.
			 * @param int $following_id ID of the user being unfollowed.
			 */
			do_action( 'buddynext_user_unfollowed', $follower_id, $following_id );
		}

		return $deleted;
	}

	/**
	 * Check whether one user follows another.
	 *
	 * @param int $follower_id  ID of the potential follower.
	 * @param int $following_id ID of the potential followee.
	 * @return bool
	 */
	public function is_following( int $follower_id, int $following_id ): bool {
		global $wpdb;

		$cache_key = "is_following_{$follower_id}_{$following_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_follows
				 WHERE follower_id = %d AND following_id = %d AND status = 'approved'",
				$follower_id,
				$following_id
			)
		);

		wp_cache_set( $cache_key, $exists, self::CACHE_GROUP, self::CACHE_TTL );

		return $exists > 0;
	}

	/**
	 * Resolve approved follow-state for a viewer against many targets at once.
	 *
	 * Batches what a per-row is_following() loop would otherwise run, so callers
	 * rendering a list (search results, member grids) avoid an N+1 of follow
	 * lookups. Returns a map keyed by target user ID with bool values; targets
	 * the viewer does not follow are present with `false`.
	 *
	 * @param int   $follower_id Viewer doing the following.
	 * @param int[] $target_ids  Target user IDs to test.
	 * @return array<int,bool> Map of target_id => is the viewer following them.
	 */
	public function following_map( int $follower_id, array $target_ids ): array {
		$follower_id = absint( $follower_id );
		$target_ids  = array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) );

		$map = array();
		foreach ( $target_ids as $tid ) {
			$map[ $tid ] = false;
		}

		if ( $follower_id <= 0 || empty( $target_ids ) ) {
			return $map;
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $target_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT following_id
				 FROM {$wpdb->prefix}bn_follows
				 WHERE follower_id = %d
				   AND status = 'approved'
				   AND following_id IN ( {$placeholders} )",
				$follower_id,
				...$target_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $tid ) {
			$map[ (int) $tid ] = true;
		}

		return $map;
	}

	/**
	 * Return true when the follower has a pending request to the target.
	 *
	 * @param int $follower_id Requester.
	 * @param int $following_id Target.
	 * @return bool
	 */
	public function has_pending_request( int $follower_id, int $following_id ): bool {
		// follow-button.php calls this for each distinct user across the feed and
		// sidebar; memoise per request, keyed by the directed pair.
		static $cache = array();
		$key          = "{$follower_id}:{$following_id}";
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cache[ $key ] = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				 FROM {$wpdb->prefix}bn_follows
				 WHERE follower_id = %d AND following_id = %d AND status = 'pending'
				 LIMIT 1",
				$follower_id,
				$following_id
			)
		);

		return $cache[ $key ];
	}

	/**
	 * Return the list of user IDs who follow the given user.
	 *
	 * Only includes approved relationships — pending follow requests are
	 * excluded so callers (feed audience, follower counts, etc.) never
	 * see an unapproved follower as a follower.
	 *
	 * @param int $user_id User being followed.
	 * @return int[]
	 */
	public function followers( int $user_id ): array {
		global $wpdb;

		$cache_key = "followers_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT follower_id
				 FROM {$wpdb->prefix}bn_follows
				 WHERE following_id = %d AND status = 'approved'
				 ORDER BY created_at DESC",
				$user_id
			)
		);

		$result = array_map( 'intval', (array) $rows );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the list of user IDs that the given user follows.
	 *
	 * @param int $user_id The following user.
	 * @return int[]
	 */
	public function following( int $user_id ): array {
		global $wpdb;

		$cache_key = "following_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT following_id
				 FROM {$wpdb->prefix}bn_follows
				 WHERE follower_id = %d AND status = 'approved'
				 ORDER BY created_at DESC",
				$user_id
			)
		);

		$result = array_map( 'intval', (array) $rows );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the total number of followers for a user.
	 *
	 * @param int $user_id The user being followed.
	 * @return int
	 */
	public function follower_count( int $user_id ): int {
		global $wpdb;

		$cache_key = "follower_count_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_follows
				 WHERE following_id = %d AND status = 'approved'",
				$user_id
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Return the total number of users the given user follows.
	 *
	 * @param int $user_id The following user.
	 * @return int
	 */
	public function following_count( int $user_id ): int {
		global $wpdb;

		$cache_key = "following_count_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_follows
				 WHERE follower_id = %d AND status = 'approved'",
				$user_id
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Return the paginated list of user IDs who follow the given user.
	 *
	 * Spec-named alias for followers(). Supports optional pagination/limit args:
	 *   'per_page' (int, default 20) — number of IDs to return.
	 *   'page'     (int, default 1)  — 1-based page offset.
	 *
	 * @param int   $user_id User being followed.
	 * @param array $args    Optional query args (per_page, page).
	 * @return int[]
	 */
	public function get_followers( int $user_id, array $args = array() ): array {
		$all      = $this->followers( $user_id );
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		return array_values( array_slice( $all, $offset, $per_page ) );
	}

	/**
	 * Return the paginated list of user IDs that the given user follows.
	 *
	 * Spec-named alias for following(). Supports optional pagination/limit args:
	 *   'per_page' (int, default 20) — number of IDs to return.
	 *   'page'     (int, default 1)  — 1-based page offset.
	 *
	 * @param int   $user_id The following user.
	 * @param array $args    Optional query args (per_page, page).
	 * @return int[]
	 */
	public function get_following( int $user_id, array $args = array() ): array {
		$all      = $this->following( $user_id );
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		return array_values( array_slice( $all, $offset, $per_page ) );
	}

	/**
	 * Return follow suggestions based on the friends-of-friends graph.
	 *
	 * Candidates are users followed by people the given user already follows,
	 * excluding the requesting user and accounts they already follow.
	 *
	 * The viewer's own following list comes from the cache-backed following()
	 * (one query, usually cached). The second-degree lookup is then a single
	 * query that scans bn_follows once — `follower_id IN (friends)` minus the
	 * exclusion set — instead of one following() call per friend. This removes
	 * the old N+1 (1 + one query per followed user) while keeping the query to
	 * a single table reference, so it stays clear of MySQL's "Can't reopen
	 * table" restriction on the WP test suite's tables.
	 *
	 * @param int $user_id The user requesting suggestions.
	 * @return int[]
	 */
	public function suggestions( int $user_id ): array {
		$following = $this->following( $user_id );

		if ( empty( $following ) ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_follows';

		// Already-followed accounts + self are excluded from the candidate set.
		$exclude    = array_merge( $following, array( $user_id ) );
		$friends_ph = implode( ', ', array_fill( 0, count( $following ), '%d' ) );
		$exclude_ph = implode( ', ', array_fill( 0, count( $exclude ), '%d' ) );

		// Suspended + shadow-banned users must not surface here either — every
		// other discovery surface (feed, directory) applies the same canonical
		// moderation exclusion, so friend-of-friend suggestions follow suit.
		// Private accounts ARE intentionally suggestible: bn_account_private
		// gates activity visibility, not discoverability.
		$moderation_where = buddynext_service( 'moderation' )->moderation_exclude_sql( 'following_id' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT following_id
				 FROM {$table}
				 WHERE follower_id IN ({$friends_ph})
				   AND status = 'approved'
				   AND following_id NOT IN ({$exclude_ph})
				   {$moderation_where}",
				array_merge( $following, $exclude )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Return the IDs of users with pending follow requests TO this user.
	 *
	 * Used by the followers-page request inbox so the owner can see who
	 * is waiting on approval.
	 *
	 * @param int $owner_id Owner of the private account.
	 * @return int[] Follower user IDs ordered oldest-first.
	 */
	public function pending_followers( int $owner_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT follower_id
				 FROM {$wpdb->prefix}bn_follows
				 WHERE following_id = %d AND status = 'pending'
				 ORDER BY created_at ASC",
				$owner_id
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Number of pending follow requests for the user.
	 *
	 * Cheap dedicated count used by the request-inbox badge.
	 *
	 * @param int $owner_id Owner of the private account.
	 * @return int
	 */
	public function pending_followers_count( int $owner_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_follows
				 WHERE following_id = %d AND status = 'pending'",
				$owner_id
			)
		);
	}

	/**
	 * Approve a pending follow request.
	 *
	 * Flips the row from `pending` to `approved` and fires
	 * buddynext_user_followed / buddynext_follower_gained at that point —
	 * downstream listeners (gamification, notifications) should treat
	 * approval as the moment the follow "happened" because that's when
	 * the relationship becomes visible.
	 *
	 * @param int $owner_id    Owner of the private account (must be acting user).
	 * @param int $follower_id Requester being approved.
	 * @return bool True when a pending row was promoted; false otherwise.
	 */
	public function approve_follow_request( int $owner_id, int $follower_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_follows
				 SET status = 'approved'
				 WHERE follower_id = %d AND following_id = %d AND status = 'pending'",
				$follower_id,
				$owner_id
			)
		);

		if ( $affected <= 0 ) {
			return false;
		}

		$this->invalidate_follow_cache( $follower_id, $owner_id );

		/** Mirrors the same hooks the public follow() path fires. */
		do_action( 'buddynext_user_followed', $follower_id, $owner_id );
		do_action( 'buddynext_follower_gained', $owner_id, $follower_id );

		/**
		 * Fires when an owner approves a pending follow request.
		 *
		 * @param int $owner_id    Approver.
		 * @param int $follower_id Approved requester.
		 */
		do_action( 'buddynext_follow_request_approved', $owner_id, $follower_id );

		return true;
	}

	/**
	 * Reject a pending follow request.
	 *
	 * Deletes the pending row outright (no rejected-history kept for
	 * privacy — the requester just sees the request go away).
	 *
	 * @param int $owner_id    Owner of the private account.
	 * @param int $follower_id Requester being rejected.
	 * @return bool True when a pending row was removed; false otherwise.
	 */
	public function reject_follow_request( int $owner_id, int $follower_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}bn_follows
				 WHERE follower_id = %d AND following_id = %d AND status = 'pending'",
				$follower_id,
				$owner_id
			)
		);

		if ( $affected <= 0 ) {
			return false;
		}

		$this->invalidate_follow_cache( $follower_id, $owner_id );

		/**
		 * Fires when an owner rejects a pending follow request.
		 *
		 * @param int $owner_id    Rejecter.
		 * @param int $follower_id Rejected requester.
		 */
		do_action( 'buddynext_follow_request_rejected', $owner_id, $follower_id );

		return true;
	}

	/**
	 * Invalidate all cache keys affected by a follow or unfollow event.
	 *
	 * @param int $follower_id  The follower.
	 * @param int $following_id The followee.
	 */
	private function invalidate_follow_cache( int $follower_id, int $following_id ): void {
		wp_cache_delete( "is_following_{$follower_id}_{$following_id}", self::CACHE_GROUP );
		wp_cache_delete( "followers_{$following_id}", self::CACHE_GROUP );
		wp_cache_delete( "following_{$follower_id}", self::CACHE_GROUP );
		wp_cache_delete( "follower_count_{$following_id}", self::CACHE_GROUP );
		wp_cache_delete( "following_count_{$follower_id}", self::CACHE_GROUP );
	}
}
