/**
 * BuddyNext admin command palette integration.
 *
 * Rather than ship a competing overlay, this registers every BuddyNext admin
 * screen into WordPress core's native command palette (@wordpress/commands,
 * Cmd/Ctrl+K). The index is localized from PHP (window.bnNavIndex) by AdminHub,
 * built from the AdminHub tab registry — so Pro screens appear automatically
 * when Pro is active, and only Free screens show when it is not.
 */
( function () {
	'use strict';

	var INDEX = window.bnNavIndex || [];
	if ( ! INDEX.length || ! window.wp || ! wp.data ) {
		return;
	}

	var store;
	try {
		store = wp.data.dispatch( 'core/commands' );
	} catch ( e ) {
		return;
	}
	if ( ! store || typeof store.registerCommand !== 'function' ) {
		return;
	}

	// The brand-bar "Search" button opens WordPress core's native palette.
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-bn-open-command]' ) ) {
			e.preventDefault();
			try {
				wp.data.dispatch( 'core/commands' ).open();
			} catch ( err ) {}
		}
	} );

	INDEX.forEach( function ( item, i ) {
		var url = item.url;
		if ( ! url ) {
			return;
		}
		store.registerCommand( {
			name: 'buddynext/nav-' + i,
			// "Section: Label" reads clearly and is searchable by either part.
			label: item.section + ': ' + item.label,
			searchLabel: item.label + ' ' + item.section + ' ' + ( item.sub || '' ),
			callback: function ( args ) {
				if ( args && typeof args.close === 'function' ) {
					args.close();
				}
				window.location.href = url;
			},
		} );
	} );
}() );
