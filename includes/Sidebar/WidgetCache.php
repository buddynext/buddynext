<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Sidebar widget cache layer.
 *
 * Wraps wp_cache_* for the three sidebar widgets and owns the keys +
 * cache groups + TTLs. Used by WidgetService; bust hooks are registered
 * in WidgetListener.
 *
 * Per docs/specs/SCALE-CONTRACT.md: sidebar widgets fire on every BN
 * hub page render. Caching is mandatory for the 100k x 100k scale.
 *
 * @package BuddyNext\Sidebar
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace BuddyNext\Sidebar;

/**
 * Get / set / delete sidebar widget cache entries.
 */
class WidgetCache {

	/**
	 * Cache group for site-wide widgets (trending hashtags).
	 */
	public const GROUP_GLOBAL = 'buddynext_widgets';

	/**
	 * Cache group for per-user widgets (suggested follows, joined spaces).
	 */
	public const GROUP_USER = 'buddynext_user_meta';

	/**
	 * Trending hashtags refresh every 60 s. Fast-moving aggregate.
	 */
	public const TTL_TRENDING = 60;

	/**
	 * Per-user lists refresh every 300 s. Bust on relevant write.
	 */
	public const TTL_USER = 300;

	/**
	 * Get a cached value, or compute + store it via the miss callback.
	 *
	 * @param string   $key            Cache key (already scoped).
	 * @param string   $group          Cache group constant.
	 * @param int      $ttl            TTL in seconds.
	 * @param callable $miss_callback  Producer when the cache misses.
	 * @return mixed
	 */
	public function get( string $key, string $group, int $ttl, callable $miss_callback ) {
		$found = false;
		$value = wp_cache_get( $key, $group, false, $found );
		if ( true === $found ) {
			return $value;
		}
		$value = $miss_callback();
		wp_cache_set( $key, $value, $group, $ttl );
		return $value;
	}

	/**
	 * Default display limits, matching the WidgetService signatures. These are
	 * the only limits partials/sidebar.php renders, so they are the only keys
	 * invalidation needs to clear; larger limits are administrative and tolerate
	 * one stale read until TTL.
	 */
	public const DEFAULT_LIMIT_TRENDING  = 5;
	public const DEFAULT_LIMIT_SUGGESTED = 3;
	public const DEFAULT_LIMIT_SPACES    = 4;

	/**
	 * Candidate-pool size for a given suggested-follows display limit.
	 *
	 * The suggested-follows widget caches an over-fetched POOL and draws the
	 * display sample from it per render, so the cache key is keyed by the POOL
	 * size, not the display limit. That indirection is why the key drifted -
	 * see key_suggested() - so the derivation lives here, next to the key that
	 * depends on it, rather than being recomputed at the call site.
	 *
	 * @param int $limit Display limit.
	 * @return int
	 */
	public static function pool_size( int $limit ): int {
		return max( $limit * 4, $limit + 6 );
	}

	/**
	 * Cache key for the trending-hashtags widget.
	 *
	 * @param int $limit Display limit.
	 * @return string
	 */
	public static function key_trending( int $limit ): string {
		return 'trending:' . $limit;
	}

	/**
	 * Cache key for the suggested-follows candidate pool.
	 *
	 * Every key below is built here and nowhere else, because the one time a key
	 * was built in two places it silently broke: suggested_follows() was renamed
	 * from a v2 display-sample key to a v3 POOL key, and invalidate_user() kept
	 * deleting `suggested-v2:{user}:3`. Nothing failed - wp_cache_delete() on a
	 * key that does not exist is a successful no-op - so following or blocking
	 * someone cleared nothing and they kept appearing in Suggested People until
	 * the 300s TTL expired, on every site with a persistent object cache.
	 *
	 * Two mismatches at once, which is what made it invisible to inspection: the
	 * prefix was stale AND the suffix was the display limit (3) where the real
	 * key carries the pool size (12).
	 *
	 * @param int $user_id Viewer ID.
	 * @param int $limit   Display limit.
	 * @return string
	 */
	public static function key_suggested( int $user_id, int $limit ): string {
		return 'suggested-pool-v3:' . $user_id . ':' . self::pool_size( $limit );
	}

	/**
	 * Cache key for the joined-spaces widget.
	 *
	 * @param int $user_id Viewer ID.
	 * @param int $limit   Display limit.
	 * @return string
	 */
	public static function key_spaces( int $user_id, int $limit ): string {
		return 'spaces:' . $user_id . ':' . $limit;
	}

	/**
	 * Invalidate all trending-hashtag entries.
	 *
	 * @return void
	 */
	public function invalidate_trending(): void {
		// We don't enumerate keys — just version-bump the global group via
		// a sentinel option. Cheap implementation: delete the canonical
		// limit=5 key (the only one in normal use). Larger limits are
		// administrative and tolerate one stale read.
		wp_cache_delete( self::key_trending( self::DEFAULT_LIMIT_TRENDING ), self::GROUP_GLOBAL );
	}

	/**
	 * Invalidate per-user suggested-follows + joined-spaces entries.
	 *
	 * @param int $user_id Viewer ID.
	 * @return void
	 */
	public function invalidate_user( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		// Default-limit keys are the only ones rendered by partials/sidebar.php.
		// Larger-limit keys are admin-only and tolerate one stale read until TTL.
		wp_cache_delete( self::key_suggested( $user_id, self::DEFAULT_LIMIT_SUGGESTED ), self::GROUP_USER );
		wp_cache_delete( self::key_spaces( $user_id, self::DEFAULT_LIMIT_SPACES ), self::GROUP_USER );
	}
}
