<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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

	/**
	 * Return cached value or compute and cache it.
	 *
	 * Calls $callback only on a cache miss, stores the result, and returns it.
	 *
	 * @param string   $key      Cache key.
	 * @param int      $ttl      TTL in seconds.
	 * @param callable $callback Callable that produces the value on a cache miss.
	 * @return mixed
	 */
	public function remember( string $key, int $ttl, callable $callback ): mixed {
		$cached = $this->get( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		$value = $callback();
		$this->set( $key, $value, $ttl );

		return $value;
	}

	/**
	 * Flush all cache entries in the BuddyNext cache group.
	 *
	 * Delegates to wp_cache_flush_group() when available (Redis Object Cache,
	 * Memcached). Silent no-op on in-process object cache (entries expire at
	 * the end of the request anyway).
	 *
	 * @param string $group Cache group name. Defaults to the BuddyNext group.
	 * @return void
	 */
	public function forget_group( string $group = self::GROUP ): void {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( $group );
		}
	}
}
