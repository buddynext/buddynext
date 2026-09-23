/**
 * BuddyNext — cookie consent banner.
 *
 * Reveals the [data-bn-cookie-consent] banner (rendered hidden by
 * CookieConsentService::render) and persists acknowledgement in a first-party
 * cookie on accept, storing the version acknowledged (data-cookie-version).
 * The cookie name is read from the banner's data-cookie-name attribute.
 * Always enqueued while the notice is on, so page caches stay correct.
 */
( function () {
	'use strict';

	function init() {
		var el = document.querySelector( '[data-bn-cookie-consent]' );
		if ( ! el ) {
			return;
		}
		var name = el.getAttribute( 'data-cookie-name' ) || 'bn_cookie_consent';
		var version = el.getAttribute( 'data-cookie-version' ) || '1';
		// Hidden only when the visitor acknowledged THIS version; a changed
		// notice or policy page carries a new version and asks again.
		if ( ( '; ' + document.cookie + ';' ).indexOf( '; ' + name + '=' + version + ';' ) !== -1 ) {
			if ( el.parentNode ) {
				el.parentNode.removeChild( el );
			}
			return;
		}
		el.hidden = false;
		var btn = el.querySelector( '[data-bn-cookie-accept]' );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				document.cookie = name + '=' + version + '; max-age=' + ( 60 * 60 * 24 * 365 ) + '; path=/; samesite=lax' + ( 'https:' === window.location.protocol ? '; secure' : '' );
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
