/* BuddyNext admin save bar: unsaved-change indicator.
 *
 * Every settings tab ends in one `.bn-save-bar` inside its <form>. This script
 * shows "Unsaved changes" in that bar once a named field changes, and asks the
 * browser to confirm before the owner leaves the tab with edits not saved.
 * Fields without a name (search boxes, filters) are not settings and never
 * count. Without JS the bar is just a Save button, as before.
 */
( function () {
	'use strict';

	var label = ( window.bnSaveBar && window.bnSaveBar.unsaved ) || 'Unsaved changes';
	var dirty = false;
	var saving = false;

	document.querySelectorAll( '.bn-save-bar' ).forEach( function ( bar ) {
		var form = bar.closest( 'form' );
		if ( ! form ) {
			return;
		}

		var status = document.createElement( 'span' );
		status.className = 'bn-save-bar__status';
		status.setAttribute( 'role', 'status' );
		bar.insertBefore( status, bar.firstChild );

		function mark( event ) {
			var field = event.target;
			if ( ! field.name || 'search' === field.type || field.hasAttribute( 'data-bn-no-dirty' ) ) {
				return;
			}
			dirty = true;
			bar.setAttribute( 'data-dirty', 'true' );
			status.textContent = label;
		}

		form.addEventListener( 'input', mark );
		form.addEventListener( 'change', mark );
		form.addEventListener( 'submit', function () {
			saving = true;
		} );
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty && ! saving ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
}() );
