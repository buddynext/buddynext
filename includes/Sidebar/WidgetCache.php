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
	 * Invalidate all trending-hashtag entries.
	 *
	 * @return void
	 */
	public function invalidate_trending(): void {
		// We don't enumerate keys — just version-bump the global group via
		// a sentinel option. Cheap implementation: delete the canonical
		// limit=5 key (the only one in normal use). Larger limits are
		// administrative and tolerate one stale read.
		wp_cache_delete( 'trending:5', self::GROUP_GLOBAL );
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
		wp_cache_delete( 'suggested:' . $user_id . ':3', self::GROUP_USER );
		wp_cache_delete( 'spaces:' . $user_id . ':4', self::GROUP_USER );
	}
}
