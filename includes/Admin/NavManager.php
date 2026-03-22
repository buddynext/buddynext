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
	 * Option key for persisted admin overrides.
	 */
	private const OPTION_OVERRIDES = 'buddynext_nav_overrides';

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
		'spaces'        => 'buddynext_page_spaces',
		'messages'      => 'buddynext_page_messages',
		'notifications' => 'buddynext_page_notifications',
	);

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_post_bn_save_nav', array( $this, 'handle_save_nav' ) );
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

		return array_values( $tabs );
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
		$tabs       = $this->get_tabs();
		$first_slug = ! empty( $tabs ) ? sanitize_key( (string) ( $tabs[0]['slug'] ?? '' ) ) : '';

		$this->render_nav_styles();

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
						<button type="submit" class="bn-nav-btn-save">
							<?php esc_html_e( 'Save Changes', 'buddynext' ); ?>
						</button>
					</div>

					<?php $this->render_nav_section( $tabs ); ?>

					<?php
					$this->render_collapsed_section(
						__( 'Profile Tabs', 'buddynext' ),
						__( '4 core · Posts, Media, Spaces, Friends · plugins can add more', 'buddynext' ),
						__( '4 core items', 'buddynext' )
					);
					$this->render_collapsed_section(
						__( 'Space Tabs', 'buddynext' ),
						__( '4 core · Feed, Members, Forum, Media · plugins can add more', 'buddynext' ),
						__( '4 core items', 'buddynext' )
					);
					$this->render_collapsed_section(
						__( 'Mobile Bottom Nav', 'buddynext' ),
						__( '5 items · Feed, Explore, Spaces, Notifications, Messages', 'buddynext' ),
						__( '5 items', 'buddynext' )
					);
					?>

				</div><!-- /.bn-nav-main-panel -->

				<div class="bn-nav-config-panel">
					<?php $this->render_all_config_panels( $tabs, $first_slug ); ?>
				</div>

			</div><!-- /.bn-three-panel -->
		</form>

		<?php
		$this->render_dev_bar();
		$this->render_nav_script( $first_slug );
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
			<div class="bn-scope-item bn-scope-active">📋 <?php esc_html_e( 'Main Navigation', 'buddynext' ); ?></div>
			<div class="bn-scope-item bn-scope-soon">👤 <?php esc_html_e( 'Profile Tabs', 'buddynext' ); ?></div>
			<div class="bn-scope-item bn-scope-soon">🏠 <?php esc_html_e( 'Space Tabs', 'buddynext' ); ?></div>
			<div class="bn-scope-item bn-scope-soon">📱 <?php esc_html_e( 'Mobile Bottom Nav', 'buddynext' ); ?></div>
			<div class="bn-scope-tip">
				<?php
				echo wp_kses(
					__( '💡 Any plugin can inject tabs into any scope using filters — <code>buddynext_main_nav_items</code>, <code>buddynext_profile_tabs</code>, <code>buddynext_space_tabs</code>.', 'buddynext' ),
					array( 'code' => array() )
				);
				?>
			</div>
		</div>
		<?php
	}

	// ── Render: main nav section ──────────────────────────────────────────────

	/**
	 * Render the Main Navigation section card with the sortable tab list.
	 *
	 * @param array<int, array<string, mixed>> $tabs Ordered tab list.
	 * @return void
	 */
	private function render_nav_section( array $tabs ): void {
		$count = count( $tabs );
		?>
		<div class="bn-nav-section">
			<div class="bn-nav-section-header">
				<div class="bn-nav-section-title">
					<?php esc_html_e( 'Main Navigation', 'buddynext' ); ?>
					<span class="bn-nav-section-sub"><?php esc_html_e( '— Community Nav Bar', 'buddynext' ); ?></span>
				</div>
				<div class="bn-nav-section-badge">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of core items */
							_n( '%d core item', '%d core items', $count, 'buddynext' ),
							$count
						)
					);
					?>
					&nbsp;&middot;&nbsp;<?php esc_html_e( 'plugins can add more', 'buddynext' ); ?>
				</div>
			</div>

			<ul class="bn-nav-list" id="bn-nav-sortable">
				<?php foreach ( $tabs as $idx => $tab ) : ?>
					<?php $this->render_nav_row( $tab, $idx ); ?>
				<?php endforeach; ?>
			</ul>

			<div class="bn-nav-add-row">
				<button type="button" class="bn-add-tab-btn" disabled title="<?php esc_attr_e( 'Custom tab creation coming soon', 'buddynext' ); ?>">
					<span aria-hidden="true">＋</span>
					<?php esc_html_e( 'Add Custom Tab', 'buddynext' ); ?>
				</button>
				<button type="button" class="bn-add-tab-btn bn-add-plugin-btn" disabled title="<?php esc_attr_e( 'Use the buddynext_main_nav_items filter to inject tabs from your plugin', 'buddynext' ); ?>">
					🔌 <?php esc_html_e( 'Tab from Plugin', 'buddynext' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single nav item row inside the sortable list.
	 *
	 * @param array<string, mixed> $tab Resolved tab entry.
	 * @param int                  $idx Zero-based index used for input names.
	 * @return void
	 */
	private function render_nav_row( array $tab, int $idx ): void {
		$slug    = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
		$label   = (string) ( $tab['label'] ?? '' );
		$icon    = (string) ( $tab['icon'] ?? '' );
		$hidden  = ! empty( $tab['hidden'] );
		$is_core = empty( $tab['custom'] );
		?>
		<li class="bn-nav-row<?php echo $hidden ? ' bn-row-hidden' : ''; ?>"
			data-slug="<?php echo esc_attr( $slug ); ?>"
			id="bn-row-<?php echo esc_attr( $slug ); ?>">

			<input type="hidden"
					name="bn_nav_slug[<?php echo esc_attr( (string) $idx ); ?>]"
					value="<?php echo esc_attr( $slug ); ?>">

			<div class="bn-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'buddynext' ); ?>" aria-hidden="true">
				<span></span><span></span><span></span>
			</div>

			<div class="bn-row-icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></div>

			<div class="bn-row-info">
				<div class="bn-row-name">
					<?php echo esc_html( $label ); ?>
					<?php if ( $is_core ) : ?>
						<span class="bn-badge bn-badge-core"><?php esc_html_e( 'Core', 'buddynext' ); ?></span>
					<?php else : ?>
						<span class="bn-badge bn-badge-custom"><?php esc_html_e( 'Custom', 'buddynext' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="bn-row-desc"><?php echo esc_html( (string) ( $tab['description'] ?? '' ) ); ?></div>
			</div>

			<div class="bn-row-actions">
				<button type="button"
						class="bn-config-btn"
						data-slug="<?php echo esc_attr( $slug ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: tab label */ __( 'Configure %s', 'buddynext' ), $label ) ); ?>">
					⚙
				</button>
				<label class="bn-toggle-wrap"
						title="<?php echo $hidden ? esc_attr__( 'Hidden — click to show', 'buddynext' ) : esc_attr__( 'Visible — click to hide', 'buddynext' ); ?>">
					<input type="checkbox"
							class="bn-toggle-input"
							name="bn_nav_visible[<?php echo esc_attr( $slug ); ?>]"
							value="1"
							<?php checked( ! $hidden ); ?>>
					<span class="bn-toggle<?php echo ! $hidden ? ' bn-toggle-on' : ''; ?>" aria-hidden="true"></span>
					<span class="screen-reader-text">
						<?php echo $hidden ? esc_html__( 'Show tab', 'buddynext' ) : esc_html__( 'Hide tab', 'buddynext' ); ?>
					</span>
				</label>
			</div>
		</li>
		<?php
	}

	// ── Render: collapsed section placeholder ─────────────────────────────────

	/**
	 * Render a collapsed (stub) section card for future scopes.
	 *
	 * @param string $title   Section title.
	 * @param string $summary One-line summary shown in collapsed state.
	 * @param string $badge   Badge label (item count).
	 * @return void
	 */
	private function render_collapsed_section( string $title, string $summary, string $badge ): void {
		?>
		<div class="bn-nav-section bn-nav-section-collapsed">
			<div class="bn-nav-section-header">
				<div class="bn-nav-section-title">
					<span class="bn-collapse-arrow" aria-hidden="true">▾</span>
					<?php echo esc_html( $title ); ?>
				</div>
				<div class="bn-nav-collapsed-summary"><?php echo esc_html( $summary ); ?></div>
				<div class="bn-nav-section-badge"><?php echo esc_html( $badge ); ?></div>
			</div>
		</div>
		<?php
	}

	// ── Render: config panels ─────────────────────────────────────────────────

	/**
	 * Render all per-tab config panels, hiding all but the active one.
	 *
	 * Each panel is pre-rendered and shown/hidden via JS when the user clicks
	 * the ⚙ button on the corresponding nav row.
	 *
	 * @param array<int, array<string, mixed>> $tabs       Ordered tab list.
	 * @param string                           $first_slug Slug of the initially visible panel.
	 * @return void
	 */
	private function render_all_config_panels( array $tabs, string $first_slug ): void {
		foreach ( $tabs as $tab ) {
			$slug      = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
			$is_active = ( $slug === $first_slug );
			?>
			<div class="bn-config-card"
				id="bn-config-<?php echo esc_attr( $slug ); ?>"
				<?php echo $is_active ? '' : 'hidden'; ?>>
				<?php $this->render_config_panel_for_tab( $tab ); ?>
			</div>
			<?php
		}
	}

	/**
	 * Render the config panel body for a single tab.
	 *
	 * For core tabs that have a PAGE_OPTIONS mapping, a wp_dropdown_pages()
	 * selector is shown so the admin can assign (or reassign) the WordPress
	 * page that serves this hub.
	 *
	 * @param array<string, mixed> $tab Resolved tab entry.
	 * @return void
	 */
	private function render_config_panel_for_tab( array $tab ): void {
		$slug        = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
		$label       = (string) ( $tab['label'] ?? '' );
		$icon        = (string) ( $tab['icon'] ?? '' );
		$order       = (int) ( $tab['order'] ?? 10 );
		$visibility  = (string) ( $tab['visibility'] ?? 'all' );
		$capability  = (string) ( $tab['capability'] ?? 'read' );
		$login_req   = ! empty( $tab['login_required'] );
		$guest_label = (string) ( $tab['guest_label'] ?? '' );
		$is_core     = empty( $tab['custom'] );
		$page_opt    = self::PAGE_OPTIONS[ $slug ] ?? '';
		$page_id     = $page_opt ? (int) get_option( $page_opt, 0 ) : 0;

		// Helper: generate namespaced input name for this tab's config.
		$n = static function ( string $field ) use ( $slug ): string {
			return 'bn_nav_config[' . $slug . '][' . $field . ']';
		};
		?>
		<div class="bn-config-header">
			<div class="bn-config-breadcrumb"><?php esc_html_e( 'Main Navigation', 'buddynext' ); ?> &rsaquo;</div>
			<div class="bn-config-title"><?php echo esc_html( trim( $icon . ' ' . $label ) ); ?></div>
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
						style="width:80px;">
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

	// ── Render: developer info bar ────────────────────────────────────────────

	/**
	 * Render the developer code reference bar at the bottom of the page.
	 *
	 * @return void
	 */
	private function render_dev_bar(): void {
		?>
		<div class="bn-dev-bar">
			<div class="bn-dev-bar-title">
				<?php esc_html_e( '🔌 How to register a custom tab from your plugin:', 'buddynext' ); ?>
			</div>
			<pre class="bn-dev-code">add_filter( 'buddynext_nav_tabs', function( $tabs ) {
	$tabs[] = [
		'slug'        => 'my-tab',
		'label'       => __( 'My Tab', 'my-plugin' ),
		'icon'        => '⭐',
		'description' => __( 'My custom tab', 'my-plugin' ),
		'order'       => 60,
		'capability'  => 'read',
		'custom'      => true,
	];
	return $tabs;
} );</pre>
			<p class="bn-dev-bar-note">
				<?php
				echo wp_kses(
					__( 'Same filter pattern for profile tabs (<code>buddynext_profile_tabs</code>) and space tabs (<code>buddynext_space_tabs</code>).', 'buddynext' ),
					array( 'code' => array() )
				);
				?>
			</p>
		</div>
		<?php
	}

	// ── Render: inline styles ─────────────────────────────────────────────────

	/**
	 * Output the inline CSS for the three-panel layout.
	 *
	 * Scoped to .bn-three-panel and .bn-nav-* to avoid polluting WP admin.
	 *
	 * @return void
	 */
	private function render_nav_styles(): void {
		?>
		<style>
		/* Layout */
		.bn-three-panel{display:flex;gap:20px;align-items:flex-start;margin-top:20px}

		/* Scope sidebar */
		.bn-nav-scope-sidebar{width:220px;flex-shrink:0;background:#fff;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;position:sticky;top:32px}
		.bn-scope-header{padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#646970;border-bottom:1px solid #f0f0f1;background:#f9f9f9}
		.bn-scope-item{padding:9px 14px;color:#1d2327;font-size:13px;border-bottom:1px solid #f0f0f1}
		.bn-scope-item:last-of-type{border-bottom:none}
		.bn-scope-active{background:#e8f4fb;color:#0073aa;font-weight:600;border-left:3px solid #0073aa;padding-left:11px}
		.bn-scope-soon{color:#9ca3af;cursor:default}
		.bn-scope-tip{padding:12px 14px;font-size:11px;color:#646970;line-height:1.6;border-top:1px solid #f0f0f1;background:#f9f9f9}
		.bn-scope-tip code{background:#f0f0f1;padding:1px 4px;border-radius:2px;font-size:10px;font-family:monospace}

		/* Main panel */
		.bn-nav-main-panel{flex:1;min-width:0}
		.bn-nav-page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;gap:16px}
		.bn-nav-page-title{font-size:20px;font-weight:700;color:#1d2327;margin:0 0 4px}
		.bn-nav-page-desc{font-size:13px;color:#646970;margin:0}
		.bn-nav-btn-save{background:#0073aa;color:#fff;padding:8px 20px;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;border:none;white-space:nowrap;flex-shrink:0}
		.bn-nav-btn-save:hover{background:#005f8c}

		/* Section card */
		.bn-nav-section{background:#fff;border:1px solid #c3c4c7;border-radius:4px;margin-bottom:16px;overflow:hidden}
		.bn-nav-section-header{padding:12px 16px;border-bottom:1px solid #f0f0f1;display:flex;align-items:center;justify-content:space-between;gap:12px}
		.bn-nav-section-collapsed .bn-nav-section-header{border-bottom:none;opacity:.7}
		.bn-nav-section-title{font-weight:700;font-size:14px;color:#1d2327}
		.bn-nav-section-sub{font-weight:400;font-size:13px;color:#646970}
		.bn-nav-section-badge{font-size:11px;color:#646970;background:#f0f0f1;padding:3px 8px;border-radius:10px;white-space:nowrap;flex-shrink:0}
		.bn-nav-collapsed-summary{font-size:12px;color:#787c82;margin-left:auto;padding-right:8px}
		.bn-collapse-arrow{display:inline-block;font-size:11px;color:#646970;margin-right:4px;transform:rotate(-90deg)}

		/* Nav row list */
		.bn-nav-list{list-style:none;margin:0;padding:0}
		.bn-nav-row{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #f6f7f7;transition:background .1s;cursor:default}
		.bn-nav-row:last-child{border-bottom:none}
		.bn-nav-row:hover{background:#f9fafb}
		.bn-row-hidden{opacity:.55;background:#fafafa}
		.bn-drag-handle{display:flex;flex-direction:column;gap:3px;cursor:grab;padding:4px 2px;flex-shrink:0}
		.bn-drag-handle span{display:block;width:18px;height:2px;background:#c3c4c7;border-radius:1px}
		.bn-drag-handle:hover span{background:#8c8f94}
		.bn-row-icon{font-size:18px;flex-shrink:0;width:24px;text-align:center}
		.bn-row-info{flex:1;min-width:0}
		.bn-row-name{font-weight:600;font-size:13px;color:#1d2327;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
		.bn-row-desc{font-size:11px;color:#787c82;margin-top:2px}
		.bn-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;line-height:1.4}
		.bn-badge-core{background:#0073aa;color:#fff}
		.bn-badge-custom{background:#6b7280;color:#fff}
		.bn-row-actions{display:flex;align-items:center;gap:10px;flex-shrink:0}
		.bn-config-btn{background:none;border:1px solid #c3c4c7;border-radius:3px;padding:4px 9px;font-size:13px;cursor:pointer;color:#646970;line-height:1}
		.bn-config-btn:hover,.bn-config-btn-active{background:#0073aa;border-color:#0073aa;color:#fff}

		/* Toggle switch */
		.bn-toggle-wrap{display:inline-flex;align-items:center;cursor:pointer}
		.bn-toggle-input{position:absolute;opacity:0;width:0;height:0}
		.bn-toggle{width:40px;height:22px;background:#787c82;border-radius:11px;position:relative;transition:background .2s;flex-shrink:0;display:inline-block}
		.bn-toggle-on{background:#00a32a}
		.bn-toggle::after{content:'';position:absolute;width:16px;height:16px;background:#fff;border-radius:50%;top:3px;left:3px;transition:left .2s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
		.bn-toggle-on::after{left:21px}

		/* Add tab row */
		.bn-nav-add-row{display:flex;border-top:1px solid #f0f0f1}
		.bn-add-tab-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:11px 16px;border:none;border-right:1px solid #f0f0f1;background:none;font-size:13px;color:#9ca3af;cursor:not-allowed}
		.bn-add-plugin-btn{border-right:none;color:#9ca3af}

		/* Config panel */
		.bn-nav-config-panel{width:280px;flex-shrink:0;position:sticky;top:32px}
		.bn-config-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden}
		.bn-config-header{padding:11px 14px;border-bottom:1px solid #f0f0f1;background:#f9f9f9}
		.bn-config-breadcrumb{font-size:11px;color:#646970;margin-bottom:2px}
		.bn-config-title{font-size:13px;font-weight:700;color:#1d2327}
		.bn-config-body{padding:14px}
		.bn-cf{margin-bottom:14px}
		.bn-cf:last-child{margin-bottom:0}
		.bn-cf>label{display:block;font-weight:600;font-size:11px;color:#50575e;text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px}
		.bn-cf input[type=text],.bn-cf input[type=number],.bn-cf select{width:100%;border:1px solid #c3c4c7;border-radius:3px;padding:6px 8px;font-size:13px;color:#1d2327;background:#fff;font-family:inherit;box-sizing:border-box}
		.bn-cf input[type=text]:focus,.bn-cf input[type=number]:focus,.bn-cf select:focus{outline:none;border-color:#0073aa;box-shadow:0 0 0 1px #0073aa}
		.bn-cf-hint{display:block;font-size:11px;color:#787c82;margin-top:4px}
		.bn-config-divider{height:1px;background:#f0f0f1;margin:14px 0}
		.bn-config-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
		.bn-config-toggle-label{font-weight:600;font-size:12px;color:#1d2327}
		.bn-config-toggle-sub{font-size:11px;color:#787c82;margin-top:1px}
		.bn-cfg-toggle-chk{width:18px;height:18px;cursor:pointer;flex-shrink:0;accent-color:#0073aa}
		.bn-config-note{font-size:11px;color:#646970;background:#f6f7f7;border:1px solid #f0f0f1;border-radius:3px;padding:8px 10px;line-height:1.5}
		.bn-config-note strong{color:#50575e}

		/* Developer bar */
		.bn-dev-bar{background:#1e1e1e;color:#e5e7eb;padding:20px 32px;font-size:12px;line-height:1.7;margin-top:32px;border-radius:4px}
		.bn-dev-bar-title{font-weight:700;color:#fff;font-size:13px;margin-bottom:12px}
		.bn-dev-code{background:#111;color:#e5e7eb;border:1px solid #3a3a3a;border-radius:4px;padding:16px;font-family:'Menlo','Consolas','Courier New',monospace;font-size:12px;line-height:1.65;white-space:pre;overflow-x:auto;margin:8px 0 14px}
		.bn-dev-bar-note{font-size:11px;color:#9ca3af}
		.bn-dev-bar-note code{background:#2d2d2d;padding:1px 5px;border-radius:3px;font-family:monospace;color:#e5e7eb}

		/* Responsive */
		@media screen and (max-width:1100px){
			.bn-three-panel{flex-wrap:wrap}
			.bn-nav-scope-sidebar{width:100%;position:static}
			.bn-nav-config-panel{width:100%;position:static}
		}
		@media screen and (max-width:640px){
			.bn-nav-page-header{flex-direction:column;align-items:stretch}
			.bn-nav-btn-save{width:100%}
			.bn-nav-add-row{flex-direction:column}
			.bn-add-tab-btn{border-right:none;border-bottom:1px solid #f0f0f1}
		}
		</style>
		<?php
	}

	// ── Render: inline script ─────────────────────────────────────────────────

	/**
	 * Output the inline JS for config panel switching, toggle sync, and sort.
	 *
	 * Uses vanilla JS for panel switching; falls back to jQuery UI Sortable
	 * (always available in WP admin) for drag-reorder.
	 *
	 * @param string $first_slug Slug of the initially active config panel.
	 * @return void
	 */
	private function render_nav_script( string $first_slug ): void {
		?>
		<script>
		(function () {
			'use strict';

			// ── Config panel switching ─────────────────────────────────────
			var activeSlug = <?php echo wp_json_encode( $first_slug ); ?>;

			function showPanel( slug ) {
				document.querySelectorAll( '.bn-config-card' ).forEach( function ( el ) {
					el.hidden = true;
				} );
				document.querySelectorAll( '.bn-config-btn' ).forEach( function ( b ) {
					b.classList.remove( 'bn-config-btn-active' );
				} );

				var panel = document.getElementById( 'bn-config-' + slug );
				if ( panel ) {
					panel.hidden = false;
					activeSlug   = slug;
				}

				var btn = document.querySelector( '.bn-config-btn[data-slug="' + slug + '"]' );
				if ( btn ) {
					btn.classList.add( 'bn-config-btn-active' );
				}
			}

			// Mark initial active button.
			if ( activeSlug ) {
				showPanel( activeSlug );
			}

			document.querySelectorAll( '.bn-config-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					showPanel( this.dataset.slug );
				} );
			} );

			// ── Toggle switch visual sync ──────────────────────────────────
			document.querySelectorAll( '.bn-toggle-input' ).forEach( function ( chk ) {
				chk.addEventListener( 'change', function () {
					var toggle = this.nextElementSibling;
					var row    = this.closest( '.bn-nav-row' );
					if ( this.checked ) {
						if ( toggle ) { toggle.classList.add( 'bn-toggle-on' ); }
						if ( row )    { row.classList.remove( 'bn-row-hidden' ); }
					} else {
						if ( toggle ) { toggle.classList.remove( 'bn-toggle-on' ); }
						if ( row )    { row.classList.add( 'bn-row-hidden' ); }
					}
				} );
			} );

			// ── Drag-reorder via jQuery UI Sortable ────────────────────────
			if ( window.jQuery && jQuery.fn.sortable ) {
				jQuery( '#bn-nav-sortable' ).sortable( {
					handle      : '.bn-drag-handle',
					axis        : 'y',
					containment : 'parent',
					update      : function () {
						jQuery( '#bn-nav-sortable .bn-nav-row' ).each( function ( i ) {
							var slug        = this.dataset.slug;
							var orderInput  = document.querySelector(
								'#bn-config-' + slug + ' input[type="number"]'
							);
							if ( orderInput ) {
								orderInput.value = ( i + 1 ) * 10;
							}
						} );
					}
				} ).disableSelection();
			}
		}());
		</script>
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

		// ── 1. Which slugs are visible (checkbox = visible) ───────────────
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$visible_slugs = array_map(
			'sanitize_key',
			array_keys( (array) wp_unslash( $_POST['bn_nav_visible'] ?? array() ) )
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// ── 2. Per-tab config ─────────────────────────────────────────────
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_config = (array) wp_unslash( $_POST['bn_nav_config'] ?? array() );

		// ── 3. Page conflict check ────────────────────────────────────────
		$seen_pages = array();
		foreach ( $raw_config as $slug => $cfg ) {
			$slug = sanitize_key( (string) $slug );
			if ( ! isset( self::PAGE_OPTIONS[ $slug ] ) ) {
				continue;
			}
			$page_id = absint( ( (array) $cfg )['page_id'] ?? 0 );
			if ( 0 === $page_id ) {
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

		// ── 4. Persist page assignments + build overrides ─────────────────
		$overrides = array();

		foreach ( $raw_config as $slug => $cfg ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$cfg = (array) $cfg;

			// Page assignment for core hubs.
			if ( isset( self::PAGE_OPTIONS[ $slug ] ) ) {
				update_option( self::PAGE_OPTIONS[ $slug ], absint( $cfg['page_id'] ?? 0 ) );
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

		update_option( self::OPTION_OVERRIDES, $overrides );

		wp_safe_redirect(
			add_query_arg( 'bn_notice', 'saved', admin_url( 'admin.php?page=buddynext-nav' ) )
		);
		exit;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Return persisted admin overrides keyed by tab slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_overrides(): array {
		return (array) get_option( self::OPTION_OVERRIDES, array() );
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
				'icon'        => '📰',
				'description' => __( 'Main community feed', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'explore',
				'label'       => __( 'Explore', 'buddynext' ),
				'order'       => 20,
				'icon'        => '🔍',
				'description' => __( 'Discover public content', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'spaces',
				'label'       => __( 'Spaces', 'buddynext' ),
				'order'       => 30,
				'icon'        => '🏘️',
				'description' => __( 'Browse and manage spaces', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'messages',
				'label'       => __( 'Messages', 'buddynext' ),
				'order'       => 40,
				'icon'        => '💬',
				'description' => __( 'Direct messages', 'buddynext' ),
				'capability'  => 'read',
			),
			array(
				'slug'        => 'notifications',
				'label'       => __( 'Notifications', 'buddynext' ),
				'order'       => 50,
				'icon'        => '🔔',
				'description' => __( 'Activity notifications', 'buddynext' ),
				'capability'  => 'read',
			),
		);
	}
}
