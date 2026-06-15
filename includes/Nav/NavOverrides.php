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
		$items = (array) $items;
		$overrides = $this->overrides( 'main' );
		if ( empty( $overrides ) ) {
			return $items;
		}

		$index = 0;
		foreach ( $items as &$item ) {
			// Preserve current visual order for items with no saved order.
			$item['order'] = isset( $item['order'] ) ? (int) $item['order'] : ( ++$index * 10 );

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
		}
		unset( $item );

		usort(
			$items,
			static fn( array $a, array $b ): int => ( (int) ( $a['order'] ?? 10 ) ) <=> ( (int) ( $b['order'] ?? 10 ) )
		);

		return $items;
	}
}
