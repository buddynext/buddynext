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
	 * User_meta key holding the per-user "private account" toggle. When set
	 * to a truthy value, follow attempts land as `pending` and must be
	 * approved by the owner before the follower sees protected content.
	 */
	public const PRIVATE_META = 'bn_account_private';

	/**
	 * Cache group for the ranked follow-suggestion candidate lists (separate
	 * from the relationship cache so a version-salt bust never touches the
	 * hot follow lookups).
	 */
	private const SUGGEST_CACHE_GROUP = 'buddynext_follow_suggestions';

	/**
	 * Suggestion cache TTL in seconds (5 minutes) — backstop behind the
	 * explicit busts on follow/unfollow and interest edits.
	 */
	private const SUGGEST_CACHE_TTL = 300;

	/**
	 * Bound on the friends-of-friends candidate query.
	 */
	private const FOF_LIMIT = 200;

	/**
	 * Bound on the interest-overlap candidate query.
	 */
	private const INTEREST_LIMIT = 50;

	/**
	 * Cap on the viewer's picks used for matching (their rarest N).
	 */
	private const INTEREST_PICK_CAP = 10;

	/**
	 * Minimum absolute member count before the selectivity ceiling can
	 * exclude a category — keeps small communities (where 10% of members is
	 * a handful) from losing the interest signal entirely.
	 */
	private const INTEREST_CEILING_FLOOR = 20;

	/**
	 * Ranking weight for a friend-of-friend hit (social proof stays king,
	 * matching the space engine's weighting).
	 */
	private const W_FOF = 3;

	/**
	 * Ranking weight per shared interest category.
	 */
	private const W_INTEREST = 2;

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
	public function follow( int $follower_id, int $following_id ): bool|WP_Error {
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

		// Service-layer block guard for direct callers (the controller checks
		// earlier with a friendlier 403). Use the shared, per-request-memoised
		// BlockService check rather than a standalone raw query, so the earlier
		// controller call makes this effectively free.
		$blocks = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : null;
		if ( $blocks && method_exists( $blocks, 'is_blocking_either' )
			&& $blocks->is_blocking_either( $follower_id, $following_id ) ) {
			return new WP_Error(
				'blocked',
				__( 'Cannot follow a blocked user.', 'buddynext' )
			);
		}

		// Follow cap. Bounds a member's following set so the home-feed
		// "people I follow" subquery (FeedService) can never degrade for someone
		// following tens of thousands of accounts. 5,000 matches the mainstream
		// social norm (Facebook/X/LinkedIn sit in this range); filterable per
		// site, and 0 disables the cap. The count is the cached approved-following
		// total, so this guard is effectively free. Skipped when re-following an
		// already-followed account (INSERT IGNORE is a no-op there anyway).
		$cap = (int) apply_filters( 'buddynext_max_following', 5000, $follower_id );
		if ( $cap > 0
			&& ! $this->is_following( $follower_id, $following_id )
			&& $this->following_count( $follower_id ) >= $cap ) {
			return new WP_Error(
				'follow_limit_reached',
				sprintf(
					/* translators: %s: maximum number of accounts a member may follow. */
					__( 'You can follow up to %s accounts. Unfollow someone to follow more.', 'buddynext' ),
					number_format_i18n( $cap )
				),
				array( 'status' => 422 )
			);
		}

		global $wpdb;

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
			// Maintain the denormalised follow counters in O(1) — this branch is
			// reached only for a NEW approved edge (the pending path returned above).
			$counters = buddynext_service( 'counters' );
			$counters->adjust_user_counter( $following_id, 'bn_follower_count', 1 );
			$counters->adjust_user_counter( $follower_id, 'bn_following_count', 1 );

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

		// Capture the edge's status before deleting: only an APPROVED follow was ever
		// counted, so only an approved one decrements the denormalised counters — a
		// withdrawn pending request must leave the displayed counts untouched.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$was_approved = 'approved' === (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND following_id = %d",
				$follower_id,
				$following_id
			)
		);

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

		if ( $deleted ) {
			// Only invalidate caches + fire the hook when a row actually went
			// away. A no-op unfollow (e.g. the double unfollow BlockService runs
			// during block cleanup) must not evict still-valid follower/following
			// counts. Mirrors the approve/reject_follow_request pattern below.
			$this->invalidate_follow_cache( $follower_id, $following_id );

			// Decrement the denormalised counters only for an approved edge.
			if ( $was_approved ) {
				$counters = buddynext_service( 'counters' );
				$counters->adjust_user_counter( $following_id, 'bn_follower_count', -1 );
				$counters->adjust_user_counter( $follower_id, 'bn_following_count', -1 );
			}

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

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated %d list; $follower_id plus the spread $target_ids bind each placeholder.
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

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
			$result = (array) $cached;
		} else {
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
		}

		/**
		 * Filter the follower id list for a user.
		 *
		 * @param int[] $result  Follower user IDs (approved follows), newest first.
		 * @param int   $user_id The user whose followers these are.
		 */
		return (array) apply_filters( 'buddynext_followers', $result, $user_id );
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
			$result = (array) $cached;
		} else {
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
		}

		/**
		 * Filter the following id list for a user.
		 *
		 * @param int[] $result  User IDs the user follows (approved), newest first.
		 * @param int   $user_id The follower.
		 */
		return (array) apply_filters( 'buddynext_following', $result, $user_id );
	}

	/**
	 * Return the total number of followers for a user.
	 *
	 * @param int $user_id The user being followed.
	 * @return int
	 */
	public function follower_count( int $user_id ): int {
		$cache_key = "follower_count_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// Read the denormalised counter (O(1)) rather than COUNT(*)-ing bn_follows on
		// every cache-cold call (P-A: counts must hold up cache-cold). A missing key —
		// first read, or a member who followed before this counter shipped — lazy
		// recounts so the store self-heals; the write paths keep it current after.
		$meta = get_user_meta( $user_id, 'bn_follower_count', true );
		if ( '' === $meta ) {
			buddynext_service( 'counters' )->recount_follow_counts( $user_id );
			$meta = get_user_meta( $user_id, 'bn_follower_count', true );
		}
		$count = (int) $meta;

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
		$cache_key = "following_count_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// Denormalised counter read with lazy-recount self-heal — see follower_count().
		$meta = get_user_meta( $user_id, 'bn_following_count', true );
		if ( '' === $meta ) {
			buddynext_service( 'counters' )->recount_follow_counts( $user_id );
			$meta = get_user_meta( $user_id, 'bn_following_count', true );
		}
		$count = (int) $meta;

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
	 * Return ranked follow suggestions.
	 *
	 * Two additive candidate sources, merged and scored in PHP:
	 *   1. Friends-of-friends (weight 3) — users followed by people the viewer
	 *      already follows. Social proof stays king, matching the space
	 *      engine's weighting.
	 *   2. Interest overlap (weight 2 per shared category) — users sharing
	 *      picked space categories with the viewer, via the system 'interests'
	 *      profile field riding the (field_id, value) index on
	 *      bn_profile_values. Blank interests leave the engine exactly as the
	 *      friends-of-friends graph alone (additive signal, never a
	 *      dependency); the interest branch solves the cold start — a new
	 *      member with picks and zero follows no longer gets an empty list.
	 *
	 * The ranked candidate list is cached per viewer (short TTL + explicit
	 * busts on follow/unfollow and interest edits, see InterestListener). The
	 * block filter and the buddynext_follow_suggestions seam run on every
	 * call, outside the cache, so blocks apply instantly and Pro reranking is
	 * never frozen into Free's cache.
	 *
	 * @param int $user_id The user requesting suggestions.
	 * @return int[]
	 */
	public function suggestions( int $user_id ): array {
		$ids = $this->ranked_suggestion_ids( $user_id );

		if ( empty( $ids ) && empty( $this->following( $user_id ) ) ) {
			// True cold start with no signal at all (no follows, no interest
			// matches) — preserve the historical contract: an empty result
			// without firing the rerank seam.
			return array();
		}

		// Never suggest a user in a block relationship with the viewer (either
		// direction). Filtered here in the service — not only at the controller —
		// so every caller of suggestions() is protected. Resolved in one batch
		// query (no N+1) with no bn_blocks JOIN, preserving the single-table
		// constraint the SELECT above relies on.
		if ( $ids ) {
			$blocks = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : null;
			if ( $blocks && method_exists( $blocks, 'blocking_either_map' ) ) {
				$blocked = $blocks->blocking_either_map( $user_id, $ids );
				if ( $blocked ) {
					$ids = array_values(
						array_filter( $ids, static fn( int $id ): bool => ! isset( $blocked[ $id ] ) )
					);
				}
			}
		}

		/**
		 * Filter the "who to follow" suggestion id list.
		 *
		 * @param int[] $ids     Suggested user IDs (block-filtered), in rank order.
		 * @param int   $user_id The user the suggestions are for.
		 */
		return (array) apply_filters( 'buddynext_follow_suggestions', $ids, $user_id );
	}

	/**
	 * Flush a viewer's cached suggestion candidates.
	 *
	 * Called on the viewer's own follow/unfollow (a just-followed account must
	 * drop out immediately) and by InterestListener on interest edits.
	 *
	 * @param int $user_id Viewer user ID.
	 * @return void
	 */
	public function flush_suggestions_for( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		// Bump the per-user version salt embedded in the cache key — the
		// cheapest portable invalidation without an object-cache group flush
		// (same pattern as SpaceSuggestionService::flush_for_user()).
		wp_cache_set(
			'ver:' . $user_id,
			$this->suggestions_cache_version( $user_id ) . '.' . wp_rand( 1, 99999 ),
			self::SUGGEST_CACHE_GROUP
		);
	}

	/**
	 * Current per-user suggestion cache-version salt (seeded on first read).
	 *
	 * @param int $user_id Viewer user ID.
	 * @return string
	 */
	private function suggestions_cache_version( int $user_id ): string {
		$ver = wp_cache_get( 'ver:' . $user_id, self::SUGGEST_CACHE_GROUP );
		if ( ! is_string( $ver ) || '' === $ver ) {
			$ver = '1';
			wp_cache_set( 'ver:' . $user_id, $ver, self::SUGGEST_CACHE_GROUP );
		}
		return $ver;
	}

	/**
	 * Ranked suggestion candidate ids for a viewer, cached per user.
	 *
	 * @param int $user_id Viewer user ID.
	 * @return int[] Candidate user IDs in rank order.
	 */
	private function ranked_suggestion_ids( int $user_id ): array {
		$cache_key = $user_id . ':' . $this->suggestions_cache_version( $user_id );
		$cached    = wp_cache_get( $cache_key, self::SUGGEST_CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		// Bound the friend-of-friend sample: a user following thousands draws
		// candidates from (and excludes) the first 200 follows, so neither the
		// IN-list nor the NOT-IN exclude grows into a multi-thousand-literal query.
		$following = array_slice( $this->following( $user_id ), 0, self::FOF_LIMIT );
		$exclude   = array_merge( $following, array( $user_id ) );

		$scores = array();
		foreach ( $this->fof_candidate_ids( $following, $exclude ) as $id ) {
			$scores[ $id ] = self::W_FOF;
		}
		foreach ( $this->interest_overlap_counts( $user_id, $exclude ) as $id => $shared ) {
			$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + ( self::W_INTEREST * $shared );
		}

		// Stable descending sort (PHP 8 sorts are stable): equal-score
		// candidates keep source order, so a friends-of-friends-only result
		// is bit-identical to the pre-ranking engine's output.
		arsort( $scores );
		$ids = array_keys( $scores );

		wp_cache_set( $cache_key, $ids, self::SUGGEST_CACHE_GROUP, self::SUGGEST_CACHE_TTL );

		return $ids;
	}

	/**
	 * Friends-of-friends candidate ids.
	 *
	 * The viewer's own following list comes from the cache-backed following()
	 * (one query, usually cached). The second-degree lookup is then a single
	 * query that scans bn_follows once — `follower_id IN (friends)` minus the
	 * exclusion set — instead of one following() call per friend. This removes
	 * the old N+1 (1 + one query per followed user) while keeping the query to
	 * a single table reference, so it stays clear of MySQL's "Can't reopen
	 * table" restriction on the WP test suite's tables.
	 *
	 * @param int[] $following Viewer's (bounded) followed-user ids.
	 * @param int[] $exclude   Ids to exclude (followed set + self).
	 * @return int[]
	 */
	private function fof_candidate_ids( array $following, array $exclude ): array {
		if ( empty( $following ) ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_follows';

		$friends_ph = implode( ', ', array_fill( 0, count( $following ), '%d' ) );
		$exclude_ph = implode( ', ', array_fill( 0, count( $exclude ), '%d' ) );

		// Suspended + shadow-banned users must not surface here either — every
		// other discovery surface (feed, directory) applies the same canonical
		// moderation exclusion, so friend-of-friend suggestions follow suit.
		// Private accounts ARE intentionally suggestible: bn_account_private
		// gates activity visibility, not discoverability.
		$moderation_where = buddynext_service( 'moderation' )->moderation_exclude_sql( 'following_id' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $friends_ph/$exclude_ph are generated %d lists bound via array_merge(); $moderation_where is a self-built, word-char-sanitised fragment with no placeholders.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT following_id
				 FROM {$table}
				 WHERE follower_id IN ({$friends_ph})
				   AND status = 'approved'
				   AND following_id NOT IN ({$exclude_ph})
				   {$moderation_where}
				 LIMIT %d",
				array_merge( $following, $exclude, array( self::FOF_LIMIT ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Candidate user id => shared-interest count for the viewer.
	 *
	 * One indexed query on bn_profile_values riding the (field_id, value)
	 * index: range-scans ONLY the rows for the viewer's categories, never all
	 * members. Guards that keep it flat at 100k members:
	 *   - selectivity ceiling: categories held by more than ~10% of members
	 *     are excluded from matching (a category everyone shares carries no
	 *     signal, and it is also the only case that makes the scan expensive);
	 *   - the IN-list is capped at the viewer's ~10 rarest picks;
	 *   - the result is bounded (LIMIT 50) and cached by the caller.
	 *
	 * Returns an empty map when the viewer has no picks, the interests field
	 * is absent, or nothing overlaps — the engine then falls back to the
	 * friends-of-friends signal alone (never a dependency, never a throw).
	 *
	 * @param int   $user_id Viewer user ID.
	 * @param int[] $exclude Ids to exclude (followed set + self).
	 * @return array<int,int> Candidate user id => shared category count.
	 */
	private function interest_overlap_counts( int $user_id, array $exclude ): array {
		$field_id = $this->interests_field_id();
		if ( $field_id <= 0 ) {
			return array();
		}

		global $wpdb;

		// Viewer's own picks — bounded by the pick count, rides the
		// user_field_entry index.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$picks = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}bn_profile_values
				  WHERE user_id = %d AND field_id = %d
				  ORDER BY entry_index ASC",
				$user_id,
				$field_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$picks = array_values( array_unique( array_filter( array_map( 'absint', (array) $picks ) ) ) );
		if ( empty( $picks ) ) {
			return array();
		}

		$stats  = $this->interest_category_stats( $field_id );
		$counts = $stats['counts'];

		/**
		 * Filter the selectivity ceiling fraction for interest matching.
		 *
		 * Categories picked by more than this fraction of members are excluded
		 * from suggestion matching — a near-universal pick carries no signal
		 * and is the only case that makes the overlap scan expensive.
		 *
		 * @param float $fraction Ceiling fraction of total members (default 0.10).
		 * @param int   $user_id  The viewer the suggestions are for.
		 */
		$fraction = (float) apply_filters( 'buddynext_interest_match_ceiling', 0.10, $user_id );
		$fraction = min( 1.0, max( 0.001, $fraction ) );
		$ceiling  = max( self::INTEREST_CEILING_FLOOR, (int) ceil( $stats['total_members'] * $fraction ) );

		$picks = array_values(
			array_filter(
				$picks,
				static fn( int $cat ): bool => ( $counts[ $cat ] ?? 0 ) <= $ceiling
			)
		);
		if ( empty( $picks ) ) {
			return array();
		}

		// Cap the IN-list at the viewer's rarest picks — the rarest categories
		// carry the most signal (TF-IDF logic) and scan the fewest rows.
		if ( count( $picks ) > self::INTEREST_PICK_CAP ) {
			usort(
				$picks,
				static fn( int $a, int $b ): int => ( $counts[ $a ] ?? 0 ) <=> ( $counts[ $b ] ?? 0 )
			);
			$picks = array_slice( $picks, 0, self::INTEREST_PICK_CAP );
		}

		// Category ids are stored as strings in the value column — bind as %s
		// so the comparison stays on the (field_id, value) index.
		$pick_values = array_map( 'strval', $picks );
		$picks_ph    = implode( ', ', array_fill( 0, count( $pick_values ), '%s' ) );
		$exclude_ph  = implode( ', ', array_fill( 0, count( $exclude ), '%d' ) );

		$moderation_where = buddynext_service( 'moderation' )->moderation_exclude_sql( 'user_id' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $picks_ph/$exclude_ph are generated placeholder lists; every placeholder is bound via one array_merge() argument; $moderation_where is a self-built, word-char-sanitised fragment with no placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS shared
				 FROM {$wpdb->prefix}bn_profile_values
				 WHERE field_id = %d
				   AND value IN ({$picks_ph})
				   AND user_id NOT IN ({$exclude_ph})
				   {$moderation_where}
				 GROUP BY user_id
				 ORDER BY shared DESC
				 LIMIT %d",
				array_merge( array( $field_id ), $pick_values, $exclude, array( self::INTEREST_LIMIT ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['user_id'] ] = (int) $row['shared'];
		}
		return $out;
	}

	/**
	 * Per-category pick counts + total member count for the selectivity ceiling.
	 *
	 * One cheap grouped COUNT over the interests field (bounded by the number
	 * of authored categories) plus the users-table count, object-cached for
	 * 5 minutes — ceiling statistics may lag interest edits by the TTL, which
	 * is harmless for a tuning guard.
	 *
	 * @param int $field_id Interests field id.
	 * @return array{total_members:int, counts:array<int,int>}
	 */
	private function interest_category_stats( int $field_id ): array {
		$cache_key = 'interest_stats:' . $field_id;
		$cached    = wp_cache_get( $cache_key, self::SUGGEST_CACHE_GROUP );
		if ( is_array( $cached ) && isset( $cached['total_members'], $cached['counts'] ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT value, COUNT(*) AS c FROM {$wpdb->prefix}bn_profile_values
				  WHERE field_id = %d GROUP BY value",
				$field_id
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$cat = absint( $row['value'] );
			if ( $cat > 0 ) {
				$counts[ $cat ] = (int) $row['c'];
			}
		}

		$stats = array(
			'total_members' => $total,
			'counts'        => $counts,
		);
		wp_cache_set( $cache_key, $stats, self::SUGGEST_CACHE_GROUP, self::SUGGEST_CACHE_TTL );

		return $stats;
	}

	/**
	 * Resolve the system 'interests' profile field id (0 when absent).
	 *
	 * Missing field (deleted install state, isolation harness) simply
	 * disables the interest branch — never a throw (no-fatal contract).
	 *
	 * @return int
	 */
	private function interests_field_id(): int {
		$cached = wp_cache_get( 'interests_field_id', self::SUGGEST_CACHE_GROUP );
		if ( is_int( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$field_id = (int) $wpdb->get_var(
			"SELECT id FROM {$wpdb->prefix}bn_profile_fields
			  WHERE field_key = 'interests' AND type = 'category_multiselect'
			  LIMIT 1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_set( 'interests_field_id', $field_id, self::SUGGEST_CACHE_GROUP, self::SUGGEST_CACHE_TTL );

		return $field_id;
	}

	/**
	 * Return the IDs of users with pending follow requests TO this user.
	 *
	 * Used by the followers-page request inbox so the owner can see who
	 * is waiting on approval.
	 *
	 * @param int $owner_id Owner of the private account.
	 * @param int $limit    Max rows (default 200, hard-capped at 200) — the inbox is
	 *                      bounded so a follow-request bot-flood can't load thousands.
	 *                      Use pending_followers_count() for the true total.
	 * @param int $offset   Pagination offset.
	 * @return int[] Follower user IDs ordered oldest-first.
	 */
	public function pending_followers( int $owner_id, int $limit = 200, int $offset = 0 ): array {
		global $wpdb;

		$limit  = ( $limit <= 0 ) ? 200 : min( $limit, 200 );
		$offset = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT follower_id
				 FROM {$wpdb->prefix}bn_follows
				 WHERE following_id = %d AND status = 'pending'
				 ORDER BY created_at ASC
				 LIMIT %d OFFSET %d",
				$owner_id,
				$limit,
				$offset
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

		// The pending edge just became approved — now it counts.
		$counters = buddynext_service( 'counters' );
		$counters->adjust_user_counter( $owner_id, 'bn_follower_count', 1 );
		$counters->adjust_user_counter( $follower_id, 'bn_following_count', 1 );

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

		// The follower's ranked suggestion candidates depend on their follow
		// set — bust so a just-followed account drops out immediately instead
		// of lingering for the cache TTL.
		$this->flush_suggestions_for( $follower_id );
	}
}
