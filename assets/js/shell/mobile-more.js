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

	// The sheet is aria-modal: Tab stays inside it and the page behind does not scroll while it is open. This script is
	// standalone (it ships to pages without the hub shell), so the trap is small and local.
	var releaseTrap = null;
	function trap( s ) {
		function onKey( e ) {
			if ( e.key !== 'Tab' ) return;
			var stops = Array.prototype.filter.call(
				s.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])' ),
				function ( el ) { return el.getClientRects().length > 0; }
			);
			if ( ! stops.length ) return;
			var at = stops.indexOf( document.activeElement );
			var next = -1 === at ? ( e.shiftKey ? stops.length - 1 : 0 ) : ( at + ( e.shiftKey ? -1 : 1 ) + stops.length ) % stops.length;
			e.preventDefault();
			stops[ next ].focus();
		}
		document.addEventListener( 'keydown', onKey, true );
		document.body.classList.add( 'bn-modal-open' );
		return function () {
			document.removeEventListener( 'keydown', onKey, true );
			document.body.classList.remove( 'bn-modal-open' );
		};
	}

	function setOpen( open ) {
		var s = sheet();
		var b = backdrop();
		if ( ! s ) return;
		if ( open && ! releaseTrap ) { releaseTrap = trap( s ); }
		if ( ! open && releaseTrap ) { releaseTrap(); releaseTrap = null; }
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
