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

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
