/* BuddyNext — client-side navigation action.
 *
 * Owns the bare-`buddynext` Interactivity store's `navigate` action, bound via
 * data-wp-on--click on the persistent .bn-app shell (hub-shell.php). Clicking
 * an in-app link swaps only the router region (<main data-wp-router-region=
 * "buddynext/main">) instead of reloading the whole document.
 *
 * The Interactivity router (@wordpress/interactivity-router) is a dynamic
 * dependency, so it downloads once on the first client navigation and is reused
 * after that. The real <a href> is always preserved as the fallback: JS-off,
 * router errors, modified clicks, and deny-listed routes all degrade to a
 * classic full-page navigation.
 *
 * Deny-list: routes whose path matches window.bnShellData.navDeny full-load
 * (rich editors + security-sensitive flows). Everything else client-navs —
 * a deny-list, not an allow-list, so new routes are fast by default.
 */

import { store } from '@wordpress/interactivity';

/**
 * Decide whether a same-origin in-app link must full-load. Driven entirely by
 * server/nav-API data — the JS hardcodes NO route shapes:
 *   1. The link's own `data-bn-full-load` (set from `NavItem.full_load` by the
 *      shared nav renderer, or by any template that renders a drill-in link).
 *   2. `bnShellData.navDenyPatterns` — JS-RegExp source strings PageRouter emits
 *      for the rich sub-routes it owns (profile edit, space settings/admin, post
 *      permalink, checkout), built from the live admin-configurable bases.
 *   3. `bnShellData.navDeny` — whole-surface path prefixes (auth/onboarding +
 *      partner router-region bases), filterable via `buddynext_client_nav_deny`.
 *
 * @param {HTMLAnchorElement} link The candidate in-app link.
 * @return {boolean} True when the route must full-load.
 */
function isDenied( link ) {
	// 1. Per-link declaration (the nav API's full_load, or any renderer's opt-out).
	if ( link.hasAttribute( 'data-bn-full-load' ) ) {
		return true;
	}

	const data = window.bnShellData || {};
	const path = link.pathname;

	// 2. Server-provided rich-route patterns (PageRouter owns these route shapes).
	const patterns = Array.isArray( data.navDenyPatterns ) ? data.navDenyPatterns : [];
	for ( let i = 0; i < patterns.length; i++ ) {
		try {
			if ( new RegExp( patterns[ i ] ).test( path ) ) {
				return true;
			}
		} catch ( e ) {
			// A malformed server pattern must never strand navigation — skip it.
		}
	}

	// 3. Whole-surface prefix bases (a string or an array of them per surface key).
	const deny = data.navDeny || {};
	const startsWith = ( prefix ) => {
		if ( ! prefix ) {
			return false;
		}
		const list = Array.isArray( prefix ) ? prefix : [ prefix ];
		return list.some( ( p ) => p && path.indexOf( p.replace( /\/+$/, '' ) ) === 0 );
	};
	return Object.keys( deny ).some( ( key ) => {
		// The hub ROOTS (people/spaces) must client-nav — only their rich sub-routes
		// (handled by the patterns above) full-load.
		if ( 'people' === key || 'spaces' === key ) {
			return false;
		}
		return startsWith( deny[ key ] );
	} );
}

/**
 * Re-sync the active state on persistent nav after a client swap. The nav that
 * lives OUTSIDE the router region (rail, mobile bar, context bar) keeps its
 * server-rendered active state, so it goes stale on a client navigation.
 *
 * Driven by the Nav API's own output, not hardcoded selectors: every
 * registry-rendered nav container carries `data-bn-nav` (emitted by the shared
 * renderers — rail.php, partials/nav.php, parts/nav-bar.php), so this re-marks
 * active state across ALL of them generically. A new nav surface is picked up
 * automatically — no edit here. Active is `aria-current="page"` (CSS keys every
 * nav's active styling off `[aria-current]`); exactly one link per nav is marked
 * — the longest path-prefix match — so a sub-route doesn't leave two current.
 *
 * @return {void}
 */
function syncActiveNav() {
	const here = window.location.pathname.replace( /\/+$/, '' );
	document.querySelectorAll( '[data-bn-nav]' ).forEach( ( nav ) => {
		const links = nav.querySelectorAll( 'a[href]' );
		// Pick the single best (longest-prefix) match within this nav.
		let best = null;
		let bestLen = -1;
		links.forEach( ( a ) => {
			const target = ( a.pathname || '' ).replace( /\/+$/, '' );
			if ( target && here.indexOf( target ) === 0 && target.length > bestLen ) {
				best = a;
				bestLen = target.length;
			}
		} );
		links.forEach( ( a ) => {
			if ( a === best ) {
				a.setAttribute( 'aria-current', 'page' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		} );
	} );
}

store( 'buddynext', {
	actions: {
		*navigate( event ) {
			// Rollout switch: client-nav stays off until each surface is made
			// nav-aware (Phase 3) and verified (Phase 5). While off, every click
			// falls through to a normal full-page navigation.
			if ( ! ( window.bnShellData && window.bnShellData.clientNav ) ) {
				return;
			}

			const link = event.target.closest( 'a' );
			if ( ! link || ! link.href ) {
				return;
			}
			// Respect handlers that already claimed the click.
			if ( event.defaultPrevented ) {
				return;
			}
			// In-page anchors.
			if ( link.getAttribute( 'href' ).charAt( 0 ) === '#' ) {
				return;
			}
			// Modified / new-tab / download / cross-origin → let the browser do it.
			if (
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey ||
				event.button !== 0 ||
				link.target === '_blank' ||
				link.hasAttribute( 'download' ) ||
				link.origin !== window.location.origin
			) {
				return;
			}
			// Deny-list → full-page load (per-link flag + server-provided patterns).
			if ( isDenied( link ) ) {
				return;
			}

			event.preventDefault();
			const href = link.href;

			try {
				const router = yield import( '@wordpress/interactivity-router' );
				yield router.actions.navigate( href );

				document.dispatchEvent(
					new CustomEvent( 'buddynext:navigated', {
						detail: { href },
					} )
				);

				// Post-swap a11y + chrome sync. Focus the main column, not the router
				// region: the region wrapper is now display:contents (it wraps main +
				// the right sidebar so both swap together) and is not focusable.
				const mainCol = document.getElementById( 'bn-main-content' );
				if ( mainCol ) {
					mainCol.focus();
				}
				window.scrollTo( 0, 0 );
				syncActiveNav();
			} catch ( err ) {
				// Never strand the user — fall back to a classic navigation.
				window.location.href = href;
			}
		},
	},
} );
