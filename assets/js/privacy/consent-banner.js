/**
 * BuddyNext — cookie consent banner.
 *
 * Reveals the [data-bn-cookie-consent] banner (rendered hidden by
 * CookieConsentService::render) and persists acknowledgement in a first-party
 * cookie on accept. The cookie name is read from the banner's data-cookie-name
 * attribute. Enqueued by CookieConsentService::enqueue_assets only when the
 * visitor has not yet acknowledged.
 */
( function () {
	'use strict';

	function init() {
		var el = document.querySelector( '[data-bn-cookie-consent]' );
		if ( ! el ) {
			return;
		}
		var name = el.getAttribute( 'data-cookie-name' ) || 'bn_cookie_consent';
		if ( document.cookie.indexOf( name + '=' ) !== -1 ) {
			if ( el.parentNode ) {
				el.parentNode.removeChild( el );
			}
			return;
		}
		el.hidden = false;
		var btn = el.querySelector( '[data-bn-cookie-accept]' );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				document.cookie = name + '=1; max-age=' + ( 60 * 60 * 24 * 365 ) + '; path=/; samesite=lax';
				if ( el.parentNode ) {
					el.parentNode.removeChild( el );
				}
			} );
		}
	}

	// Inlined nav-init (once) — this file is a classic IIFE, not an ES module,
	// so it cannot import shell/nav-init.js. The consent banner is a global
	// chrome surface that persists across client-side navigations, so it binds
	// on initial load only — equivalent to onNavReady( init, { once: true } ).
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
