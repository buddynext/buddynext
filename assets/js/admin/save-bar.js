/* BuddyNext admin save bar: unsaved-change indicator.
 *
 * Every settings tab ends in one `.bn-save-bar` inside its <form>. This script
 * shows "Unsaved changes" in that bar while the form differs from how it loaded,
 * clears it when the owner types the original value back, and asks the browser to
 * confirm before leaving the tab with edits not saved. Fields without a name
 * (search boxes, filters) are not settings and never count. Without JS the bar is
 * just a Save button, as before.
 */
( function () {
	'use strict';

	var label = ( window.bnSaveBar && window.bnSaveBar.unsaved ) || 'Unsaved changes';
	var forms = [];
	var saving = false;

	function snapshot( form ) {
		var parts = [];
		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( ! field.name || 'search' === field.type || field.hasAttribute( 'data-bn-no-dirty' ) ) {
				return;
			}
			var on = ( 'checkbox' === field.type || 'radio' === field.type ) ? field.checked : field.value;
			parts.push( field.name + '=' + on );
		} );
		return parts.join( '\n' );
	}

	document.querySelectorAll( '.bn-save-bar' ).forEach( function ( bar ) {
		var form = bar.closest( 'form' );
		if ( ! form ) {
			return;
		}

		var status = document.createElement( 'span' );
		status.className = 'bn-save-bar__status';
		status.setAttribute( 'role', 'status' );
		bar.insertBefore( status, bar.firstChild );

		var entry = { form: form, start: snapshot( form ), dirty: false };
		forms.push( entry );

		function update() {
			entry.dirty = snapshot( form ) !== entry.start;
			bar.setAttribute( 'data-dirty', entry.dirty ? 'true' : 'false' );
			status.textContent = entry.dirty ? label : '';
		}

		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
		form.addEventListener( 'submit', function () {
			saving = true;
		} );
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( ! saving && forms.some( function ( entry ) { return entry.dirty; } ) ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
}() );
