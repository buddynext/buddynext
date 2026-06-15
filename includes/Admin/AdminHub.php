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
 * @phpstan-type BnAdminTab array{label:string, render:callable, cap:string, position:int, layout:string, badge:callable|null, icon:string, group:string, order:int}
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
		'settings'     => array(
			'slug'  => 'buddynext',
			'label' => 'Settings',
			'top'   => true,
		),
		'members'      => array(
			'slug'  => 'buddynext-members',
			'label' => 'Members',
		),
		'spaces'       => array(
			'slug'  => 'buddynext-spaces',
			'label' => 'Spaces',
		),
		'moderation'   => array(
			'slug'  => 'buddynext-moderation',
			'label' => 'Moderation',
		),
		'growth'       => array(
			'slug'  => 'buddynext-growth',
			'label' => 'Growth',
		),
		'monetization' => array(
			'slug'  => 'buddynext-monetization',
			'label' => 'Monetization',
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
		if ( ! $screen instanceof \WP_Screen || ! $this->is_hub_screen( (string) $screen->id ) ) {
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
		if ( ! $this->is_hub_screen( $hook_suffix ) ) {
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
	private function is_hub_screen( string $hook_suffix ): bool {
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
	 *
	 * Back-compat: passing a capability string as the 5th arg still works.
	 *
	 * @param string                                                                                                 $section Section key.
	 * @param string                                                                                                 $slug    Tab slug — URL `?tab=` value.
	 * @param string                                                                                                 $label   Visible tab label (already translated).
	 * @param callable                                                                                               $render  Render callback for the tab body.
	 * @param array{cap?:string,position?:int,badge?:callable,icon?:string,group?:string,layout?:string}|string|null $args    Extension args, or capability string.
	 * @return void
	 */
	public static function register_tab( string $section, string $slug, string $label, callable $render, array|string|null $args = null ): void {
		if ( ! isset( self::sections()[ $section ] ) ) {
			return;
		}
		if ( is_string( $args ) ) {
			$args = array( 'cap' => $args );
		}
		$args = is_array( $args ) ? $args : array();

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
					// Settings section
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
					'addons'        => 'code',
					'reactions'     => 'smile',
					'push'          => 'bell',
					'push-prefs'    => 'bell',
					'realtime'      => 'zap',
					'white-label'   => 'palette',
					// Members section
					'directory'     => 'users',
					'labels'        => 'hash',
					// Moderation section
					'rules'         => 'shield',
					'ai'            => 'sparkles',
					'bulk'          => 'check-double',
					'reports'       => 'flag',
					'suspensions'   => 'ban',
					'appeals'       => 'message-circle',
					// Growth section
					'analytics'     => 'bar-chart',
					'insights'      => 'trending',
					'broadcasts'    => 'megaphone',
					'drip'          => 'mail',
					'scheduled'     => 'clock',
					'ai-feed'       => 'sparkles',
					// Monetization section
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
		// White-label: the wp-admin menu title can be renamed to the site's own
		// community name via Settings → Appearance (option buddynext_white_label).
		$bn_label = (string) get_option( 'buddynext_white_label', '' );
		$bn_label = '' !== trim( $bn_label ) ? $bn_label : __( 'BuddyNext', 'buddynext' );

		add_menu_page(
			$bn_label,
			$bn_label,
			'manage_options',
			self::TOP_SLUG,
			array( $this, 'render_section' ),
			'dashicons-groups',
			30
		);

		foreach ( self::sections() as $key => $section ) {
			if ( empty( self::$tabs[ $key ] ) ) {
				continue;
			}
			add_submenu_page(
				self::TOP_SLUG,
				__( $section['label'], 'buddynext' ),
				__( $section['label'], 'buddynext' ),
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

		if ( $is_wide ) {
			// Wide layout: slim section-tab picker at top, edge-to-edge body.
			$this->render_wide_picker( $section['slug'], $tabs, $active_slug );
			echo '<main class="bn-admin-hub__main bn-admin-hub__main--wide">';
			call_user_func( $active['render'] );
			echo '</main>';
		} elseif ( count( $tabs ) > 1 ) {
			// Sidebar layout: vertical nav + body.
			echo '<div class="bn-admin-hub__layout">';
			$this->render_sidebar( $section['slug'], $section['label'], $tabs, $active_slug );
			echo '<main class="bn-admin-hub__main">';
			call_user_func( $active['render'] );
			echo '</main>';
			echo '</div>';
		} else {
			// Single-tab section — body only.
			echo '<div class="bn-admin-hub__body">';
			call_user_func( $active['render'] );
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the top picker for wide-layout tabs.
	 *
	 * A compact dropdown listing every tab in the section, so admins can
	 * jump to another tab without losing the editor's full horizontal
	 * room. Pure HTML <select> — no JS dependency.
	 *
	 * @param string                                                       $page_slug Section page slug.
	 * @param array<string, array{label:string,layout?:string,cap:string}> $tabs      Tab registry slice.
	 * @param string                                                       $active    Active tab slug.
	 * @return void
	 */
	private function render_wide_picker( string $page_slug, array $tabs, string $active ): void {
		?>
		<nav class="bn-admin-hub__picker" aria-label="<?php esc_attr_e( 'Section tabs', 'buddynext' ); ?>">
			<label class="bn-admin-hub__picker-label" for="bn-hub-picker">
				<?php esc_html_e( 'Section:', 'buddynext' ); ?>
			</label>
			<select
				id="bn-hub-picker"
				class="bn-admin-hub__picker-select"
				data-bn-navigate-on-change
			>
				<?php
				foreach ( $tabs as $slug => $tab ) :
					if ( ! current_user_can( $tab['cap'] ) ) {
						continue; }
					$url = add_query_arg(
						array(
							'page' => $page_slug,
							'tab'  => $slug,
						),
						admin_url( 'admin.php' )
					);
					?>
					<option value="<?php echo esc_url( $url ); ?>" <?php selected( $slug, $active ); ?>>
						<?php echo esc_html( $tab['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</nav>
		<?php
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
	}

	/**
	 * Render the section sidebar — vertical nav listing every tab.
	 *
	 * Replaces the previous horizontal tab strip per skill rule F5 (jetonomy
	 * pattern). Scales cleanly when a section grows past 6-7 tabs; the
	 * horizontal version overflows.
	 *
	 * @param string                    $page_slug      Section page slug.
	 * @param string                    $section_label  Section label for the sidebar header.
	 * @param array<string, BnAdminTab> $tabs           Sorted tabs.
	 * @param string                    $active         Active tab slug.
	 * @return void
	 */
	private function render_sidebar( string $page_slug, string $section_label, array $tabs, string $active ): void {
		?>
		<aside class="bn-admin-hub__sidebar" aria-label="<?php esc_attr_e( 'Section navigation', 'buddynext' ); ?>">
			<header class="bn-admin-hub__sidebar-head">
				<span class="bn-admin-hub__sidebar-brand-icon" aria-hidden="true">
					<?php echo \BuddyNext\Core\IconService::render( 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
				</span>
				<div class="bn-admin-hub__sidebar-brand-text">
					<span class="bn-admin-hub__sidebar-brand-name">BuddyNext</span>
					<span class="bn-admin-hub__sidebar-section"><?php echo esc_html( $section_label ); ?></span>
				</div>
			</header>
			<nav class="bn-admin-hub__sidebar-nav" role="tablist">
				<?php
				$current_group = null;
				foreach ( $tabs as $slug => $tab ) {
					if ( ! current_user_can( $tab['cap'] ) ) {
						continue;
					}

					// Render group eyebrow when the group changes.
					$group = (string) ( $tab['group'] ?? '' );
					if ( $group !== $current_group ) {
						if ( '' !== $group ) {
							echo '<div class="bn-admin-hub__sidebar-group">' . esc_html( $group ) . '</div>';
						} elseif ( null !== $current_group && '' !== $current_group ) {
							// Coming back to ungrouped after a group — visual divider.
							echo '<div class="bn-admin-hub__sidebar-divider" aria-hidden="true"></div>';
						}
						$current_group = $group;
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
							$badge_html = ' <span class="bn-admin-hub__sidebar-badge" aria-label="' . esc_attr(
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
							$icon_html = '<span class="bn-admin-hub__sidebar-icon" aria-hidden="true">' . $svg . '</span>';
						}
					}

					printf(
						'<a class="bn-admin-hub__sidebar-link%s" href="%s" role="tab" aria-selected="%s"%s>%s<span class="bn-admin-hub__sidebar-label">%s</span>%s</a>',
						$is_active ? ' is-active' : '',
						esc_url( $url ),
						$is_active ? 'true' : 'false',
						$is_active ? ' data-active="true"' : '',
						$icon_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService wp_kses'd.
						esc_html( $tab['label'] ),
						$badge_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above; only digits/"99+" inside.
					);
				}
				?>
			</nav>
		</aside>
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
