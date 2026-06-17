<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin information-architecture hub.
 *
 * Owns the BuddyNext top-level admin menu and dispatches every section
 * page to its registered tabs. Sections are declared statically below;
 * tabs are contributed by admin classes via AdminHub::register_tab().
 *
 * Empty sections are hidden from the sub-menu, so a section like
 * "Moderation" only appears once a feature registers a tab into it.
 *
 * See docs/ADMIN_IA_PLAN.md for the long-term IA shape this implements.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Central admin menu + tab dispatcher.
 *
 * @phpstan-type BnAdminTab array{label:string, render:callable, cap:string, position:int, layout:string, badge:callable|null, icon:string, group:string, order:int, subtitle:string, action:string}
 */
class AdminHub {

	/**
	 * Top-level menu slug. Reused as the page slug of the default "Settings"
	 * section so the first BuddyNext sub-menu entry maps to the same URL the
	 * top-level click resolves to.
	 */
	private const TOP_SLUG = 'buddynext';

	/**
	 * Default sections, in sidebar order. Filterable via
	 * `bn_admin_hub_sections` so an extension can add or rename a section
	 * without editing this file.
	 *
	 * `slug` is the wp-admin `?page=` value. `top` marks the section whose
	 * slug is shared with the top-level menu (so its first tab is what
	 * shows when an admin clicks "BuddyNext" itself).
	 *
	 * @var array<string, array{slug:string, label:string, top?:bool}>
	 */
	private const DEFAULT_SECTIONS = array(
		'settings'      => array(
			'slug'  => 'buddynext',
			'label' => 'Settings',
			'top'   => true,
		),
		'platform'      => array(
			'slug'  => 'buddynext-platform',
			'label' => 'Platform',
		),
		'members'       => array(
			'slug'  => 'buddynext-members',
			'label' => 'Members',
		),
		'spaces'        => array(
			'slug'  => 'buddynext-spaces',
			'label' => 'Spaces',
		),
		'engagement'    => array(
			'slug'  => 'buddynext-engagement',
			'label' => 'Engagement',
		),
		'notifications' => array(
			'slug'  => 'buddynext-notifications',
			'label' => 'Notifications',
		),
		'realtime'      => array(
			'slug'  => 'buddynext-realtime',
			'label' => 'Realtime & Push',
		),
		'campaigns'     => array(
			'slug'  => 'buddynext-campaigns',
			'label' => 'Campaigns',
		),
		'moderation'    => array(
			'slug'  => 'buddynext-moderation',
			'label' => 'Moderation',
		),
		'automod'       => array(
			'slug'  => 'buddynext-automod',
			'label' => 'Auto-Moderation',
		),
		'monetization'  => array(
			'slug'  => 'buddynext-monetization',
			'label' => 'Monetization',
		),
		'upgrade'       => array(
			'slug'  => 'buddynext-upgrade',
			'label' => 'Upgrade',
		),
	);

