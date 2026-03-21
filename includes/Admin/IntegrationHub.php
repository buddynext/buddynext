<?php
/**
 * BuddyNext admin integration hub.
 *
 * Provides a submenu page listing all first-party and third-party addons that
 * can integrate with BuddyNext, along with their current activation status.
 *
 * Third-party code can register addons via the `buddynext_register_addons`
 * filter.  Each addon entry is an associative array:
 *
 *   'id'          => string  Unique kebab-case identifier.
 *   'label'       => string  Human-readable name.
 *   'description' => string  Short description (1–2 sentences).
 *   'plugin_file' => string  WordPress plugin file relative to plugins dir.
 *                            Used to determine if the addon is active.
 *   'url'         => string  Optional product URL for "Get" button.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Admin hub for discovering and displaying BuddyNext addon status.
 */
class IntegrationHub {

	/**
	 * Filter name for registering addon entries.
	 */
	public const FILTER_ADDONS = 'buddynext_register_addons';

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
	 * Add the Integrations submenu under the BuddyNext top-level menu.
	 *
	 * @return void
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'buddynext',
			__( 'Integrations', 'buddynext' ),
			__( 'Integrations', 'buddynext' ),
			'manage_options',
			'buddynext-integrations',
			array( $this, 'render_page' )
		);
	}

	// ── Addon registry ────────────────────────────────────────────────────────

	/**
	 * Return all registered addon entries, each decorated with an 'active' flag.
	 *
	 * Applies the `buddynext_register_addons` filter so third parties can add
	 * their own integration entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_addons(): array {
		$built_in = $this->built_in_addons();

		/**
		 * Filter the list of BuddyNext integration addons.
		 *
		 * @param array<int, array<string, mixed>> $addons Registered addon entries.
		 */
		$addons = (array) apply_filters( self::FILTER_ADDONS, $built_in );

		// Decorate each entry with its runtime activation status.
		foreach ( $addons as &$addon ) {
			$addon['active'] = $this->is_plugin_active( (string) ( $addon['plugin_file'] ?? '' ) );
		}
		unset( $addon );

		return array_values( $addons );
	}

	/**
	 * Return whether a specific addon is currently active.
	 *
	 * @param string $addon_id The addon ID from the registry.
	 * @return bool
	 */
	public function get_addon_status( string $addon_id ): bool {
		foreach ( $this->get_addons() as $addon ) {
			if ( $addon['id'] === $addon_id ) {
				return (bool) $addon['active'];
			}
		}
		return false;
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	/**
	 * Render the integrations hub admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'buddynext' ) );
		}

		$addons       = $this->get_addons();
		$active_count = count( array_filter( $addons, fn( $a ) => $a['active'] ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BuddyNext Integrations', 'buddynext' ) . '</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: active count, 2: total count */
				__( '%1$d of %2$d integrations active', 'buddynext' ),
				$active_count,
				count( $addons )
			)
		) . '</p>';
		echo '</div>';
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Return the built-in first-party addon definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function built_in_addons(): array {
		return array(
			array(
				'id'          => 'wpmediaverse',
				'label'       => 'WPMediaVerse',
				'description' => __( 'Direct messaging, media galleries, and social feeds.', 'buddynext' ),
				'plugin_file' => 'wpmediaverse/wpmediaverse.php',
			),
			array(
				'id'          => 'jetonomy',
				'label'       => 'Jetonomy',
				'description' => __( 'Forum-style threaded discussions and Q&A boards.', 'buddynext' ),
				'plugin_file' => 'jetonomy/jetonomy.php',
			),
			array(
				'id'          => 'wb-gamification',
				'label'       => 'WBGamification',
				'description' => __( 'Points, badges, levels, and leaderboards.', 'buddynext' ),
				'plugin_file' => 'wb-gamification/wb-gamification.php',
			),
			array(
				'id'          => 'career-board',
				'label'       => 'Career Board',
				'description' => __( 'Job listings and applicant management.', 'buddynext' ),
				'plugin_file' => 'career-board/career-board.php',
			),
		);
	}

	/**
	 * Check whether a plugin file is active.
	 *
	 * @param string $plugin_file Plugin file path relative to the plugins directory.
	 * @return bool
	 */
	private function is_plugin_active( string $plugin_file ): bool {
		if ( '' === $plugin_file ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}
}
