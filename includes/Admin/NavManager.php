<?php
/**
 * BuddyNext admin navigation manager.
 *
 * Exposes the `buddynext_nav_tabs` filter so that any plugin or theme can
 * add, remove, or reorder tabs in the BuddyNext navigation without touching
 * core code.  Each tab entry is an associative array:
 *
 *   'slug'  => string   Unique kebab-case identifier used in URLs.
 *   'label' => string   Human-readable label (translated).
 *   'order' => int      Sort position (lower = first).  Default 10.
 *   'icon'  => string   Optional Dashicons class or SVG path.
 *   'cap'   => string   Required capability.  Default 'manage_options'.
 *
 * Admins can override label, display order, and visibility per-tab via the
 * Navigation admin page.  Overrides are stored in wp_options under the key
 * `buddynext_nav_overrides` and applied after the filter runs, so they take
 * precedence over third-party filter callbacks.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Manages the BuddyNext frontend navigation via a filterable tab registry.
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
	 * Return the sorted list of registered navigation tabs.
	 *
	 * Applies the `buddynext_nav_tabs` filter so third-party code can inject,
	 * remove, or reorder tabs.  Admin overrides stored via the Navigation page
	 * are applied last and take precedence over filter output.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_tabs(): array {
		$defaults = $this->default_tabs();

		/**
		 * Filter the BuddyNext navigation tabs.
		 *
		 * Each tab is an array with keys: slug, label, order, icon, cap.
		 *
		 * @param array<int, array<string, mixed>> $tabs Registered tab entries.
		 */
		$tabs = (array) apply_filters( self::FILTER_TABS, $defaults );

		// Normalise missing 'order' key.
		foreach ( $tabs as &$tab ) {
			if ( ! isset( $tab['order'] ) ) {
				$tab['order'] = 10;
			}
		}
		unset( $tab );

		// Apply admin overrides — authoritative over filter output.
		$overrides = $this->get_overrides();
		foreach ( $tabs as &$tab ) {
			$slug = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
			if ( ! isset( $overrides[ $slug ] ) ) {
				continue;
			}
			$ov = $overrides[ $slug ];
			if ( isset( $ov['label'] ) && '' !== $ov['label'] ) {
				$tab['label'] = sanitize_text_field( (string) $ov['label'] );
			}
			if ( isset( $ov['order'] ) ) {
				$tab['order'] = (int) $ov['order'];
			}
			if ( isset( $ov['hidden'] ) ) {
				$tab['hidden'] = (bool) $ov['hidden'];
			}
		}
		unset( $tab );

		usort(
			$tabs,
			fn( array $a, array $b ) => ( $a['order'] ?? 10 ) <=> ( $b['order'] ?? 10 )
		);

		return array_values( $tabs );
	}

	/**
	 * Return the currently active tab slug.
	 *
	 * Reads ?tab= from the query string.  Falls back to the first registered
	 * tab if the parameter is absent or invalid.
	 *
	 * @return string
	 */
	public function get_active_tab(): string {
		$tabs = $this->get_tabs();

		if ( empty( $tabs ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested   = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
		$valid_slugs = array_column( $tabs, 'slug' );

		if ( '' !== $requested && in_array( $requested, $valid_slugs, true ) ) {
			return $requested;
		}

		return $tabs[0]['slug'];
	}

	// ── AdminPageBase interface ────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Navigation', 'buddynext' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return __( 'Configure the BuddyNext navigation tabs and their display order', 'buddynext' );
	}

	/**
	 * Render the navigation manager page content.
	 *
	 * @return void
	 */
	protected function render_content(): void {
		$tabs = $this->get_tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['saved'] ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Navigation settings saved.', 'buddynext' ); ?></p>
			</div>
			<?php
		}
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'bn_save_nav' ); ?>
			<input type="hidden" name="action" value="bn_save_nav">

			<?php $this->open_section( __( 'Navigation Tabs', 'buddynext' ) ); ?>

			<p class="description" style="margin-bottom:16px;">
				<?php esc_html_e( 'Set the display order (lower numbers appear first) and label for each tab. Hidden tabs remain registered but do not appear in the frontend navigation.', 'buddynext' ); ?>
			</p>

			<table class="bn-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tab', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Label', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Order', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Hidden', 'buddynext' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tabs as $idx => $tab ) : ?>
						<?php
						$slug   = sanitize_key( (string) ( $tab['slug'] ?? '' ) );
						$label  = (string) ( $tab['label'] ?? '' );
						$order  = (int) ( $tab['order'] ?? 10 );
						$icon   = (string) ( $tab['icon'] ?? '' );
						$hidden = ! empty( $tab['hidden'] );
						?>
						<input type="hidden" name="bn_nav_slug[<?php echo esc_attr( (string) $idx ); ?>]" value="<?php echo esc_attr( $slug ); ?>">
						<tr>
							<td>
								<div class="bn-nav-row">
									<span class="bn-drag-handle" title="<?php esc_attr_e( 'Use Order field to reorder', 'buddynext' ); ?>">⠿</span>
									<?php if ( $icon ) : ?>
										<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
									<?php endif; ?>
									<span class="bn-row-info"><?php echo esc_html( $label ); ?></span>
								</div>
							</td>
							<td><code><?php echo esc_html( $slug ); ?></code></td>
							<td>
								<input type="text"
										name="bn_nav_label[<?php echo esc_attr( (string) $idx ); ?>]"
										value="<?php echo esc_attr( $label ); ?>"
										class="bn-text-input"
										style="max-width:180px;"
										maxlength="50">
							</td>
							<td>
								<input type="number"
										name="bn_nav_order[<?php echo esc_attr( (string) $idx ); ?>]"
										value="<?php echo esc_attr( (string) $order ); ?>"
										class="bn-text-input"
										style="max-width:70px;"
										min="1"
										max="999">
							</td>
							<td>
								<input type="checkbox"
										name="bn_nav_hidden[<?php echo esc_attr( $slug ); ?>]"
										value="1"
										<?php checked( $hidden ); ?>>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php $this->close_section(); ?>
			<?php $this->render_save_bar(); ?>
		</form>
		<?php
	}

	// ── Admin-post handler ─────────────────────────────────────────────────────

	/**
	 * Handle admin_post_bn_save_nav form submission.
	 *
	 * Persists label, order, and visibility overrides for each registered tab
	 * to the `buddynext_nav_overrides` option.
	 *
	 * @return void
	 */
	public function handle_save_nav(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_save_nav' );

		$slugs  = array_map( 'sanitize_key', (array) ( $_POST['bn_nav_slug'] ?? array() ) );
		$labels = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bn_nav_label'] ?? array() ) );
		$orders = array_map( 'absint', (array) ( $_POST['bn_nav_order'] ?? array() ) );

		// Checked checkboxes send slug => '1'; unchecked slugs are absent. Keys are slugs we
		// generated in the form, unslashed and sanitize_key'd — values ('1') are discarded.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$hidden = array_map( 'sanitize_key', array_keys( (array) wp_unslash( $_POST['bn_nav_hidden'] ?? array() ) ) );

		$overrides = array();
		foreach ( $slugs as $idx => $slug ) {
			if ( '' === $slug ) {
				continue;
			}
			$overrides[ $slug ] = array(
				'label'  => $labels[ $idx ] ?? '',
				'order'  => max( 1, $orders[ $idx ] ?? 10 ),
				'hidden' => in_array( $slug, $hidden, true ),
			);
		}

		update_option( self::OPTION_OVERRIDES, $overrides );

		wp_safe_redirect(
			add_query_arg( 'saved', '1', admin_url( 'admin.php?page=buddynext-nav' ) )
		);
		exit;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Return the persisted admin overrides for navigation tabs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_overrides(): array {
		return (array) get_option( self::OPTION_OVERRIDES, array() );
	}

	/**
	 * Return the built-in BuddyNext navigation tabs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function default_tabs(): array {
		return array(
			array(
				'slug'  => 'feed',
				'label' => __( 'Feed', 'buddynext' ),
				'order' => 10,
				'icon'  => 'dashicons-rss',
				'cap'   => 'read',
			),
			array(
				'slug'  => 'members',
				'label' => __( 'Members', 'buddynext' ),
				'order' => 20,
				'icon'  => 'dashicons-groups',
				'cap'   => 'read',
			),
			array(
				'slug'  => 'spaces',
				'label' => __( 'Spaces', 'buddynext' ),
				'order' => 30,
				'icon'  => 'dashicons-building',
				'cap'   => 'read',
			),
			array(
				'slug'  => 'notifications',
				'label' => __( 'Notifications', 'buddynext' ),
				'order' => 40,
				'icon'  => 'dashicons-bell',
				'cap'   => 'read',
			),
			array(
				'slug'  => 'messages',
				'label' => __( 'Messages', 'buddynext' ),
				'order' => 50,
				'icon'  => 'dashicons-email',
				'cap'   => 'read',
			),
		);
	}
}
