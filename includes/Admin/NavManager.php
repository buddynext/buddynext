<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * BuddyNext Navigation Manager admin page.
 *
 * Three-panel layout matching admin-nav-manager.html:
 *   Left  — Navigation Scope sidebar (Main Nav active; others stubbed).
 *   Center — Sortable list of registered nav tabs with toggle + config trigger.
 *   Right  — Per-item config panel: label, position, page assignment,
 *             visibility, capability, login-required, guest label.
 *
 * Page assignments are stored in individual buddynext_page_* options.
 * Tab overrides (label, order, visibility, etc.) are stored in the single
 * buddynext_nav_overrides option as an associative array keyed by slug.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Manages the BuddyNext frontend navigation — reorder, configure, extend.
 */
class NavManager extends AdminPageBase {

	/**
	 * Filter name used to register / modify navigation tabs.
	 */
	public const FILTER_TABS = 'buddynext_nav_tabs';

	/**
	 * Map of scope slug → WP option key for persisted overrides.
	 *
	 * @var array<string, string>
	 */
	private const SCOPE_OPTION_MAP = array(
		'main'    => 'buddynext_nav_overrides',
		'profile' => 'buddynext_nav_overrides_profile',
		'space'   => 'buddynext_nav_overrides_space',
		'mobile'  => 'buddynext_nav_overrides_mobile',
	);

	/**
	 * Map of core tab slug → buddynext_page_* option name.
	 *
	 * Used to persist page assignments separately from nav overrides so that
	 * PageRouter and other services can read them without knowing about the
	 * nav system.
	 *
	 * @var array<string, string>
	 */
	private const PAGE_OPTIONS = array(
		'feed'          => 'buddynext_page_activity',
		'explore'       => 'buddynext_page_explore',
		'spaces'        => 'buddynext_page_spaces',
		'messages'      => 'buddynext_page_messages',
		'notifications' => 'buddynext_page_notifications',
		'people'        => 'buddynext_page_people',
		'auth'          => 'buddynext_page_auth',
	);

	/**
	 * Map of main-nav tab slug → buddynext_slug_* option key.
	 *
	 * Used to render a URL-slug input inside each hub's config panel and to
	 * persist slug changes when the nav form is saved.  The option keys match
	 * those used by PageRouter; PageRouter listens on update_option_buddynext_slug_*
	 * and calls flush_rewrite_rules() automatically, so no explicit flush is
	 * needed here.
	 *
	 * @var array<string, string>
	 */
	private const SLUG_OPTIONS = array(
		'feed'          => 'buddynext_slug_activity',
		'spaces'        => 'buddynext_slug_spaces',
		'messages'      => 'buddynext_slug_messages',
		'notifications' => 'buddynext_slug_notifications',
		'people'        => 'buddynext_slug_people',
		'auth'          => 'buddynext_slug_auth',
	);

