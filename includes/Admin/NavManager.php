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

		// Primary action lives in the standardized AdminHub sub-header. The button
		// sits outside #bn-nav-form, so it carries the HTML5 `form` attribute to
		// still submit that form.
		$save_action = sprintf(
			'<button type="submit" form="bn-nav-form" class="bn-btn" data-variant="primary" data-size="md">%s</button>',
			esc_html__( 'Save Changes', 'buddynext' )
		);

		AdminHub::register_tab(
			'settings',
			'navigation',
			__( 'Navigation', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'group'    => __( 'Advanced', 'buddynext' ),
				'layout'   => 'wide', // List-detail editor needs edge-to-edge room.
				'subtitle' => $this->get_subtitle(),
				'action'   => $save_action,
			)
		);

		// Pages & URLs — the single place to set every hub's URL slug (the
		// canonical route) and, optionally, back it with a WordPress page.
		add_action( 'admin_post_bn_save_hub_pages', array( $this, 'handle_save_hub_pages' ) );

		AdminHub::register_tab(
			'settings',
			'pages',
			__( 'Pages & URLs', 'buddynext' ),
			array( $this, 'render_pages_tab' ),
			array(
				'position' => 35,
				'subtitle' => __( 'Set the URL for each community hub. Hubs are virtual routes by default; optionally back one with a WordPress page.', 'buddynext' ),
			)
		);
	}

	/**
	 * The full hub catalogue for the Pages & URLs tab.
	 *
	 * Each hub has a URL slug option (the canonical route) and a page option
	 * (an optional WordPress page that backs it). Explore is a special case: it
	 * renders as a view under the Activity feed and has no slug of its own, so
	 * it exposes only the optional backing-page override.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function page_hub_catalogue(): array {
		$catalogue = array(
			'feed'          => array(
				'label'    => __( 'Activity feed', 'buddynext' ),
				'desc'     => __( 'The main community feed — your community home.', 'buddynext' ),
				'slug_opt' => 'buddynext_slug_activity',
				'page_opt' => 'buddynext_page_activity',
				'default'  => 'activity',
			),
			'spaces'        => array(
				'label'    => __( 'Spaces', 'buddynext' ),
				'desc'     => __( 'Group/community spaces directory.', 'buddynext' ),
				'slug_opt' => 'buddynext_slug_spaces',
				'page_opt' => 'buddynext_page_spaces',
				'default'  => 'spaces',
			),
			'people'        => array(
				'label'    => __( 'Members directory', 'buddynext' ),
				'desc'     => __( 'Member directory and individual profile URLs.', 'buddynext' ),
				'slug_opt' => 'buddynext_slug_people',
				'page_opt' => 'buddynext_page_people',
				'default'  => 'members',
			),
			'messages'      => array(
				'label'    => __( 'Messages', 'buddynext' ),
				'desc'     => __( 'Direct messages (requires WPMediaVerse).', 'buddynext' ),
				'slug_opt' => 'buddynext_slug_messages',
				'page_opt' => 'buddynext_page_messages',
				'default'  => 'messages',
			),
			'notifications' => array(
				'label'    => __( 'Notifications', 'buddynext' ),
				'desc'     => __( 'Activity notifications.', 'buddynext' ),
				'slug_opt' => 'buddynext_slug_notifications',
				'page_opt' => 'buddynext_page_notifications',
				'default'  => 'notifications',
			),
			'auth'          => array(
				'label'    => __( 'Login / Register', 'buddynext' ),
				'desc'     => __( 'Login, registration, and password-reset forms.', 'buddynext' ),
				'slug_opt' => 'buddynext_slug_auth',
				'page_opt' => 'buddynext_page_auth',
				'default'  => 'login',
			),
			'onboarding'    => array(
				'label'    => __( 'Onboarding', 'buddynext' ),
				'desc'     => __( 'First-run member setup flow.', 'buddynext' ),
				'slug_opt' => 'buddynext_slug_onboarding',
				'page_opt' => 'buddynext_page_onboarding',
				'default'  => 'onboarding',
			),
		);

		/**
		 * Filter the community-hub catalogue shown on the Pages & URLs tab so
		 * addons can register their own routable hub (slug + optional backing page).
		 *
		 * @param array<string, array<string, string>> $catalogue Hub key => { label, desc, slug_opt, page_opt, default }.
		 */
		return (array) apply_filters( 'bn_admin_hub_pages', $catalogue );
	}

	/**
	 * Render the Pages & URLs tab: every hub's slug (canonical route) plus an
	 * optional backing WordPress page. The single place to manage hub URLs.
	 *
	 * @return void
	 */
	public function render_pages_tab(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice routing.
		$notice = isset( $_GET['bn_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['bn_notice'] ) ) : '';
		if ( 'pages_saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Pages & URLs saved.', 'buddynext' ) . '</p></div>';
		} elseif ( 'pages_conflict' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That URL slug is already used by another hub or an existing page. Nothing was saved — change the slug and try again.', 'buddynext' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-settings-form">
			<input type="hidden" name="action" value="bn_save_hub_pages">
			<?php wp_nonce_field( 'bn_save_hub_pages' ); ?>
			<div class="bn-settings-section">
				<div class="bn-ss-header"><span class="bn-ss-title"><?php esc_html_e( 'Community hubs', 'buddynext' ); ?></span></div>
				<div class="bn-ss-body">
					<p class="bn-field-hint">
						<?php esc_html_e( 'Each hub is reachable at your site URL plus its slug. Hubs are virtual routes — only assign a WordPress page if you want a page-builder layout, a real menu entry, or page-level SEO for that hub.', 'buddynext' ); ?>
					</p>
					<table class="bn-table bn-pages-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Hub', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'URL slug', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Backing page (optional)', 'buddynext' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $this->page_hub_catalogue() as $hub => $cfg ) :
							$has_slug  = '' !== $cfg['slug_opt'];
							$slug_val  = $has_slug ? (string) get_option( $cfg['slug_opt'], $cfg['default'] ) : '';
							$page_val  = (int) get_option( $cfg['page_opt'], 0 );
							$field_id  = 'bn-hub-' . sanitize_key( $hub );
							$page_live = ( $page_val > 0 && 'publish' === get_post_status( $page_val ) );
							?>
							<tr>
								<td class="bn-pages-cell--hub">
									<strong><?php echo esc_html( $cfg['label'] ); ?></strong>
									<span class="bn-field-hint"><?php echo esc_html( $cfg['desc'] ); ?></span>
								</td>
								<td class="bn-pages-cell--slug">
									<?php if ( $has_slug ) : ?>
										<input type="text"
											id="<?php echo esc_attr( $field_id . '-slug' ); ?>"
											name="bn_hub[<?php echo esc_attr( $hub ); ?>][slug]"
											value="<?php echo esc_attr( $slug_val ); ?>"
											class="bn-text-input bn-pages-slug-input"
											pattern="[a-z0-9-]+"
											spellcheck="false"
											placeholder="<?php echo esc_attr( $cfg['default'] ); ?>">
										<span class="bn-field-hint"><?php echo esc_html( trailingslashit( home_url( '/' . ( '' !== $slug_val ? $slug_val : $cfg['default'] ) ) ) ); ?></span>
									<?php else : ?>
										<span class="bn-field-hint"><?php esc_html_e( 'Renders under Activity feed', 'buddynext' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="bn-pages-cell--page">
									<?php
									// wp_dropdown_pages() echoes its markup; arg strings escaped here for WPCS.
									wp_dropdown_pages(
										array(
											'name'     => esc_attr( 'bn_hub[' . $hub . '][page_id]' ),
											'id'       => esc_attr( $field_id . '-page' ),
											'selected' => absint( $page_val ),
											'show_option_none' => esc_html__( '— None —', 'buddynext' ),
											'option_none_value' => '0',
										)
									);
									?>
									<?php if ( $page_live ) : ?>
										<span class="bn-field-hint">
											<a href="<?php echo esc_url( (string) get_permalink( $page_val ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'buddynext' ); ?></a>
											&nbsp;·&nbsp;
											<a href="<?php echo esc_url( (string) get_edit_post_link( $page_val ) ); ?>"><?php esc_html_e( 'Edit', 'buddynext' ); ?></a>
										</span>
									<?php else : ?>
										<label class="bn-check-row bn-pages-create">
											<input type="checkbox" name="bn_hub[<?php echo esc_attr( $hub ); ?>][create]" value="1">
											<?php esc_html_e( 'Create page', 'buddynext' ); ?>
										</label>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<p class="submit"><button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save Pages & URLs', 'buddynext' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Persist the Pages & URLs tab — validates slugs against reserved words,
	 * each other, and existing non-hub content (aborting the whole save on any
	 * conflict so the admin never gets a half-applied set), then writes each
	 * hub's slug + optional page option.
	 *
	 * @return void
	 */
	public function handle_save_hub_pages(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_save_hub_pages' );

		$pages_url = admin_url( 'admin.php?page=buddynext&tab=pages' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized below.
		$raw       = (array) wp_unslash( $_POST['bn_hub'] ?? array() );
		$catalogue = $this->page_hub_catalogue();
		$seen      = array();

		foreach ( $catalogue as $hub => $cfg ) {
			if ( '' === $cfg['slug_opt'] ) {
				continue;
			}
			$slug = sanitize_title( (string) ( ( (array) ( $raw[ $hub ] ?? array() ) )['slug'] ?? '' ) );
			if ( '' === $slug ) {
				$slug = $cfg['default'];
			}
			if ( in_array( $slug, self::RESERVED_SLUGS, true ) || isset( $seen[ $slug ] ) ) {
				wp_safe_redirect( add_query_arg( 'bn_notice', 'pages_conflict', $pages_url ) );
				exit;
			}
			// A page/post already owns this slug. That is only a real conflict when
			// it is some OTHER page — if the admin is (re)assigning that very page
			// as this hub's backing page, or it is already assigned, allow it.
			// Without the page_id check, you could never assign an existing page
			// whose slug matches the hub slug (e.g. a hand-made "members" page).
			$selected_page_id = absint( ( (array) ( $raw[ $hub ] ?? array() ) )['page_id'] ?? 0 );
			$existing         = get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) );
			if ( $existing instanceof \WP_Post
				&& (int) get_option( $cfg['page_opt'], 0 ) !== (int) $existing->ID
				&& $selected_page_id !== (int) $existing->ID
			) {
				wp_safe_redirect( add_query_arg( 'bn_notice', 'pages_conflict', $pages_url ) );
				exit;
			}
			$seen[ $slug ] = $hub;
		}

		foreach ( $catalogue as $hub => $cfg ) {
			$hub_data = (array) ( $raw[ $hub ] ?? array() );
			if ( '' !== $cfg['slug_opt'] ) {
				$slug = sanitize_title( (string) ( $hub_data['slug'] ?? '' ) );
				update_option( $cfg['slug_opt'], '' !== $slug ? $slug : $cfg['default'] );
			}

			$page_id = absint( $hub_data['page_id'] ?? 0 );
			// "Create a page now" — only when none is selected, so a chosen page
			// is never silently replaced by a new blank one.
			if ( 0 === $page_id && ! empty( $hub_data['create'] ) ) {
				$page_id = $this->create_hub_backing_page( (string) $cfg['label'] );
			}
			update_option( $cfg['page_opt'], $page_id );
		}

		wp_safe_redirect( add_query_arg( 'bn_notice', 'pages_saved', $pages_url ) );
		exit;
	}

	/**
	 * Create a published WordPress page to back a hub and return its ID.
	 *
	 * The hub renders through PageRouter regardless of page content, so the page
	 * is created empty — it exists only to give the hub a real WP page (menus,
	 * SEO, page-builder). Returns 0 on failure.
	 *
	 * @param string $label Hub label, used as the page title.
	 * @return int New page ID, or 0 on failure.
	 */
	private function create_hub_backing_page( string $label ): int {
		$page_id = wp_insert_post(
			array(
				'post_title'   => $label,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
			),
			true
		);
		return ( $page_id && ! is_wp_error( $page_id ) ) ? (int) $page_id : 0;
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
		// Navigation is a tab inside the Settings hub (page=buddynext,
		// tab=navigation), not a standalone page — gate on that, not the old
		// `buddynext_page_buddynext-nav` hook suffix (which never matches now, so
		// the reorder/slug JS silently failed to load).
		if ( ! AdminHub::is_active( 'settings', 'navigation' ) ) {
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

	// ── Tab registry ──────────────────────────────────────────────────────────

	/**
	 * Return the sorted, override-applied list of registered navigation tabs.
	 *
	 * This builds the catalogue the admin nav editor renders. The
	 * `buddynext_nav_tabs` filter lets third-party code inject, remove, or
	 * reorder tabs in that editor; admin overrides stored via this page are
	 * applied last and take precedence over filter output.
	 *
	 * Front-end rendering is separate: the rail, profile tab bar, space tab bar
	 * and mobile bar each apply their own per-surface filter
	 * (`buddynext_rail_items`, `buddynext_part_profile_tab_bar_args`,
	 * `buddynext_space_tabs`, `buddynext_mobile_nav_items`), and the admin
	 * overrides saved here are mirrored onto them by Nav\NavOverrides. A
	 * main-nav tab registered programmatically through `buddynext_nav_tabs`
	 * (with a `url`) is additionally surfaced on the left rail by
	 * Nav\NavOverrides::apply_rail so the documented filter reaches the front
	 * end too.
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
				// Honour the icon chosen in the picker (persisted by handle_save_nav);
				// fall back to the generic glyph only when none was saved. Previously
				// hardcoded to 'tab-custom', so the picker was a no-op for custom tabs.
				'icon'        => '' !== (string) ( $ov['icon'] ?? '' ) ? sanitize_key( (string) $ov['icon'] ) : 'tab-custom',
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
		// Slugs MUST match the tabs the profile template actually renders
		// (templates/profile/view.php → buddynext_part_profile_tab_bar_args), so
		// the front-end applier (Nav\NavOverrides::apply_profile) can map saved
		// overrides onto real tabs. The Discussions tab is bridge-injected
		// (Jetonomy) and is left to that bridge.
		return array(
			array(
				'slug'        => 'posts',
				'label'       => __( 'Posts', 'buddynext' ),
				'order'       => 10,
				'icon'        => 'tab-feed',
				'description' => __( 'Member\'s public posts', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'replies',
				'label'       => __( 'Replies', 'buddynext' ),
				'order'       => 20,
				'icon'        => 'tab-feed',
				'description' => __( 'Comments and replies the member has posted', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'media',
				'label'       => __( 'Media', 'buddynext' ),
				'order'       => 30,
				'icon'        => 'tab-media',
				'description' => __( 'Photos and videos shared by this member', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'likes',
				'label'       => __( 'Likes', 'buddynext' ),
				'order'       => 40,
				'icon'        => 'tab-feed',
				'description' => __( 'Posts the member has liked', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'followers',
				'label'       => __( 'Followers', 'buddynext' ),
				'order'       => 50,
				'icon'        => 'tab-connections',
				'description' => __( 'Members following this member', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'following',
				'label'       => __( 'Following', 'buddynext' ),
				'order'       => 60,
				'icon'        => 'tab-connections',
				'description' => __( 'Members this member follows', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'connections',
				'label'       => __( 'Connections', 'buddynext' ),
				'order'       => 70,
				'icon'        => 'tab-connections',
				'description' => __( 'Mutual connections', 'buddynext' ),
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
	 * These MUST mirror the real 5-slot bottom bar rendered by
	 * templates/partials/nav.php: feed, spaces, create, notifications, profile.
	 * Only feed / spaces / notifications are overridable (hide / relabel /
	 * visibility) through Nav\NavOverrides::apply_mobile_items(); the centre
	 * Create button and the Profile shortcut are fixed slots (Create must stay
	 * centred), so they are flagged `locked` — shown for context but not
	 * configurable. Order is intentionally fixed on mobile, so the config panel
	 * hides the Position field for this scope.
	 *
	 * Slugs are shared with the main nav so page assignments are inherited.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function default_mobile_tabs(): array {
		return array(
			array(
				'slug'        => 'feed',
				'label'       => __( 'Feed', 'buddynext' ),
				'order'       => 10,
				'icon'        => 'tab-feed',
				'description' => __( 'Home feed (inherits main nav page)', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'spaces',
				'label'       => __( 'Spaces', 'buddynext' ),
				'order'       => 20,
				'icon'        => 'tab-spaces',
				'description' => __( 'Browse spaces', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'create',
				'label'       => __( 'Create', 'buddynext' ),
				'order'       => 30,
				'icon'        => 'tab-feed',
				'description' => __( 'Centre compose button — fixed slot, always shown.', 'buddynext' ),
				'capability'  => 'read',
				'locked'      => true,
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
				'slug'        => 'profile',
				'label'       => __( 'Profile', 'buddynext' ),
				'order'       => 50,
				'icon'        => 'tab-people',
				'description' => __( 'Profile shortcut — fixed slot, always shown.', 'buddynext' ),
				'capability'  => 'read',
				'locked'      => true,
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
	 * Suppress the base subtitle paragraph.
	 *
	 * The subtitle and primary action are rendered by AdminHub's standardized
	 * sub-header bar (declared via register_tab()'s `subtitle`/`action` args),
	 * so this screen must not also emit `.bn-admin-hub__subtitle` from the base
	 * — that would duplicate the subtitle.
	 *
	 * @return void
	 */
	protected function render_page_header(): void {
		// Intentionally empty — header subtitle + action live in the AdminHub sub-header.
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
		}
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bn-nav-form">
			<?php wp_nonce_field( 'bn_save_nav' ); ?>
			<input type="hidden" name="action" value="bn_save_nav">

			<div class="bn-three-panel">

				<?php $this->render_scope_sidebar(); ?>

				<div class="bn-nav-main-panel">

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
					__( 'Any plugin can inject links into any scope using filters — <code>buddynext_rail_items</code> (left rail), <code>buddynext_part_profile_tab_bar_args</code> (profile tabs), <code>buddynext_space_tabs</code> (space tabs), <code>buddynext_context_nav</code> (sub-nav).', 'buddynext' ),
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
				<?php foreach ( $tabs as $tab ) : ?>
					<?php $this->render_nav_row( $scope, $tab ); ?>
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
	 * @return void
	 */
	private function render_nav_row( string $scope, array $tab ): void {
		$slug    = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
		$label   = (string) ( $tab['label'] ?? '' );
		$icon    = (string) ( $tab['icon'] ?? '' );
		$hidden  = ! empty( $tab['hidden'] );
		$is_core = empty( $tab['custom'] );
		$locked  = ! empty( $tab['locked'] );
		$row_id  = 'bn-row-' . $scope . '-' . $slug;

		// A tab that depends on another plugin (e.g. Messages → WPMediaVerse) is
		// rendered disabled with an explanatory note when that plugin is not
		// active — the option is unavailable, so the owner cannot configure it.
		$requires    = (string) ( $tab['requires_plugin'] ?? '' );
		$dep_missing = '' !== $requires && ! \BuddyNext\Messages\MessagesData::available();
		?>
		<li class="bn-drag-row"
			data-slug="<?php echo esc_attr( $slug ); ?>"
			data-scope="<?php echo esc_attr( $scope ); ?>"
			id="<?php echo esc_attr( $row_id ); ?>"
			<?php echo $hidden ? 'data-row-hidden' : ''; ?>
			<?php echo $locked ? ' data-row-locked' : ''; ?>
			<?php echo $dep_missing ? ' data-row-dependency' : ''; ?>>

			<?php if ( 'mobile' !== $scope ) : ?>
			<button type="button"
					class="bn-drag-row__handle"
					aria-label="<?php esc_attr_e( 'Drag to reorder', 'buddynext' ); ?>"
					title="<?php esc_attr_e( 'Drag to reorder', 'buddynext' ); ?>">
				<span></span>
			</button>
			<?php endif; ?>

			<div class="bn-nav-row-icon" aria-hidden="true">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read from plugin file.
				echo $this->svg( $icon );
				?>
			</div>

			<div class="bn-drag-row__body">
				<div class="bn-nav-row-name">
					<?php echo esc_html( $label ); ?>
					<?php if ( $locked ) : ?>
						<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Fixed', 'buddynext' ); ?></span>
					<?php elseif ( $is_core ) : ?>
						<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Core', 'buddynext' ); ?></span>
					<?php else : ?>
						<span class="bn-badge" data-tone="accent"><?php esc_html_e( 'Custom', 'buddynext' ); ?></span>
					<?php endif; ?>
					<?php if ( $dep_missing ) : ?>
						<span class="bn-badge" data-tone="warn">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: required plugin name */
									__( 'Requires %s', 'buddynext' ),
									$requires
								)
							);
							?>
						</span>
					<?php endif; ?>
				</div>
				<div class="bn-nav-row-desc">
					<?php
					if ( $dep_missing ) {
						echo esc_html(
							sprintf(
								/* translators: %s: required plugin name */
								__( 'Unavailable — install and activate the %s plugin to enable messaging.', 'buddynext' ),
								$requires
							)
						);
					} else {
						echo esc_html( (string) ( $tab['description'] ?? '' ) );
					}
					?>
				</div>
			</div>

			<div class="bn-drag-row__actions">
				<?php if ( ! $locked ) : ?>
				<button type="button"
						class="bn-config-btn"
						data-scope="<?php echo esc_attr( $scope ); ?>"
						data-slug="<?php echo esc_attr( $slug ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: tab label */ __( 'Configure %s', 'buddynext' ), $label ) ); ?>">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read from plugin file.
					echo $this->svg( 'icon-config' );
					?>
				</button>
				<?php endif; ?>
				<?php if ( $locked ) : ?>
					<?php
					// Fixed slot: show a static, non-interactive indicator rather than
						// a disabled toggle — a greyed-but-on switch reads as actionable
						// and invites a click that does nothing.
					?>
					<span class="bn-nav-row-fixed" title="<?php esc_attr_e( 'This slot is fixed and cannot be hidden or moved.', 'buddynext' ); ?>">
						<?php esc_html_e( 'Always shown', 'buddynext' ); ?>
					</span>
				<?php else : ?>
					<?php
					$toggle_title = $dep_missing
						? sprintf(
							/* translators: %s: required plugin name */
							__( 'Requires the %s plugin', 'buddynext' ),
							$requires
						)
						: ( $hidden ? __( 'Hidden, click to show', 'buddynext' ) : __( 'Visible, click to hide', 'buddynext' ) );
					?>
					<label class="bn-toggle-wrap"
							title="<?php echo esc_attr( $toggle_title ); ?>">
						<input type="checkbox"
								class="bn-toggle-input screen-reader-text"
								name="bn_nav_visible[<?php echo esc_attr( $scope ); ?>][<?php echo esc_attr( $slug ); ?>]"
								value="1"
								<?php checked( ! $hidden && ! $dep_missing ); ?>
								<?php disabled( $dep_missing ); ?>>
						<span class="bn-toggle"
								role="switch"
								aria-checked="<?php echo ( $hidden || $dep_missing ) ? 'false' : 'true'; ?>"
								aria-hidden="true"></span>
						<span class="screen-reader-text">
							<?php echo $hidden ? esc_html__( 'Show tab', 'buddynext' ) : esc_html__( 'Hide tab', 'buddynext' ); ?>
						</span>
					</label>
				<?php endif; ?>
			</div>
		</li>
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
			// Locked slots (mobile Create / Profile) are fixed and not
			// configurable, so they render no config card.
			if ( ! empty( $tab['locked'] ) ) {
				continue;
			}
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
		// URL slug + backing page are owned by the Pages & URLs tab now, so the
		// config panel no longer renders them — it covers display only (label,
		// order, visibility, capability, login, guest label).
		$has_routing = ( 'main' === $scope ) && ( isset( self::PAGE_OPTIONS[ $slug ] ) || isset( self::SLUG_OPTIONS[ $slug ] ) );

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

			<?php
			// Icon picker — the panel header shows the icon, but without this field
			// the icon was never submitted, so any change reset to the default on
			// save. The read side (get_tabs / get_tabs_for_scope) already applies a
			// saved 'icon' override.
			$bn_icon_choices = $this->available_tab_icons();
			if ( '' !== $icon && ! in_array( $icon, $bn_icon_choices, true ) ) {
				array_unshift( $bn_icon_choices, $icon );
			}
			?>
			<div class="bn-cf">
				<label for="bn-cfg-icon-<?php echo esc_attr( $slug ); ?>">
					<?php esc_html_e( 'Icon', 'buddynext' ); ?>
				</label>
				<select id="bn-cfg-icon-<?php echo esc_attr( $slug ); ?>"
						name="<?php echo esc_attr( $n( 'icon' ) ); ?>">
					<?php foreach ( $bn_icon_choices as $bn_icon_slug ) : ?>
						<option value="<?php echo esc_attr( $bn_icon_slug ); ?>" <?php selected( $icon, $bn_icon_slug ); ?>>
							<?php echo esc_html( ucwords( str_replace( array( 'tab-', '-', '_' ), array( '', ' ', ' ' ), $bn_icon_slug ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php if ( 'mobile' !== $scope ) : ?>
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
			<?php else : ?>
			<input type="hidden"
					name="<?php echo esc_attr( $n( 'order' ) ); ?>"
					value="<?php echo esc_attr( (string) $order ); ?>">
			<?php endif; ?>

			<?php if ( $has_routing ) : ?>
			<div class="bn-cf">
				<span class="bn-cf-hint">
					<?php
					printf(
						/* translators: %s: link to the Pages & URLs tab. */
						wp_kses_post( __( 'This hub\'s URL slug and backing WordPress page are managed in the %s tab.', 'buddynext' ) ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=buddynext&tab=pages' ) ) . '">' . esc_html__( 'Pages &amp; URLs', 'buddynext' ) . '</a>'
					);
					?>
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
			<?php else : ?>
			<div class="bn-config-divider"></div>
			<div class="bn-cf">
				<label class="bn-check-row bn-tab-delete">
					<input type="checkbox" name="<?php echo esc_attr( $n( 'delete' ) ); ?>" value="1">
					<?php esc_html_e( 'Delete this custom tab', 'buddynext' ); ?>
				</label>
				<span class="bn-cf-hint">
					<?php esc_html_e( 'Removes the tab on Save. Custom tabs (unlike core tabs) can be deleted.', 'buddynext' ); ?>
				</span>
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
					<button type="submit" class="bn-btn" data-variant="primary" data-size="sm">
						<?php esc_html_e( 'Add Tab', 'buddynext' ); ?>
					</button>
					<button type="button" class="bn-btn bn-cancel-add-tab" data-variant="secondary" data-size="sm"
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

		// ── 2. Persist overrides for each scope. URL slug + backing page are no
		// longer handled here — they live in the Pages & URLs tab
		// (handle_save_hub_pages), so nav-save only touches display overrides. ──
		foreach ( self::SCOPE_OPTION_MAP as $scope => $option_key ) {
			$scope_config  = (array) ( $raw_config_all[ $scope ] ?? array() );
			$scope_visible = (array) ( $raw_visible[ $scope ] ?? array() );
			$visible_slugs = array_map( 'sanitize_key', array_keys( $scope_visible ) );

			// Previously-stored overrides. A custom tab submitted as an existing
			// config row carries no 'custom'/'url' field, so carry both forward
			// from here — otherwise the rebuilt override loses its custom marker
			// and get_tabs_for_scope() drops the tab on the next render.
			$existing = (array) get_option( $option_key, array() );

			$overrides = array();

			// Whether the messaging engine (WPMediaVerse) is active. When it is
			// not, the Messages row renders disabled and its visibility toggle is
			// not posted — so deriving hidden from the absent toggle would wrongly
			// persist hidden=true and keep Messages hidden even after the plugin
			// is reactivated. Carry the prior override forward untouched instead.
			$messaging_available = \BuddyNext\Messages\MessagesData::available();

			foreach ( $scope_config as $slug => $cfg ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug ) {
					continue;
				}

				if ( 'messages' === $slug && ! $messaging_available ) {
					if ( isset( $existing[ $slug ] ) ) {
						$overrides[ $slug ] = (array) $existing[ $slug ];
					}
					continue;
				}

				$cfg = (array) $cfg;

				// A custom tab flagged for deletion is dropped by simply not
				// re-adding it to the rebuilt overrides (the option is replaced
				// wholesale on save). Core tabs can't be deleted — only hidden — so
				// the delete flag is honoured only for custom tabs.
				if ( ! empty( $cfg['delete'] ) && ! empty( $existing[ $slug ]['custom'] ) ) {
					continue;
				}

				$overrides[ $slug ] = array(
					'label'          => sanitize_text_field( (string) ( $cfg['label'] ?? '' ) ),
					'icon'           => sanitize_key( (string) ( $cfg['icon'] ?? '' ) ),
					'order'          => max( 1, absint( $cfg['order'] ?? 10 ) ),
					'hidden'         => ! in_array( $slug, $visible_slugs, true ),
					'visibility'     => sanitize_key( (string) ( $cfg['visibility'] ?? 'all' ) ),
					'capability'     => sanitize_text_field( (string) ( $cfg['capability'] ?? 'read' ) ),
					'login_required' => ! empty( $cfg['login_required'] ),
					'guest_label'    => sanitize_text_field( (string) ( $cfg['guest_label'] ?? '' ) ),
				);

				// Preserve the custom-tab identity (and its destination URL) that
				// the config row does not resubmit, so re-saving keeps the tab.
				if ( ! empty( $existing[ $slug ]['custom'] ) ) {
					$overrides[ $slug ]['custom'] = true;
					$overrides[ $slug ]['url']    = esc_url_raw( (string) ( $cfg['url'] ?? $existing[ $slug ]['url'] ?? '' ) );
				}
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
					'icon'           => 'tab-custom',
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
			add_query_arg( 'bn_notice', 'saved', admin_url( 'admin.php?page=buddynext&tab=navigation' ) )
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
	 * Available nav-tab icon slugs, from the bundled admin SVG set.
	 *
	 * Globs assets/svg/admin/tab-*.svg so the icon picker stays in sync with the
	 * shipped glyphs. Returns slugs without the .svg extension, sorted.
	 *
	 * @return array<int, string>
	 */
	private function available_tab_icons(): array {
		$icons = array();
		foreach ( (array) glob( self::SVG_DIR . 'tab-*.svg' ) as $file ) {
			$slug = basename( (string) $file, '.svg' );
			if ( '' !== $slug ) {
				$icons[] = $slug;
			}
		}
		sort( $icons );
		return $icons;
	}

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
	 * Explore is intentionally absent: it is reached as the Activity hub's
	 * Home/Explore sub-tab (templates/feed/home.php), so a separate main-nav
	 * row would be a duplicate entry to the same surface. A site that wants it
	 * in the main nav can re-add it via the buddynext_nav_tabs filter. The
	 * mobile bottom nav keeps its Explore tab (it has no sub-tab row).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function default_tabs(): array {
		return array(
			array(
				'slug'        => 'feed',
				'label'       => __( 'Feed', 'buddynext' ),
				'order'       => 10,
				'icon'        => 'tab-feed',
				'description' => __( 'Main community feed', 'buddynext' ),
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
				'slug'            => 'messages',
				'label'           => __( 'Messages', 'buddynext' ),
				'order'           => 40,
				'icon'            => 'tab-messages',
				'description'     => __( 'Direct messages', 'buddynext' ),
				'capability'      => 'read',
				'requires_plugin' => 'WPMediaVerse',
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
				'label'       => __( 'Members', 'buddynext' ),
				'order'       => 60,
				'icon'        => 'tab-people',
				'description' => __( 'Page that renders the member directory and individual profile URLs.', 'buddynext' ),
				'capability'  => 'read',
				// Visible by default: the left rail shows Members as a core nav
				// item, so it must default to on (otherwise saving the nav form
				// would hide Members from the rail). Owners can still toggle it.
				'hidden'      => false,
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
