/**
 * BuddyNext mobile bottom bar: open and close the "More" sheet (templates/partials/mobile-nav.php).
 *
 * Standalone so a page that shows the bar without the hub shell (a Jetonomy page under
 * BuddyNext) gets it without shell/extras.js. Delegated from the document; needs no data.
 *
 * @package BuddyNext
 */
// ── Mobile "More" overflow sheet ───────────────────────────────────────
// The bottom bar folds the Profile shortcut + admin-created custom tabs into
// a bottom sheet (nav.php) when overflow items exist. Toggle/close it here.
( function () {
	function sheet() { return document.getElementById( 'bn-mobile-more' ); }
	function backdrop() { return document.querySelector( '.bn-mobile-more-backdrop' ); }

	function setOpen( open ) {
		var s = sheet();
		var b = backdrop();
		if ( ! s ) return;
		s.hidden = ! open;
		if ( b ) b.hidden = ! open;
		var toggle = document.querySelector( '[data-bn-more-toggle]' );
		if ( toggle ) toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		var focusTo = open ? s.querySelector( '.bn-mobile-more__close' ) : toggle;
		if ( focusTo ) focusTo.focus();
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) return;
		if ( e.target.closest( '[data-bn-more-toggle]' ) ) {
			e.preventDefault();
			setOpen( sheet() && sheet().hidden );
			return;
		}
		if ( e.target.closest( '[data-bn-more-close]' ) ) {
			e.preventDefault();
			setOpen( false );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			var s = sheet();
			if ( s && ! s.hidden ) setOpen( false );
		}
	} );
}() );
