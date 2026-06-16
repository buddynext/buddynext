/**
 * BuddyNext PWA — service worker registration.
 *
 * Registers the BuddyNext service worker (served from the REST route in
 * PwaService::rest_service_worker) once the page has loaded. The worker URL is
 * provided by PwaService::enqueue_sw_registration() via wp_localize_script.
 * Registration failures are non-fatal — the site keeps working without offline
 * support.
 */
( function () {
	'use strict';

	if ( ! ( 'serviceWorker' in navigator ) ) {
		return;
	}

	var cfg = window.bnPwaSw || {};
	if ( ! cfg.swUrl ) {
		return;
	}

	window.addEventListener( 'load', function () {
		navigator.serviceWorker.register( cfg.swUrl, { scope: '/' } ).catch( function () {
			// Non-fatal — offline support simply stays off.
		} );
	} );
}() );
