<?php
/**
 * Render-time resolver for BuddyNext dynamic menu items in ANY WordPress menu.
 *
 * Site owners add BuddyNext items (via the Appearance → Menus metabox, or by
 * hand as Custom Links with a `#bn-*` URL) to any theme menu location. This
 * filter, on `wp_nav_menu_objects`, rewrites each such item's URL to the CURRENT
 * member's page and removes items that do not belong to the visitor's login
 * state — so "My Profile" goes to the viewer's own profile and "Log In" only
 * shows to logged-out visitors. Non-BuddyNext items pass through untouched.
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

/**
 * Resolves `#bn-*` menu items per-user and by login-state visibility.
 */
final class MenuRenderer {

	/**
	 * Attach the menu filter. Called from Plugin::init() on the front end.
	 */
	public function register(): void {
		add_filter( 'wp_nav_menu_objects', array( $this, 'resolve_items' ), 10, 2 );
	}

	/**
	 * Resolve BuddyNext tokens and drop items that do not match login state.
	 *
	 * @param array<int,object> $items The menu item objects.
	 * @param object            $args  wp_nav_menu() args (unused).
	 * @return array<int,object>
	 */
	public function resolve_items( $items, $args = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $args required by the filter signature.
		if ( ! is_array( $items ) ) {
			return array();
		}

		// Community-nav injection toggle. When the site owner turns
		// "Show BuddyNext community navigation" off
		// (buddynext_enable_community_nav, default on), BuddyNext stops placing
		// its community items into the host theme's menus: drop every BN token
		// item and leave the theme's own menu exactly as authored. Non-BN items
		// always pass through. Default-on keeps the established behaviour.
		if ( ! (bool) get_option( 'buddynext_enable_community_nav', true ) ) {
			return array_values(
				array_filter(
					$items,
					static function ( $item ): bool {
						$url = isset( $item->url ) ? (string) $item->url : '';
						return ! UserLinks::is_token( $url );
					}
				)
			);
		}

		$user_id   = get_current_user_id();
		$logged_in = $user_id > 0;
		$out       = array();

		foreach ( $items as $item ) {
			$url = isset( $item->url ) ? (string) $item->url : '';

			if ( ! UserLinks::is_token( $url ) ) {
				$out[] = $item;
				continue;
			}

			$visibility = UserLinks::visibility( $url );

			// Hide items that do not belong to the visitor's login state.
			if ( ( UserLinks::LOGGEDIN === $visibility && ! $logged_in )
				|| ( UserLinks::LOGGEDOUT === $visibility && $logged_in )
			) {
				continue;
			}

			$resolved = UserLinks::resolve( $url, $user_id );
			if ( '' === $resolved ) {
				// Unknown token or a feature that is not available — drop it
				// rather than leave a dead `#bn-*` link in the menu.
				continue;
			}

			$item->url = $resolved;
			$out[]     = $item;
		}

		return $out;
	}
}
