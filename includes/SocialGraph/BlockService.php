<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Block and mute service.
 *
 * Manages block and mute relationships in the bn_blocks table. A block
 * prevents all interaction from the blocked user; a mute silences their
 * content without notification to the muted user.
 *
 * Each (blocker_id, blocked_id) pair holds exactly one record. Blocking an
 * already-muted user upgrades the type to 'block' via ON DUPLICATE KEY UPDATE.
 *
 * @package BuddyNext\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\SocialGraph;

use WP_Error;

/**
 * Handles block and mute relationships.
 */
class BlockService {

	/**
	 * Cache group for all block data.
	 */
	private const CACHE_GROUP = 'buddynext_blocks';

	/**
	 * Cache TTL in seconds (10 minutes).
	 */
	private const CACHE_TTL = 600;

	/**
	 * Per-request memo for is_blocking_either(), keyed by the unordered user
	 * pair ("min:max"). Class-static (not function-static) so the single
	 * invalidation chokepoint — invalidate_block_cache() — can drop a pair's
	 * entry the instant a block/mute/restrict changes within the same request.
	 * Otherwise a block→unblock in one request would keep returning a stale
	 * "blocked" verdict for the rest of that request.
	 *
	 * @var array<string,bool>
	 */
	private static array $blocking_pair_cache = array();

