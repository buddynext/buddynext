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
	 * Block a user.
	 *
	 * If a mute already exists for this pair it is upgraded to a block.
	 * Duplicate blocks are silently safe.
	 *
	 * @param int $blocker_id ID of the user doing the blocking.
	 * @param int $blocked_id ID of the user being blocked.
	 * @return true|WP_Error True on success; WP_Error on self-block.
	 */
	public function block( int $blocker_id, int $blocked_id ): true|WP_Error {
		if ( $blocker_id === $blocked_id ) {
			return new WP_Error(
				'cannot_block_self',
				__( 'A user cannot block themselves.', 'buddynext' )
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_blocks (blocker_id, blocked_id, type)
				 VALUES (%d, %d, 'block')
				 ON DUPLICATE KEY UPDATE type = 'block'",
				$blocker_id,
				$blocked_id
			)
		);

		$this->invalidate_block_cache( $blocker_id, $blocked_id );

		if ( $wpdb->rows_affected > 0 ) {
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

		/**
		 * Fires after a user is unblocked.
		 *
		 * @param int $blocker_id ID of the user removing the block.
		 * @param int $blocked_id ID of the previously blocked user.
		 */
		do_action( 'buddynext_unblock', $blocker_id, $blocked_id );
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
	public function mute( int $muter_id, int $muted_id ): true|WP_Error {
		if ( $muter_id === $muted_id ) {
			return new WP_Error(
				'cannot_mute_self',
				__( 'A user cannot mute themselves.', 'buddynext' )
			);
		}

		global $wpdb;

		// INSERT IGNORE preserves an existing block row — mute never downgrades a block.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_blocks (blocker_id, blocked_id, type)
				 VALUES (%d, %d, 'mute')",
				$muter_id,
				$muted_id
			)
		);

		$this->invalidate_block_cache( $muter_id, $muted_id );

		/**
		 * Fires after a user mutes another.
		 *
		 * @param int $muter_id User doing the muting.
		 * @param int $muted_id User being muted.
		 */
		do_action( 'buddynext_mute', $muter_id, $muted_id );

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

		/**
		 * Fires after a mute relationship is removed.
		 *
		 * @param int $muter_id User removing the mute.
		 * @param int $muted_id Previously muted user.
		 */
		do_action( 'buddynext_unmute', $muter_id, $muted_id );
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
	public function restrict( int $actor_id, int $target_id ): true|WP_Error {
		if ( $actor_id === $target_id ) {
			return new WP_Error(
				'cannot_restrict_self',
				__( 'A user cannot restrict themselves.', 'buddynext' )
			);
		}

		global $wpdb;

		// INSERT IGNORE preserves an existing block — restrict never downgrades.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_blocks (blocker_id, blocked_id, type)
				 VALUES (%d, %d, 'restrict')",
				$actor_id,
				$target_id
			)
		);

		$this->invalidate_block_cache( $actor_id, $target_id );

		/**
		 * Fires after a user restricts another.
		 *
		 * @param int $actor_id  Actor doing the restricting.
		 * @param int $target_id User being restricted.
		 */
		do_action( 'buddynext_user_restricted', $actor_id, $target_id );

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

		/**
		 * Fires after a restrict relationship is removed.
		 *
		 * @param int $actor_id  Actor.
		 * @param int $target_id Target.
		 */
		do_action( 'buddynext_user_unrestricted', $actor_id, $target_id );
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
	 * @return int[]
	 */
	public function restricted_users( int $user_id ): array {
		global $wpdb;

		$cache_key = "restricted_users_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

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

		return $result;
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

		$last_active = (int) get_user_meta( $target_id, 'bn_last_active', true );
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

		return $count > 0;
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$map = array();
		foreach ( (array) $rows as $row ) {
			$peer         = ( (int) $row->blocker_id === $viewer_id ) ? (int) $row->blocked_id : (int) $row->blocker_id;
			$map[ $peer ] = true;
		}

		return $map;
	}

	/**
	 * Return the list of user IDs muted by the given user.
	 *
	 * @param int $user_id The muting user.
	 * @return int[]
	 */
	public function muted_users( int $user_id ): array {
		global $wpdb;

		$cache_key = "muted_users_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

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

		return $result;
	}

	/**
	 * Return the list of user IDs blocked by the given user.
	 *
	 * @param int $user_id The blocking user.
	 * @return int[]
	 */
	public function blocked_users( int $user_id ): array {
		global $wpdb;

		$cache_key = "blocked_users_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

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
		wp_cache_delete( "blocked_users_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "muted_users_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "restricted_users_{$user_a}", self::CACHE_GROUP );

		// Reverse direction: user_b's perspective of the relationship.
		wp_cache_delete( "is_blocked_{$user_b}_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "is_muted_{$user_b}_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "is_restricted_{$user_b}_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "blocked_users_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "muted_users_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "restricted_users_{$user_b}", self::CACHE_GROUP );
	}
}
