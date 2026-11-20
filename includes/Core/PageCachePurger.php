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
	 * Whether the whole page cache was already flushed in this request.
	 *
	 * @var bool
	 */
	private static bool $purged_all = false;

	/**
	 * Authors whose Articles pages were already purged in this request.
	 *
	 * @var array<int,true>
	 */
	private static array $purged_authors = array();

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
		// A comment added, edited, deleted, hidden or restored changes the thread and
		// the count on every page that shows the post: guests saw the old thread.
		add_action( 'buddynext_comment_thread_changed', array( $this, 'on_comment_thread_change' ), 10, 2 );

		// A member's Articles tab lists WordPress posts, which raise no BuddyNext feed
		// event of their own (a private, password or author change withdraws or moves
		// nothing in the feed): guests kept the old list until the cache expired.
		add_action( 'wp_after_insert_post', array( $this, 'on_article_saved' ), 10, 4 );
		add_action( 'deleted_post', array( $this, 'on_article_deleted' ), 10, 2 );

		// An owner setting changes what every guest page looks like (appearance, width,
		// names, navigation), and only the setting that moved a hub was ever caught:
		// guests kept the old page until the cache expired.
		add_action( 'updated_option', array( $this, 'on_option_saved' ), 10, 1 );
		add_action( 'added_option', array( $this, 'on_option_saved' ), 10, 1 );

		// The hub registry is filled on init:0, so its slug options are known from init:1.
		if ( did_action( 'init' ) ) {
			$this->watch_hub_slugs();
		} else {
			add_action( 'init', array( $this, 'watch_hub_slugs' ), 1 );
		}
	}

	/**
	 * Listen for a hub slug change (Settings > Pages, the setup wizard, WP-CLI).
	 *
	 * Every rename writes the hub's slug option, so this is the one place they all
	 * pass. A first rename creates the option, so its old slug is the default.
	 *
	 * @return void
	 */
	public function watch_hub_slugs(): void {
		foreach ( HubRegistry::instance()->all() as $hub ) {
			add_action(
				'update_option_' . $hub->slug_option,
				function ( $old_slug, $new_slug ): void {
					$this->on_hub_renamed( (string) $old_slug, (string) $new_slug );
				},
				10,
				2
			);
			add_action(
				'add_option_' . $hub->slug_option,
				function ( $option, $new_slug ) use ( $hub ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- add_option_{name} passes the name first.
					$this->on_hub_renamed( $hub->default_slug, (string) $new_slug );
				},
				10,
				2
			);
		}
	}

	/**
	 * An owner setting was saved.
	 *
	 * The settings screens register every field they own through register_setting(),
	 * so "registered under our prefix" is exactly the set an owner can change, and
	 * runtime flags and counters (never registered) do not flush the cache. Owner
	 * saves are rare, so the whole cache goes rather than a guess at which pages a
	 * given setting reaches.
	 *
	 * @param string $option Option name that was added or changed.
	 * @return void
	 */
	public function on_option_saved( string $option ): void {
		if ( 0 !== strpos( $option, 'buddynext' ) ) {
			return;
		}
		if ( ! isset( get_registered_settings()[ $option ] ) && ! in_array( $option, self::public_markup_options(), true ) ) {
			return;
		}
		self::purge_all();
	}

	/**
	 * Options an owner saves that are not registered settings but change every
	 * guest page: the Appearance screen saves its own, and the navigation manager
	 * stores its overrides in four.
	 *
	 * @return string[]
	 */
	private static function public_markup_options(): array {
		$options = array(
			'buddynext_default_theme',
			'buddynext_container_width',
			'buddynext_container_width_custom',
			'buddynext_brand_color',
			'buddynext_logo_url',
			'buddynext_custom_css',
			'buddynext_nav_overrides',
			'buddynext_nav_overrides_profile',
			'buddynext_nav_overrides_space',
			'buddynext_nav_overrides_mobile',
		);

		/**
		 * Filter the options whose change flushes the page cache. An add-on whose
		 * setting reaches every guest page (a brand name, a logo) adds its key here.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $options Option names.
		 */
		return array_map( 'strval', (array) apply_filters( 'buddynext_public_markup_options', $options ) );
	}

	/**
	 * A hub moved to a new address.
	 *
	 * Every cached page carries the hub links in its header and nav, so after a
	 * rename every cached copy points at the old address, and the old hub URL
	 * itself was still served as a cached 200 instead of redirecting. The whole
	 * page cache is flushed (a rare, owner-made change), and the old and new hub
	 * URLs go through the per-URL purge so a cache that only listens to
	 * `buddynext_purge_page_cache` drops them too.
	 *
	 * @param string $old_slug Slug the hub is leaving.
	 * @param string $new_slug Slug the hub now answers to.
	 * @return void
	 */
	public function on_hub_renamed( string $old_slug, string $new_slug ): void {
		$old_slug = sanitize_title( $old_slug );
		$new_slug = sanitize_title( $new_slug );
		if ( $old_slug === $new_slug ) {
			return;
		}

		self::purge_all();
		foreach ( array( $old_slug, $new_slug ) as $slug ) {
			if ( '' !== $slug ) {
				self::purge( trailingslashit( home_url( '/' . $slug ) ) ); // bn-route-ok: an old or new hub slug, not a registered route.
			}
		}
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
	 * A WordPress post was saved (created, edited, published, unpublished, made
	 * private, trashed, restored, given a password, given to another author).
	 *
	 * Only a post that is or was published can be on a guest's Articles tab, so a
	 * draft save costs nothing. Both authors are purged on a reassignment.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post|null $post        The post after the write.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before The post before the write, null on create.
	 * @return void
	 */
	public function on_article_saved( $post_id, $post, $update, $post_before ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- wp_after_insert_post passes the id and $update first.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$before = $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ? $post_before : null;
		if ( null === $before && 'publish' !== $post->post_status ) {
			return;
		}
		$this->purge_article_writers( $post );
		if ( null !== $before && (int) $before->post_author !== (int) $post->post_author ) {
			$this->purge_article_writers( $before );
		}
	}

	/**
	 * A WordPress post was permanently deleted. A published one left its
	 * authors' Articles tabs (trashing one passes through on_article_saved()).
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    The deleted post.
	 * @return void
	 */
	public function on_article_deleted( $post_id, $post = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- deleted_post passes the id first.
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			$this->purge_article_writers( $post );
		}
	}

	/**
	 * Purge the profile pages of everyone whose Articles tab lists this post:
	 * the profile, the Articles tab and its /page/N/ pages.
	 *
	 * Once per author per request, so a bulk edit of 500 posts by one author
	 * purges that author's pages once, not once per post.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	private function purge_article_writers( \WP_Post $post ): void {
		if ( ! \BuddyNext\Bridges\MemberBlogBridge::articles_tab_available() ) {
			return;
		}
		/** This filter is documented in includes/Feed/BlogPostListener.php */
		$types = array_map( 'strval', (array) apply_filters( 'buddynext_site_tracking_post_types', array( 'post' ) ) );
		if ( ! in_array( $post->post_type, $types, true ) ) {
			return;
		}

		// Member Blog's own answer to "who wrote this": the author, then any co-authors
		// (Pro), each of whom lists the post on their own tab.
		$writers = array_map( 'intval', (array) apply_filters( 'bpmb_post_writers', array( (int) $post->post_author ), (int) $post->ID ) );
		$bridge  = new \BuddyNext\Bridges\MemberBlogBridge();
		foreach ( array_unique( $writers ) as $user_id ) {
			if ( $user_id <= 0 || isset( self::$purged_authors[ $user_id ] ) ) {
				continue;
			}
			self::$purged_authors[ $user_id ] = true;

			$profile = PageRouter::profile_url( $user_id );
			if ( '' === $profile ) {
				continue;
			}
			$tab = trailingslashit( $profile ) . 'articles/';
			self::purge( $profile );
			self::purge( $tab );
			// One page more than the tab has now: a removed post shrank it by one.
			// ponytail: capped at 20 pages; deeper pages wait for the cache to expire
			// (WP Super Cache and WP Rocket drop /page/N/ with the tab URL anyway).
			$pages = min( 20, (int) ceil( $bridge->article_total( $user_id ) / \BuddyNext\Bridges\MemberBlogBridge::articles_per_page() ) + 1 );
			for ( $page = 2; $page <= $pages; $page++ ) {
				self::purge( $tab . 'page/' . $page . '/' );
			}
		}
	}

	/**
	 * A comment thread changed. Only a post's thread is on a cached page.
	 *
	 * @param string $object_type Thread owner type.
	 * @param int    $object_id   Thread owner ID.
	 * @return void
	 */
	public function on_comment_thread_change( string $object_type, int $object_id ): void {
		if ( 'post' === $object_type && $object_id > 0 ) {
			$this->on_post_change( $object_id );
		}
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
	 * Flush every page cache that is active, once per request.
	 *
	 * For a change that alters every page (a hub address in the nav), not for
	 * content changes, which purge their own URLs.
	 *
	 * @return void
	 */
	private static function purge_all(): void {
		if ( self::$purged_all ) {
			return;
		}
		self::$purged_all = true;

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache.
		}
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache.

		/**
		 * Fires after BuddyNext flushed the whole page cache of the known caches.
		 *
		 * Runs when a hub address changes, because every cached page links to the
		 * hubs. A page cache BuddyNext does not know (Cloudflare APO, Varnish, a
		 * proxy) flushes everything here.
		 *
		 * @since 1.3.0
		 */
		do_action( 'buddynext_purge_page_cache_all' );
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
	public static function purge( string $url ): void {
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