	/**
	 * Block a user.
	 *
	 * If a mute already exists for this pair it is upgraded to a block.
	 * Duplicate blocks are silently safe.
	 *
	 * @param int $blocker_id ID of the user doing the blocking.
	 * @param int $blocked_id ID of the user being blocked.
	 * @return true|WP_Error True on success; WP_Error on self-block.
	 */
	public function block( int $blocker_id, int $blocked_id ): bool|WP_Error {
		if ( $blocker_id === $blocked_id ) {
			return new WP_Error(
				'cannot_block_self',
				__( 'A user cannot block themselves.', 'buddynext' )
			);
		}

		// Capture prior state BEFORE the write: invalidate_block_cache() below
		// clears this key, and the follow/connection severing must run only on a
		// genuinely new block. A repeat block of an already-blocked user already
		// had its relationships severed by the first block, so re-running the
		// cleanup is wasted SELECT/DELETE/cache-flush work.
		$already_blocked = $this->has_blocked( $blocker_id, $blocked_id );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_blocks (blocker_id, blocked_id, type)
				 VALUES (%d, %d, 'block')
				 ON DUPLICATE KEY UPDATE type = 'block'",
				$blocker_id,
				$blocked_id
			)
		);

		// A failed write must surface as an error, not a false success — otherwise
		// we would bust the cache and fire buddynext_block while no row was stored.
		if ( false === $result ) {
			return new WP_Error( 'block_failed', __( 'Could not block this user. Please try again.', 'buddynext' ) );
		}

		// Capture the insert's affected-row count NOW: the unfollow / connection
		// cleanup below each runs its own $wpdb query that overwrites
		// $wpdb->rows_affected, so reading it after that point would gate the hook
		// on a stale value (0 when the pair had no follow/connection rows) and
		// silently skip buddynext_block.
		$row_was_written = $wpdb->rows_affected > 0;

		// Twitter/X/Instagram behaviour: a block severs any existing follow and
		// connection between the two users (both directions), and an unblock does
		// NOT restore them. Route through the canonical services so follower /
		// following / connection counts, caches and hooks all stay correct
		// instead of duplicating that relationship logic here. Only on a NEW
		// block — a duplicate block already severed these on the first call.
		if ( ! $already_blocked ) {
			$follows = buddynext_service( 'follows' );
			$follows->unfollow( $blocker_id, $blocked_id );
			$follows->unfollow( $blocked_id, $blocker_id );

			$connections = buddynext_service( 'connections' );
			$connections->remove_connection( $blocker_id, $blocked_id ); // Accepted, either direction.
			$connections->withdraw_request( $blocker_id, $blocked_id );  // Pending blocker -> blocked.
			$connections->withdraw_request( $blocked_id, $blocker_id );  // Pending blocked -> blocker.
		}

		$this->invalidate_block_cache( $blocker_id, $blocked_id );

		if ( $row_was_written ) {
			/**
			 * Fires after a user is blocked.
			 *
			 * @param int $blocker_id ID of the blocking user.
			 * @param int $blocked_id ID of the blocked user.
			 */
			do_action( 'buddynext_block', $blocker_id, $blocked_id );
		}

		return true;
	}

	/**
	 * Remove a block relationship.
	 *
	 * @param int $blocker_id ID of the user removing the block.
	 * @param int $blocked_id ID of the previously blocked user.
	 */
	public function unblock( int $blocker_id, int $blocked_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $blocker_id,
				'blocked_id' => $blocked_id,
				'type'       => 'block',
			),
			array( '%d', '%d', '%s' )
		);

		$this->invalidate_block_cache( $blocker_id, $blocked_id );

		if ( $wpdb->rows_affected > 0 ) {
			/**
			 * Fires after a user is unblocked.
			 *
			 * Only fires when a block row was actually removed, so listeners
			 * never run for a no-op unblock.
			 *
			 * @param int $blocker_id ID of the user removing the block.
			 * @param int $blocked_id ID of the previously blocked user.
			 */
			do_action( 'buddynext_unblock', $blocker_id, $blocked_id );
		}
	}

	/**
	 * Mute a user.
	 *
	 * Muting silences content without notifying the muted user. If a block
	 * already exists for this pair the existing block is preserved (not
	 * downgraded to a mute).
	 *
	 * @param int $muter_id ID of the user doing the muting.
	 * @param int $muted_id ID of the user being muted.
	 * @return true|WP_Error True on success; WP_Error on self-mute.
	 */
	public function mute( int $muter_id, int $muted_id ): bool|WP_Error {
		if ( $muter_id === $muted_id ) {
			return new WP_Error(
				'cannot_mute_self',
				__( 'A user cannot mute themselves.', 'buddynext' )
			);
		}

		global $wpdb;

		// INSERT IGNORE preserves an existing block row — mute never downgrades a block.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_blocks (blocker_id, blocked_id, type)
				 VALUES (%d, %d, 'mute')",
				$muter_id,
				$muted_id
			)
		);

		// Surface a failed write instead of reporting a false success.
		if ( false === $result ) {
			return new WP_Error( 'mute_failed', __( 'Could not mute this user. Please try again.', 'buddynext' ) );
		}

		$this->invalidate_block_cache( $muter_id, $muted_id );

		if ( $wpdb->rows_affected > 0 ) {
			/**
			 * Fires after a user mutes another.
			 *
			 * Only fires when a new mute row was inserted (INSERT IGNORE is a
			 * no-op when a block/mute already exists), so listeners never run
			 * for a no-op mute.
			 *
			 * @param int $muter_id User doing the muting.
			 * @param int $muted_id User being muted.
			 */
			do_action( 'buddynext_mute', $muter_id, $muted_id );
		}

		return true;
	}

	/**
	 * Remove a mute relationship.
	 *
	 * @param int $muter_id ID of the user removing the mute.
	 * @param int $muted_id ID of the previously muted user.
	 */
	public function unmute( int $muter_id, int $muted_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $muter_id,
				'blocked_id' => $muted_id,
				'type'       => 'mute',
			),
			array( '%d', '%d', '%s' )
		);

		$this->invalidate_block_cache( $muter_id, $muted_id );

		if ( $wpdb->rows_affected > 0 ) {
			/**
			 * Fires after a mute relationship is removed.
			 *
			 * Only fires when a mute row was actually removed, so listeners
			 * never run for a no-op unmute.
			 *
			 * @param int $muter_id User removing the mute.
			 * @param int $muted_id Previously muted user.
			 */
			do_action( 'buddynext_unmute', $muter_id, $muted_id );
		}
	}

	/**
	 * Restrict another user — Instagram-style soft block.
	 *
	 * The restricted user can still see your profile and posts. The
	 * effect is one-way and silent: the restrictor doesn't see their
	 * comments / DMs / notifications in normal places, but the
	 * restricted user gets no signal.
	 *
	 * @param int $actor_id  ID of the user doing the restricting.
	 * @param int $target_id ID of the user being restricted.
	 * @return true|WP_Error True on success; WP_Error on self-restrict.
	 */
	public function restrict( int $actor_id, int $target_id ): bool|WP_Error {
		if ( $actor_id === $target_id ) {
			return new WP_Error(
				'cannot_restrict_self',
				__( 'A user cannot restrict themselves.', 'buddynext' )
			);
		}

		global $wpdb;

		// INSERT IGNORE preserves an existing block — restrict never downgrades.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_blocks (blocker_id, blocked_id, type)
				 VALUES (%d, %d, 'restrict')",
				$actor_id,
				$target_id
			)
		);

		// Surface a failed write instead of reporting a false success.
		if ( false === $result ) {
			return new WP_Error( 'restrict_failed', __( 'Could not restrict this user. Please try again.', 'buddynext' ) );
		}

		$this->invalidate_block_cache( $actor_id, $target_id );

		if ( $wpdb->rows_affected > 0 ) {
			/**
			 * Fires after a user restricts another.
			 *
			 * Only fires when a new restrict row was inserted (INSERT IGNORE is
			 * a no-op when a block/restrict already exists), so listeners never
			 * run for a no-op restrict.
			 *
			 * @param int $actor_id  Actor doing the restricting.
			 * @param int $target_id User being restricted.
			 */
			do_action( 'buddynext_user_restricted', $actor_id, $target_id );
		}

		return true;
	}

	/**
	 * Remove a restrict relationship.
	 *
	 * @param int $actor_id  ID of the user removing the restrict.
	 * @param int $target_id ID of the previously restricted user.
	 */
	public function unrestrict( int $actor_id, int $target_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $actor_id,
				'blocked_id' => $target_id,
				'type'       => 'restrict',
			),
			array( '%d', '%d', '%s' )
		);

		$this->invalidate_block_cache( $actor_id, $target_id );

		if ( $wpdb->rows_affected > 0 ) {
			/**
			 * Fires after a restrict relationship is removed.
			 *
			 * Only fires when a restrict row was actually removed, so listeners
			 * never run for a no-op unrestrict.
			 *
			 * @param int $actor_id  Actor.
			 * @param int $target_id Target.
			 */
			do_action( 'buddynext_user_unrestricted', $actor_id, $target_id );
		}
	}

	/**
	 * Check whether one user has restricted another (one direction).
	 *
	 * @param int $actor_id  Potential restrictor.
	 * @param int $target_id Potentially restricted user.
	 * @return bool
	 */
	public function is_restricted( int $actor_id, int $target_id ): bool {
		global $wpdb;

		$cache_key = "is_restricted_{$actor_id}_{$target_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_blocks
				 WHERE blocker_id = %d AND blocked_id = %d AND type = 'restrict'",
				$actor_id,
				$target_id
			)
		);

		wp_cache_set( $cache_key, $exists, self::CACHE_GROUP, self::CACHE_TTL );

		return $exists > 0;
	}

	/**
	 * Return the list of user IDs the given user has restricted.
	 *
	 * @param int $user_id The restricting user.
	 * @param int $limit   Optional. Max IDs to return; 0 returns the full list. Default 0.
	 * @param int $offset  Optional. Offset into the list when limited. Default 0.
	 * @return int[]
	 */
	public function restricted_users( int $user_id, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$cache_key = "restricted_users_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$result = (array) $cached;
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT blocked_id
					 FROM {$wpdb->prefix}bn_blocks
					 WHERE blocker_id = %d AND type = 'restrict'
					 ORDER BY created_at DESC",
					$user_id
				)
			);

			$result = array_map( 'intval', (array) $rows );

			wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $limit > 0 ? array_slice( $result, max( 0, $offset ), $limit ) : $result;
	}

	/**
	 * Return true when the target user appears online to the given viewer.
	 *
	 * Single seam for the four surfaces that decide whether to render a
	 * green presence dot (member directory, profile hero, DM list rail,
	 * DM thread header). Centralises the bn_last_active threshold so it
	 * lives in one place, and applies the restrict gate: a user the
	 * viewer has restricted always reads as offline so the viewer never
	 * sees activity signals from them.
	 *
	 * The check is one-way. The restricted user themselves sees normal
	 * presence dots — restrict is silent.
	 *
	 * @param int $viewer_id    Viewer (0 = anonymous, no restrict gate applied).
	 * @param int $target_id    User whose presence is being checked.
	 * @param int $threshold_s  Optional. Window in seconds. Default 300 (5 minutes).
	 * @return bool
	 */
	public function is_user_online( int $viewer_id, int $target_id, int $threshold_s = 300 ): bool {
		if ( $target_id <= 0 ) {
			return false;
		}

		if ( $viewer_id > 0 && $viewer_id !== $target_id && $this->is_restricted( $viewer_id, $target_id ) ) {
			return false;
		}

		$last_active = \BuddyNext\Realtime\PresenceService::last_active_at( $target_id );
		if ( $last_active <= 0 ) {
			return false;
		}
		return $last_active >= ( time() - $threshold_s );
	}

	/**
	 * Check whether a block exists between two users (bidirectional).
	 *
	 * Returns true when user_a has blocked user_b OR user_b has blocked
	 * user_a. Use has_blocked() when you need a single-direction check.
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return bool
	 */
	public function is_blocked( int $user_a, int $user_b ): bool {
		return $this->is_blocking_either( $user_a, $user_b );
	}

	/**
	 * Check whether user_a has specifically blocked user_b (one direction only).
	 *
	 * @param int $blocker_id ID of the potential blocker.
	 * @param int $blocked_id ID of the potentially blocked user.
	 * @return bool
	 */
	public function has_blocked( int $blocker_id, int $blocked_id ): bool {
		global $wpdb;

		$cache_key = "is_blocked_{$blocker_id}_{$blocked_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_blocks
				 WHERE blocker_id = %d AND blocked_id = %d AND type = 'block'",
				$blocker_id,
				$blocked_id
			)
		);

		wp_cache_set( $cache_key, $exists, self::CACHE_GROUP, self::CACHE_TTL );

		return $exists > 0;
	}

	/**
	 * Check whether a user has muted another.
	 *
	 * @param int $muter_id ID of the potential muter.
	 * @param int $muted_id ID of the potentially muted user.
	 * @return bool
	 */
	public function is_muted( int $muter_id, int $muted_id ): bool {
		global $wpdb;

		$cache_key = "is_muted_{$muter_id}_{$muted_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_blocks
				 WHERE blocker_id = %d AND blocked_id = %d AND type = 'mute'",
				$muter_id,
				$muted_id
			)
		);

		wp_cache_set( $cache_key, $exists, self::CACHE_GROUP, self::CACHE_TTL );

		return $exists > 0;
	}

	/**
	 * Check whether either user has blocked the other.
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return bool
	 */
	public function is_blocking_either( int $user_a, int $user_b ): bool {
		// The same viewer/author pair is checked repeatedly across follow and
		// connection buttons in one feed render. Memoise per request, keyed by
		// the unordered pair (the check is symmetric).
		$key = $user_a < $user_b ? "{$user_a}:{$user_b}" : "{$user_b}:{$user_a}";
		if ( isset( self::$blocking_pair_cache[ $key ] ) ) {
			return self::$blocking_pair_cache[ $key ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_blocks
				 WHERE type = 'block'
				   AND ( ( blocker_id = %d AND blocked_id = %d )
				      OR ( blocker_id = %d AND blocked_id = %d ) )",
				$user_a,
				$user_b,
				$user_b,
				$user_a
			)
		);

		self::$blocking_pair_cache[ $key ] = $count > 0;

		return self::$blocking_pair_cache[ $key ];
	}

	/**
	 * Warm the per-pair is_restricted() object cache for a whole page of peers
	 * using a single restricted_users() lookup.
	 *
	 * Callers such as is_user_online() check is_restricted() with a viewer↔peer
	 * cache key; without priming each distinct peer is a cache miss and a query.
	 * Calling this once before a row loop turns that N+1 into one query.
	 *
	 * @param int   $viewer_id Viewer user ID.
	 * @param int[] $peer_ids  Peer user IDs on the current page.
	 * @return void
	 */
	public function prime_restricted_cache( int $viewer_id, array $peer_ids ): void {
		$peer_ids = array_values( array_unique( array_filter( array_map( 'intval', $peer_ids ) ) ) );
		if ( $viewer_id <= 0 || ! $peer_ids ) {
			return;
		}

		$restricted = array_fill_keys( $this->restricted_users( $viewer_id ), true );
		foreach ( $peer_ids as $peer ) {
			wp_cache_set(
				"is_restricted_{$viewer_id}_{$peer}",
				isset( $restricted[ $peer ] ) ? 1 : 0,
				self::CACHE_GROUP,
				self::CACHE_TTL
			);
		}
	}

	/**
	 * Resolve, in one query, which of the given peers are in a block
	 * relationship with the viewer (in either direction).
	 *
	 * Avoids the N+1 that calling is_blocking_either() per peer would produce
	 * on a member directory page.
	 *
	 * @param int   $viewer_id Viewer user ID.
	 * @param int[] $peer_ids  Peer user IDs on the current page.
	 * @return array<int, true> Peer-ID keyed map of peers blocked either way.
	 */
	public function blocking_either_map( int $viewer_id, array $peer_ids ): array {
		$peer_ids = array_values( array_unique( array_filter( array_map( 'intval', $peer_ids ) ) ) );
		if ( $viewer_id <= 0 || ! $peer_ids ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $peer_ids ), '%d' ) );
		$params       = array_merge( array( $viewer_id ), $peer_ids, array( $viewer_id ), $peer_ids );

		// $placeholders is a generated list of %d for an int array; every value is
		// bound through $wpdb->prepare() below, so the interpolation is safe.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated %d list; $params binds viewer_id + all peer IDs twice, matching the two IN() clauses.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blocker_id, blocked_id
				 FROM {$wpdb->prefix}bn_blocks
				 WHERE type = 'block'
				   AND ( ( blocker_id = %d AND blocked_id IN ( {$placeholders} ) )
				      OR ( blocked_id = %d AND blocker_id IN ( {$placeholders} ) ) )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$map = array();
		foreach ( (array) $rows as $row ) {
			$peer         = ( (int) $row->blocker_id === $viewer_id ) ? (int) $row->blocked_id : (int) $row->blocker_id;
			$map[ $peer ] = true;
		}

		return $map;
	}

	/**
	 * Resolve every directed block/mute/restrict state for one pair in a
	 * single query.
	 *
	 * The profile view needs is_blocked, is_muted and is_restricted for the
	 * same (blocker → blocked) direction. Calling has_blocked() / is_muted() /
	 * is_restricted() separately fires three round-trips; this collapses them
	 * to one SELECT that returns whichever of the three type rows exist, then
	 * primes the per-type object cache so a later single-type check is a hit.
	 *
	 * @param int $blocker_id ID of the acting user (viewer).
	 * @param int $blocked_id ID of the target user (profile being viewed).
	 * @return array{block:bool, mute:bool, restrict:bool}
	 */
	public function directed_block_types( int $blocker_id, int $blocked_id ): array {
		$state = array(
			'block'    => false,
			'mute'     => false,
			'restrict' => false,
		);

		if ( $blocker_id <= 0 || $blocked_id <= 0 ) {
			return $state;
		}

		$cache_key = "directed_types_{$blocker_id}_{$blocked_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$types = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT type
				 FROM {$wpdb->prefix}bn_blocks
				 WHERE blocker_id = %d AND blocked_id = %d
				   AND type IN ( 'block', 'mute', 'restrict' )",
				$blocker_id,
				$blocked_id
			)
		);

		foreach ( (array) $types as $type ) {
			if ( isset( $state[ $type ] ) ) {
				$state[ $type ] = true;
			}
		}

		// Prime the per-type object cache so any later has_blocked() /
		// is_muted() / is_restricted() call for this pair is a cache hit.
		wp_cache_set( "is_blocked_{$blocker_id}_{$blocked_id}", $state['block'] ? 1 : 0, self::CACHE_GROUP, self::CACHE_TTL );
		wp_cache_set( "is_muted_{$blocker_id}_{$blocked_id}", $state['mute'] ? 1 : 0, self::CACHE_GROUP, self::CACHE_TTL );
		wp_cache_set( "is_restricted_{$blocker_id}_{$blocked_id}", $state['restrict'] ? 1 : 0, self::CACHE_GROUP, self::CACHE_TTL );
		wp_cache_set( $cache_key, $state, self::CACHE_GROUP, self::CACHE_TTL );

		return $state;
	}

	/**
	 * Return the list of user IDs muted by the given user.
	 *
	 * @param int $user_id The muting user.
	 * @param int $limit   Optional. Max IDs to return; 0 returns the full list. Default 0.
	 * @param int $offset  Optional. Offset into the list when limited. Default 0.
	 * @return int[]
	 */
	public function muted_users( int $user_id, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$cache_key = "muted_users_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$result = (array) $cached;
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT blocked_id
					 FROM {$wpdb->prefix}bn_blocks
					 WHERE blocker_id = %d AND type = 'mute'
					 ORDER BY created_at DESC",
					$user_id
				)
			);

			$result = array_map( 'intval', (array) $rows );

			wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );
		}

		// The full list stays cached (feed exclusion needs all of it); a bounded
		// caller gets a slice without re-querying.
		return $limit > 0 ? array_slice( $result, max( 0, $offset ), $limit ) : $result;
	}

	/**
	 * Return the list of user IDs blocked by the given user.
	 *
	 * @param int $user_id The blocking user.
	 * @param int $limit   Optional. Max IDs to return; 0 returns the full list. Default 0.
	 * @param int $offset  Optional. Offset into the list when limited. Default 0.
	 * @return int[]
	 */
	public function blocked_users( int $user_id, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$cache_key = "blocked_users_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$result = (array) $cached;
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT blocked_id
					 FROM {$wpdb->prefix}bn_blocks
					 WHERE blocker_id = %d AND type = 'block'
					 ORDER BY created_at DESC",
					$user_id
				)
			);

			$result = array_map( 'intval', (array) $rows );

			wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $limit > 0 ? array_slice( $result, max( 0, $offset ), $limit ) : $result;
	}

	/**
	 * Return every user ID in a block relationship with the given user, in
	 * either direction (users they blocked + users who blocked them).
	 *
	 * Mirrors the bidirectional rule is_blocking_either() applies, but for a
	 * whole-account exclusion list rather than a single pair. Used by the
	 * member directory's server-rendered first page so the no-JS / initial
	 * paint hides exactly the members the REST/live pipeline excludes.
	 *
	 * @param int $user_id The viewing user.
	 * @return int[] Distinct user IDs blocked either way (empty for guests).
	 */
	public function block_related_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$cache_key = "block_related_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT blocked_id FROM {$wpdb->prefix}bn_blocks WHERE blocker_id = %d AND type = 'block'
				 UNION
				 SELECT blocker_id FROM {$wpdb->prefix}bn_blocks WHERE blocked_id = %d AND type = 'block'",
				$user_id,
				$user_id
			)
		);

		$result = array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Invalidate all cache keys affected by a block or mute state change.
	 *
	 * Clears both directions: acting user (user_a) and target user (user_b).
	 * Without the reverse keys, user_b would see stale has_blocked/is_muted
	 * results for up to CACHE_TTL seconds after the relationship changed.
	 *
	 * @param int $user_a The acting user.
	 * @param int $user_b The target user.
	 */
	private function invalidate_block_cache( int $user_a, int $user_b ): void {
		// Forward direction: user_a acted on user_b.
		wp_cache_delete( "is_blocked_{$user_a}_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "is_muted_{$user_a}_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "is_restricted_{$user_a}_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "directed_types_{$user_a}_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "blocked_users_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "muted_users_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "restricted_users_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "block_related_{$user_a}", self::CACHE_GROUP );

		// Reverse direction: user_b's perspective of the relationship.
		wp_cache_delete( "is_blocked_{$user_b}_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "is_muted_{$user_b}_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "is_restricted_{$user_b}_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "directed_types_{$user_b}_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "blocked_users_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "muted_users_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "restricted_users_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "block_related_{$user_b}", self::CACHE_GROUP );

		// Drop the per-request is_blocking_either() memo for this pair so a
		// block/unblock (or mute/restrict change) within one request never
		// returns a stale verdict.
		$pair_key = $user_a < $user_b ? "{$user_a}:{$user_b}" : "{$user_b}:{$user_a}";
		unset( self::$blocking_pair_cache[ $pair_key ] );
	}
}
