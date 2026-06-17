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
		// The per-user and global version stamps make every page-size variant
		// of a user's page-1 key invalidatable at once: bumping a version
		// changes every key derived from it, the object-cache-safe way to
		// "delete" keys a store cannot wildcard-match.
		return 'home:p1:' . $user_id . ':' . $per_page
			. ':u' . $this->user_version( $user_id )
			. ':g' . $this->global_version();
	}

	/**
	 * Per-user feed cache version (defaults to 1, lazily seeded).
	 *
	 * @param int $user_id Viewer.
	 * @return int
	 */
	private function user_version( int $user_id ): int {
		$key   = 'home:ver:' . $user_id;
		$value = wp_cache_get( $key, self::GROUP_USER );
		if ( false === $value ) {
			$value = 1;
			wp_cache_set( $key, $value, self::GROUP_USER );
		}
		return (int) $value;
	}

	/**
	 * Global feed cache version (defaults to 1, lazily seeded).
	 *
	 * @return int
	 */
	private function global_version(): int {
		$value = wp_cache_get( 'home:gver', self::GROUP_GLOBAL );
		if ( false === $value ) {
			$value = 1;
			wp_cache_set( 'home:gver', $value, self::GROUP_GLOBAL );
		}
		return (int) $value;
	}

	/**
	 * Invalidate every cached page-1 home feed for this user.
	 *
	 * Bumps the user's feed version so ALL page sizes are bypassed at once
	 * (the old code only busted the two hard-coded sizes 15 and 20, leaving
	 * custom page sizes stale until the 30s TTL). Superseded entries expire
	 * naturally via that TTL.
	 *
	 * @param int $writer_id User who created the post / triggered the write.
	 * @return void
	 */
	public function invalidate_writer( int $writer_id ): void {
		if ( $writer_id <= 0 ) {
			return;
		}
		$key = 'home:ver:' . $writer_id;
		wp_cache_set( $key, ( (int) wp_cache_get( $key, self::GROUP_USER ) ) + 1, self::GROUP_USER );
	}

	/**
	 * Invalidate every per-user page-1 feed cache immediately (announcements,
	 * bulk moderation, banned-word changes, spaces deletion).
	 *
	 * Bumps the global feed version, which is part of every page-1 key, so all
	 * users' cached pages are bypassed at once without an (often unsupported)
	 * wp_cache_flush_group() call.
	 *
	 * @return void
	 */
	public function invalidate_all_users(): void {
		wp_cache_set( 'home:gver', ( (int) wp_cache_get( 'home:gver', self::GROUP_GLOBAL ) ) + 1, self::GROUP_GLOBAL );
	}
}
