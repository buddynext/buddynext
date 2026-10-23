<?php
/**
 * WB Member Blog bridge: the member's published articles, on their profile.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces a member's published WordPress posts as an Articles profile tab.
 *
 * BuddyNext's interest here is deliberately narrow and generic: WHICH MEMBER WROTE
 * A WordPress POST. It reads `post` through WP_Query and nothing else - no Member
 * Blog tables, no Member Blog functions beyond the one dashboard URL below, and no
 * BuddyNext code inside Member Blog. Member Blog is simply the thing that lets a
 * member write one from the front end; a post written in wp-admin lands on the tab
 * identically.
 *
 * The feed half already exists and is NOT here. {@see \BuddyNext\Feed\BlogPostListener}
 * publishes a typed `article` card for every tracked post type on publish, and has
 * since site tracking landed - so a member publishing an article already reaches
 * the community feed with no bridge involved. This class adds the missing half: the
 * profile surface, and the owner's route back to where they write.
 *
 * Owner decision 2026-08-02: the tab appears only when Member Blog is active. A site
 * without it gives members no front-end way to write, so the tab would be empty for
 * everyone except the admins - and the "Write a new post" affordance would have
 * nowhere to point.
 *
 * Reference, not embed (bridge contract rule 7): every row links OUT to the post and
 * the owner's controls link OUT to Member Blog's dashboard. BuddyNext renders no
 * editor and owns no authoring UI.
 */
class MemberBlogBridge {

	/**
	 * Integration key. Deliberately the SAME key BlogPostListener already declares.
	 *
	 * Both surfaces show one thing - the member's WordPress posts - so a site owner
	 * gets one control for it, with `feed` and `nav` as its two aspects, rather than
	 * two entries that can contradict each other.
	 */
	private const INTEGRATION = 'blog';

	/**
	 * Attach hooks. Called via the `buddynext_load_bridges` action (priority 25).
	 *
	 * Hooks are attached unconditionally and every surface self-guards at hook time,
	 * which is the contract every other bridge follows: `plugins_loaded:25` can still
	 * be earlier than some partner's own late bootstrap, so an activity check HERE
	 * would silently disable the tab on a site where the plugin is in fact present.
	 */
	public function init(): void {
		add_action( 'buddynext_register_nav', array( $this, 'register_nav_items' ) );
		add_filter( 'buddynext_integrations', array( $this, 'register_integration' ) );

		// REST read model for the member's articles so the app + developers render
		// the Articles panel from data, not HTML. The handler self-reports
		// available/enabled, so it is safe to register unconditionally.
		add_action(
			'rest_api_init',
			static function (): void {
				( new MemberBlogRestController() )->register_routes();
			}
		);
	}

	/**
	 * Whether Member Blog is on this site.
	 *
	 * Guards on the VERSION CONSTANT, which Member Blog's entry file defines
	 * unconditionally on every request it is active for. That is the real bootstrap
	 * symbol.
	 *
	 * It is deliberately NOT `class_exists( '\Member_Blog_Compat' )`, which is the
	 * obvious-looking check and is wrong: that class is loaded conditionally, and on
	 * a BuddyNext profile page it is not loaded at all. Guarding on it made the tab
	 * resolve correctly everywhere EXCEPT the one screen it belongs on - present at
	 * `init`, gone by template render - which reads as a caching bug rather than a
	 * bad guard. A conditionally-loaded class is never a bootstrap symbol.
	 */
	public static function available(): bool {
		return defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' );
	}

	/**
	 * Whether profiles show the Articles tab: Member Blog is active and the
	 * owner left the blog Integrations "nav" switch on.
	 *
	 * The one answer for the tab itself and for author links
	 * (Profile\AuthorLinkListener), which go to the profile only while the
	 * profile can list the author's posts.
	 *
	 * @return bool
	 */
	public static function articles_tab_available(): bool {
		return self::available() && buddynext_integration_enabled( self::INTEGRATION, 'nav' );
	}

