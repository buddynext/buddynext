<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Front-end applier for the Settings → Navigation overrides.
 *
 * The Navigation admin (Admin\NavManager) persists per-scope overrides
 * (hidden / label / order) into the buddynext_nav_overrides* options. That
 * admin class only runs in wp-admin, so the SAVE side has no effect on its own
 * — this class is the READ side: it hooks the front-end navigation filters and
 * applies the saved overrides so toggling a tab off in the admin actually hides
 * it, relabelling actually renames it, and reordering actually reorders it.
 *
 * Scope → option → filter:
 *   main → buddynext_nav_overrides → buddynext_rail_items (left rail)
 *
 * Profile / space / mobile scopes are wired as their renderers are connected.
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

/**
 * Applies Settings → Navigation overrides to the front-end nav renderers.
 */
final class NavOverrides {

	/**
	 * Scope key → option name where Admin\NavManager stores that scope's
	 * overrides. Mirrors NavManager::SCOPE_OPTION_MAP.
	 *
	 * @var array<string,string>
	 */
	private const SCOPE_OPTION = array(
		'main'    => 'buddynext_nav_overrides',
		'profile' => 'buddynext_nav_overrides_profile',
		'space'   => 'buddynext_nav_overrides_space',
		'mobile'  => 'buddynext_nav_overrides_mobile',
	);

	/**
	 * Hook the front-end nav filters.
	 *
	 * @return void
	 */
	public function register(): void {
		// Run late (20) so admin overrides win over bridge-injected items.
		add_filter( 'buddynext_rail_items', array( $this, 'apply_rail' ), 20, 2 );
		// Profile AND space tabs now flow through the unified Nav API; overrides
		// apply to the resolved registry items (id-keyed), per surface.
		add_filter( 'buddynext_nav_items', array( $this, 'apply_nav_items' ), 20, 2 );
		add_filter( 'buddynext_mobile_nav_items', array( $this, 'apply_mobile_items' ), 20, 2 );
	}

