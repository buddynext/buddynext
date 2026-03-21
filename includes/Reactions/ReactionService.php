<?php
/**
 * Reaction service.
 *
 * Manages emoji reactions on any object type (posts, comments, messages).
 * Each user may have at most one reaction per object (enforced by the UNIQUE
 * KEY on bn_reactions). React/unreact invalidate the count cache; toggling
 * re-uses react() and unreact() internally.
 *
 * @package BuddyNext\Reactions
 */

declare( strict_types=1 );

namespace BuddyNext\Reactions;

/**
 * Handles reaction add, remove, toggle, and count reads.
 */
class ReactionService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_reactions';

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Add a reaction from a user on an object.
	 *
	 * Uses INSERT IGNORE so duplicate reactions are silently skipped.
	 *
	 * @param int    $user_id     Reacting user.
	 * @param string $object_type Object type (e.g. 'post', 'comment').
	 * @param int    $object_id   Object ID.
	 * @param string $emoji       Emoji identifier (e.g. 'like', 'heart').
	 * @return true
	 */
	public function react( int $user_id, string $object_type, int $object_id, string $emoji = 'like' ): true {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_reactions (user_id, object_type, object_id, emoji)
				 VALUES (%d, %s, %d, %s)",
				$user_id,
				sanitize_key( $object_type ),
				$object_id,
				sanitize_key( $emoji )
			)
		);

		if ( $wpdb->rows_affected > 0 ) {
			$this->invalidate_cache( $object_type, $object_id, $user_id );

			if ( 'post' === $object_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}bn_posts SET reaction_count = reaction_count + 1 WHERE id = %d",
						$object_id
					)
				);
			}

			/**
			 * Fires after a reaction is added to an object.
			 *
			 * @param string $object_type Object type (e.g. 'post', 'comment').
			 * @param int    $object_id   Object ID.
			 * @param int    $user_id     Reacting user.
			 * @param string $emoji       Emoji identifier.
			 */
			do_action( 'buddynext_reaction_added', $object_type, $object_id, $user_id, $emoji );
		}

		return true;
	}

	/**
	 * Remove a reaction from a user on an object.
	 *
	 * @param int    $user_id     User whose reaction to remove.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 */
	public function unreact( int $user_id, string $object_type, int $object_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_reactions',
			array(
				'user_id'     => $user_id,
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id,
			),
			array( '%d', '%s', '%d' )
		);

		if ( $wpdb->rows_affected > 0 ) {
			if ( 'post' === $object_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}bn_posts SET reaction_count = GREATEST(0, reaction_count - 1) WHERE id = %d",
						$object_id
					)
				);
			}

			/**
			 * Fires after a reaction is removed from an object.
			 *
			 * @param string $object_type Object type (e.g. 'post', 'comment').
			 * @param int    $object_id   Object ID.
			 * @param int    $user_id     User who removed their reaction.
			 */
			do_action( 'buddynext_reaction_removed', $object_type, $object_id, $user_id );
		}

		$this->invalidate_cache( $object_type, $object_id, $user_id );
	}

	/**
	 * Toggle a reaction.
	 *
	 * - Same emoji as existing: removes the reaction.
	 * - Different emoji from existing: replaces the existing reaction.
	 * - No existing reaction: adds the reaction.
	 *
	 * @param int    $user_id     Reacting user.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $emoji       Emoji identifier.
	 */
	public function toggle( int $user_id, string $object_type, int $object_id, string $emoji = 'like' ): void {
		$current = $this->get_user_emoji( $user_id, $object_type, $object_id );

		if ( null === $current ) {
			$this->react( $user_id, $object_type, $object_id, $emoji );
			return;
		}

		if ( $current === $emoji ) {
			$this->unreact( $user_id, $object_type, $object_id );
			return;
		}

		// Replace the existing emoji with the new one.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_reactions',
			array( 'emoji' => sanitize_key( $emoji ) ),
			array(
				'user_id'     => $user_id,
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id,
			),
			array( '%s' ),
			array( '%d', '%s', '%d' )
		);

		$this->invalidate_cache( $object_type, $object_id, $user_id );
	}

	/**
	 * Return per-emoji reaction counts for an object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array<string, int> Emoji slug to count map.
	 */
	public function get_counts( string $object_type, int $object_id ): array {
		$cache_key = "counts_{$object_type}_{$object_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT emoji, COUNT(*) AS cnt FROM {$wpdb->prefix}bn_reactions
				 WHERE object_type = %s AND object_id = %d
				 GROUP BY emoji",
				sanitize_key( $object_type ),
				$object_id
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row['emoji'] ] = (int) $row['cnt'];
		}

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, self::CACHE_TTL );

		return $counts;
	}

	/**
	 * Check whether a user has reacted to an object.
	 *
	 * @param int    $user_id     User to check.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return bool
	 */
	public function has_reacted( int $user_id, string $object_type, int $object_id ): bool {
		return null !== $this->get_user_emoji( $user_id, $object_type, $object_id );
	}

	/**
	 * Return the emoji a user reacted with, or null if they have not reacted.
	 *
	 * @param int    $user_id     User to check.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string|null
	 */
	public function get_user_emoji( int $user_id, string $object_type, int $object_id ): ?string {
		$cache_key = "user_emoji_{$user_id}_{$object_type}_{$object_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return ( '' === $cached ) ? null : (string) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$emoji = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT emoji FROM {$wpdb->prefix}bn_reactions
				 WHERE user_id = %d AND object_type = %s AND object_id = %d",
				$user_id,
				sanitize_key( $object_type ),
				$object_id
			)
		);

		wp_cache_set( $cache_key, $emoji ?? '', self::CACHE_GROUP, self::CACHE_TTL );

		return $emoji;
	}

	/**
	 * Return the total reaction count for an object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return int
	 */
	public function count( string $object_type, int $object_id ): int {
		$cache_key = "count_{$object_type}_{$object_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions
				 WHERE object_type = %s AND object_id = %d",
				sanitize_key( $object_type ),
				$object_id
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Invalidate cache entries for an object and optionally a specific user.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $user_id     User whose personal cache to also clear.
	 */
	private function invalidate_cache( string $object_type, int $object_id, int $user_id ): void {
		wp_cache_delete( "count_{$object_type}_{$object_id}", self::CACHE_GROUP );
		wp_cache_delete( "counts_{$object_type}_{$object_id}", self::CACHE_GROUP );
		wp_cache_delete( "user_emoji_{$user_id}_{$object_type}_{$object_id}", self::CACHE_GROUP );
	}
}