	/**
	 * Declare the `nav` aspect on the existing blog integration entry.
	 *
	 * MERGES rather than replaces: BlogPostListener registers this same key for the
	 * feed aspect and may run either side of this filter. Overwriting the entry would
	 * silently turn the feed aspect off, or be turned off itself, depending on hook
	 * order - a class of bug that is invisible until someone reorders a hook.
	 *
	 * @param array<string,array<string,mixed>> $items Integration registry.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_integration( array $items ): array {
		$existing = isset( $items[ self::INTEGRATION ] ) && is_array( $items[ self::INTEGRATION ] )
			? $items[ self::INTEGRATION ]
			: array();

		$items[ self::INTEGRATION ] = array_merge(
			array(
				'label'      => __( 'Blog posts', 'buddynext' ),
				'version'    => null,
				'has_feed'   => true,
				'has_search' => false,
			),
			$existing,
			array(
				// The Articles tab only exists with Member Blog active, so the "Show in
				// navigation" switch is only offered then. It used to be offered always,
				// for a tab that could not appear.
				'has_nav'        => self::available(),
				'nav_label'      => __( 'Articles', 'buddynext' ),
				// BlogPostListener registers this key with a null version, because
				// site tracking is core - there is no plugin behind it to name. Once
				// Member Blog IS present it is the thing supplying the surface, so
				// report its version: /app/config publishes these so a mobile client
				// can gate a module on the build actually installed, and a null there
				// means the app cannot tell an old Member Blog from no Member Blog.
				'version'        => self::available() ? BUDDYPRESS_MEMBER_BLOG_VERSION : ( $existing['version'] ?? null ),
				// Only meaningful when Member Blog is the thing supplying the
				// surface; core site-tracking has no partner release to test.
				// Verified against 4.1.0: both consumed symbols (Member_Blog_Compat::
				// get_dashboard_url, bp_member_blog_get_settings) are unchanged, and
				// 4.1.0's additions are Member-Blog-internal (composer/email/REST),
				// none consumed here. Tested against 4.3.0: checked against the partner code at that tag (every hook and API the bridge uses) on 2026-10-06.
				'tested_version' => self::available() ? '4.4.0' : ( $existing['tested_version'] ?? null ),
			)
		);

		return $items;
	}

	/**
	 * Register the Articles profile tab.
	 *
	 * @param NavRegistry $registry Nav registry.
	 */
	public function register_nav_items( NavRegistry $registry ): void {
		$enabled = static fn(): bool => self::articles_tab_available();

		$registry->register(
			array(
				'id'        => 'articles',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Articles', 'buddynext' ),
				// No icon, deliberately. Profile tabs are text-only - ProfileNav
				// declares none for any core tab, and the strip reads as one row of
				// words. An icon here is not a nicer tab, it is the only tab that
				// looks different.
				//
				// This used to note that JetonomyBridge's `message-square` was
				// harmless because no such SVG existed, so the declaration rendered
				// nothing. That stopped being true on 2026-08-12, when a sweep for
				// referenced-but-unbundled Lucide icons added the file and silently
				// gave Discussions the only icon in the strip. Both declarations are
				// now gone. Do not reintroduce one here: a nav icon is a decision
				// for the whole strip, not for one tab.
				// After Discussions (60), before the Portfolio cluster - authored
				// long-form sits with the member's other social content.
				'priority'  => 65,
				'condition' => $enabled,
				'url'       => static fn( NavContext $c ): string => trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . 'articles/',
				'count'     => fn( NavContext $c ): int => $this->article_count( $c->subject_id ),
				'render'    => function ( NavContext $c ): void {
					$this->render_profile_articles_panel( $c->subject_id, $c->viewer_id );
				},
			)
		);
	}

