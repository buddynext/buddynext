<?php
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
	}

	/**
	 * Check whether a user has blocked another.
	 *
	 * @param int $blocker_id ID of the potential blocker.
	 * @param int $blocked_id ID of the potentially blocked user.
	 * @return bool
	 */
	public function is_blocked( int $blocker_id, int $blocked_id ): bool {
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
	 * @param int $user_a The acting user.
	 * @param int $user_b The target user.
	 */
	private function invalidate_block_cache( int $user_a, int $user_b ): void {
		wp_cache_delete( "is_blocked_{$user_a}_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "is_muted_{$user_a}_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "blocked_users_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "muted_users_{$user_a}", self::CACHE_GROUP );
	}
}
