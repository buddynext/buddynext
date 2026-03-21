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
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Manages the BuddyNext frontend navigation via a filterable tab registry.
 */
class NavManager {

	/**
	 * Filter name used to register / modify navigation tabs.
	 */
	public const FILTER_TABS = 'buddynext_nav_tabs';

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
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
	 * remove, or reorder tabs.  Results are sorted ascending by 'order'.
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

		// Normalise missing 'order' key before sorting.
		foreach ( $tabs as &$tab ) {
			if ( ! isset( $tab['order'] ) ) {
				$tab['order'] = 10;
			}
		}
		unset( $tab );

		usort(
			$tabs,
			fn( array $a, array $b ) => $a['order'] <=> $b['order']
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

		$requested = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$valid_slugs = array_column( $tabs, 'slug' );
		if ( '' !== $requested && in_array( $requested, $valid_slugs, true ) ) {
			return $requested;
		}

		return $tabs[0]['slug'];
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	/**
	 * Render the navigation manager admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'buddynext' ) );
		}

		$tabs       = $this->get_tabs();
		$active_tab = $this->get_active_tab();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BuddyNext Navigation', 'buddynext' ) . '</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: tab count */
				__( 'Registered tabs: %d', 'buddynext' ),
				count( $tabs )
			)
		) . '</p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: active tab slug */
				__( 'Active tab: %s', 'buddynext' ),
				$active_tab
			)
		) . '</p>';
		echo '</div>';
	}

	// ── Private helpers ───────────────────────────────────────────────────────

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
