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

	// Translation runtime — available for any user-facing string added later.
	// All current labels/confirm text are server-rendered (PHP-translated), so
	// nothing here is wrapped today; the import keeps the script i18n-ready.
	// eslint-disable-next-line no-unused-vars
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) ? window.wp.i18n.__ : function ( s ) { return s; };

	// The field-type registry, localised by ProfileFieldsManager as
	// `bnProfileFieldTypes` (slug => { isChoice, isDate, ... }). It is the single
	// source of truth for which type reveals the Options textarea and which reveals
	// the Date Format box, so add-on types registered through buddynext_field_types
	// (Pro's multi_select_advanced, date_extended) are handled without a second
	// hardcoded list here drifting out of sync with the PHP registry — the exact bug
	// that hid those boxes for Pro types.
	var FIELD_TYPES = ( window.bnProfileFieldTypes && 'object' === typeof window.bnProfileFieldTypes )
		? window.bnProfileFieldTypes
		: {};

	// Fallback for the core types only, used if the registry failed to localise.
	var CHOICE_TYPES = [ 'select', 'multiselect', 'radio', 'checkbox' ];
	var DATE_TYPES   = [ 'date', 'daterange' ];

	function isChoiceType( type ) {
		if ( FIELD_TYPES[ type ] ) {
			return !! FIELD_TYPES[ type ].isChoice;
		}
		return CHOICE_TYPES.indexOf( type ) >= 0;
	}

	function isDateType( type ) {
		if ( FIELD_TYPES[ type ] ) {
			return !! FIELD_TYPES[ type ].isDate;
		}
		return DATE_TYPES.indexOf( type ) >= 0;
	}

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
			optWrap.style.display = isChoiceType( type ) ? 'block' : 'none';
		}
		if ( dateWrap ) {
			dateWrap.style.display = isDateType( type ) ? 'block' : 'none';
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

	// The two-step delete confirmation used to live here: it revealed an impact
	// panel and a Yes/Cancel pair INSIDE the form, which forced the 64px action
	// column open and wrapped the warning to one or two words per line. Both
	// delete forms now carry data-bn-confirm and open the shared destructive
	// dialog that members.js already drives (Basecamp 10264027382), so nothing
	// on this screen reveals a confirmation in place any more.

	// Auto-submit visibility / required inline forms when the control changes.
	document.addEventListener( 'change', function ( e ) {
		var el = e.target.closest( '[data-bn-autosubmit]' );
		if ( el && el.form ) {
			el.form.submit();
		}
	} );

	/*
	 * Bring the Add Group form into view when its disclosure opens.
	 *
	 * The control sits at the bottom of a page thousands of pixels tall, so the
	 * revealed form can open just past the fold. This is a progressive
	 * enhancement only: <details> already reveals the form in place with no page
	 * load and no scroll loss, which is the actual fix. Without JS the admin
	 * simply scrolls a little.
	 */
	document.addEventListener( 'toggle', function ( e ) {
		var details = e.target;
		if ( ! details.classList || ! details.classList.contains( 'bn-pf-add-group' ) || ! details.open ) {
			return;
		}
		var field = details.querySelector( '#bn-ag-label' );
		if ( ! field ) {
			return;
		}
		var rect = field.getBoundingClientRect();
		if ( rect.bottom > window.innerHeight ) {
			details.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
		}
	}, true ); // capture: the toggle event does not bubble.
}() );
