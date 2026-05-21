/**
 * BuddyNext — Profile Fields admin tab JS.
 *
 * Powers the Profile Fields manager UI:
 *   - Toggles the add-field panel per group (single-open behaviour).
 *   - Toggles the inline edit-field row.
 *   - Shows/hides the Options textarea (choice types) and date display select
 *     (date types) when the field type changes.
 *   - Two-step inline delete confirmation (no native confirm()).
 *
 * Enqueued by ProfileFieldsManager::enqueue_assets() on the Members admin page
 * when the active tab is "profile-fields".
 *
 * @package BuddyNext
 * @since   1.0.0
 */
( function () {
	'use strict';

	var CHOICE_TYPES = [ 'select', 'multiselect', 'radio', 'checkbox' ];
	var DATE_TYPES   = [ 'date', 'daterange' ];

	function toggleAddPanel( panelId ) {
		var el = document.getElementById( panelId );
		if ( ! el ) {
			return;
		}
		if ( el.classList.contains( 'bn-open' ) ) {
			el.classList.remove( 'bn-open' );
			return;
		}
		document.querySelectorAll( '.bn-pf-af-panel.bn-open' ).forEach( function ( p ) {
			p.classList.remove( 'bn-open' );
		} );
		el.classList.add( 'bn-open' );
		var input = el.querySelector( 'input[type="text"]' );
		if ( input ) {
			input.focus();
		}
	}

	function toggleEditRow( rowId ) {
		var row = document.getElementById( rowId );
		if ( ! row ) {
			return;
		}
		var isHidden = ( 'none' === row.style.display || '' === row.style.display );
		document.querySelectorAll( 'tr[id^="bn-ef-row-"]' ).forEach( function ( r ) {
			r.style.display = 'none';
		} );
		if ( isHidden ) {
			row.style.display = 'table-row';
		}
	}

	function onTypeChange( selectEl, optWrapId, dateWrapId ) {
		var type    = selectEl.value;
		var optWrap = document.getElementById( optWrapId );
		var dateWrap = dateWrapId ? document.getElementById( dateWrapId ) : null;
		if ( optWrap ) {
			optWrap.style.display = CHOICE_TYPES.indexOf( type ) >= 0 ? 'block' : 'none';
		}
		if ( dateWrap ) {
			dateWrap.style.display = DATE_TYPES.indexOf( type ) >= 0 ? 'block' : 'none';
		}
	}

	// Delegated handlers for toggles.
	document.addEventListener( 'click', function ( e ) {
		var addToggle = e.target.closest( '[data-bn-pf-toggle]' );
		if ( addToggle ) {
			e.preventDefault();
			toggleAddPanel( addToggle.dataset.bnPfToggle );
			return;
		}
		var editToggle = e.target.closest( '[data-bn-pf-toggle-edit]' );
		if ( editToggle ) {
			toggleEditRow( editToggle.dataset.bnPfToggleEdit );
		}
	} );

	// Delegated handler for the type <select> elements (shared add + edit forms).
	document.addEventListener( 'change', function ( e ) {
		var sel = e.target;
		if ( ! ( sel instanceof HTMLSelectElement ) ) {
			return;
		}
		var optWrapId  = sel.getAttribute( 'data-bn-pf-opts-wrap' );
		var dateWrapId = sel.getAttribute( 'data-bn-pf-date-wrap' );
		if ( ! optWrapId && ! dateWrapId ) {
			return;
		}
		onTypeChange( sel, optWrapId, dateWrapId );
	} );

	// Inline two-step delete confirmation — no browser dialogs.
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.matches && e.target.matches( '.bn-del-trigger' ) ) {
			var form = e.target.closest( '.bn-del-form' );
			if ( ! form ) {
				return;
			}
			e.target.style.display = 'none';
			var confirmBtn = form.querySelector( '.bn-del-confirm' );
			var cancelBtn  = form.querySelector( '.bn-del-cancel' );
			if ( confirmBtn ) {
				confirmBtn.style.display = 'inline-flex';
			}
			if ( cancelBtn ) {
				cancelBtn.style.display = 'inline-flex';
			}
		}
		if ( e.target.matches && e.target.matches( '.bn-del-cancel' ) ) {
			var form2 = e.target.closest( '.bn-del-form' );
			if ( ! form2 ) {
				return;
			}
			var trigger = form2.querySelector( '.bn-del-trigger' );
			var confirm2 = form2.querySelector( '.bn-del-confirm' );
			var cancel2  = form2.querySelector( '.bn-del-cancel' );
			if ( trigger ) {
				trigger.style.display = 'inline-flex';
			}
			if ( confirm2 ) {
				confirm2.style.display = 'none';
			}
			if ( cancel2 ) {
				cancel2.style.display = 'none';
			}
		}
	} );

	// Auto-submit visibility / required inline forms when the control changes.
	document.addEventListener( 'change', function ( e ) {
		var el = e.target.closest( '[data-bn-autosubmit]' );
		if ( el && el.form ) {
			el.form.submit();
		}
	} );
}() );