	/**
	 * Read a scope's stored overrides (slug => {hidden,label,order,…}).
	 *
	 * @param string $scope One of: main, profile, space, mobile.
	 * @return array<string,array<string,mixed>>
	 */
	private function overrides( string $scope ): array {
		$option = self::SCOPE_OPTION[ $scope ] ?? '';
		if ( '' === $option ) {
			return array();
		}
		$stored = get_option( $option, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether an override denies the current viewer access to a tab, per its
	 * visibility / required-capability / login-required settings. Shared by the
	 * rail + mobile appliers so the gate behaves identically everywhere.
	 *
	 * @param array<string,mixed> $ov Override row.
	 * @return bool
	 */
	private function tab_denied( array $ov ): bool {
		$logged_in = is_user_logged_in();

		$vis = (string) ( $ov['visibility'] ?? 'all' );
		if ( 'logged_in' === $vis && ! $logged_in ) {
			return true;
		}
		if ( 'admins' === $vis && ! current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( 'cap' === $vis ) {
			$cap = sanitize_key( (string) ( $ov['capability'] ?? 'read' ) );
			if ( '' !== $cap && ! current_user_can( $cap ) ) {
				return true;
			}
		}
		if ( ! empty( $ov['login_required'] ) && ! $logged_in ) {
			return true;
		}
		return false;
	}

	/**
	 * Apply main-scope overrides to the left-rail item list.
	 *
	 * Each rail item carries a `key` that matches a NavManager main-scope slug
	 * (feed/explore/people/spaces/notifications/messages). For any item with a
	 * saved override: hide it (`show` => false), relabel it, and reorder it.
	 *
	 * @param array<int,array<string,mixed>> $items Rail item definitions.
	 * @param string                         $hub   Current hub slug (unused).
	 * @return array<int,array<string,mixed>>
	 */
	public function apply_rail( $items, $hub = '' ): array {
		$items     = (array) $items;
		$overrides = $this->overrides( 'main' );

		// NOTE: do not early-return when there are no overrides. The
		// buddynext_nav_tabs bridge below must still run on a default site so a
		// programmatically registered main-nav tab reaches the rail even when the
		// admin has saved no nav overrides. The override loops below are no-ops
		// against an empty override set.

		$index = 0;
		foreach ( $items as &$item ) {
			// Preserve current visual order for items with no saved order.
			if ( isset( $item['order'] ) ) {
				$item['order'] = (int) $item['order'];
			} else {
				++$index;
				$item['order'] = $index * 10;
			}

			$key = sanitize_key( (string) ( $item['key'] ?? '' ) );
			if ( '' === $key || ! isset( $overrides[ $key ] ) ) {
				continue;
			}
			$ov = (array) $overrides[ $key ];

			if ( ! empty( $ov['hidden'] ) ) {
				$item['show'] = false;
			}
			if ( isset( $ov['label'] ) && '' !== (string) $ov['label'] ) {
				$item['label'] = sanitize_text_field( (string) $ov['label'] );
			}
			if ( isset( $ov['order'] ) ) {
				$item['order'] = max( 1, (int) $ov['order'] );
			}

			// Access gate — visibility / capability / login-required. A denied tab
			// is hidden, unless a guest label is set for logged-out visitors, in
			// which case it becomes a sign-in call-to-action.
			if ( $this->tab_denied( $ov ) ) {
				$guest_label = sanitize_text_field( (string) ( $ov['guest_label'] ?? '' ) );
				if ( '' !== $guest_label && ! is_user_logged_in() ) {
					$item['label'] = $guest_label;
					$item['url']   = trailingslashit( home_url( '/' . (string) get_option( 'buddynext_slug_auth', 'login' ) ) );
				} else {
					$item['show'] = false;
				}
			}
		}
		unset( $item );

		// Append admin-created custom tabs. NavManager stores these in the same
		// overrides option flagged custom => true (label + url + capability), and
		// the admin list already surfaces them via get_tabs_for_scope(). The rail
		// previously only mutated existing items, so a custom tab never reached the
		// front end — add each as a new rail link here.
		$existing_keys = array();
		foreach ( $items as $existing ) {
			$existing_keys[ sanitize_key( (string) ( $existing['key'] ?? '' ) ) ] = true;
		}
		$fallback_order = ( count( $items ) + 1 ) * 10;
		foreach ( $overrides as $slug => $ov ) {
			$ov   = (array) $ov;
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || empty( $ov['custom'] ) || ! empty( $ov['hidden'] ) || isset( $existing_keys[ $slug ] ) ) {
				continue;
			}
			$url = esc_url_raw( (string) ( $ov['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			// Honour the configured capability (default 'read' = any logged-in user).
			$cap = sanitize_key( (string) ( $ov['capability'] ?? 'read' ) );
			if ( '' !== $cap && ! current_user_can( $cap ) ) {
				continue;
			}
			$items[]         = array(
				'key'   => $slug,
				'label' => sanitize_text_field( (string) ( $ov['label'] ?? $slug ) ),
				'url'   => $url,
				'icon'  => 'link',
				'show'  => true,
				'order' => isset( $ov['order'] ) ? max( 1, (int) $ov['order'] ) : $fallback_order,
			);
			$fallback_order += 10;
		}

		// Bridge the documented main-nav filter to the front end. NavManager's
		// editor builds its catalogue from buddynext_nav_tabs, but that filter is
		// otherwise admin-only — a tab a plugin registers there never reached the
		// rail. Applying it here with an empty seed yields only the tabs
		// third-party code added (core defaults are seeded inside NavManager, not
		// via add_filter), so each programmatic main-nav tab carrying a URL is
		// surfaced as a rail link, deduped against existing keys + capability-gated.
		$registered = (array) apply_filters( 'buddynext_nav_tabs', array() );
		$rail_keys  = array();
		foreach ( $items as $existing_item ) {
			$rail_keys[ sanitize_key( (string) ( $existing_item['key'] ?? '' ) ) ] = true;
		}
		foreach ( $registered as $reg ) {
			if ( ! is_array( $reg ) ) {
				continue;
			}
			$slug = sanitize_key( (string) ( $reg['slug'] ?? '' ) );
			$url  = esc_url_raw( (string) ( $reg['url'] ?? '' ) );
			if ( '' === $slug || '' === $url || isset( $rail_keys[ $slug ] ) ) {
				continue;
			}
			$cap = sanitize_key( (string) ( $reg['capability'] ?? 'read' ) );
			if ( '' !== $cap && ! current_user_can( $cap ) ) {
				continue;
			}
			$rail_keys[ $slug ] = true;
			$items[]            = array(
				'key'   => $slug,
				'label' => sanitize_text_field( (string) ( $reg['label'] ?? $slug ) ),
				'url'   => $url,
				'icon'  => sanitize_key( (string) ( $reg['icon'] ?? 'link' ) ),
				'show'  => true,
				'order' => isset( $reg['order'] ) ? max( 1, (int) $reg['order'] ) : $fallback_order,
			);
			$fallback_order    += 10;
		}

		usort(
			$items,
			static fn( array $a, array $b ): int => ( (int) ( $a['order'] ?? 10 ) ) <=> ( (int) ( $b['order'] ?? 10 ) )
		);

		return $items;
	}

	/**
	 * Apply per-surface overrides to the unified Nav registry items.
	 *
	 * Hooked on `buddynext_nav_items` (which passes the raw registration arrays +
	 * the NavContext). Acts on the `profile` and `space` surfaces: hidden items are
	 * dropped, labels renamed, order applied (mapped to `priority` so the
	 * registry's own sort honours it), and admin-created custom tabs appended as
	 * registration arrays. Overrides are keyed by item id (== the legacy slug),
	 * read from the matching scope option (profile / space).
	 *
	 * @param mixed                     $items Raw registration arrays for the surface.
	 * @param \BuddyNext\Nav\NavContext $ctx   Resolution context.
	 * @return array<int,array<string,mixed>>
	 */
	public function apply_nav_items( $items, $ctx = null ): array {
		$items = is_array( $items ) ? $items : array();
		if ( ! ( $ctx instanceof \BuddyNext\Nav\NavContext )
			|| ! in_array( $ctx->surface, array( 'profile', 'space' ), true )
		) {
			return $items;
		}
		$scope     = $ctx->surface;
		$overrides = $this->overrides( $scope );
		if ( empty( $overrides ) ) {
			return $items;
		}

		$kept = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' !== $id && isset( $overrides[ $id ] ) ) {
				$ov = (array) $overrides[ $id ];

				if ( ! empty( $ov['hidden'] ) || $this->tab_denied( $ov ) ) {
					continue; // Drop hidden / gated items.
				}
				if ( isset( $ov['label'] ) && '' !== (string) $ov['label'] ) {
					$item['label'] = sanitize_text_field( (string) $ov['label'] );
				}
				// Map admin order onto the registry's priority (lower = earlier),
				// and clear before/after so the explicit order wins cleanly.
				if ( isset( $ov['order'] ) ) {
					$item['priority'] = max( 1, (int) $ov['order'] );
					$item['before']   = null;
					$item['after']    = null;
				}
			}
			$kept[] = $item;
		}

		// Append admin-created custom tabs (mirrors apply_rail). NavManager stores
		// these flagged custom => true; rendered as a real link tab (url only).
		$existing = array();
		foreach ( $kept as $existing_item ) {
			$existing[ sanitize_key( (string) ( $existing_item['id'] ?? '' ) ) ] = true;
		}
		foreach ( $overrides as $slug => $ov ) {
			$ov   = (array) $ov;
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || empty( $ov['custom'] ) || ! empty( $ov['hidden'] ) || isset( $existing[ $slug ] ) ) {
				continue;
			}
			$url = esc_url_raw( (string) ( $ov['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$cap = sanitize_key( (string) ( $ov['capability'] ?? 'read' ) );
			if ( '' !== $cap && ! current_user_can( $cap ) ) {
				continue;
			}
			$kept[] = array(
				'id'       => $slug,
				'surface'  => $scope,
				'layer'    => 'primary',
				'label'    => sanitize_text_field( (string) ( $ov['label'] ?? $slug ) ),
				'url'      => $url,
				'icon'     => 'link',
				'priority' => isset( $ov['order'] ) ? max( 1, (int) $ov['order'] ) : 900,
			);
		}

		return $kept;
	}

	// (Removed) apply_space_tabs — the space tab bar now flows through the unified
	// Nav registry, so space-scope overrides are applied by apply_nav_items()
	// above (the same id-keyed path as profile), not the legacy buddynext_space_tabs.

	/**
	 * Apply mobile-scope overrides to the curated bottom-bar items.
	 *
	 * Deliberately honours only hidden + label (not order): the bottom bar is a
	 * fixed 5-slot strip whose centre Create button must stay centred, so
	 * reordering is intentionally not applied. Only slots whose slug the mobile
	 * admin scope controls (feed/spaces/notifications) are affected; the Create
	 * and Profile shortcuts have no override and always render.
	 *
	 * @param mixed  $items  Bar item definitions.
	 * @param string $active Active section key (unused).
	 * @return array<int,array<string,mixed>>
	 */
	public function apply_mobile_items( $items, $active = '' ): array {
		$items     = (array) $items;
		$overrides = $this->overrides( 'mobile' );
		if ( empty( $overrides ) ) {
			return $items;
		}

		foreach ( $items as &$item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $item['key'] ?? '' ) );
			if ( '' === $key || ! isset( $overrides[ $key ] ) ) {
				continue;
			}
			$ov = (array) $overrides[ $key ];

			if ( ! empty( $ov['hidden'] ) || $this->tab_denied( $ov ) ) {
				$item['show'] = false;
			}
			if ( isset( $ov['label'] ) && '' !== (string) $ov['label'] ) {
				$item['label'] = sanitize_text_field( (string) $ov['label'] );
			}
		}
		unset( $item );

		// Append admin-created custom tabs as overflow entries. The bottom bar is
		// a fixed 5-slot strip (centre Create must stay centred), so custom tabs do
		// not get their own slot — nav.php surfaces them, with Profile, in a "More"
		// sheet opened from the 5th slot. Each carries overflow => true.
		$existing_keys = array();
		foreach ( $items as $existing_item ) {
			if ( is_array( $existing_item ) ) {
				$existing_keys[ sanitize_key( (string) ( $existing_item['key'] ?? '' ) ) ] = true;
			}
		}
		foreach ( $overrides as $slug => $ov ) {
			$ov   = (array) $ov;
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || empty( $ov['custom'] ) || ! empty( $ov['hidden'] ) || isset( $existing_keys[ $slug ] ) || $this->tab_denied( $ov ) ) {
				continue;
			}
			$url = esc_url_raw( (string) ( $ov['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$items[] = array(
				'key'      => $slug,
				'url'      => $url,
				'icon'     => 'link',
				'label'    => sanitize_text_field( (string) ( $ov['label'] ?? $slug ) ),
				'show'     => true,
				'overflow' => true,
			);
		}

		return $items;
	}
}
