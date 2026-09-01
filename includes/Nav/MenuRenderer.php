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

		// The same login-state honesty, applied to the fallback nav. See
		// exclude_unusable_pages().
		add_filter( 'wp_list_pages_excludes', array( $this, 'exclude_unusable_pages' ) );
	}

	/**
	 * Hide BuddyNext pages the current visitor cannot use from the fallback nav.
	 *
	 * With no menu assigned to a theme location, WordPress falls back to
	 * wp_page_menu(), which lists every published page. resolve_items() above
	 * does login-state filtering properly — but it only runs on
	 * wp_nav_menu_objects, i.e. once an owner has hand-built a menu. On a fresh
	 * install none of that protection is active, so the theme advertised a Login
	 * link to members who were already logged in, and Messages / Notifications to
	 * logged-out visitors, both of which bounce them straight to /login/.
	 *
	 * SCOPE: this excludes only pages BuddyNext created and owns, identified by
	 * its own page options. It does not reorder the nav, does not create or
	 * assign a menu, and never touches a page belonging to the theme, another
	 * plugin, or the owner — including WordPress's "Sample Page". Deciding what a
	 * site's navigation contains is the owner's job; not advertising our own dead
	 * ends is ours.
	 *
	 * The community hubs (Activity, Members, Spaces) are deliberately KEPT, so a
	 * fresh install still surfaces the community with no configuration at all.
	 *
	 * @since 1.1.6
	 *
	 * @param array<int,int|string> $excludes Page ids WordPress will omit.
	 * @return array<int,int|string>
	 */
	public function exclude_unusable_pages( $excludes ): array {
		$excludes  = is_array( $excludes ) ? $excludes : array();
		$logged_in = is_user_logged_in();

		// HUB KEY => whether this page is unusable for the CURRENT visitor. Keyed on
		// the hub, and the page option comes from HubRegistry — because the option
		// names were written out by hand here and one of them was wrong. The auth
		// hub's option is `buddynext_page_auth`; this asked for
		// `buddynext_page_login`, which no install has ever set. `get_option()`
		// returned false, the id was 0, and the Login page was never excluded — so
		// the exact bug this method exists to fix (a Login link advertised to
		// members who are already signed in) was live the whole time, silently,
		// because a missing option looks identical to "nothing to exclude".
		$rules = array(
			// Signing in when you already are.
			'auth'          => $logged_in,
			// Both require a session and redirect to /login/ without one.
			'notifications' => ! $logged_in,
			'messages'      => ! $logged_in
				|| ! \BuddyNext\Messages\MessagesData::entry_enabled(),
		);

		$hubs = \BuddyNext\Core\HubRegistry::instance();

		foreach ( $rules as $hub_key => $unusable ) {
			if ( ! $unusable ) {
				continue;
			}

			$hub = $hubs->get( $hub_key );
			if ( null === $hub ) {
				continue;
			}

			$page_id = (int) get_option( $hub->page_option, 0 );
			if ( $page_id > 0 ) {
				$excludes[] = $page_id;
			}
		}

		/**
		 * Filters the BuddyNext pages hidden from the fallback page-list nav.
		 *
		 * @since 1.1.6
		 *
		 * @param array<int,int|string> $excludes Page ids to omit.
		 * @param bool                  $logged_in Whether the visitor is signed in.
		 */
		return (array) apply_filters( 'buddynext_fallback_nav_excludes', $excludes, $logged_in );
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

		$this->mark_current_hub( $out );

		return $out;
	}

	/**
	 * Add active-state classes to any menu item pointing at the current hub.
	 *
	 * BuddyNext hubs are virtual routes, so WordPress's nav-menu walker never
	 * tags a menu item that links to the current hub with current-menu-item the
	 * way it does for real pages. Detect the current hub URL and add the active
	 * classes ourselves so themes can style the active item consistently. Applies
	 * to every item (resolved BN tokens, Custom Links, or Page items) whose URL
	 * matches the hub.
	 *
	 * @param array<int,object> $items Resolved menu items (modified in place).
	 * @return void
	 */
	private function mark_current_hub( array $items ): void {
		$current = $this->current_hub_url();
		if ( '' === $current ) {
			return;
		}
		foreach ( $items as $item ) {
			$url = isset( $item->url ) ? (string) $item->url : '';
			if ( '' === $url || ! $this->urls_match( $url, $current ) ) {
				continue;
			}
			$classes = ( isset( $item->classes ) && is_array( $item->classes ) ) ? $item->classes : array();
			foreach ( array( 'current-menu-item', 'current_page_item' ) as $class ) {
				if ( ! in_array( $class, $classes, true ) ) {
					$classes[] = $class;
				}
			}
			$item->classes = $classes;
			$item->current = true;
		}
	}

	/**
	 * URL of the BuddyNext hub being viewed, or '' when not on a hub.
	 *
	 * @return string
	 */
	private function current_hub_url(): string {
		$hub = (string) get_query_var( 'bn_hub', '' );
		if ( '' === $hub ) {
			return '';
		}
		switch ( $hub ) {
			case 'feed':
				return 'explore' === (string) get_query_var( 'bn_activity_action', '' )
					? \BuddyNext\Core\PageRouter::explore_url()
					: \BuddyNext\Core\PageRouter::activity_url();
			case 'people':
				return \BuddyNext\Core\PageRouter::people_url();
			case 'spaces':
				return \BuddyNext\Core\PageRouter::spaces_url();
			case 'notifications':
				return \BuddyNext\Core\PageRouter::notifications_url();
			case 'messages':
				return \BuddyNext\Core\PageRouter::messages_url();
			default:
				return '';
		}
	}

	/**
	 * Whether two URLs resolve to the same path (host + trailing slash agnostic).
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	private function urls_match( string $a, string $b ): bool {
		$pa = trim( (string) wp_parse_url( $a, PHP_URL_PATH ), '/' );
		$pb = trim( (string) wp_parse_url( $b, PHP_URL_PATH ), '/' );
		return '' !== $pa && $pa === $pb;
	}
}
