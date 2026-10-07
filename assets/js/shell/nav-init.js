/* BuddyNext — nav-aware init binder.
 *
 * The uniform replacement for the `DOMContentLoaded`-only init tails scattered
 * across the feature stores. Region content swapped in by the Interactivity
 * router does not re-fire DOMContentLoaded, so any imperative setup bound only
 * to it silently dies after the first client-side navigation.
 *
 * onNavReady(init) runs `init` on initial load AND again after every
 * client-side navigation (the `buddynext:navigated` event dispatched by
 * shell/navigate.js). `init` MUST be idempotent — guard per-element work with a
 * dataset flag, and install document/window-delegated listeners behind a single
 * window flag — so re-running only wires freshly-swapped nodes.
 *
 * Chrome/global setup that lives outside the router region (font scaling,
 * consent banner, history sync) persists across navigations and must NOT
 * re-run — pass { once: true } so it binds on initial load only.
 */

// Side-effect import: load the one modal keyboard-accessibility primitive
// wherever a store loads. modal-a11y installs a document-level observer once and
// needs no per-feature wiring, so importing it here — from the module every
// store already depends on — makes every modal in the product keyboard- and
// screen-reader-accessible with no code in the feature stores themselves.
import '@buddynext/modal-a11y';

/**
 * Bind an init function to initial load and (unless `once`) every client nav.
 *
 * @param {Function} init           Idempotent setup to run.
 * @param {Object}   [options]      Options.
 * @param {boolean}  [options.once] When true, bind initial load only.
 * @return {void}
 */
export function onNavReady( init, { once = false } = {} ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	if ( ! once ) {
		document.addEventListener( 'buddynext:navigated', init );
	}
}

/**
 * The address of page `n` of the current list, in WordPress's /page/N/ shape.
 *
 * Mirrors core's get_pagenum_link() so links a store builds client-side match
 * the server pager (parts/pagination.php): any existing /page/N/ segment and
 * the legacy ?paged / ?bn_page args are dropped, page 1 is the bare list URL,
 * and every other query arg (filters, sort, search) is kept.
 *
 * @param {string} href Current list URL.
 * @param {number} n    Target page (1-based).
 * @return {string} Path + query of the target page.
 */
export function bnPageUrl( href, n ) {
	const u = new URL( href, window.location.origin );
	u.searchParams.delete( 'paged' );
	u.searchParams.delete( 'bn_page' );
	u.pathname = u.pathname.replace( /\/page\/\d+\/?$/, '/' );
	if ( n > 1 ) {
		u.pathname = u.pathname.replace( /\/?$/, '/' ) + 'page/' + n + '/';
	}
	return u.pathname + u.search;
}
