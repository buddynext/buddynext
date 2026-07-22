/**
 * BuddyNext — offline fallback page behaviour.
 *
 * The retry button reloads whatever URL the member was actually trying to reach:
 * the service worker returns this document IN PLACE OF that navigation, so the
 * address bar still holds the original URL and location.reload() retries it
 * rather than sending them to the home page.
 *
 * A separate file rather than an inline handler because inline script is a
 * blocked pattern here (ux-audit F2) — and because this file is precached with
 * the rest of the offline shell, it is present exactly when it is needed.
 */
document.addEventListener( 'DOMContentLoaded', function () {
	var retry = document.querySelector( '[data-bn-offline-retry]' );

	if ( ! retry ) {
		return;
	}

	retry.addEventListener( 'click', function () {
		window.location.reload();
	} );
} );