	/**
	 * Canonical tab placement — the single source of truth for the admin
	 * information architecture. Keyed by the tab's *origin* `section:slug`
	 * (the section a registrar passes to `register_tab()`), each rule moves
	 * the tab to its final `section` and sets its sidebar `position`.
	 *
	 * This lets every admin class — free and Pro — keep registering against
	 * its own domain section while the hub arranges the final layout in one
	 * place. No section exceeds five tabs, so no screen overwhelms the owner.
	 *
	 * A site can relocate, reorder, or hide any tab from a mu-plugin via the
	 * `bn_admin_hub_tab_placement` filter — `array( 'hidden' => true )` drops
	 * a tab, a different `section`/`position` moves it — without touching code.
	 *
	 * @var array<string, array{section?:string, position?:int, group?:string, hidden?:bool}>
	 */
	private const TAB_PLACEMENT = array(
		// Settings — identity & look.
		'settings:general'           => array(
			'section'  => 'settings',
			'position' => 10,
		),
		'settings:appearance'        => array(
			'section'  => 'settings',
			'position' => 20,
		),
		'settings:navigation'        => array(
			'section'  => 'settings',
			'position' => 30,
		),
		// White-label is retired from the IA. Hidden here so the Pro tab never
		// surfaces; the underlying subsystem is slated for full removal.
		'settings:white-label'       => array( 'hidden' => true ),

		// Platform — capabilities, extensibility, maintenance.
		'settings:features'          => array(
			'section'  => 'platform',
			'position' => 10,
		),
		'settings:integrations'      => array(
			'section'  => 'platform',
			'position' => 20,
		),
		'settings:tools'             => array(
			'section'  => 'platform',
			'position' => 40,
		),
		'settings:webhooks'          => array(
			'section'  => 'platform',
			'position' => 50,
		),

		// Members — roster, access, registration.
		'members:directory'          => array(
			'section'  => 'members',
			'position' => 10,
		),
		'members:labels'             => array(
			'section'  => 'members',
			'position' => 15,
		),
		'settings:registration'      => array(
			'section'  => 'members',
			'position' => 20,
		),
		'settings:roles'             => array(
			'section'  => 'members',
			'position' => 30,
		),
		'settings:privacy'           => array(
			'section'  => 'members',
			'position' => 40,
		),

		// Spaces.
		'spaces:directory'           => array(
			'section'  => 'spaces',
			'position' => 10,
		),
		'settings:spaces'            => array(
			'section'  => 'spaces',
			'position' => 20,
		),

		// Engagement — interaction + measurement. Insights is the single
		// measurement tab; Pro injects its analytics suite into it via the
		// buddynext_insights_after action (no separate Analytics tab).
		'growth:insights'            => array(
			'section'  => 'engagement',
			'position' => 10,
		),
		'settings:social'            => array(
			'section'  => 'engagement',
			'position' => 20,
		),
		'settings:reactions'         => array(
			'section'  => 'engagement',
			'position' => 30,
		),

		// Notifications — delivery + templates.
		'settings:notifications'     => array(
			'section'  => 'notifications',
			'position' => 10,
		),
		'settings:email'             => array(
			'section'  => 'notifications',
			'position' => 20,
		),
		'settings:templates'         => array(
			'section'  => 'notifications',
			'position' => 30,
		),

		// Realtime & Push (Pro). Hidden in free (no tabs register).
		'settings:realtime'          => array(
			'section'  => 'realtime',
			'position' => 10,
		),
		'settings:push'              => array(
			'section'  => 'realtime',
			'position' => 20,
		),
		'settings:push-prefs'        => array(
			'section'  => 'realtime',
			'position' => 30,
		),

		// Campaigns (Pro). Hidden in free.
		'growth:broadcasts'          => array(
			'section'  => 'campaigns',
			'position' => 10,
		),
		'growth:drip'                => array(
			'section'  => 'campaigns',
			'position' => 20,
		),
		'growth:scheduled'           => array(
			'section'  => 'campaigns',
			'position' => 30,
		),
		'growth:ai-feed'             => array(
			'section'  => 'campaigns',
			'position' => 40,
		),

		// Moderation — queue + filters.
		'moderation:reports'         => array(
			'section'  => 'moderation',
			'position' => 10,
		),
		'moderation:suspensions'     => array(
			'section'  => 'moderation',
			'position' => 20,
		),
		'moderation:appeals'         => array(
			'section'  => 'moderation',
			'position' => 30,
		),
		'settings:moderation'        => array(
			'section'  => 'moderation',
			'position' => 40,
		),
		'moderation:bulk'            => array(
			'section'  => 'moderation',
			'position' => 50,
		),

		// Auto-Moderation (Pro). Hidden in free.
		'moderation:rules'           => array(
			'section'  => 'automod',
			'position' => 10,
		),
		'moderation:ai'              => array(
			'section'  => 'automod',
			'position' => 20,
		),

		// Monetization (Pro). Hidden in free.
		'monetization:tiers'         => array(
			'section'  => 'monetization',
			'position' => 10,
		),
		'monetization:subscriptions' => array(
			'section'  => 'monetization',
			'position' => 20,
		),
		'monetization:stripe'        => array(
			'section'  => 'monetization',
			'position' => 30,
		),
		'settings:license'           => array(
			'section'  => 'monetization',
			'position' => 40,
		),
	);

	/**
	 * Cached merged section map (DEFAULT_SECTIONS + filter additions).
	 * Resolved lazily on first access so the filter sees the full plugin
	 * load order.
	 *
	 * @var array<string, array{slug:string, label:string, top?:bool}>|null
	 */
	private static ?array $sections_cache = null;

