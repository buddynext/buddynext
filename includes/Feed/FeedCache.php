<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Feed cache layer.
 *
 * Per docs/specs/SCALE-CONTRACT.md: the home feed renders on every BN
 * hub /activity/ page load. At 100k sites × 100k members the first-page
 * read pressure is the single biggest scale concern after the sidebar
 * widgets. This class wraps the per-user first-page cache + the global
 * trending cache + the per-user unread count.
 *
 * Layer 2 Cache file per docs/specs/MODULAR-ARCHITECTURE.md.
 *
 * @package BuddyNext\Feed
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

/**
 * Cache groups + TTLs + get/set/bust helpers for the feed.
 */
class FeedCache {

	/**
	 * Cache group for site-wide aggregates (trending, hottest, etc.).
	 */
	public const GROUP_GLOBAL = 'buddynext_feed_global';

	/**
	 * Cache group for per-user feed reads.
	 */
	public const GROUP_USER = 'buddynext_feed_user';

	/**
	 * First-page home feed cache TTL (30 s). Tight enough that new posts
	 * surface fast, long enough that a refresh storm doesn't crush DB.
	 */
	public const TTL_HOME_PAGE_1 = 30;

	/**
	 * Trending posts cache TTL (300 s). Recompute every 5 min.
	 */
	public const TTL_TRENDING = 300;

	/**
	 * Get a cached value or compute + store it via the miss callback.
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
	 * Build the cache key for a user's first-page home feed.
	 *
	 * @param int $user_id Viewer.
	 * @param int $per_page Page size.
	 * @return string
	 */
	public function home_page_1_key( int $user_id, int $per_page ): string {
		return 'home:p1:' . $user_id . ':' . $per_page;
	}

	/**
	 * Invalidate every cached page-1 home feed that mentions this user.
	 *
	 * Cheap version: bust the writer's own key (their feed always
	 * contains their post). Other users' feeds tolerate one stale read
	 * until the 30s TTL.
	 *
	 * @param int $writer_id User who created the post / triggered the write.
	 * @return void
	 */
	public function invalidate_writer( int $writer_id ): void {
		if ( $writer_id <= 0 ) {
			return;
		}
		wp_cache_delete( $this->home_page_1_key( $writer_id, 20 ), self::GROUP_USER );
		wp_cache_delete( $this->home_page_1_key( $writer_id, 15 ), self::GROUP_USER );
	}

	/**
	 * Invalidate every per-user feed cache (used on cross-cutting events
	 * like spaces deletion). Currently a no-op stub — bumping the entire
	 * group via wp_cache_flush_group() is expensive and most stores
	 * don't implement it. Callers that need group invalidation should
	 * rely on TTL drift.
	 *
	 * @return void
	 */
	public function invalidate_all_users(): void {
		// Intentional no-op: rely on 30s TTL drift.
	}
}
