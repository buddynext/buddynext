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

		if ( null !== $primary ) {
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

			// A parent that owns no panel (e.g. Network/Portfolio) falls through to
			// its first child, so a bare /network/ route deep-links to the first sub-tab.
			if ( ! empty( $primary->children ) ) {
				$primary->children[0]->render_panel( $ctx );
			}
			return;
		}

		// No TOP-LEVEL primary matched the active id. The active tab may be a sub-nav
		// CHILD reached directly — e.g. a profile hero metric pill links to
		// /members/{slug}/followers/, where `followers` is a child of the `network`
		// parent — or a metric-layer panel. Search children first, then metrics, for
		// an id match that owns a render. An unknown id renders nothing, so the
		// surface template still owns the fallback.
		foreach ( $nav->layer( 'primary' ) as $item ) {
			foreach ( $item->children as $child ) {
				if ( $child->id === $active && $child->has_render() ) {
					$child->render_panel( $ctx );
					return;
				}
			}
		}
		foreach ( $nav->layer( 'metric' ) as $metric ) {
			if ( $metric->id === $active && $metric->has_render() ) {
				$metric->render_panel( $ctx );
				return;
			}
		}
	}
}