	/**
	 * How many published articles this member has.
	 *
	 * Counted with a dedicated COUNT query rather than by measuring the list: the
	 * badge must not pull a prolific author's whole archive into memory to print a
	 * number.
	 *
	 * @param int $user_id Profile owner.
	 */
	public function article_count( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$query = new \WP_Query(
			array_merge(
				$this->articles_query_args( $user_id, 1, 1 ),
				array(
					'fields'                 => 'ids',
					'update_post_meta_cache' => false,
				)
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Articles per page on the profile tab.
	 *
	 * @return int
	 */
	public static function articles_per_page(): int {
		return max( 1, min( 50, (int) apply_filters( 'buddynext_profile_articles_per_page', 10 ) ) );
	}

	/**
	 * How many articles are on this member's tab.
	 *
	 * The same query the panel renders, counted, so the router's past-the-end
	 * check and the panel can never disagree about the last page.
	 *
	 * @param int $user_id Profile owner.
	 * @return int
	 */
	public function article_total( int $user_id ): int {
		$args           = $this->articles_query_args( $user_id, 1, 1 );
		$args['fields'] = 'ids';
		return (int) ( new \WP_Query( $args ) )->found_posts;
	}

	/**
	 * WP_Query args for a member's articles: published only, for every viewer,
	 * owner included. Drafts are managed in the Member Blog dashboard.
	 *
	 * @param int $user_id  Profile owner.
	 * @param int $per_page Page size.
	 * @param int $paged    Page.
	 * @return array<string,mixed>
	 */
	private function articles_query_args( int $user_id, int $per_page, int $paged ): array {
		$args = array(
			'author'                 => $user_id,
			'post_type'              => $this->tracked_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => $paged,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);

		// Member Blog's own seam for "posts this member writes": Pro widens the query
		// to co-authored posts in SQL (paginated, indexed) and leaves it untouched when
		// the member co-authors nothing or Pro is off.
		return (array) apply_filters( 'bp_member_blog_dashboard_query_args', $args, $user_id, 'publish', 'posts' );
	}

	/**
	 * The post's other writers, as seen from one member's profile: Member Blog's
	 * `bpmb_post_writers` (lead author first, then accepted co-authors) minus that
	 * member. Empty for a solo post on its author's own tab.
	 *
	 * @param \WP_Post $post        Post.
	 * @param int      $profile_uid Profile owner.
	 * @return array<int,array{id:int,name:string}>
	 */
	private function co_writers( \WP_Post $post, int $profile_uid ): array {
		$ids = array_map( 'intval', (array) apply_filters( 'bpmb_post_writers', array( (int) $post->post_author ), (int) $post->ID ) );
		$out = array();
		foreach ( array_diff( array_unique( $ids ), array( $profile_uid, 0 ) ) as $id ) {
			$out[] = array(
				'id'   => $id,
				'name' => (string) get_the_author_meta( 'display_name', $id ),
			);
		}
		return $out;
	}

	/**
	 * Render the Articles panel.
	 *
	 * @param int $user_id   Profile owner.
	 * @param int $viewer_id Viewer (0 = logged out).
	 */
	public function render_profile_articles_panel( int $user_id, int $viewer_id ): void {
		// /members/{slug}/articles/page/N/. The pre-1.2.4 ?bn_page=N is 301ed there
		// by PageRouter::dispatch_hub_template(), so only the query var is read.
		$paged    = max( 1, absint( get_query_var( 'paged', 0 ) ) );
		$per_page = self::articles_per_page();

		$query    = new \WP_Query( $this->articles_query_args( $user_id, $per_page, $paged ) );
		$is_owner = $viewer_id > 0 && $viewer_id === $user_id;

		// Each row's Edit goes to THAT post. It used to open the dashboard for
		// every row, leaving the owner to find the post again.
		$edit_urls  = array();
		$co_writers = array();
		foreach ( $query->posts as $post ) {
			if ( $is_owner ) {
				$edit_urls[ (int) $post->ID ] = $this->edit_url( (int) $post->ID, $user_id );
			}
			$co_writers[ (int) $post->ID ] = wp_list_pluck( $this->co_writers( $post, $user_id ), 'name' );
		}

		buddynext_get_template(
			'parts/profile/articles-panel.php',
			array(
				'profile_user_id' => $user_id,
				'viewer_id'       => $viewer_id,
				'is_owner'        => $is_owner,
				'articles'        => $query->posts,
				'edit_urls'       => $edit_urls,
				'co_writers'      => $co_writers,
				'article_count'   => (int) $query->found_posts,
				'page'            => $paged,
				'total_pages'     => (int) $query->max_num_pages,
				'dashboard_url'   => $this->dashboard_url( $user_id ),
				'write_url'       => $this->write_url( $user_id ),
			)
		);

		wp_reset_postdata();
	}

	/**
	 * The Articles panel as a plain data array — the same posts + pagination the
	 * profile tab renders, shaped for JSON so the app and developers read the
	 * panel without scraping HTML. Honours the same author/type/status rules
	 * (published posts only).
	 *
	 * @param int $user_id   Profile owner.
	 * @param int $viewer_id Viewer (0 = logged out).
	 * @param int $page      Page (1-based).
	 * @param int $per_page  Per page (1-50).
	 * @return array<string,mixed> { available, enabled, is_owner, total, page, total_pages, dashboard_url, items[] }
	 */
	public function articles_data( int $user_id, int $viewer_id, int $page = 1, int $per_page = 10 ): array {
		$available = self::available();
		$enabled   = ! function_exists( 'buddynext_integration_enabled' ) || (bool) buddynext_integration_enabled( self::INTEGRATION, 'nav' );
		$is_owner  = $viewer_id > 0 && $viewer_id === $user_id;
		$out       = array(
			'available'     => $available,
			'enabled'       => $enabled,
			'is_owner'      => $is_owner,
			'total'         => 0,
			'page'          => max( 1, $page ),
			'total_pages'   => 0,
			'dashboard_url' => '',
			'items'         => array(),
		);
		if ( $user_id <= 0 || ! $available || ! $enabled ) {
			return $out;
		}
		$page     = max( 1, $page );
		$per_page = max( 1, min( 50, $per_page ) );

		$query = new \WP_Query( $this->articles_query_args( $user_id, $per_page, $page ) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$cover   = get_the_post_thumbnail_url( $post, 'medium' );
			$items[] = array(
				'id'           => (int) $post->ID,
				'co_written'   => (int) $post->post_author !== $user_id,
				'co_writers'   => $this->co_writers( $post, $user_id ),
				'title'        => (string) get_the_title( $post ),
				'url'          => (string) get_permalink( $post ),
				'date'         => (string) get_the_date( 'c', $post ),
				'date_display' => (string) get_the_date( '', $post ),
				'excerpt'      => wp_trim_words( wp_strip_all_tags( (string) get_the_excerpt( $post ) ), 28 ),
				'cover'        => $cover ? (string) $cover : null,
				// Kept in the REST shape; the list is published-only, so always "publish".
				'status'       => 'publish',
				'status_label' => __( 'Published', 'buddynext' ),
				// Where the owner edits THIS post; '' for everyone else.
				'edit_url'     => $is_owner ? $this->edit_url( (int) $post->ID, $user_id ) : '',
			);
		}
		wp_reset_postdata();

		$out['total']         = (int) $query->found_posts;
		$out['total_pages']   = (int) $query->max_num_pages;
		$out['items']         = $items;
		$out['dashboard_url'] = $is_owner ? $this->dashboard_url( $user_id ) : '';
		return $out;
	}

	/**
	 * Post types treated as articles.
	 *
	 * Reads the SAME filter BlogPostListener uses for site tracking, so a site that
	 * teaches BuddyNext about a custom post type gets it on the profile tab and in
	 * the feed from one change rather than two.
	 *
	 * @return string[]
	 */
	private function tracked_types(): array {
		$types = (array) apply_filters( 'buddynext_site_tracking_post_types', array( 'post' ) );
		$types = array_values( array_filter( array_map( 'strval', $types ) ) );

		return array() === $types ? array( 'post' ) : $types;
	}

	/**
	 * Where the owner starts a new post: Member Blog's own "post" page, the same
	 * page every row's Edit resolves through, else the dashboard.
	 *
	 * @param int $user_id Profile owner.
	 * @return string
	 */
	private function write_url( int $user_id ): string {
		if ( class_exists( '\Member_Blog_Compat' ) ) {
			$url = (string) \Member_Blog_Compat::get_create_post_url();
			if ( '' !== $url ) {
				return $url;
			}
		}

		if ( function_exists( 'bp_member_blog_get_settings' ) ) {
			$page_id = (int) bp_member_blog_get_settings( 'bp_post_page', 0 );
			$url     = $page_id > 0 ? (string) get_permalink( $page_id ) : '';
			if ( '' !== $url ) {
				return $url;
			}
		}

		return $this->dashboard_url( $user_id );
	}

	/**
	 * Where the owner edits one of their posts.
	 *
	 * Member Blog's own answer when its class is loaded, else its configured
	 * "post" page with the post id (the address that class builds on a standalone
	 * site), else the WordPress editor for a member who can use it. Empty when
	 * there is nowhere to send them; the panel then falls back to the dashboard.
	 *
	 * @param int $post_id The post.
	 * @param int $user_id Its author.
	 * @return string
	 */
	private function edit_url( int $post_id, int $user_id ): string {
		if ( class_exists( '\Member_Blog_Compat' ) ) {
			$url = (string) \Member_Blog_Compat::get_edit_post_url( $post_id );
			if ( '' !== $url ) {
				return $url;
			}
		}

		if ( function_exists( 'bp_member_blog_get_settings' ) ) {
			$page_id = (int) bp_member_blog_get_settings( 'bp_post_page', 0 );
			$url     = $page_id > 0 ? (string) get_permalink( $page_id ) : '';
			if ( '' !== $url ) {
				return add_query_arg( 'post_id', $post_id, $url );
			}
		}

		return user_can( $user_id, 'edit_post', $post_id ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '';
	}

	/**
	 * Where this member writes and manages their posts.
	 *
	 * Member Blog's own dashboard when it can tell us (it resolves the configured
	 * page and any per-member routing), falling back to wp-admin for a member who can
	 * reach it. Returns '' when neither applies, and the template then renders no
	 * management affordance rather than a link that 404s or bounces off a login wall.
	 *
	 * @param int $user_id Profile owner.
	 */
	private function dashboard_url( int $user_id ): string {
		// Member Blog's own resolver when its compat class happens to be loaded - it
		// knows about per-platform profile routing that a page id cannot express.
		//
		// class_exists() alone: the class and get_dashboard_url() are one public API
		// that ships together, so a method_exists() on the literal class-string adds
		// no real protection. (The method_exists() guards elsewhere in Bridges/ are a
		// different case - those test runtime OBJECTS pulled from a partner's
		// container, where the shape genuinely varies.
		if ( class_exists( '\Member_Blog_Compat' ) ) {
			$url = (string) \Member_Blog_Compat::get_dashboard_url( $user_id );
			if ( '' !== $url && home_url() !== $url ) {
				return $url;
			}
		}

		// That class is NOT loaded on a BuddyNext profile page, which is precisely
		// where this link is rendered, so the configured page is read directly as
		// well. On a standalone site (BuddyNext is not BuddyPress, so this is the
		// mode Member Blog reports here) get_dashboard_url() resolves to exactly
		// this page anyway - same answer, one fewer dependency on load order.
		if ( function_exists( 'bp_member_blog_get_settings' ) ) {
			$page_id = (int) bp_member_blog_get_settings( 'bp_dashboard_page', 0 );
			if ( $page_id > 0 ) {
				$url = (string) get_permalink( $page_id );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return user_can( $user_id, 'edit_posts' ) ? admin_url( 'edit.php' ) : '';
	}
}
