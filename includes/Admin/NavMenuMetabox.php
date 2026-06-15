<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * "BuddyNext" nav-menu metabox (Appearance → Menus).
 *
 * Mirrors the BuddyPress menu metabox: a panel that lets a site owner add
 * BuddyNext's dynamic, per-member account and auth links to ANY WordPress menu.
 * Each item is added as an ordinary Custom Link whose URL is a `#bn-*` token;
 * MenuRenderer resolves that token to the current member's URL at render time
 * and hides items that do not match the visitor's login state.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use BuddyNext\Nav\UserLinks;

/**
 * Registers and renders the BuddyNext metabox on the nav-menus screen.
 */
final class NavMenuMetabox {

	/**
	 * Attach the metabox to the nav-menus admin screen.
	 */
	public function register(): void {
		add_action( 'load-nav-menus.php', array( $this, 'add_metabox' ) );
		// Defensive guard for a WP 7.0 core regression: nav-menu.js uses the
		// `columns` global from common.js but does not declare `common` as a
		// dependency, so on some builds common.js loads after nav-menu.js and
		// Appearance → Menus throws "columns is not defined", breaking the
		// menu-item checkboxes our metabox renders into. Add the missing
		// dependency ourselves (no-op once core declares it). Priority 1 so it
		// runs before nav-menu is printed.
		add_action( 'admin_enqueue_scripts', array( $this, 'ensure_nav_menu_common_dep' ), 1 );
	}

	/**
	 * Ensure `common` is a dependency of the core `nav-menu` script.
	 *
	 * Forces common.js (which defines the `columns` global nav-menu.js relies
	 * on) to load before nav-menu.js, working around the WP 7.0 missing-deps
	 * regression. Forward-compatible: skips when core already declares it.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function ensure_nav_menu_common_dep( string $hook_suffix ): void {
		if ( 'nav-menus.php' !== $hook_suffix ) {
			return;
		}
		$nav_menu = wp_scripts()->query( 'nav-menu', 'registered' );
		if ( $nav_menu instanceof \_WP_Dependency
			&& is_array( $nav_menu->deps )
			&& ! in_array( 'common', $nav_menu->deps, true )
		) {
			$nav_menu->deps[] = 'common';
		}
	}

	/**
	 * Register the side metabox on Appearance → Menus.
	 */
	public function add_metabox(): void {
		add_meta_box(
			'buddynext-nav-menu',
			__( 'BuddyNext', 'buddynext' ),
			array( $this, 'render' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	/**
	 * Render the two grouped checklists + the "Add to Menu" button.
	 */
	public function render(): void {
		global $nav_menu_selected_id;

		$walker    = new \Walker_Nav_Menu_Checklist();
		$loggedin  = $this->menu_items( UserLinks::LOGGEDIN );
		$loggedout = $this->menu_items( UserLinks::LOGGEDOUT );
		?>
		<div id="buddynext-nav-menu" class="posttypediv">
			<div id="tabs-panel-buddynext-nav-menu" class="tabs-panel tabs-panel-active">
				<p class="bn-nav-group"><strong><?php esc_html_e( 'Logged-in members', 'buddynext' ); ?></strong></p>
				<ul class="categorychecklist form-no-clear">
					<?php
					// Core walker — escapes its own output.
					echo walk_nav_menu_tree( $loggedin, 0, (object) array( 'walker' => $walker ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</ul>
				<p class="bn-nav-group"><strong><?php esc_html_e( 'Logged-out visitors', 'buddynext' ); ?></strong></p>
				<ul class="categorychecklist form-no-clear">
					<?php
					echo walk_nav_menu_tree( $loggedout, 0, (object) array( 'walker' => $walker ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input
						type="submit"
						<?php disabled( $nav_menu_selected_id, 0 ); ?>
						class="button submit-add-to-menu right"
						value="<?php esc_attr_e( 'Add to Menu', 'buddynext' ); ?>"
						name="add-buddynext-nav-menu-item"
						id="submit-buddynext-nav-menu"
					/>
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Build the pseudo nav-menu items for one visibility group.
	 *
	 * Each is a Custom Link (`type = custom`) whose URL is the `#bn-*` token, so
	 * "Add to Menu" stores an ordinary custom-link item that MenuRenderer then
	 * resolves per-user at render time. Negative IDs keep the checklist's field
	 * names unique without colliding with real menu-item IDs.
	 *
	 * @param string $visibility UserLinks::LOGGEDIN | UserLinks::LOGGEDOUT.
	 * @return array<int,object>
	 */
	private function menu_items( string $visibility ): array {
		$items = array();
		$index = 0;

		foreach ( UserLinks::items( $visibility ) as $entry ) {
			--$index;

			$item                   = new \stdClass();
			$item->ID               = $index;
			$item->object_id        = $index;
			$item->db_id            = 0;
			$item->object           = 'bn_nav';
			$item->menu_item_parent = 0;
			$item->type             = 'custom';
			$item->type_label       = __( 'BuddyNext', 'buddynext' );
			$item->title            = $entry['label'];
			$item->url              = $entry['token'];
			$item->target           = '';
			$item->attr_title       = '';
			$item->description      = '';
			$item->classes          = array();
			$item->xfn              = '';

			$items[] = wp_setup_nav_menu_item( $item );
		}

		return $items;
	}
}
