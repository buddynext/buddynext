<?php
/**
 * WB Member Blog bridge: the member's published articles, on their profile.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Feed\ArticleProvider;
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
 *
 * Since 1.3.0 Member Blog's own Add new post and My Blog Dashboard pages are framed
 * by the shell (the write hub below): same pages and URLs, Member Blog's own markup
 * and assets, with BuddyNext's header, rail and two tabs around them.
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
	 * Hub key (and bn_hub value) of the write hub that frames Member Blog's
	 * Add new post and My Blog Dashboard pages in the shell. Letters only: the
	 * bn_hub rewrite tag matches [a-z]+.
	 */
	public const WRITE_HUB = 'blogwrite';

	/**
	 * The Member Blog page this request renders in the write hub (0 = none).
	 *
	 * @var int
	 */
	private static int $write_page = 0;

	/**
	 * The global post held aside while Member Blog enqueues its assets.
	 *
	 * @var \WP_Post|null
	 */
	private static ?\WP_Post $held_post = null;

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

		// Member Blog's Add new post and My Blog Dashboard pages render inside the
		// shell: same pages, same URLs, Member Blog's own markup, BuddyNext's frame.
		add_action( 'buddynext_register_hubs', array( $this, 'register_write_hub' ) );
		// Priority 5: before the hub dispatch (10) reads bn_hub.
		add_action( 'template_redirect', array( $this, 'route_write_pages' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'lend_page_to_enqueue' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'restore_after_enqueue' ), 9999 );
		add_filter( 'buddynext_rail_items', array( $this, 'add_write_rail_item' ), 10, 2 );
		add_filter( 'buddynext_document_title', array( $this, 'write_document_title' ), 10, 2 );
		add_filter( 'buddynext_client_nav_deny', array( $this, 'deny_client_nav' ) );
		add_filter( 'buddynext_client_nav_enabled', array( $this, 'no_client_nav_on_write_hub' ) );
		// Member Blog is the article provider, registered from BuddyNext's side.
		add_filter( 'buddynext_article_provider', array( $this, 'register_article_provider' ), 10, 2 );
	}

	/**
	 * Offer Member Blog as the article provider while the Articles tab is on.
	 *
	 * Fills the slot only when nothing earlier claimed it. Member Blog's editor
	 * reads no title query arg today, so `title_arg` is '' and the composer's
	 * title is not carried into it.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string,mixed>|null $provider Provider so far.
	 * @param int                      $user_id  Member.
	 * @return array<string,mixed>|null
	 */
	public function register_article_provider( $provider, int $user_id ): ?array {
		if ( is_array( $provider ) || ! self::articles_tab_available() ) {
			return is_array( $provider ) ? $provider : null;
		}

		$dashboard = $this->dashboard_url( $user_id );
		return array(
			'key'             => 'member-blog',
			'label'           => __( 'Article', 'buddynext' ),
			'icon'            => 'file-text',
			'can_write'       => self::can_write( $user_id ),
			'new_url'         => $this->write_url( $user_id ),
			// Member Blog's dashboard page opens a tab from ?tab=.
			'drafts_url'      => '' !== $dashboard ? add_query_arg( 'tab', 'drafts', $dashboard ) : '',
			'my_articles_url' => $dashboard,
			'title_arg'       => '',
			'supports_space'  => false,
		);
	}

	/**
	 * Whether the member's article provider is Member Blog and lets them write.
	 *
	 * @param int $user_id Member.
	 * @return array<string,mixed>|null The provider when it is, else null.
	 */
	private static function member_blog_writer( int $user_id ): ?array {
		$provider = ArticleProvider::for_user( $user_id );
		return null !== $provider && 'member-blog' === $provider['key'] && $provider['can_write'] ? $provider : null;
	}

	/**
	 * Register the write hub while Member Blog is active. No rewrite rule and no
	 * backing page: it is reached only by route_write_pages() on Member Blog's
	 * own two pages.
	 *
	 * @since 1.3.0
	 *
	 * @param \BuddyNext\Core\HubRegistry $reg Hub registry.
	 * @return void
	 */
	public function register_write_hub( \BuddyNext\Core\HubRegistry $reg ): void {
		// Fires at init:0, after every plugin loaded, so the presence check is final here.
		if ( ! self::available() ) {
			return;
		}
		$reg->register(
			new \BuddyNext\Core\HubDescriptor(
				self::WRITE_HUB,
				'buddynext_slug_' . self::WRITE_HUB,
				'', // No slug of its own, so the isolation mu-plugin never claims Member Blog's URLs.
				'',
				__( 'Write a post', 'buddynext' ),
				'',
				resolve_template: static fn( string $hub ): ?string => self::WRITE_HUB === $hub && self::$write_page > 0 ? 'blog/write-hub.php' : null,
				backing_page: false,
				configurable_slug: false,
				admin_managed: false
			)
		);
	}

	/**
	 * Member Blog's post page and dashboard page ids, from its settings.
	 *
	 * @return array{post:int,dashboard:int}
	 */
	private static function write_pages(): array {
		if ( ! function_exists( 'bp_member_blog_get_settings' ) ) {
			return array(
				'post'      => 0,
				'dashboard' => 0,
			);
		}
		return array(
			'post'      => (int) bp_member_blog_get_settings( 'bp_post_page', 0 ),
			'dashboard' => (int) bp_member_blog_get_settings( 'bp_dashboard_page', 0 ),
		);
	}

	/**
	 * Point a request for Member Blog's post or dashboard page at the write hub.
	 *
	 * The old URLs keep working (no redirect); only the chrome changes. A guest
	 * is sent to sign in and back, since both pages are for writing.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function route_write_pages(): void {
		if ( '' !== (string) get_query_var( 'bn_hub', '' ) || ! self::articles_tab_available() ) {
			return;
		}

		$page_id = 0;
		foreach ( self::write_pages() as $candidate ) {
			if ( $candidate > 0 && is_page( $candidate ) ) {
				$page_id = $candidate;
				break;
			}
		}
		if ( $page_id <= 0 ) {
			return;
		}

		/**
		 * Filters whether Member Blog's Add new post and My Blog Dashboard pages
		 * render inside the BuddyNext shell.
		 *
		 * Return false to keep the theme's own page template for them. Returning
		 * false for guests only (`is_user_logged_in() ? $in_shell : false`) shows
		 * Member Blog's own sign-in state instead of sending them to sign in.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $in_shell Default true.
		 * @param int  $page_id  The Member Blog page being viewed.
		 */
		if ( ! (bool) apply_filters( 'buddynext_blog_write_in_shell', true, $page_id ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			// Back to this page with its query args: an emailed edit link carries ?post_id=.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only return address, sanitised and encoded.
			$args = rawurlencode_deep( map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ) );
			wp_safe_redirect( \BuddyNext\Core\PageRouter::login_url( add_query_arg( $args, (string) get_permalink( $page_id ) ) ) );
			exit;
		}

		self::$write_page = $page_id;
		set_query_var( 'bn_hub', self::WRITE_HUB );
	}

	/**
	 * The Member Blog page the write hub is rendering, or null.
	 *
	 * @since 1.3.0
	 *
	 * @return \WP_Post|null
	 */
	public static function write_hub_page(): ?\WP_Post {
		$post = self::$write_page > 0 ? get_post( self::$write_page ) : null;
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * The write hub's two tabs (New post, My articles), or none for a member
	 * Member Blog does not let write: they see its own "not available" notice.
	 *
	 * @since 1.3.0
	 *
	 * @return array<int,array{label:string,url:string,current:bool}>
	 */
	public static function write_hub_tabs(): array {
		$provider = self::member_blog_writer( get_current_user_id() );
		if ( null === $provider ) {
			return array();
		}

		$here = self::$write_page > 0 ? untrailingslashit( (string) get_permalink( self::$write_page ) ) : '';
		$tabs = array();
		foreach (
			array(
				'new_url'         => __( 'New post', 'buddynext' ),
				'my_articles_url' => __( 'My articles', 'buddynext' ),
			) as $key => $label
		) {
			$url = (string) $provider[ $key ];
			if ( '' !== $url ) {
				$tabs[] = array(
					'label'   => $label,
					'url'     => $url,
					'current' => '' !== $here && untrailingslashit( (string) strtok( $url, '?' ) ) === $here,
				);
			}
		}
		return $tabs;
	}

	/**
	 * Lend Member Blog the real page as the global post while scripts enqueue.
	 *
	 * Member Blog decides what to load from is_page() and the global post's
	 * content; inside the hub the global post is the shell's virtual one, so it
	 * would load nothing. Restored by restore_after_enqueue().
	 *
	 * @return void
	 */
	public function lend_page_to_enqueue(): void {
		$page = self::write_hub_page();
		if ( null === $page || self::WRITE_HUB !== (string) get_query_var( 'bn_hub', '' ) ) {
			return;
		}
		self::$held_post = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- lent for the span of wp_enqueue_scripts, restored at 9999.
	}

	/**
	 * Put the shell's global post back after Member Blog enqueued.
	 *
	 * @return void
	 */
	public function restore_after_enqueue(): void {
		if ( null === self::$held_post ) {
			return;
		}
		$GLOBALS['post'] = self::$held_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the shell's own post.
		self::$held_post = null;
	}

	/**
	 * Add "Write a post" to the rail's You group for a member who can write.
	 *
	 * @param array<int,array<string,mixed>> $items Rail items.
	 * @param string                         $hub   Current hub.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_write_rail_item( array $items, string $hub = '' ): array {
		// The entry opens the write hub, which frames Member Blog's pages only: the
		// WordPress-editor fallback has no rail entry.
		$provider = self::articles_tab_available() ? self::member_blog_writer( get_current_user_id() ) : null;
		$url      = null !== $provider ? (string) $provider['new_url'] : '';
		if ( '' === $url ) {
			return $items;
		}

		$items[] = array(
			'key'    => 'write-post',
			'label'  => __( 'Write a post', 'buddynext' ),
			'url'    => $url,
			'icon'   => 'edit',
			'show'   => true,
			'active' => self::WRITE_HUB === $hub,
			'group'  => 'you',
			// After Events (207), before Edit Profile (210).
			'order'  => 208,
		);
		return $items;
	}

	/**
	 * Name the browser tab after the Member Blog page, not the hub.
	 *
	 * @param string $title Title the shell would use.
	 * @param string $hub   Hub key.
	 * @return string
	 */
	public function write_document_title( string $title, string $hub ): string {
		$page = self::WRITE_HUB === $hub ? self::write_hub_page() : null;
		return null !== $page && '' !== get_the_title( $page ) ? get_the_title( $page ) : $title;
	}

	/**
	 * Links to the write pages full-load: Member Blog's editor scripts cannot
	 * survive a client-side region swap.
	 *
	 * @param array<string,string|string[]> $deny Deny-list keyed by surface.
	 * @return array<string,string|string[]>
	 */
	public function deny_client_nav( array $deny ): array {
		$paths = array();
		foreach ( self::write_pages() as $page_id ) {
			$path = $page_id > 0 ? (string) wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH ) : '';
			if ( '' !== $path && '/' !== $path ) {
				$paths[] = $path;
			}
		}
		if ( array() !== $paths ) {
			$deny['blog-write'] = $paths;
		}
		return $deny;
	}

	/**
	 * No client-side navigation away from a write page either: a swap would keep
	 * Member Blog's editor scripts running on a page without the editor and skip
	 * its unsaved-changes prompt.
	 *
	 * @param bool $enabled Whether client-nav is on.
	 * @return bool
	 */
	public function no_client_nav_on_write_hub( $enabled ): bool {
		return (bool) $enabled && self::WRITE_HUB !== (string) get_query_var( 'bn_hub', '' );
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
				// 4.5.1 (2026-11-04): get_dashboard_url and bp_member_blog_get_settings unchanged from 4.5.0.
				// 4.5.2 (2026-11-11, 1.2.9 release site): every hook and API above checked at the tag; 4.5.2 changed only CSS, POT and Pro notices.
				// 4.5.3 (2026-11-20): the write hub also reads bp_post_page / bp_dashboard_page and bp_member_blog_user_can_create; all unchanged.
				'tested_version' => self::available() ? '4.5.3' : ( $existing['tested_version'] ?? null ),
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
		// Shown to members Member Blog lets write, and on any profile that already
		// has articles; never an empty tab inviting a member who cannot write.
		$enabled = fn( NavContext $c ): bool => self::articles_tab_available()
			&& ( ( ArticleProvider::for_user( $c->subject_id )['can_write'] ?? false ) || $this->article_count( $c->subject_id ) > 0 );

		$registry->register(
			array(
				'id'          => 'articles',
				'surface'     => 'profile',
				'integration' => self::INTEGRATION,
				'layer'       => 'primary',
				'label'       => __( 'Articles', 'buddynext' ),
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
				'priority'    => 65,
				'condition'   => $enabled,
				'url'         => static fn( NavContext $c ): string => trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . 'articles/',
				'count'       => fn( NavContext $c ): int => $this->article_count( $c->subject_id ),
				'render'      => function ( NavContext $c ): void {
					$this->render_profile_articles_panel( $c->subject_id, $c->viewer_id );
				},
			)
		);
	}

	/**
	 * Whether Member Blog lets this member write (its role / member-type rules).
	 *
	 * @since 1.2.8
	 *
	 * @param int $user_id Member.
	 * @return bool
	 */
	private static function can_write( int $user_id ): bool {
		return function_exists( 'bp_member_blog_user_can_create' ) && (bool) bp_member_blog_user_can_create( $user_id );
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
	 * WP_Query args for a member's articles: published and not password protected,
	 * for every viewer, owner included. The tab is the member's public showcase, so
	 * it lists only what everyone may read; drafts, private and password-protected
	 * posts are managed in the Member Blog dashboard. The tab, its count, the
	 * past-the-end check and REST all read these args, so the rule holds on each.
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
			'has_password'           => false,
			'posts_per_page'         => $per_page,
			'paged'                  => $paged,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);

		// Member Blog's own seam for "posts this member writes": Pro widens the query
		// to co-authored posts in SQL (paginated, indexed) and leaves it untouched when
		// the member co-authors nothing or Pro is off.
		$args = (array) apply_filters( 'bp_member_blog_dashboard_query_args', $args, $user_id, 'publish', 'posts' );

		// Re-asserted after the partner filter: what may show publicly is BuddyNext's
		// rule, and a filter that rebuilds the args must not widen it.
		$args['post_status']  = 'publish';
		$args['has_password'] = false;
		return $args;
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
		$provider = ArticleProvider::for_user( $user_id );

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
				'can_write'       => (bool) ( $provider['can_write'] ?? false ),
				'articles'        => $query->posts,
				'edit_urls'       => $edit_urls,
				'co_writers'      => $co_writers,
				'article_count'   => (int) $query->found_posts,
				'page'            => $paged,
				'total_pages'     => (int) $query->max_num_pages,
				'dashboard_url'   => (string) ( $provider['my_articles_url'] ?? '' ),
				'write_url'       => (string) ( $provider['new_url'] ?? '' ),
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
		$out['dashboard_url'] = $is_owner ? (string) ( ArticleProvider::for_user( $user_id )['my_articles_url'] ?? '' ) : '';
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
