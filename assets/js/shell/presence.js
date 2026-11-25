/* BuddyNext - presence ping.
 *
 * Keeps a signed-in member "online" when no server-rendered page view stamps
 * them: after a client-side navigation (the router swaps the page without a
 * request that reaches template_redirect), when a tab comes back into view, and
 * on a page a cache served (the cached HTML never ran template_redirect).
 *
 * Event-driven only. No timer, no Heartbeat API, no admin-ajax: a hidden or idle
 * tab sends nothing. Each tab pings at most once per MIN_GAP_MS (remembered in
 * sessionStorage so full page loads in the same tab share the budget), and the
 * server writes at most once per member per minute (PresenceService::stamp()).
 *
 * Enqueued for signed-in members on every BuddyNext hub (PageRouter).
 */

import { restFetch } from '@buddynext/rest-client';

const STORAGE_KEY = 'bnPresencePingAt';
const MIN_GAP_MS = 4 * 60 * 1000;

let lastPingAt = 0;
let stopped = false;

/**
 * POST /me/presence/heartbeat when the tab is visible and the gap has passed.
 *
 * sessionStorage can be unavailable (private mode, blocked storage); the
 * in-memory copy then throttles this page on its own.
 *
 * @return {void}
 */
function ping() {
	if ( stopped || 'visible' !== document.visibilityState ) {
		return;
	}
	let last = lastPingAt;
	try {
		last = Math.max( last, Number( window.sessionStorage.getItem( STORAGE_KEY ) ) || 0 );
	} catch ( e ) {
		// Storage blocked: fall back to the in-memory timestamp.
	}
	const now = Date.now();
	if ( now - last < MIN_GAP_MS ) {
		return;
	}
	lastPingAt = now;
	try {
		window.sessionStorage.setItem( STORAGE_KEY, String( now ) );
	} catch ( e ) {
		// Storage blocked: the in-memory timestamp still throttles this page.
	}
	// Fire and forget: presence is best effort and never surfaces an error, not even the hold notice (a
	// suspended member is told on the page they act on, not by a background ping on every load).
	restFetch( '/me/presence/heartbeat', {
		method: 'POST',
		keepalive: true,
		toastOnError: false,
		silent: true,
	} ).then( function ( result ) {
		// A refused member cannot be present: stop asking for the rest of this document.
		if ( result && ( result.suspended || 403 === result.status ) ) {
			stopped = true;
		}
	} );
}

// A module evaluates once per document, so these bind once; a client-side
// navigation keeps the document and never re-runs this.
document.addEventListener( 'buddynext:navigated', ping );
document.addEventListener( 'visibilitychange', ping );
if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', ping );
} else {
	ping();
}