	/**
	 * WordPress core URL slugs and feed endpoints that must not be used as hub slugs.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED_SLUGS = array(
		'wp-admin',
		'wp-login',
		'wp-content',
		'wp-includes',
		'wp-json',
		'feed',
		'rss',
		'rss2',
		'atom',
		'rdf',
		'comments',
		'trackback',
		'embed',
		'wp-cron',
	);

	/**
	 * Base path to the admin SVG icon directory.
	 */
	private const SVG_DIR = __DIR__ . '/../../assets/svg/admin/';

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_save_nav', array( $this, 'handle_save_nav' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		AdminHub::register_tab(
			'settings',
			'navigation',
			__( 'Navigation', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'group'  => __( 'Advanced', 'buddynext' ),
				'layout' => 'wide', // list-detail editor needs edge-to-edge room
			)
		);
	}

	/**
	 * Enqueue the Nav Manager admin script on this page only.
	 *
	 * The matching CSS lives in assets/css/bn-admin.css and is enqueued
	 * globally for all BuddyNext admin pages by AssetService.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'buddynext_page_buddynext-nav' !== $hook_suffix ) {
			return;
		}

		$plugin_url = defined( 'BUDDYNEXT_FILE' )
			? plugin_dir_url( (string) constant( 'BUDDYNEXT_FILE' ) )
			: plugins_url( '/', __DIR__ . '/../../buddynext.php' );

		$version = defined( 'BUDDYNEXT_VERSION' ) ? (string) constant( 'BUDDYNEXT_VERSION' ) : '1.0.0';

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_script(
			'bn-nav-manager',
			$plugin_url . 'assets/js/admin/nav-manager.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			$version,
			true
		);

		$first_slug = '';
		$main_tabs  = $this->get_tabs_for_scope( 'main' );
		if ( ! empty( $main_tabs ) ) {
			$first_slug = sanitize_key( (string) ( $main_tabs[0]['slug'] ?? '' ) );
		}

		wp_localize_script(
			'bn-nav-manager',
			'bnNavManager',
			array(
				'firstSlug' => $first_slug,
				'restUrl'   => esc_url_raw( rest_url( 'buddynext/v1/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'slugHint'  => __( 'URL path for this hub, e.g. "members" → /members/. Saving flushes rewrite rules automatically.', 'buddynext' ),
					'slugFree'  => __( 'Slug is available', 'buddynext' ),
					'slugWarn'  => __( 'An existing page uses this slug, it will become unreachable', 'buddynext' ),
					'slugBlock' => __( 'This slug is reserved or used by another hub', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Add the Nav Manager submenu under the BuddyNext top-level menu.
	 *
	 * @return void
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'buddynext',
			__( 'Navigation', 'buddynext' ),
			__( 'Navigation', 'buddynext' ),
			'manage_options',
			'buddynext-nav',
			array( $this, 'render_page' )
		);
	}

	// ── Tab registry ──────────────────────────────────────────────────────────

	/**
	 * Return the sorted, override-applied list of registered navigation tabs.
	 *
	 * Applies the `buddynext_nav_tabs` filter so third-party code can inject,
	 * remove, or reorder tabs.  Admin overrides stored via this page are
	 * applied last and take precedence over filter output.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_tabs(): array {
		$defaults = $this->default_tabs();

		/**
		 * Filter the BuddyNext navigation tabs.
		 *
		 * Each tab is an array with keys: slug, label, order, icon,
		 * description, capability.  Add 'custom' => true for non-core tabs.
		 *
		 * @param array<int, array<string, mixed>> $tabs Registered tab entries.
		 */
		$tabs = (array) apply_filters( self::FILTER_TABS, $defaults );

		foreach ( $tabs as &$tab ) {
			if ( ! isset( $tab['order'] ) ) {
				$tab['order'] = 10;
			}
		}
		unset( $tab );

		$overrides = $this->get_overrides();

		foreach ( $tabs as &$tab ) {
			$slug = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
			if ( ! isset( $overrides[ $slug ] ) ) {
				continue;
			}
			$ov = $overrides[ $slug ];

			foreach ( array( 'label', 'icon', 'visibility', 'capability', 'guest_label' ) as $field ) {
				if ( isset( $ov[ $field ] ) && '' !== $ov[ $field ] ) {
					$tab[ $field ] = sanitize_text_field( (string) $ov[ $field ] );
				}
			}
			if ( isset( $ov['order'] ) ) {
				$tab['order'] = (int) $ov['order'];
			}
			if ( isset( $ov['hidden'] ) ) {
				$tab['hidden'] = (bool) $ov['hidden'];
			}
			if ( isset( $ov['login_required'] ) ) {
				$tab['login_required'] = (bool) $ov['login_required'];
			}
		}
		unset( $tab );

		usort(
			$tabs,
			fn( array $a, array $b ) => ( $a['order'] ?? 10 ) <=> ( $b['order'] ?? 10 )
		);

		return $tabs;
	}

	/**
	 * Return the slug of the currently active main-nav tab.
	 *
	 * Reads the `bn_nav_scope` request parameter (set by JS when the admin
	 * clicks a scope in the sidebar). Falls back to the first tab slug.
	 *
	 * @return string Active tab slug.
	 */
	public function get_active_tab(): string {
		$tabs = $this->get_tabs();
		if ( empty( $tabs ) ) {
			return '';
		}
		$requested = sanitize_key( (string) ( $_GET['bn_nav_scope'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( $tabs as $tab ) {
			if ( sanitize_key( (string) ( $tab['slug'] ?? '' ) ) === $requested ) {
				return $requested;
			}
		}
		return sanitize_key( (string) ( $tabs[0]['slug'] ?? '' ) );
	}

	/**
	 * Return the sorted, override-applied tab list for any scope.
	 *
	 * Applies the relevant filter and merges admin overrides.
	 *
	 * @param string $scope One of: main, profile, space, mobile.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_tabs_for_scope( string $scope ): array {
		switch ( $scope ) {
			case 'profile':
				$defaults = $this->default_profile_tabs();
				break;
			case 'space':
				$defaults = $this->default_space_tabs();
				break;
			case 'mobile':
				$defaults = $this->default_mobile_tabs();
				break;
			default:
				$defaults = $this->default_tabs();
				break;
		}

		$overrides = $this->get_overrides_for_scope( $scope );

		foreach ( $defaults as &$tab ) {
			if ( ! isset( $tab['order'] ) ) {
				$tab['order'] = 10;
			}
			$slug = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
			if ( ! isset( $overrides[ $slug ] ) ) {
				continue;
			}
			$ov = $overrides[ $slug ];
			foreach ( array( 'label', 'icon', 'visibility', 'capability', 'guest_label' ) as $field ) {
				if ( isset( $ov[ $field ] ) && '' !== $ov[ $field ] ) {
					$tab[ $field ] = sanitize_text_field( (string) $ov[ $field ] );
				}
			}
			if ( isset( $ov['order'] ) ) {
				$tab['order'] = (int) $ov['order'];
			}
			if ( isset( $ov['hidden'] ) ) {
				$tab['hidden'] = (bool) $ov['hidden'];
			}
			if ( isset( $ov['login_required'] ) ) {
				$tab['login_required'] = (bool) $ov['login_required'];
			}
		}
		unset( $tab );

		// Append custom tabs stored in overrides.
		foreach ( $overrides as $slug => $ov ) {
			if ( empty( $ov['custom'] ) ) {
				continue;
			}
			// Skip if already in defaults.
			$already = false;
			foreach ( $defaults as $t ) {
				if ( sanitize_key( (string) ( $t['slug'] ?? '' ) ) === $slug ) {
					$already = true;
					break;
				}
			}
			if ( $already ) {
				continue;
			}
			$defaults[] = array(
				'slug'        => $slug,
				'label'       => sanitize_text_field( (string) ( $ov['label'] ?? $slug ) ),
				'order'       => (int) ( $ov['order'] ?? 99 ),
				'icon'        => 'tab-custom',
				'description' => sanitize_text_field( (string) ( $ov['description'] ?? '' ) ),
				'capability'  => sanitize_text_field( (string) ( $ov['capability'] ?? 'read' ) ),
				'url'         => esc_url_raw( (string) ( $ov['url'] ?? '' ) ),
				'hidden'      => (bool) ( $ov['hidden'] ?? false ),
				'custom'      => true,
			);
		}

		usort(
			$defaults,
			fn( array $a, array $b ) => ( $a['order'] ?? 10 ) <=> ( $b['order'] ?? 10 )
		);

		return $defaults;
	}

	/**
	 * Built-in profile page tabs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function default_profile_tabs(): array {
		return array(
			array(
				'slug'        => 'about',
				'label'       => __( 'About', 'buddynext' ),
				'order'       => 10,
				'icon'        => 'tab-about',
				'description' => __( 'Bio, location, and profile fields', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'activity',
				'label'       => __( 'Activity', 'buddynext' ),
				'order'       => 20,
				'icon'        => 'tab-feed',
				'description' => __( 'Member\'s public posts', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'connections',
				'label'       => __( 'Connections', 'buddynext' ),
				'order'       => 30,
				'icon'        => 'tab-connections',
				'description' => __( 'Followers and following', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'media',
				'label'       => __( 'Media', 'buddynext' ),
				'order'       => 40,
				'icon'        => 'tab-media',
				'description' => __( 'Photos and videos shared by this member', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'badges',
				'label'       => __( 'Badges', 'buddynext' ),
				'order'       => 50,
				'icon'        => 'tab-badges',
				'description' => __( 'Earned badges and achievements', 'buddynext' ),
				'capability'  => 'read',
			),
		);
	}

	/**
	 * Built-in space detail page tabs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function default_space_tabs(): array {
		return array(
			array(
				'slug'        => 'feed',
				'label'       => __( 'Feed', 'buddynext' ),
				'order'       => 10,
				'icon'        => 'tab-feed',
				'description' => __( 'Posts and activity inside this space', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'members',
				'label'       => __( 'Members', 'buddynext' ),
				'order'       => 20,
				'icon'        => 'tab-connections',
				'description' => __( 'Members of this space', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'media',
				'label'       => __( 'Media', 'buddynext' ),
				'order'       => 30,
				'icon'        => 'tab-media',
				'description' => __( 'Media shared inside this space', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'about',
				'label'       => __( 'About', 'buddynext' ),
				'order'       => 40,
				'icon'        => 'tab-about',
				'description' => __( 'Space description and details', 'buddynext' ),
				'capability'  => 'read',
			),
		);
	}

	/**
	 * Built-in mobile bottom navigation items.
	 *
	 * Shares slugs with the main nav so page assignments are inherited.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function default_mobile_tabs(): array {
		return array(
			array(
				'slug'        => 'feed',
				'label'       => __( 'Home', 'buddynext' ),
				'order'       => 10,
				'icon'        => 'tab-feed',
				'description' => __( 'Home feed (inherits main nav page)', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'explore',
				'label'       => __( 'Explore', 'buddynext' ),
				'order'       => 20,
				'icon'        => 'tab-explore',
				'description' => __( 'Explore public content', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'spaces',
				'label'       => __( 'Spaces', 'buddynext' ),
				'order'       => 30,
				'icon'        => 'tab-spaces',
				'description' => __( 'Browse spaces', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'notifications',
				'label'       => __( 'Alerts', 'buddynext' ),
				'order'       => 40,
				'icon'        => 'tab-notifications',
				'description' => __( 'Notification badge', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'messages',
				'label'       => __( 'Messages', 'buddynext' ),
				'order'       => 50,
				'icon'        => 'tab-messages',
				'description' => __( 'Direct messages badge', 'buddynext' ),
				'capability'  => 'read',
			),
		);
	}

	// ── AdminPageBase interface ────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Navigation Manager', 'buddynext' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return __( 'Configure page assignments, reorder tabs, and control visibility for each navigation hub.', 'buddynext' );
	}

	/**
	 * Render the three-panel navigation manager page.
	 *
	 * @return void
	 */
	protected function render_content(): void {
		$main_tabs    = $this->get_tabs_for_scope( 'main' );
		$profile_tabs = $this->get_tabs_for_scope( 'profile' );
		$space_tabs   = $this->get_tabs_for_scope( 'space' );
		$mobile_tabs  = $this->get_tabs_for_scope( 'mobile' );
		$first_slug   = ! empty( $main_tabs ) ? sanitize_key( (string) ( $main_tabs[0]['slug'] ?? '' ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( (string) wp_unslash( $_GET['bn_notice'] ?? '' ) );

		if ( 'saved' === $notice ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Navigation settings saved.', 'buddynext' ); ?></p>
			</div>
			<?php
		} elseif ( 'page_conflict' === $notice ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Save failed: two or more hubs cannot share the same WordPress page. Please assign a unique page to each hub.', 'buddynext' ); ?></p>
			</div>
			<?php
		}
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bn-nav-form">
			<?php wp_nonce_field( 'bn_save_nav' ); ?>
			<input type="hidden" name="action" value="bn_save_nav">

			<div class="bn-three-panel">

				<?php $this->render_scope_sidebar(); ?>

				<div class="bn-nav-main-panel">

					<div class="bn-nav-page-header">
						<div>
							<h2 class="bn-nav-page-title"><?php esc_html_e( 'Navigation Manager', 'buddynext' ); ?></h2>
							<p class="bn-nav-page-desc"><?php esc_html_e( 'Drag to reorder. Toggle to show/hide. Click ⚙ to assign a WordPress page and configure visibility.', 'buddynext' ); ?></p>
						</div>
						<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
							<?php esc_html_e( 'Save Changes', 'buddynext' ); ?>
						</button>
					</div>

					<!-- Main Navigation scope panel -->
					<div class="bn-scope-panel" data-scope-panel="main">
						<?php $this->render_nav_section( 'main', $main_tabs, __( 'Main Navigation', 'buddynext' ), __( '— Community Nav Bar', 'buddynext' ) ); ?>
					</div>

					<!-- Profile Tabs scope panel -->
					<div class="bn-scope-panel" data-scope-panel="profile" hidden>
						<?php $this->render_nav_section( 'profile', $profile_tabs, __( 'Profile Tabs', 'buddynext' ), __( '— Member profile pages', 'buddynext' ) ); ?>
					</div>

					<!-- Space Tabs scope panel -->
					<div class="bn-scope-panel" data-scope-panel="space" hidden>
						<?php $this->render_nav_section( 'space', $space_tabs, __( 'Space Tabs', 'buddynext' ), __( '— Space detail pages', 'buddynext' ) ); ?>
					</div>

					<!-- Mobile Bottom Nav scope panel -->
					<div class="bn-scope-panel" data-scope-panel="mobile" hidden>
						<?php $this->render_nav_section( 'mobile', $mobile_tabs, __( 'Mobile Bottom Nav', 'buddynext' ), __( '— Bottom navigation bar', 'buddynext' ) ); ?>
						<?php $this->render_mobile_note(); ?>
					</div>

				</div><!-- /.bn-nav-main-panel -->

				<div class="bn-nav-config-panel">
					<div data-config-scope="main">
						<?php $this->render_all_config_panels( 'main', $main_tabs, $first_slug ); ?>
					</div>
					<div data-config-scope="profile" hidden>
						<?php
						$prof_first = ! empty( $profile_tabs ) ? sanitize_key( (string) ( $profile_tabs[0]['slug'] ?? '' ) ) : '';
						$this->render_all_config_panels( 'profile', $profile_tabs, $prof_first );
						?>
					</div>
					<div data-config-scope="space" hidden>
						<?php
						$space_first = ! empty( $space_tabs ) ? sanitize_key( (string) ( $space_tabs[0]['slug'] ?? '' ) ) : '';
						$this->render_all_config_panels( 'space', $space_tabs, $space_first );
						?>
					</div>
					<div data-config-scope="mobile" hidden>
						<?php
						$mob_first = ! empty( $mobile_tabs ) ? sanitize_key( (string) ( $mobile_tabs[0]['slug'] ?? '' ) ) : '';
						$this->render_all_config_panels( 'mobile', $mobile_tabs, $mob_first );
						?>
					</div>
				</div>

			</div><!-- /.bn-three-panel -->

			<?php $this->render_hub_page_assignments(); ?>

		</form>
		<?php
	}

	// ── Render: scope sidebar ─────────────────────────────────────────────────

	/**
	 * Render the left-hand Navigation Scope selector sidebar.
	 *
	 * @return void
	 */
	private function render_scope_sidebar(): void {
		?>
		<div class="bn-nav-scope-sidebar">
			<div class="bn-scope-header"><?php esc_html_e( 'Navigation Scope', 'buddynext' ); ?></div>
			<div class="bn-scope-item bn-scope-active" data-scope="main" role="button" tabindex="0">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read from plugin file.
				echo $this->svg( 'scope-main' );
				?>
				<?php esc_html_e( 'Main Navigation', 'buddynext' ); ?>
			</div>
			<div class="bn-scope-item" data-scope="profile" role="button" tabindex="0">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read from plugin file.
				echo $this->svg( 'scope-profile' );
				?>
				<?php esc_html_e( 'Profile Tabs', 'buddynext' ); ?>
			</div>
			<div class="bn-scope-item" data-scope="space" role="button" tabindex="0">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read from plugin file.
				echo $this->svg( 'scope-space' );
				?>
				<?php esc_html_e( 'Space Tabs', 'buddynext' ); ?>
			</div>
			<div class="bn-scope-item" data-scope="mobile" role="button" tabindex="0">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read from plugin file.
				echo $this->svg( 'scope-mobile' );
				?>
				<?php esc_html_e( 'Mobile Bottom Nav', 'buddynext' ); ?>
			</div>
			<div class="bn-scope-tip">
				<?php
				echo wp_kses(
					__( 'Any plugin can inject tabs into any scope using filters — <code>buddynext_main_nav_items</code>, <code>buddynext_profile_tabs</code>, <code>buddynext_space_tabs</code>.', 'buddynext' ),
					array( 'code' => array() )
				);
				?>
			</div>
		</div>
		<?php
	}

	// ── Render: main nav section ──────────────────────────────────────────────

	/**
	 * Render a nav section card with the sortable tab list for a scope.
	 *
	 * @param string                           $scope Scope slug (main|profile|space|mobile).
	 * @param array<int, array<string, mixed>> $tabs  Ordered tab list.
	 * @param string                           $title Section heading.
	 * @param string                           $sub   Subtitle shown next to the heading.
	 * @return void
	 */
	private function render_nav_section( string $scope, array $tabs, string $title, string $sub ): void {
		$count   = count( $tabs );
		$list_id = "bn-nav-sortable-{$scope}";
		?>
		<div class="bn-nav-section">
			<div class="bn-nav-section-header">
				<div class="bn-nav-section-title">
					<?php echo esc_html( $title ); ?>
					<span class="bn-nav-section-sub"><?php echo esc_html( $sub ); ?></span>
				</div>
				<div class="bn-nav-section-badge">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of items */
							_n( '%d item', '%d items', $count, 'buddynext' ),
							$count
						)
					);
					?>
				</div>
			</div>

			<ul class="bn-nav-list" id="<?php echo esc_attr( $list_id ); ?>">
				<?php foreach ( $tabs as $idx => $tab ) : ?>
					<?php $this->render_nav_row( $scope, $tab, $idx ); ?>
				<?php endforeach; ?>
			</ul>

			<div class="bn-nav-add-row">
				<button type="button" class="bn-add-tab-btn"
					data-scope="<?php echo esc_attr( $scope ); ?>"
					data-action="bn-open-add-tab"
					aria-label="<?php esc_attr_e( 'Add a custom tab to this scope', 'buddynext' ); ?>">
					<span aria-hidden="true">+</span>
					<?php esc_html_e( 'Add Custom Tab', 'buddynext' ); ?>
				</button>
			</div>

			<?php $this->render_add_tab_form( $scope ); ?>
		</div>
		<?php
	}

	/**
	 * Render a single nav item row inside the sortable list.
	 *
	 * @param string               $scope Scope slug.
	 * @param array<string, mixed> $tab   Resolved tab entry.
	 * @param int                  $idx   Zero-based index used for input names.
	 * @return void
	 */
	private function render_nav_row( string $scope, array $tab, int $idx ): void {
		$slug    = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
		$label   = (string) ( $tab['label'] ?? '' );
		$icon    = (string) ( $tab['icon'] ?? '' );
		$hidden  = ! empty( $tab['hidden'] );
		$is_core = empty( $tab['custom'] );
		$row_id  = 'bn-row-' . $scope . '-' . $slug;
		?>
		<li class="bn-drag-row"
			data-slug="<?php echo esc_attr( $slug ); ?>"
			data-scope="<?php echo esc_attr( $scope ); ?>"
			id="<?php echo esc_attr( $row_id ); ?>"
			<?php echo $hidden ? 'data-row-hidden' : ''; ?>>

			<input type="hidden"
					name="bn_nav_slug[<?php echo esc_attr( $scope ); ?>][<?php echo esc_attr( (string) $idx ); ?>]"
					value="<?php echo esc_attr( $slug ); ?>">

			<button type="button"
					class="bn-drag-row__handle"
					aria-label="<?php esc_attr_e( 'Drag to reorder', 'buddynext' ); ?>"
					title="<?php esc_attr_e( 'Drag to reorder', 'buddynext' ); ?>">
				<span></span>
			</button>

			<div class="bn-nav-row-icon" aria-hidden="true">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read from plugin file.
				echo $this->svg( $icon );
				?>
			</div>

			<div class="bn-drag-row__body">
				<div class="bn-nav-row-name">
					<?php echo esc_html( $label ); ?>
					<?php if ( $is_core ) : ?>
						<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Core', 'buddynext' ); ?></span>
					<?php else : ?>
						<span class="bn-badge" data-tone="accent"><?php esc_html_e( 'Custom', 'buddynext' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="bn-nav-row-desc"><?php echo esc_html( (string) ( $tab['description'] ?? '' ) ); ?></div>
			</div>

			<div class="bn-drag-row__actions">
				<button type="button"
						class="bn-config-btn"
						data-scope="<?php echo esc_attr( $scope ); ?>"
						data-slug="<?php echo esc_attr( $slug ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: tab label */ __( 'Configure %s', 'buddynext' ), $label ) ); ?>">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read from plugin file.
					echo $this->svg( 'icon-config' );
					?>
				</button>
				<label class="bn-toggle-wrap"
						title="<?php echo $hidden ? esc_attr__( 'Hidden, click to show', 'buddynext' ) : esc_attr__( 'Visible, click to hide', 'buddynext' ); ?>">
					<input type="checkbox"
							class="bn-toggle-input screen-reader-text"
							name="bn_nav_visible[<?php echo esc_attr( $scope ); ?>][<?php echo esc_attr( $slug ); ?>]"
							value="1"
							<?php checked( ! $hidden ); ?>>
					<span class="bn-toggle<?php echo ! $hidden ? ' bn-toggle-on' : ''; ?>"
							role="switch"
							aria-checked="<?php echo $hidden ? 'false' : 'true'; ?>"
							aria-hidden="true"></span>
					<span class="screen-reader-text">
						<?php echo $hidden ? esc_html__( 'Show tab', 'buddynext' ) : esc_html__( 'Hide tab', 'buddynext' ); ?>
					</span>
				</label>
			</div>
		</li>
		<?php
	}

	/**
	 * Render the standalone hub page assignments section.
	 *
	 * Shows a simple page-picker row for each hub that has no main-nav tab
	 * (Member Directory and Login/Register). Conflict validation in
	 * handle_save_nav() covers these alongside the nav-tab page options.
	 *
	 * @return void
	 */
	private function render_hub_page_assignments(): void {
		$hubs = array(
			'people' => array(
				'label'       => __( 'Member Directory', 'buddynext' ),
				'description' => __( 'Page that renders the member directory and individual profile URLs.', 'buddynext' ),
				'option'      => 'buddynext_page_people',
			),
			'auth'   => array(
				'label'       => __( 'Login / Register', 'buddynext' ),
				'description' => __( 'Page that renders the login, registration, and password reset forms.', 'buddynext' ),
				'option'      => 'buddynext_page_auth',
			),
		);
		?>
		<div class="bn-nav-section">
			<div class="bn-nav-section-header">
				<div class="bn-nav-section-title"><?php esc_html_e( 'Hub Page Assignments', 'buddynext' ); ?></div>
				<div class="bn-nav-section-desc"><?php esc_html_e( 'Assign WordPress pages to the hubs below. These hubs do not appear in the main navigation bar.', 'buddynext' ); ?></div>
			</div>
			<div class="bn-hub-pages-list">
				<?php foreach ( $hubs as $slug => $hub ) : ?>
				<div class="bn-hub-page-row">
					<div class="bn-hub-page-info">
						<span class="bn-hub-page-label"><?php echo esc_html( $hub['label'] ); ?></span>
						<span class="bn-hub-page-desc"><?php echo esc_html( $hub['description'] ); ?></span>
					</div>
					<div class="bn-hub-page-picker">
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'bn_nav_config[main][' . esc_attr( $slug ) . '][page_id]',
								'id'                => 'bn-hub-page-' . esc_attr( $slug ),
								'selected'          => (int) get_option( $hub['option'], 0 ),
								'show_option_none'  => esc_html__( '— Select a page —', 'buddynext' ),
								'option_none_value' => '0',
							)
						);
						?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	// ── Render: config panels ─────────────────────────────────────────────────

	/**
	 * Render all per-tab config panels for a scope, hiding all but the active one.
	 *
	 * Each panel is pre-rendered and shown/hidden via JS when the user clicks
	 * the config button on the corresponding nav row.
	 *
	 * @param string                           $scope      Scope slug.
	 * @param array<int, array<string, mixed>> $tabs       Ordered tab list.
	 * @param string                           $first_slug Slug of the initially visible panel.
	 * @return void
	 */
	private function render_all_config_panels( string $scope, array $tabs, string $first_slug ): void {
		foreach ( $tabs as $tab ) {
			$slug      = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
			$panel_id  = 'bn-config-' . $scope . '-' . $slug;
			$is_active = ( $slug === $first_slug );
			?>
			<div class="bn-config-card"
				id="<?php echo esc_attr( $panel_id ); ?>"
				data-scope="<?php echo esc_attr( $scope ); ?>"
				<?php echo $is_active ? '' : 'hidden'; ?>>
				<?php $this->render_config_panel_for_tab( $scope, $tab ); ?>
			</div>
			<?php
		}
	}

	/**
	 * Render the config panel body for a single tab.
	 *
	 * For main-scope core tabs that have a PAGE_OPTIONS mapping, a wp_dropdown_pages()
	 * selector is shown so the admin can assign (or reassign) the WordPress
	 * page that serves this hub.
	 *
	 * @param string               $scope Scope slug.
	 * @param array<string, mixed> $tab   Resolved tab entry.
	 * @return void
	 */
	private function render_config_panel_for_tab( string $scope, array $tab ): void {
		$slug        = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
		$label       = (string) ( $tab['label'] ?? '' );
		$icon        = (string) ( $tab['icon'] ?? '' );
		$order       = (int) ( $tab['order'] ?? 10 );
		$visibility  = (string) ( $tab['visibility'] ?? 'all' );
		$capability  = (string) ( $tab['capability'] ?? 'read' );
		$login_req   = ! empty( $tab['login_required'] );
		$guest_label = (string) ( $tab['guest_label'] ?? '' );
		$is_core     = empty( $tab['custom'] );
		$page_opt    = ( 'main' === $scope ) ? ( self::PAGE_OPTIONS[ $slug ] ?? '' ) : '';
		$page_id     = $page_opt ? (int) get_option( $page_opt, 0 ) : 0;
		$slug_opt    = ( 'main' === $scope ) ? ( self::SLUG_OPTIONS[ $slug ] ?? '' ) : '';
		$url_slug    = $slug_opt ? (string) get_option( $slug_opt, '' ) : '';

		// Helper: generate scope-namespaced input name for this tab's config.
		$n = static function ( string $field ) use ( $scope, $slug ): string {
			return 'bn_nav_config[' . $scope . '][' . $slug . '][' . $field . ']';
		};
		?>
		<div class="bn-config-header">
			<div class="bn-config-breadcrumb"><?php echo esc_html( ucwords( str_replace( '-', ' ', $scope ) ) ); ?> &rsaquo;</div>
			<div class="bn-config-title">
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read from plugin file.
			echo $this->svg( $icon );
			?>
			<?php echo esc_html( $label ); ?>
		</div>
		</div>

		<div class="bn-config-body">

			<div class="bn-cf">
				<label for="bn-cfg-label-<?php echo esc_attr( $slug ); ?>">
					<?php esc_html_e( 'Label', 'buddynext' ); ?>
				</label>
				<input type="text"
						id="bn-cfg-label-<?php echo esc_attr( $slug ); ?>"
						name="<?php echo esc_attr( $n( 'label' ) ); ?>"
						value="<?php echo esc_attr( $label ); ?>"
						maxlength="50">
			</div>

			<div class="bn-cf">
				<label for="bn-cfg-order-<?php echo esc_attr( $slug ); ?>">
					<?php esc_html_e( 'Position', 'buddynext' ); ?>
				</label>
				<input type="number"
						id="bn-cfg-order-<?php echo esc_attr( $slug ); ?>"
						name="<?php echo esc_attr( $n( 'order' ) ); ?>"
						value="<?php echo esc_attr( (string) $order ); ?>"
						min="1"
						max="999"
						class="bn-cf-position-input">
			</div>

			<?php if ( '' !== $page_opt ) : ?>
			<div class="bn-cf">
				<label for="bn-cfg-page-<?php echo esc_attr( $slug ); ?>">
					<?php esc_html_e( 'WordPress Page', 'buddynext' ); ?>
				</label>
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes its own output; $n() returns a sanitized string; $slug is sanitize_key'd; $page_id is absint'd.
				wp_dropdown_pages(
					array(
						'name'              => $n( 'page_id' ),
						'id'                => 'bn-cfg-page-' . $slug,
						'selected'          => $page_id,
						'show_option_none'  => __( '— Auto (installer default) —', 'buddynext' ),
						'option_none_value' => '0',
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<span class="bn-cf-hint">
					<?php esc_html_e( 'The WordPress page that serves this hub. Each page can only be assigned to one hub.', 'buddynext' ); ?>
				</span>
			</div>
			<?php endif; ?>

			<?php if ( '' !== $slug_opt ) : ?>
			<div class="bn-cf">
				<label for="bn-cfg-urlslug-<?php echo esc_attr( $slug ); ?>">
					<?php esc_html_e( 'URL Slug', 'buddynext' ); ?>
				</label>
				<input type="text"
						id="bn-cfg-urlslug-<?php echo esc_attr( $slug ); ?>"
						name="<?php echo esc_attr( $n( 'url_slug' ) ); ?>"
						value="<?php echo esc_attr( $url_slug ); ?>"
						maxlength="80"
						pattern="[a-z0-9\-]+"
						title="<?php esc_attr_e( 'Lowercase letters, numbers, and hyphens only.', 'buddynext' ); ?>"
						class="bn-cf-url-slug-input">
				<span class="bn-cf-hint">
					<?php esc_html_e( 'URL path for this hub, e.g. "members" → /members/. Saving flushes rewrite rules automatically.', 'buddynext' ); ?>
				</span>
			</div>
			<?php endif; ?>

			<div class="bn-cf">
				<label for="bn-cfg-vis-<?php echo esc_attr( $slug ); ?>">
					<?php esc_html_e( 'Visibility', 'buddynext' ); ?>
				</label>
				<select id="bn-cfg-vis-<?php echo esc_attr( $slug ); ?>"
						name="<?php echo esc_attr( $n( 'visibility' ) ); ?>">
					<option value="all" <?php selected( $visibility, 'all' ); ?>>
						<?php esc_html_e( 'All members', 'buddynext' ); ?>
					</option>
					<option value="logged_in" <?php selected( $visibility, 'logged_in' ); ?>>
						<?php esc_html_e( 'Logged-in only', 'buddynext' ); ?>
					</option>
					<option value="admins" <?php selected( $visibility, 'admins' ); ?>>
						<?php esc_html_e( 'Admins only', 'buddynext' ); ?>
					</option>
					<option value="cap" <?php selected( $visibility, 'cap' ); ?>>
						<?php esc_html_e( 'Custom capability', 'buddynext' ); ?>
					</option>
				</select>
			</div>

			<div class="bn-cf">
				<label for="bn-cfg-cap-<?php echo esc_attr( $slug ); ?>">
					<?php esc_html_e( 'Required Capability', 'buddynext' ); ?>
				</label>
				<input type="text"
						id="bn-cfg-cap-<?php echo esc_attr( $slug ); ?>"
						name="<?php echo esc_attr( $n( 'capability' ) ); ?>"
						value="<?php echo esc_attr( $capability ); ?>"
						maxlength="80">
			</div>

			<div class="bn-config-divider"></div>

			<div class="bn-cf">
				<div class="bn-config-toggle-row">
					<div>
						<div class="bn-config-toggle-label">
							<?php esc_html_e( 'Login required', 'buddynext' ); ?>
						</div>
						<div class="bn-config-toggle-sub">
							<?php esc_html_e( 'Redirect guests to login page', 'buddynext' ); ?>
						</div>
					</div>
					<input type="checkbox"
							class="bn-cfg-toggle-chk"
							name="<?php echo esc_attr( $n( 'login_required' ) ); ?>"
							value="1"
							<?php checked( $login_req ); ?>>
				</div>
			</div>

			<div class="bn-cf">
				<label for="bn-cfg-guest-<?php echo esc_attr( $slug ); ?>">
					<?php esc_html_e( 'Guest Label', 'buddynext' ); ?>
				</label>
				<input type="text"
						id="bn-cfg-guest-<?php echo esc_attr( $slug ); ?>"
						name="<?php echo esc_attr( $n( 'guest_label' ) ); ?>"
						value="<?php echo esc_attr( $guest_label ); ?>"
						placeholder="<?php esc_attr_e( 'Shown to guests instead', 'buddynext' ); ?>"
						maxlength="50">
				<span class="bn-cf-hint">
					<?php esc_html_e( 'Shown in nav when user is not logged in.', 'buddynext' ); ?>
				</span>
			</div>

			<?php if ( $is_core ) : ?>
			<div class="bn-config-divider"></div>
			<div class="bn-cf">
				<div class="bn-config-note">
					<strong><?php esc_html_e( 'Core tab', 'buddynext' ); ?></strong>
					<?php esc_html_e( '— Cannot be removed, only hidden.', 'buddynext' ); ?>
				</div>
			</div>
			<?php endif; ?>

		</div><!-- /.bn-config-body -->
		<?php
	}

	// ── Render: mobile note + add-tab form ──────────────────────────────────

	/**
	 * Render the mobile bottom nav note (max 5 visible items).
	 *
	 * @return void
	 */
	private function render_mobile_note(): void {
		?>
		<div class="bn-nav-section">
			<div class="bn-nav-section-header">
				<div class="bn-nav-section-title"><?php esc_html_e( 'Mobile Nav Note', 'buddynext' ); ?></div>
			</div>
			<p class="bn-mobile-note">
				<?php esc_html_e( 'Only the top 5 visible items are displayed in the mobile bottom bar. Drag to reorder; toggle to include or exclude.', 'buddynext' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the hidden inline "Add Custom Tab" form for a scope.
	 *
	 * Shown/hidden by JS; submitted with the main form so PHP reads
	 * bn_new_tab[scope] and merges into overrides on save.
	 *
	 * @param string $scope Scope slug.
	 * @return void
	 */
	private function render_add_tab_form( string $scope ): void {
		$form_id = 'bn-add-tab-form-' . sanitize_key( $scope );
		?>
		<div id="<?php echo esc_attr( $form_id ); ?>" class="bn-add-tab-inline" hidden>
			<div class="bn-add-tab-inline-inner">
				<div class="bn-cf">
					<label for="bn-new-tab-label-<?php echo esc_attr( $scope ); ?>">
						<?php esc_html_e( 'Tab Label', 'buddynext' ); ?>
					</label>
					<input type="text"
						id="bn-new-tab-label-<?php echo esc_attr( $scope ); ?>"
						name="bn_new_tab[<?php echo esc_attr( $scope ); ?>][label]"
						placeholder="<?php esc_attr_e( 'e.g. Resources', 'buddynext' ); ?>"
						maxlength="50">
				</div>
				<div class="bn-cf">
					<label for="bn-new-tab-url-<?php echo esc_attr( $scope ); ?>">
						<?php esc_html_e( 'URL', 'buddynext' ); ?>
					</label>
					<input type="url"
						id="bn-new-tab-url-<?php echo esc_attr( $scope ); ?>"
						name="bn_new_tab[<?php echo esc_attr( $scope ); ?>][url]"
						placeholder="<?php esc_attr_e( 'https://...', 'buddynext' ); ?>">
				</div>
				<div class="bn-add-tab-inline-actions">
					<button type="submit" class="button button-primary button-small">
						<?php esc_html_e( 'Add Tab', 'buddynext' ); ?>
					</button>
					<button type="button" class="button button-small bn-cancel-add-tab"
						data-scope="<?php echo esc_attr( $scope ); ?>">
						<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Admin-post handler ─────────────────────────────────────────────────────

	/**
	 * Handle admin_post_bn_save_nav form submission.
	 *
	 * Validates that no two hubs share the same WordPress page (conflict
	 * check), persists page assignments to individual buddynext_page_* options,
	 * and saves all tab overrides to buddynext_nav_overrides.
	 *
	 * @return void
	 */
	public function handle_save_nav(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_save_nav' );

		// ── 1. Raw POST data ──────────────────────────────────────────────
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_visible    = (array) wp_unslash( $_POST['bn_nav_visible'] ?? array() );
		$raw_config_all = (array) wp_unslash( $_POST['bn_nav_config'] ?? array() );
		$raw_new_tabs   = (array) wp_unslash( $_POST['bn_new_tab'] ?? array() );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// ── 2. Page conflict check (main nav tabs + standalone combined) ──
		$seen_pages = array();

		$main_config = (array) ( $raw_config_all['main'] ?? array() );
		foreach ( $main_config as $slug => $cfg ) {
			$slug    = sanitize_key( (string) $slug );
			$page_id = absint( ( (array) $cfg )['page_id'] ?? 0 );
			if ( 0 === $page_id || ! isset( self::PAGE_OPTIONS[ $slug ] ) ) {
				continue;
			}
			if ( in_array( $page_id, $seen_pages, true ) ) {
				wp_safe_redirect(
					add_query_arg( 'bn_notice', 'page_conflict', admin_url( 'admin.php?page=buddynext-nav' ) )
				);
				exit;
			}
			$seen_pages[] = $page_id;
		}

		// ── 3. Persist overrides for each scope ───────────────────────────
		foreach ( self::SCOPE_OPTION_MAP as $scope => $option_key ) {
			$scope_config  = (array) ( $raw_config_all[ $scope ] ?? array() );
			$scope_visible = (array) ( $raw_visible[ $scope ] ?? array() );
			$visible_slugs = array_map( 'sanitize_key', array_keys( $scope_visible ) );

			$overrides = array();

			foreach ( $scope_config as $slug => $cfg ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug ) {
					continue;
				}
				$cfg = (array) $cfg;

				// Page assignment for main-scope core hubs only.
				if ( 'main' === $scope && isset( self::PAGE_OPTIONS[ $slug ] ) ) {
					update_option( self::PAGE_OPTIONS[ $slug ], absint( $cfg['page_id'] ?? 0 ) );
				}

				// URL slug for main-scope hubs that have a SLUG_OPTIONS mapping.
				if ( 'main' === $scope && isset( self::SLUG_OPTIONS[ $slug ] ) ) {
					$new_url_slug = sanitize_title( (string) ( $cfg['url_slug'] ?? '' ) );
					if ( '' !== $new_url_slug ) {
						update_option( self::SLUG_OPTIONS[ $slug ], $new_url_slug );
					}
				}

				$overrides[ $slug ] = array(
					'label'          => sanitize_text_field( (string) ( $cfg['label'] ?? '' ) ),
					'order'          => max( 1, absint( $cfg['order'] ?? 10 ) ),
					'hidden'         => ! in_array( $slug, $visible_slugs, true ),
					'visibility'     => sanitize_key( (string) ( $cfg['visibility'] ?? 'all' ) ),
					'capability'     => sanitize_text_field( (string) ( $cfg['capability'] ?? 'read' ) ),
					'login_required' => ! empty( $cfg['login_required'] ),
					'guest_label'    => sanitize_text_field( (string) ( $cfg['guest_label'] ?? '' ) ),
				);
			}

			// Merge in any new custom tab submitted for this scope.
			$new_tab   = (array) ( $raw_new_tabs[ $scope ] ?? array() );
			$new_label = sanitize_text_field( (string) ( $new_tab['label'] ?? '' ) );
			$new_url   = esc_url_raw( (string) ( $new_tab['url'] ?? '' ) );

			if ( '' !== $new_label ) {
				$new_slug = sanitize_key( $new_label );
				// Ensure uniqueness.
				$base    = $new_slug;
				$counter = 1;
				while ( isset( $overrides[ $new_slug ] ) ) {
					$new_slug = $base . '-' . $counter;
					++$counter;
				}
				$overrides[ $new_slug ] = array(
					'label'          => $new_label,
					'order'          => 99,
					'hidden'         => false,
					'visibility'     => 'all',
					'capability'     => 'read',
					'login_required' => false,
					'guest_label'    => '',
					'url'            => $new_url,
					'custom'         => true,
				);
			}

			update_option( $option_key, $overrides );
		}

		wp_safe_redirect(
			add_query_arg( 'bn_notice', 'saved', admin_url( 'admin.php?page=buddynext-nav' ) )
		);
		exit;
	}
	// ── Slug conflict detection ───────────────────────────────────────────────

	/**
	 * Check whether a proposed hub URL slug is available.
	 *
	 * Returns:
	 *   'free'  — slug is unclaimed, safe to use
	 *   'warn'  — slug matches an existing WP post/page (BN rewrite wins but
	 *             the existing page becomes unreachable via its old URL)
	 *   'block' — slug is a reserved WP keyword or claimed by another BN hub
	 *
	 * @param string $slug         Proposed slug (sanitized internally).
	 * @param string $current_hub  Hub slug making the request (excluded from
	 *                             conflict check against other hubs).
	 * @return string 'free' | 'warn' | 'block'
	 */
	public function check_slug_status( string $slug, string $current_hub ): string {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return 'block';
		}

		// 1. Reserved WordPress keywords.
		if ( in_array( $slug, self::RESERVED_SLUGS, true ) ) {
			return 'block';
		}

		// 2. Another BN hub already uses this slug.
		$hub_options = array(
			'feed'          => 'buddynext_slug_activity',
			'people'        => 'buddynext_slug_people',
			'spaces'        => 'buddynext_slug_spaces',
			'messages'      => 'buddynext_slug_messages',
			'notifications' => 'buddynext_slug_notifications',
			'auth'          => 'buddynext_slug_auth',
		);

		foreach ( $hub_options as $hub => $option ) {
			if ( $hub === $current_hub ) {
				continue;
			}
			$existing = (string) get_option( $option, '' );
			if ( '' !== $existing && $existing === $slug ) {
				return 'block';
			}
		}

		// 3. Existing WP post or page has this slug.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				  WHERE post_name   = %s
				    AND post_status = 'publish'
				    AND post_type   IN ('post', 'page')",
				$slug
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $count > 0 ) {
			return 'warn';
		}

		return 'free';
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Return the inline SVG markup for a named admin icon.
	 *
	 * Reads from assets/svg/admin/{name}.svg. Returns an empty string if the
	 * file does not exist so missing icons fail silently.
	 *
	 * @param string $name Filename without extension, e.g. 'scope-main'.
	 * @return string Safe SVG markup (already sanitised at authoring time).
	 */
	private function svg( string $name ): string {
		$path = self::SVG_DIR . sanitize_file_name( $name ) . '.svg';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (string) file_get_contents( $path );
	}

	/**
	 * Return persisted overrides for a given scope, keyed by tab slug.
	 *
	 * @param string $scope One of: main, profile, space, mobile.
	 * @return array<string, array<string, mixed>>
	 */
	private function get_overrides_for_scope( string $scope ): array {
		$option = self::SCOPE_OPTION_MAP[ $scope ] ?? self::SCOPE_OPTION_MAP['main'];
		return (array) get_option( $option, array() );
	}

	/**
	 * Return persisted overrides for the main scope (backward-compat helper).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_overrides(): array {
		return $this->get_overrides_for_scope( 'main' );
	}

	/**
	 * Return the built-in BuddyNext main navigation tabs.
	 *
	 * These match the five core rows shown in admin-nav-manager.html.
	 * The 'explore' tab shares the activity page (uses #explore anchor);
	 * it therefore has no PAGE_OPTIONS entry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function default_tabs(): array {
		return array(
			array(
				'slug'        => 'feed',
				'label'       => __( 'Home Feed', 'buddynext' ),
				'order'       => 10,
				'icon'        => 'tab-feed',
				'description' => __( 'Main community feed', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'explore',
				'label'       => __( 'Explore', 'buddynext' ),
				'order'       => 20,
				'icon'        => 'tab-explore',
				'description' => __( 'Discover public content', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'spaces',
				'label'       => __( 'Spaces', 'buddynext' ),
				'order'       => 30,
				'icon'        => 'tab-spaces',
				'description' => __( 'Browse and manage spaces', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'messages',
				'label'       => __( 'Messages', 'buddynext' ),
				'order'       => 40,
				'icon'        => 'tab-messages',
				'description' => __( 'Direct messages', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'notifications',
				'label'       => __( 'Notifications', 'buddynext' ),
				'order'       => 50,
				'icon'        => 'tab-notifications',
				'description' => __( 'Activity notifications', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'people',
				'label'       => __( 'Member Directory', 'buddynext' ),
				'order'       => 60,
				'icon'        => 'tab-people',
				'description' => __( 'Page that renders the member directory and individual profile URLs.', 'buddynext' ),
				'capability'  => 'read',
				'hidden'      => true,
			),
			array(
				'slug'        => 'auth',
				'label'       => __( 'Login / Register', 'buddynext' ),
				'order'       => 70,
				'icon'        => 'tab-auth',
				'description' => __( 'Page that renders the login, registration, and password reset forms.', 'buddynext' ),
				'capability'  => 'read',
				'hidden'      => true,
			),
		);
	}
}
