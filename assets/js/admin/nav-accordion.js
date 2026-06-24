/* BuddyNext admin left-nav accordion.
 *
 * The sidebar renders each section as a native <details> group; the section
 * that owns the current page is open server-side. This script only persists
 * the owner's MANUAL expansions across page loads (localStorage) and restores
 * them on the next screen — the active section always stays open regardless.
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
}() );
