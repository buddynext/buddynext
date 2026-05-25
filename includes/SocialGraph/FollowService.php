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
	 * Follow a user.
	 *
	 * A duplicate follow is silently ignored (INSERT IGNORE). The
	 * buddynext_user_followed action fires only when a new relationship is
	 * created, preventing duplicate notifications on retry.
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

		global $wpdb;

		// Enforce block guard: refuse the follow if either party has blocked the other.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$block = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blocker_id
				 FROM {$wpdb->prefix}bn_blocks
				 WHERE ( blocker_id = %d AND blocked_id = %d )
				    OR ( blocker_id = %d AND blocked_id = %d )
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_follows (follower_id, following_id)
				 VALUES (%d, %d)",
				$follower_id,
				$following_id
			)
		);
		$inserted = $wpdb->rows_affected > 0;

		$this->invalidate_follow_cache( $follower_id, $following_id );

		if ( $inserted ) {
			/**
			 * Fires after a new follow relationship is created.
			 *
			 * @param int $follower_id  ID of the follower.
			 * @param int $following_id ID of the user being followed.
			 */
			do_action( 'buddynext_user_followed', $follower_id, $following_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$follow_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
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

		/**
		 * Fires after a follow relationship is removed.
		 *
		 * @param int $follower_id  ID of the unfollowing user.
		 * @param int $following_id ID of the user being unfollowed.
		 */
		do_action( 'buddynext_user_unfollowed', $follower_id, $following_id );

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
				 WHERE follower_id = %d AND following_id = %d",
				$follower_id,
				$following_id
			)
		);

		wp_cache_set( $cache_key, $exists, self::CACHE_GROUP, self::CACHE_TTL );

		return $exists > 0;
	}

	/**
	 * Return the list of user IDs who follow the given user.
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
				 WHERE following_id = %d
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
				 WHERE follower_id = %d
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
				 WHERE following_id = %d",
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
				 WHERE follower_id = %d",
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
	 * Each second-degree lookup re-uses the cache-backed following() method,
	 * keeping DB load low. This also avoids MySQL's "Can't reopen table"
	 * restriction on TEMPORARY tables (used by the WP test suite).
	 *
	 * @param int $user_id The user requesting suggestions.
	 * @return int[]
	 */
	public function suggestions( int $user_id ): array {
		$following = $this->following( $user_id );

		if ( empty( $following ) ) {
			return array();
		}

		$candidates = array();
		foreach ( $following as $friend_id ) {
			foreach ( $this->following( $friend_id ) as $candidate_id ) {
				$candidates[] = $candidate_id;
			}
		}

		return array_values( array_diff( array_unique( $candidates ), $following, array( $user_id ) ) );
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