	/**
	 * Cached resolved tab-placement map (TAB_PLACEMENT + filter overrides).
	 *
	 * @var array<string, array{section?:string, position?:int, group?:string, hidden?:bool}>|null
	 */
	private static ?array $placement_cache = null;

	/**
	 * Tab registry. Keyed by section then tab slug.
	 *
	 * @var array<string, array<string, BnAdminTab>>
	 */
	private static array $tabs = array();

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Singleton accessor — also used by static register_tab().
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'build_menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Hide empty-label submenu rows (Pro legacy entries) via inline
		// admin CSS — we can't `unset` them from $submenu because WP's
		// permission check (`get_plugin_page_hook()`) walks that array
		// to validate page access, and removing the entry returns a 403.
		add_action( 'admin_head', array( $this, 'inject_empty_li_hide_css' ) );
		// Keep BuddyNext's own admin screens clean: clear the admin-notice hooks
		// so unrelated nags (the host theme's TGMPA "recommended plugins" notice,
		// other plugins' "set up" notices, etc.) don't crowd the settings UI. The
		// Hub renders its own save/status feedback, so it doesn't rely on the
		// core notice stream. Scoped strictly to Hub screens via is_hub_screen().
		add_action( 'in_admin_header', array( $this, 'suppress_foreign_admin_notices' ), 1 );
	}

	/**
	 * Clear admin notices on BuddyNext's own admin screens.
	 *
	 * @return void
	 */
	public function suppress_foreign_admin_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || ! self::is_hub_screen( (string) $screen->id ) ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Print a tiny CSS rule that hides empty-label submenu rows from the
	 * BuddyNext sidebar entry only. Pro Admin classes register their
	 * legacy `?page=X` URLs with `menu_title=''` so old bookmarks resolve;
	 * those rows render as `<li><a></a></li>` and accumulate whitespace
	 * below the real sections. This rule hides them visually while
	 * leaving the page handlers + access check intact.
	 *
	 * @return void
	 */
	public function inject_empty_li_hide_css(): void {
		echo '<style id="bn-admin-hub-prune">'
			. '#toplevel_page_' . esc_attr( self::TOP_SLUG ) . ' .wp-submenu li:has(> a:empty){display:none;}'
			. '</style>';
	}

	/**
	 * Load the Hub stylesheet only on Hub-owned pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! self::is_hub_screen( $hook_suffix ) ) {
			return;
		}
		$version    = defined( 'BUDDYNEXT_VERSION' ) ? (string) constant( 'BUDDYNEXT_VERSION' ) : '1.0.0';
		$assets_url = defined( 'BUDDYNEXT_URL' ) ? constant( 'BUDDYNEXT_URL' ) . 'assets/' : plugins_url( 'assets/', __FILE__ );
		wp_enqueue_style(
			'bn-admin-hub',
			$assets_url . 'css/bn-admin-hub.css',
			array( 'bn-base' ),
			$version
		);
	}

	/**
	 * Whether the current hook_suffix points at a Hub-owned section page.
	 *
	 * @param string $hook_suffix Hook suffix from admin_enqueue_scripts.
	 * @return bool
	 */
	public static function is_hub_screen( string $hook_suffix ): bool {
		if ( 'toplevel_page_' . self::TOP_SLUG === $hook_suffix ) {
			return true;
		}
		foreach ( self::sections() as $section ) {
			if ( str_ends_with( $hook_suffix, '_page_' . $section['slug'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a tab is the active tab on the current hub screen — regardless of
	 * which section the tab now lives in.
	 *
	 * Registrars gate their page-specific assets on this so a tab keeps its
	 * CSS/JS no matter where the central placement map sends it. They reference
	 * only the tab slug, never a hardcoded page/section, so a future move never
	 * silently drops assets.
	 *
	 * @param string $slug Tab slug.
	 * @return bool
	 */
	public static function is_tab_active( string $slug ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( '' === $page ) {
			return false;
		}
		$section_key = self::instance()->section_from_slug( $page );
		if ( null === $section_key ) {
			return false;
		}
		$tabs = self::get_tabs( $section_key );
		if ( empty( $tabs ) ) {
			return false;
		}
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $active || ! isset( $tabs[ $active ] ) ) {
			$active = (string) array_key_first( $tabs );
		}
		return $active === $slug;
	}

	// ── Section / Tab API ────────────────────────────────────────────────────

	/**
	 * Resolved section map (defaults + `bn_admin_hub_sections` filter).
	 *
	 * Extensions can add a new top-level section like this:
	 *
	 *     add_filter( 'bn_admin_hub_sections', function ( $sections ) {
	 *         $sections['marketplace'] = array(
	 *             'slug'  => 'buddynext-marketplace',
	 *             'label' => __( 'Marketplace', 'my-ext' ),
	 *         );
	 *         return $sections;
	 *     } );
	 *
	 * The section appears in the sidebar only when at least one tab is
	 * registered into it.
	 *
	 * @return array<string, array{slug:string, label:string, top?:bool}>
	 */
	public static function sections(): array {
		if ( null !== self::$sections_cache ) {
			return self::$sections_cache;
		}
		/**
		 * Filter the admin-hub section map.
		 *
		 * @param array $sections Section key → { slug, label, top? } map.
		 */
		$filtered             = apply_filters( 'bn_admin_hub_sections', self::DEFAULT_SECTIONS );
		self::$sections_cache = is_array( $filtered ) ? $filtered : self::DEFAULT_SECTIONS;
		return self::$sections_cache;
	}

	/**
	 * Resolve a section key to its wp-admin `?page=` slug.
	 *
	 * Registrars should use this (and {@see tab_url()}) instead of hard-coding a
	 * section slug, so in-tab links and post-submit redirects land back inside
	 * the Hub chrome rather than on a registrar's hidden legacy page.
	 *
	 * @param string $section Section key (e.g. 'monetization').
	 * @return string Page slug, or '' if the section is unknown.
	 */
	public static function section_slug( string $section ): string {
		$sections = self::sections();
		return isset( $sections[ $section ]['slug'] ) ? (string) $sections[ $section ]['slug'] : '';
	}

	/**
	 * Build the wp-admin URL for a Hub tab (`?page=<section>&tab=<slug>`), with
	 * optional extra query args merged in.
	 *
	 * The `$section` argument is the tab's *origin* section (what its registrar
	 * passed to {@see register_tab()}); this resolves it through the same
	 * placement map register_tab() uses, so a tab relocated to another section
	 * (e.g. growth:broadcasts → campaigns) still produces the correct page slug.
	 *
	 * @param string               $section Origin section key (e.g. 'growth').
	 * @param string               $tab     Tab slug (e.g. 'broadcasts').
	 * @param array<string, mixed> $extra   Extra query args to append.
	 * @return string Absolute admin URL; bare admin.php if the section is unknown.
	 */
	public static function tab_url( string $section, string $tab, array $extra = array() ): string {
		// Apply the canonical placement so an origin section that the IA map
		// relocates resolves to the section page the tab actually renders on.
		$rule = self::tab_placement()[ $section . ':' . $tab ] ?? null;
		if ( is_array( $rule ) && isset( $rule['section'] ) ) {
			$section = (string) $rule['section'];
		}

		$page_slug = self::section_slug( $section );
		if ( '' === $page_slug ) {
			return admin_url( 'admin.php' );
		}

		$args = array_merge(
			array(
				'page' => $page_slug,
				'tab'  => $tab,
			),
			$extra
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Resolved tab-placement map (defaults + `bn_admin_hub_tab_placement`).
	 *
	 * The map is keyed by a tab's origin `section:slug` and decides the final
	 * section, sidebar position, and visibility of every tab. A site can move,
	 * reorder, or hide any tab from a mu-plugin:
	 *
	 *     add_filter( 'bn_admin_hub_tab_placement', function ( $map ) {
	 *         $map['settings:webhooks']['hidden'] = true;          // hide a tab
	 *         $map['settings:social']['section']  = 'notifications'; // move a tab
	 *         return $map;
	 *     } );
	 *
	 * @return array<string, array{section?:string, position?:int, group?:string, hidden?:bool}>
	 */
	private static function tab_placement(): array {
		if ( null !== self::$placement_cache ) {
			return self::$placement_cache;
		}
		/**
		 * Filter the admin-hub tab-placement map.
		 *
		 * @param array $map Origin `section:slug` → { section?, position?, group?, hidden? }.
		 */
		$filtered              = apply_filters( 'bn_admin_hub_tab_placement', self::TAB_PLACEMENT );
		self::$placement_cache = is_array( $filtered ) ? $filtered : self::TAB_PLACEMENT;
		return self::$placement_cache;
	}

	/**
	 * Contribute a tab to a section.
	 *
	 * Call from a feature's `register()` method (or any code that runs before
	 * `admin_menu` priority 9). Sections with no registered tabs are hidden
	 * from the BuddyNext sub-menu.
	 *
	 * `$args` recognised keys:
	 *  - `cap`      string    Capability required (default: manage_options).
	 *  - `position` int       Lower numbers sort earlier (default 50).
	 *  - `badge`    callable  Optional `fn(): int`; when > 0 the tab renders a counter pill (queues, requests).
	 *  - `icon`     string    Lucide icon slug. Auto-mapped from tab slug when omitted.
	 *  - `group`    string    Sidebar group eyebrow (e.g. "Advanced").
	 *  - `layout`   string    'sidebar' (default) — body renders next to the section sidebar.
	 *                         'wide' — body renders edge-to-edge with a slim section-tab picker at top.
	 *                                  Use for list-detail editors that need horizontal room.
	 *  - `subtitle` string    One-line description shown in the standardized sub-header bar
	 *                         below the tab strip. Escaped with esc_html on render.
	 *  - `action`   string    Pre-built, already-escaped HTML for the sub-header's right side
	 *                         (e.g. an Export CSV form/button). Printed verbatim — the screen
	 *                         that supplies it is responsible for escaping every value inside.
	 *
	 * Back-compat: passing a capability string as the 5th arg still works.
	 *
	 * @param string                                                                                                                                 $section Section key.
	 * @param string                                                                                                                                 $slug    Tab slug — URL `?tab=` value.
	 * @param string                                                                                                                                 $label   Visible tab label (already translated).
	 * @param callable                                                                                                                               $render  Render callback for the tab body.
	 * @param array{cap?:string,position?:int,badge?:callable,icon?:string,group?:string,layout?:string,subtitle?:string,action?:string}|string|null $args    Extension args, or capability string.
	 * @return void
	 */
	public static function register_tab( string $section, string $slug, string $label, callable $render, array|string|null $args = null ): void {
		if ( is_string( $args ) ) {
			$args = array( 'cap' => $args );
		}
		$args = is_array( $args ) ? $args : array();

		// Apply the canonical IA placement. The map relocates the tab to its
		// final section and sets its sidebar position, so every registrar can
		// keep passing its own domain section while the hub arranges the final
		// layout in one place. `hidden` drops the tab entirely.
		$placement = self::tab_placement();
		$rule      = $placement[ $section . ':' . $slug ] ?? null;
		if ( is_array( $rule ) ) {
			if ( ! empty( $rule['hidden'] ) ) {
				return;
			}
			if ( isset( $rule['section'] ) ) {
				$section = (string) $rule['section'];
			}
			if ( isset( $rule['position'] ) ) {
				$args['position'] = (int) $rule['position'];
			}
			// The map owns grouping: clear any registrar-supplied group unless
			// the rule sets one, so legacy "Advanced" eyebrows don't leak into
			// the flat, capped sections.
			$args['group'] = isset( $rule['group'] ) ? (string) $rule['group'] : '';
		}

		if ( ! isset( self::sections()[ $section ] ) ) {
			return;
		}

		self::$tabs[ $section ][ $slug ] = array(
			'label'    => $label,
			'render'   => $render,
			'cap'      => isset( $args['cap'] ) ? (string) $args['cap'] : 'manage_options',
			'position' => isset( $args['position'] ) ? (int) $args['position'] : 50,
			'layout'   => isset( $args['layout'] ) && 'wide' === $args['layout'] ? 'wide' : 'sidebar',
			'badge'    => isset( $args['badge'] ) && is_callable( $args['badge'] ) ? $args['badge'] : null,
			'icon'     => isset( $args['icon'] ) ? (string) $args['icon'] : self::default_icon_for( $slug ),
			'group'    => isset( $args['group'] ) ? (string) $args['group'] : '',
			'order'    => count( self::$tabs[ $section ] ?? array() ),
			'subtitle' => isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '',
			'action'   => isset( $args['action'] ) ? (string) $args['action'] : '',
		);
	}

	/**
	 * Return a sensible default Lucide icon slug for a tab whose registration
	 * didn't pass one. Falls back to a generic icon for unmapped slugs.
	 *
	 * Filterable via `bn_admin_hub_default_icon_map` so extensions can
	 * register defaults for their own tab slugs without editing this file.
	 *
	 * @param string $tab_slug Tab slug.
	 * @return string Icon slug (matches /assets/icons/{slug}.svg).
	 */
	private static function default_icon_for( string $tab_slug ): string {
		static $cached = null;
		if ( null === $cached ) {
			/**
			 * Filter the default tab-slug → icon-slug map.
			 *
			 * @param array<string, string> $map
			 */
			$cached = apply_filters(
				'bn_admin_hub_default_icon_map',
				array(
					// Settings section.
					'general'       => 'settings',
					'features'      => 'sparkles',
					'registration'  => 'user',
					'social'        => 'globe',
					'spaces'        => 'grid',
					'notifications' => 'bell',
					'email'         => 'mail',
					'moderation'    => 'shield',
					'integrations'  => 'code',
					'privacy'       => 'lock',
					'appearance'    => 'palette',
					'tools'         => 'cpu',
					'roles'         => 'award',
					'webhooks'      => 'share',
					'navigation'    => 'list',
					'templates'     => 'mail',
					'reactions'     => 'smile',
					'push'          => 'bell',
					'push-prefs'    => 'bell',
					'realtime'      => 'zap',
					'white-label'   => 'palette',
					// Members section.
					'directory'     => 'users',
					'labels'        => 'hash',
					// Moderation section.
					'rules'         => 'shield',
					'ai'            => 'sparkles',
					'bulk'          => 'check-double',
					'reports'       => 'flag',
					'suspensions'   => 'ban',
					'appeals'       => 'message-circle',
					// Engagement / Campaigns section.
					'analytics'     => 'bar-chart',
					'insights'      => 'trending',
					'broadcasts'    => 'megaphone',
					'drip'          => 'mail',
					'scheduled'     => 'clock',
					'ai-feed'       => 'sparkles',
					// Monetization section.
					'tiers'         => 'crown',
					'subscriptions' => 'crown',
					'paywall'       => 'lock',
					'stripe'        => 'crown',
				)
			);
		}
		return $cached[ $tab_slug ] ?? 'more-horizontal';
	}

	/**
	 * Return a section's tabs, sorted by `position` then registration order.
	 *
	 * @param string $section Section key.
	 * @return array<string, BnAdminTab>
	 */
	public static function get_tabs( string $section ): array {
		$tabs = self::$tabs[ $section ] ?? array();
		if ( empty( $tabs ) ) {
			return array();
		}
		uasort(
			$tabs,
			static function ( array $a, array $b ): int {
				$cmp = $a['position'] <=> $b['position'];
				return 0 !== $cmp ? $cmp : ( $a['order'] <=> $b['order'] );
			}
		);
		return $tabs;
	}

	/**
	 * Check whether the current admin request is on a given section + tab.
	 *
	 * Lets a feature decide whether to enqueue its CSS/JS without rebuilding
	 * the page resolution logic.
	 *
	 * @param string $section Section key.
	 * @param string $tab     Tab slug.
	 * @return bool
	 */
	public static function is_active( string $section, string $tab ): bool {
		if ( ! isset( self::sections()[ $section ] ) ) {
			return false;
		}
		$expected_page = self::sections()[ $section ]['slug'];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( $page !== $expected_page ) {
			return false;
		}
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// Empty `?tab` means the first registered tab.
		if ( '' === $current_tab ) {
			$first = self::first_tab_slug( $section );
			return $first === $tab;
		}
		return $current_tab === $tab;
	}

	// ── Menu build ───────────────────────────────────────────────────────────

	/**
	 * Build the top-level BuddyNext menu and each section's sub-menu entry.
	 *
	 * @return void
	 */
	public function build_menu(): void {
		$bn_label = __( 'BuddyNext', 'buddynext' );

		add_menu_page(
			$bn_label,
			$bn_label,
			'manage_options',
			self::TOP_SLUG,
			array( $this, 'render_section' ),
			// Distinct from Jetonomy's dashicons-groups so the two menus are not
			// confused in the wp-admin sidebar; the network glyph reads as the
			// social-graph platform.
			'dashicons-networking',
			30
		);

		foreach ( self::sections() as $key => $section ) {
			if ( empty( self::$tabs[ $key ] ) ) {
				continue;
			}
			add_submenu_page(
				self::TOP_SLUG,
				__( $section['label'], 'buddynext' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- section labels are first-party literals defined in DEFAULT_SECTIONS.
				__( $section['label'], 'buddynext' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- section labels are first-party literals defined in DEFAULT_SECTIONS.
				'manage_options',
				$section['slug'],
				array( $this, 'render_section' )
			);
		}
	}

	// ── Render ───────────────────────────────────────────────────────────────

	/**
	 * Render a section page — header + tab strip + active tab body.
	 *
	 * @return void
	 */
	public function render_section(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'buddynext' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$section_key = $this->section_from_slug( $page );
		$tabs        = null !== $section_key ? self::get_tabs( $section_key ) : array();
		if ( null === $section_key || empty( $tabs ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'BuddyNext', 'buddynext' ) . '</h1><p>'
				. esc_html__( 'Nothing is registered here yet.', 'buddynext' )
				. '</p></div>';
			return;
		}

		$section = self::sections()[ $section_key ];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$active_slug = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $active_slug ] ) ) {
			$active_slug = (string) array_key_first( $tabs );
		}
		$active = $tabs[ $active_slug ];

		if ( ! current_user_can( $active['cap'] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this tab.', 'buddynext' ) );
		}

		$is_wide = ( 'wide' === ( $active['layout'] ?? 'sidebar' ) );

		echo '<div class="wrap bn-admin-hub' . ( $is_wide ? ' is-wide' : '' ) . '" data-section="' . esc_attr( $section_key ) . '">';
		$this->render_header( $section['label'] );

		// Every multi-tab section — wide editors included — uses the same
		// horizontal tab strip above a full-width body, so the chrome is
		// identical everywhere. Wide editors only differ in body width.
		$main_classes = 'bn-admin-hub__main ' . ( $is_wide ? 'bn-admin-hub__main--wide' : 'bn-admin-hub__main--full' );
		if ( count( $tabs ) > 1 ) {
			$this->render_tabstrip( $section['slug'], $tabs, $active_slug );
			$this->render_subhead( $active );
			printf(
				'<main class="%s" id="bn-admin-hub-panel" role="tabpanel" aria-labelledby="%s" tabindex="0">',
				esc_attr( $main_classes ),
				esc_attr( 'bn-hubtab-' . $active_slug )
			);
		} else {
			// Single-tab section — body only, no strip.
			$this->render_subhead( $active );
			printf( '<main class="%s">', esc_attr( $main_classes ) );
		}
		call_user_func( $active['render'] );
		echo '</main>';
		echo '</div>';
	}

	/**
	 * Render the section page header (single H1, no sub-text).
	 *
	 * @param string $label Section label.
	 * @return void
	 */
	private function render_header( string $label ): void {
		echo '<header class="bn-admin-hub__header">';
		echo '<h1 class="bn-admin-hub__title">' . esc_html__( $label, 'buddynext' ) . '</h1>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		echo '</header>';
		// WordPress core hoists admin notices to just after the first <h1>, which
		// here lands them inside the flex header and squishes them to a sliver.
		// This marker tells core to place notices after the header instead, so
		// they render full-width in the content column (invisible via core CSS).
		echo '<hr class="wp-header-end" />';
	}

	/**
	 * Render the standardized sub-header bar for the active tab.
	 *
	 * One consistent bar across every screen: the tab's one-line subtitle on the
	 * left, its primary action (e.g. an "Export CSV" form/button) on the right.
	 * Rendered only when the active tab declares a `subtitle` or an `action`, so
	 * screens that need neither stay clean. This is the single place a screen
	 * gets a subtitle or a header action — screens must not print their own.
	 *
	 * @param array<string, mixed> $tab Active tab record.
	 * @return void
	 */
	private function render_subhead( array $tab ): void {
		$subtitle = isset( $tab['subtitle'] ) ? (string) $tab['subtitle'] : '';
		$action   = isset( $tab['action'] ) ? (string) $tab['action'] : '';
		if ( '' === $subtitle && '' === $action ) {
			return;
		}

		echo '<div class="bn-admin-hub__subhead">';
		if ( '' !== $subtitle ) {
			echo '<p class="bn-admin-hub__subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		if ( '' !== $action ) {
			// $action is trusted, pre-built HTML supplied by the registering screen
			// (e.g. an Export CSV form). The screen is contractually responsible for
			// escaping every value inside it before passing it to register_tab().
			echo '<div class="bn-admin-hub__subhead-actions">' . $action . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted pre-escaped HTML by Header API contract.
		}
		echo '</div>';
	}

	/**
	 * Render the section tab strip — a horizontal nav listing every tab.
	 *
	 * Sections are capped at a few tabs, so a horizontal strip reads cleanly and
	 * uses the full content width (a vertical rail would waste the left column).
	 * Flat by design — no group eyebrows — since the cap keeps each list short.
	 *
	 * @param string                    $page_slug Section page slug.
	 * @param array<string, BnAdminTab> $tabs      Sorted tabs.
	 * @param string                    $active    Active tab slug.
	 * @return void
	 */
	private function render_tabstrip( string $page_slug, array $tabs, string $active ): void {
		?>
		<nav class="bn-admin-hub__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Section tabs', 'buddynext' ); ?>">
			<?php
			foreach ( $tabs as $slug => $tab ) {
				if ( ! current_user_can( $tab['cap'] ) ) {
					continue;
				}

				$is_active = ( $slug === $active );
				$url       = add_query_arg(
					array(
						'page' => $page_slug,
						'tab'  => $slug,
					),
					admin_url( 'admin.php' )
				);

				$badge_html = '';
				if ( ! empty( $tab['badge'] ) && is_callable( $tab['badge'] ) ) {
					$count = (int) call_user_func( $tab['badge'] );
					if ( $count > 0 ) {
						$display    = $count > 99 ? '99+' : (string) $count;
						$badge_html = ' <span class="bn-admin-hub__tab-badge" aria-label="' . esc_attr(
							sprintf(
							/* translators: %d: pending item count */
								_n( '%d pending', '%d pending', $count, 'buddynext' ),
								$count
							)
						) . '">' . esc_html( $display ) . '</span>';
					}
				}

				$icon_html = '';
				if ( ! empty( $tab['icon'] ) ) {
					$svg = \BuddyNext\Core\IconService::render( (string) $tab['icon'] );
					if ( '' !== $svg ) {
						$icon_html = '<span class="bn-admin-hub__tab-icon" aria-hidden="true">' . $svg . '</span>';
					}
				}

				printf(
					'<a class="bn-admin-hub__tab%s" href="%s" id="bn-hubtab-%s" role="tab" aria-selected="%s" aria-controls="bn-admin-hub-panel">%s<span class="bn-admin-hub__tab-label">%s</span>%s</a>',
					$is_active ? ' is-active' : '',
					esc_url( $url ),
					esc_attr( $slug ),
					$is_active ? 'true' : 'false',
					$icon_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService wp_kses'd.
					esc_html( $tab['label'] ),
					$badge_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above; only digits/"99+" inside.
				);
			}
			?>
		</nav>
		<?php
	}

	// ── Internal helpers ─────────────────────────────────────────────────────

	/**
	 * Map a wp-admin page slug back to a section key.
	 *
	 * @param string $slug Page slug (`?page=` value).
	 * @return string|null
	 */
	private function section_from_slug( string $slug ): ?string {
		foreach ( self::sections() as $key => $section ) {
			if ( $section['slug'] === $slug ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Return the slug of the first registered tab in a section, or null.
	 *
	 * @param string $section Section key.
	 * @return string|null
	 */
	private static function first_tab_slug( string $section ): ?string {
		$tabs = self::get_tabs( $section );
		if ( empty( $tabs ) ) {
			return null;
		}
		return (string) array_key_first( $tabs );
	}
}
