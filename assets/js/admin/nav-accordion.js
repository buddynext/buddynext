/* BuddyNext admin left-nav accordion.
 *
 * The sidebar renders each section as a native <details> group; the section
 * that owns the current page is open server-side. This script persists the
 * owner's MANUAL expansions across page loads (localStorage) and restores them
 * on the next screen — the active section always stays open regardless — and
 * then scrolls the active link into view inside the panel.
 * No framework, no dependencies; degrades to plain <details> if JS is off.
 */
( function () {
	'use strict';

	var KEY   = 'bnAdminNavOpen';
	var panel = document.querySelector( '.bn-admin-hub__panel' );
	if ( ! panel ) {
		return;
	}

	var groups = panel.querySelectorAll( 'details[data-bn-nav-group]' );
	if ( ! groups.length ) {
		return;
	}

	function readSaved() {
		try {
			var raw = window.localStorage.getItem( KEY );
			var val = raw ? JSON.parse( raw ) : [];
			return Array.isArray( val ) ? val : [];
		} catch ( e ) {
			return [];
		}
	}

	function persistOpen() {
		var open = [];
		groups.forEach( function ( g ) {
			if ( g.open ) {
				open.push( g.getAttribute( 'data-bn-nav-group' ) );
			}
		} );
		try {
			window.localStorage.setItem( KEY, JSON.stringify( open ) );
		} catch ( e ) {
			/* storage unavailable — accordion still works, just not remembered */
		}
	}

	// Restore previously-expanded sections (never closes the active one).
	var saved = readSaved();
	groups.forEach( function ( g ) {
		if ( saved.indexOf( g.getAttribute( 'data-bn-nav-group' ) ) !== -1 ) {
			g.open = true;
		}
	} );

	// Remember every manual expand/collapse.
	groups.forEach( function ( g ) {
		g.addEventListener( 'toggle', persistOpen );
	} );

	/**
	 * Bring the active link into view inside the panel.
	 *
	 * The panel is its own scroll container, and every screen is a full page
	 * load, so it always starts at scrollTop 0. On a short window that puts the
	 * link for the page you are ON below the fold: the sidebar shows no
	 * selection at all and you have to hunt for where you are. Restoring saved
	 * expansions above makes it likelier, not less - each reopened section adds
	 * height above the active one.
	 *
	 * Scrolls the PANEL, never the page: element.scrollIntoView() would also
	 * scroll the document and yank the admin screen away from the top.
	 *
	 * Deliberately not "remember the scroll offset". A remembered offset is
	 * right only when you arrived by clicking in this nav - it is wrong for a
	 * bookmark, a deep link, the back button, or a redirect after saving, and
	 * wrong silently. Where the active link is, is correct in every one of
	 * those.
	 */
	function revealActive() {
		if ( panel.scrollHeight <= panel.clientHeight ) {
			return;
		}

		var active = panel.querySelector( '.bn-hub-nav-link.is-active' );
		if ( ! active ) {
			return;
		}

		var panelBox  = panel.getBoundingClientRect();
		var activeBox = active.getBoundingClientRect();

		// Already fully visible - leave the scroll position alone.
		if ( activeBox.top >= panelBox.top && activeBox.bottom <= panelBox.bottom ) {
			return;
		}

		// Centre it, and let the browser clamp at either end so the first and
		// last entries do not end up stranded against an edge.
		panel.scrollTop += ( activeBox.top - panelBox.top )
			- ( ( panel.clientHeight - activeBox.height ) / 2 );
	}

	// After layout: <details> restored above changes the panel's height, and the
	// admin stylesheet may still be settling, so rects read now would be stale.
	if ( 'requestAnimationFrame' in window ) {
		window.requestAnimationFrame( revealActive );
	} else {
		revealActive();
	}
}() );
