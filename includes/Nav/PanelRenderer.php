<?php
/**
 * BuddyNext — Nav panel renderer.
 *
 * The single content path for both per-entity tab menus (a member profile and a
 * space hub). Given the resolved nav for a surface, the active primary tab, and
 * the active sub-tab (on the context), it renders ONLY the active panel — never
 * the inactive ones — by invoking that item's `render` callable. Core panels,
 * Pro's Portfolio panels, and any integration's panels all flow through here, so
 * a tab and its screen are declared together and rendered identically.
 *
 * Performance contract: render one panel, not N. Cost is flat regardless of how
 * many integrations add tabs.
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

/**
 * Resolves and echoes the active panel for a nav surface.
 */
final class PanelRenderer {

	/**
	 * Render the active panel for a surface.
	 *
	 * Resolution order:
	 *  1. Find the primary item whose id matches the active tab. Unknown tab →
	 *     render nothing (the surface template owns the fallback, e.g. the feed).
	 *  2. If a sub-tab is active (`$ctx->sub`) and matches a child of the active
	 *     primary, render that child's panel.
	 *  3. Else if the primary carries its own panel, render it.
	 *  4. Else (a parent that owns no panel, e.g. Portfolio) render its FIRST
	 *     child's panel.
	 *
	 * @param ResolvedNav $nav    Resolved nav for the surface.
	 * @param NavContext  $ctx    Resolution context; `->sub` carries the active sub-tab.
	 * @param string      $active Active primary tab id (the current route's tab).
	 * @return void
	 */
	public function render_panels( ResolvedNav $nav, NavContext $ctx, string $active ): void {
		$active  = sanitize_key( $active );
		$primary = null;
		foreach ( $nav->layer( 'primary' ) as $item ) {
			if ( $item->id === $active ) {
				$primary = $item;
				break;
			}
		}

		if ( null === $primary ) {
			return; // Unknown tab — the surface template decides the fallback.
		}

		// A live sub-tab wins when it matches a child of the active primary.
		$sub = sanitize_key( $ctx->sub );
		if ( '' !== $sub ) {
			foreach ( $primary->children as $child ) {
				if ( $child->id === $sub ) {
					$child->render_panel( $ctx );
					return;
				}
			}
		}

		// The primary renders its own panel when it has one.
		if ( $primary->has_render() ) {
			$primary->render_panel( $ctx );
			return;
		}

		// A parent that owns no panel (e.g. Portfolio) falls through to its first
		// child, so a bare /portfolio/ route deep-links to the first sub-tab.
		if ( ! empty( $primary->children ) ) {
			$primary->children[0]->render_panel( $ctx );
		}
	}
}
