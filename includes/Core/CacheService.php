<?php
/**
 * Centralised wp_cache_* wrappers for BuddyNext hot-path cache keys.
 *
 * All cache keys and TTLs are defined here so that every caller uses the
 * same key format.  The cache group 'buddynext' keeps BuddyNext entries
 * namespaced away from the rest of the object cache.
 *
 * On hosts with Redis or Memcached the WordPress object cache will persist
 * across requests.  On shared hosting the in-memory request cache is used,
 * which still prevents duplicate queries within a single page load.
 *
 * Cache keys defined here match the spec in docs/specs/features/19-database-scale.md:
 *   bn_notif_count_{user_id}   — notification unread count   TTL 30s
 *   bn_trending_hashtags       — trending hashtag list        TTL 30min
 *   bn_space_members_{space}   — space member count           TTL 5min
 *   bn_follow_counts_{user_id} — follower + following counts  TTL 5min
 *   bn_abilities_{user_id}     — granted ability list         TTL 60s
 *   bn_hashtag_ac_{query}      — hashtag autocomplete         TTL 10min
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Read/write helpers for the BuddyNext object-cache layer.
 */
class CacheService {

	/**
	 * WordPress object-cache group.
	 */
	private const GROUP = 'buddynext';

	// ── Notification count ────────────────────────────────────────────────────

	/**
	 * Return the cached unread notification count for a user, or null.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int|null Cached count, or null on cache miss.
	 */
	public function get_notification_count( int $user_id ): ?int {
		$value = wp_cache_get( "bn_notif_count_{$user_id}", self::GROUP );
		return false === $value ? null : (int) $value;
	}

	/**
	 * Store the unread notification count for a user (TTL 30 s).
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $count   Unread count to cache.
	 * @return void
	 */
	public function set_notification_count( int $user_id, int $count ): void {
		wp_cache_set( "bn_notif_count_{$user_id}", $count, self::GROUP, 30 );
	}

