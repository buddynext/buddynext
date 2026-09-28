<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Page-cache purge for the public feed pages.
 *
 * Guests get cached copies of the public hubs (see docs/website/getting-started/
 * 09a-page-cache-and-optimisation.md). A feed item is not a WordPress post, so a
 * page cache's own "purge when a post is published" rule never fires for it: a new
 * public post stayed invisible to guests until the cache expired (seven days on a
 * LiteSpeed default), while the author, who is logged in and bypasses the cache,
 * saw it at once. This listener purges the pages a feed change shows up on.
 *
 * @package BuddyNext\Core
 * @since 1.2.2
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Purges the feed pages from the site's page cache when a post changes.
 */
class PageCachePurger implements ListenerInterface {

	/**
	 * URLs already purged in this request.
	 *
	 * Per-REQUEST memo: a bulk import or a bridge that writes many posts asks for
	 * the same few URLs again and again, so each is purged once.
	 *
	 * @var array<string,true>
	 */
	private static array $purged = array();

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Created and deleted carry the author, so their profile is purged too.
		foreach ( array( 'buddynext_post_created', 'buddynext_post_deleted' ) as $hook ) {
			add_action( $hook, array( $this, 'on_authored_change' ), 10, 2 );
		}
		// Every other change carries the post as its first argument.
		foreach ( array( 'buddynext_post_updated', 'buddynext_post_approved', 'buddynext_post_auto_hidden', 'buddynext_post_restored' ) as $hook ) {
			add_action( $hook, array( $this, 'on_post_change' ), 10, 1 );
		}
		add_action( 'buddynext_space_posts_changed', array( $this, 'on_space_change' ), 10, 1 );
	}

	/**
	 * A post was created or deleted.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id Author, or 0 when the system acted.
	 * @return void
	 */
	public function on_authored_change( int $post_id, int $user_id = 0 ): void {
		$this->on_post_change( $post_id );
		self::purge( PageRouter::profile_url( $user_id ) ); // '' for the system: ignored.
	}

	/**
	 * A post changed: the hubs that list it and its own page.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_post_change( int $post_id ): void {
		self::purge( PageRouter::activity_url() );
		self::purge( PageRouter::explore_url() );
		// The site's front page may be the activity hub (a static front page).
		self::purge( home_url( '/' ) );
		self::purge( PageRouter::post_url( $post_id ) );
	}

	/**
	 * A space's post set changed.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	public function on_space_change( int $space_id ): void {
		self::purge( PageRouter::space_url( $space_id ) );
	}

	/**
	 * Purge one URL from every page cache that is active.
	 *
	 * It runs at once, not at shutdown: LiteSpeed Cache sends a purge as a response
	 * header and drops it once headers are sent, which on a REST write is before
	 * shutdown. Each cache is guarded by its own "does this plugin exist" check, so
	 * a site with no page cache pays nothing. A cache not listed here (Cloudflare
	 * APO, a proxy) hooks `buddynext_purge_page_cache`.
	 *
	 * @param string $url Absolute URL; ignored when empty or already purged.
	 * @return void
	 */
	private static function purge( string $url ): void {
		if ( '' === $url || isset( self::$purged[ $url ] ) ) {
			return;
		}
		self::$purged[ $url ] = true;

		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array( $url ) ); // WP Rocket.
		}
		// LiteSpeed Cache: the path, as it derives a page's tag from the request path. An absolute
		// URL on a non-standard port hashes to a different tag and evicts nothing.
		do_action( 'litespeed_purge_url', (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $url ); // W3 Total Cache.
		}
		if ( function_exists( 'wpsc_delete_url_cache' ) ) {
			wpsc_delete_url_cache( $url ); // WP Super Cache.
		}

		/**
		 * Fires after BuddyNext purged a feed page from the known page caches.
		 *
		 * A page cache BuddyNext does not know (Cloudflare APO, Varnish, a proxy)
		 * purges the same URL here.
		 *
		 * @since 1.2.2
		 *
		 * @param string $url Absolute URL.
		 */
		do_action( 'buddynext_purge_page_cache', $url );
	}
}
