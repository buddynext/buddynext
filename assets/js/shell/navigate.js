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
 * Decide whether a same-origin in-app path must full-load (deny-listed).
 *
 * @param {string} path window.location-style pathname of the target link.
 * @return {boolean} True when the route must full-load.
 */
function isDenied( path ) {
	const deny = ( window.bnShellData && window.bnShellData.navDeny ) || {};
	const startsWith = ( prefix ) =>
		prefix && path.indexOf( prefix.replace( /\/+$/, '' ) ) === 0;

	// Auth + onboarding: full forms, redirect-after-login, own slim shell.
	if (
		startsWith( deny.auth ) ||
		startsWith( deny.signup ) ||
		startsWith( deny.verify ) ||
		startsWith( deny.reset ) ||
		startsWith( deny.onboarding )
	) {
		return true;
	}

	// Profile edit — rich editor (avatar/cover upload, repeater fields).
	if ( startsWith( deny.people ) && /\/edit\/?$/.test( path ) ) {
		return true;
	}

	// Space settings/admin — cover/icon upload + settings forms.
	if (
		startsWith( deny.spaces ) &&
		/\/(settings|admin)\/?$/.test( path )
	) {
		return true;
	}

	// Single-post permalink (/p/{id}/) — rich reply composer.
	if ( /\/p\/\d+\/?$/.test( path ) ) {
		return true;
	}

	// Membership checkout (Stripe Embedded Checkout mounts here).
	if ( /\/(checkout|membership\/checkout)\/?$/.test( path ) ) {
		return true;
	}

	return false;
}

/**
 * Re-sync the active state on persistent nav (rail + mobile bar) after a swap.
 * The server-rendered active state lives outside the router region, so it goes
 * stale on a client-side navigation.
 *
 * Updates the REAL markers each nav styles + announces with: the rail's active
 * item is `aria-current="page"` (.bn-rail__item[aria-current="page"]) and the
 * mobile bar's is the `--active` modifier class; aria-current also drives the
 * screen-reader "current page" state. Exactly one link per nav is marked — the
 * longest path-prefix match — so a sub-route (e.g. /activity/explore/) doesn't
 * leave two items current. (The previous version toggled an `.is-active` class
 * no stylesheet reads, so neither the visual state nor aria-current updated.)
 *
 * @return {void}
 */
function syncActiveNav() {
	const here = window.location.pathname.replace( /\/+$/, '' );
	[ '.bn-app__rail', '.bn-mobile-nav' ].forEach( ( scope ) => {
		const links = document.querySelectorAll( scope + ' a' );
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
			const active = a === best;
			if ( active ) {
				a.setAttribute( 'aria-current', 'page' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
			if ( a.classList.contains( 'bn-mobile-nav__item' ) ) {
				a.classList.toggle( 'bn-mobile-nav__item--active', active );
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
			// Deny-list → full-page load.
			if ( isDenied( link.pathname ) ) {
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

				// Post-swap a11y + chrome sync.
				const region = document.querySelector(
					'[data-wp-router-region="buddynext/main"]'
				);
				if ( region ) {
					region.focus();
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