	/**
	 * Evict the notification count for a user from the cache.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function invalidate_notification_count( int $user_id ): void {
		wp_cache_delete( "bn_notif_count_{$user_id}", self::GROUP );
	}

	// ── Trending hashtags ─────────────────────────────────────────────────────

	/**
	 * Return the cached trending hashtag list, or null.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public function get_trending_hashtags(): ?array {
		$value = wp_cache_get( 'bn_trending_hashtags', self::GROUP );
		return false === $value ? null : (array) $value;
	}

	/**
	 * Store the trending hashtag list (TTL 30 min).
	 *
	 * @param array<int, array<string, mixed>> $tags Ordered hashtag rows.
	 * @return void
	 */
	public function set_trending_hashtags( array $tags ): void {
		wp_cache_set( 'bn_trending_hashtags', $tags, self::GROUP, 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * Evict the trending hashtag list from the cache.
	 *
	 * @return void
	 */
	public function invalidate_trending_hashtags(): void {
		wp_cache_delete( 'bn_trending_hashtags', self::GROUP );
	}

	// ── Space member count ────────────────────────────────────────────────────

	/**
	 * Return the cached member count for a space, or null.
	 *
	 * @param int $space_id Space ID.
	 * @return int|null Cached count, or null on cache miss.
	 */
	public function get_space_member_count( int $space_id ): ?int {
		$value = wp_cache_get( "bn_space_members_{$space_id}", self::GROUP );
		return false === $value ? null : (int) $value;
	}

	/**
	 * Store the member count for a space (TTL 5 min).
	 *
	 * @param int $space_id Space ID.
	 * @param int $count    Member count to cache.
	 * @return void
	 */
	public function set_space_member_count( int $space_id, int $count ): void {
		wp_cache_set( "bn_space_members_{$space_id}", $count, self::GROUP, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Evict the member count for a space from the cache.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	public function invalidate_space_member_count( int $space_id ): void {
		wp_cache_delete( "bn_space_members_{$space_id}", self::GROUP );
	}

	// ── Follow counts ─────────────────────────────────────────────────────────

	/**
	 * Return the cached follow counts for a user, or null.
	 *
	 * Returned array has keys 'followers' and 'following'.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, int>|null Cached counts, or null on cache miss.
	 */
	public function get_follow_counts( int $user_id ): ?array {
		$value = wp_cache_get( "bn_follow_counts_{$user_id}", self::GROUP );
		return false === $value ? null : (array) $value;
	}

	/**
	 * Store the follow counts for a user (TTL 5 min).
	 *
	 * @param int                $user_id WordPress user ID.
	 * @param array<string, int> $counts  Array with 'followers' and 'following' keys.
	 * @return void
	 */
	public function set_follow_counts( int $user_id, array $counts ): void {
		wp_cache_set( "bn_follow_counts_{$user_id}", $counts, self::GROUP, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Evict follow counts for a user from the cache.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function invalidate_follow_counts( int $user_id ): void {
		wp_cache_delete( "bn_follow_counts_{$user_id}", self::GROUP );
	}

	// ── User abilities ────────────────────────────────────────────────────────

	/**
	 * Return the cached granted ability list for a user, or null.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<int, string>|null Cached ability slugs, or null on miss.
	 */
	public function get_user_abilities( int $user_id ): ?array {
		$value = wp_cache_get( "bn_abilities_{$user_id}", self::GROUP );
		return false === $value ? null : (array) $value;
	}

	/**
	 * Store the granted ability list for a user (TTL 60 s).
	 *
	 * @param int                $user_id   WordPress user ID.
	 * @param array<int, string> $abilities Array of ability slugs.
	 * @return void
	 */
	public function set_user_abilities( int $user_id, array $abilities ): void {
		wp_cache_set( "bn_abilities_{$user_id}", $abilities, self::GROUP, 60 );
	}

	/**
	 * Evict the ability list for a user from the cache.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function invalidate_user_abilities( int $user_id ): void {
		wp_cache_delete( "bn_abilities_{$user_id}", self::GROUP );
	}

	// ── Hashtag autocomplete ──────────────────────────────────────────────────

	/**
	 * Return the cached autocomplete results for a hashtag prefix, or null.
	 *
	 * @param string $query Autocomplete prefix string.
	 * @return array<int, array<string, mixed>>|null
	 */
	public function get_hashtag_autocomplete( string $query ): ?array {
		$value = wp_cache_get( 'bn_hashtag_ac_' . md5( $query ), self::GROUP );
		return false === $value ? null : (array) $value;
	}

	/**
	 * Store autocomplete results for a hashtag prefix (TTL 10 min).
	 *
	 * @param string                           $query   Autocomplete prefix.
	 * @param array<int, array<string, mixed>> $results Matching hashtag rows.
	 * @return void
	 */
	public function set_hashtag_autocomplete( string $query, array $results ): void {
		wp_cache_set( 'bn_hashtag_ac_' . md5( $query ), $results, self::GROUP, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Evict hashtag autocomplete results for a specific prefix.
	 *
	 * @param string $query Autocomplete prefix.
	 * @return void
	 */
	public function invalidate_hashtag_autocomplete( string $query ): void {
		wp_cache_delete( 'bn_hashtag_ac_' . md5( $query ), self::GROUP );
	}

	// ── Generic helpers ───────────────────────────────────────────────────────

	/**
	 * Read a raw cache entry. Returns null on miss.
	 *
	 * @param string $key Cache key.
	 * @return mixed|null Cached value, or null on cache miss.
	 */
	public function get( string $key ): mixed {
		$value = wp_cache_get( $key, self::GROUP );
		return false === $value ? null : $value;
	}

	/**
	 * Write a raw cache entry.
	 *
	 * @param string $key        Cache key.
	 * @param mixed  $value      Value to cache.
	 * @param int    $ttl        TTL in seconds. Default 3600.
	 * @return void
	 */
	public function set( string $key, mixed $value, int $ttl = 3600 ): void {
		wp_cache_set( $key, $value, self::GROUP, $ttl );
	}

	/**
	 * Delete a raw cache entry.
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public function delete( string $key ): void {
		wp_cache_delete( $key, self::GROUP );
	}
}
