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
	 * Build the cache key for the first page of a SPACE feed, as seen by one viewer.
	 *
	 * THE VIEWER IS IN THE KEY, and that is the whole safety argument. A space feed is
	 * viewer-scoped: it drops posts from members this viewer has blocked or muted. Two
	 * viewers of the same space do not see the same feed, so they must not share a cache
	 * entry -- a viewer-blind key would serve one member the posts of someone they blocked,
	 * which is the one thing a block is for. Logged-out viewers all key on 0, which is
	 * correct: no blocks, one identical public feed.
	 *
	 * The per-SPACE version busts when anything is posted to or deleted from the space
	 * (buddynext_space_posts_changed), so a new post shows for every viewer at once; the
	 * global version handles bulk moderation. A block is bounded by the short TTL rather
	 * than busted instantly -- exactly as the home feed handles it -- and because the
	 * viewer is in the key it is never a leak, only a <=TTL self-staleness.
	 *
	 * @param int $space_id  Space.
	 * @param int $viewer_id Viewer (0 = logged out).
	 * @param int $per_page  Page size.
	 * @return string
	 */
	public function space_page_1_key( int $space_id, int $viewer_id, int $per_page ): string {
		return 'space:p1:' . $space_id . ':' . $viewer_id . ':' . $per_page
			. ':s' . $this->space_version( $space_id )
			. ':g' . $this->global_version();
	}

	/**
	 * Build the cache key for the first page of a PROFILE feed, as seen by one viewer.
	 *
	 * Viewer in the key for the same reason as the space feed (block/mute scoping), plus
	 * profile_feed() runs a privacy gate BEFORE it reaches this cache -- a viewer who may
	 * not see the profile's activity never gets here, and if the owner flips to private
	 * later the gate denies on the next request, so a stale "allowed" cache is never
	 * served.
	 *
	 * Keyed on the profile OWNER's user version, which invalidate_writer() already bumps
	 * on the owner's post create/delete -- so the owner's new post shows on their profile
	 * for every viewer at once, with no new hook.
	 *
	 * @param int $profile_id Profile owner.
	 * @param int $viewer_id  Viewer (0 = logged out).
	 * @param int $per_page   Page size.
	 * @return string
	 */
	public function profile_page_1_key( int $profile_id, int $viewer_id, int $per_page ): string {
		return 'prof:p1:' . $profile_id . ':' . $viewer_id . ':' . $per_page
			. ':o' . $this->user_version( $profile_id )
			. ':g' . $this->global_version();
	}

	/**
	 * Per-space feed cache version (defaults to 1, lazily seeded).
	 *
	 * @param int $space_id Space.
	 * @return int
	 */
	private function space_version( int $space_id ): int {
		$key   = 'space:ver:' . $space_id;
		$value = wp_cache_get( $key, self::GROUP_GLOBAL );
		if ( false === $value ) {
			$value = 1;
			wp_cache_set( $key, $value, self::GROUP_GLOBAL );
		}
		return (int) $value;
	}

	/**
	 * Invalidate every cached page-1 feed for a space, for every viewer, in O(1).
	 *
	 * Called from buddynext_space_posts_changed (a post created in or deleted from the
	 * space). A single bump orphans every viewer's cached view of that space's feed.
	 *
	 * @param int $space_id Space whose post set changed.
	 * @return void
	 */
	public function invalidate_space( int $space_id ): void {
		if ( $space_id <= 0 ) {
			return;
		}
		$key = 'space:ver:' . $space_id;
		wp_cache_set( $key, ( (int) wp_cache_get( $key, self::GROUP_GLOBAL ) ) + 1, self::GROUP_GLOBAL );
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
