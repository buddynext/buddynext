/**
 * BuddyNext admin — shared bulk-select wiring for hand-rolled list tables.
 *
 * Any admin table marked `data-bn-bulk="{form-id}"` gets:
 *   - a header select-all checkbox (thead) that toggles every row checkbox
 *     (.bn-bulk-cb in tbody), with indeterminate state on partial selection;
 *   - the form's Apply button disabled until a bulk action is chosen AND at least
 *     one row is ticked, so a no-op click is never silently swallowed;
 *   - a submit guard on the associated bulk <form id="{form-id}"> as a backstop, so
 *     it won't POST without a chosen action AND at least one selected row even if
 *     the button's disabled state is defeated.
 *
 * Row checkboxes associate with the bulk form via the form="" attribute, so
 * they are NOT nested inside the table's per-row action forms (invalid HTML).
 *
 * Enqueued on the Members and Spaces admin list pages.
 *
 * @package BuddyNext\Admin
 */
( function () {
	'use strict';

	function initBulkSelect() {
		var tables = document.querySelectorAll( 'table[data-bn-bulk]' );
		if ( ! tables.length ) {
			return;
		}

		tables.forEach( function ( table ) {
			var formId       = table.getAttribute( 'data-bn-bulk' );
			var form         = formId ? document.getElementById( formId ) : null;
			var selectAll    = table.querySelector( 'thead input[type="checkbox"]' );
			var applyBtn     = form ? form.querySelector( 'button[type="submit"], input[type="submit"]' ) : null;
			var actionSelect = form ? form.querySelector( '[name="bulk_action"]' ) : null;

			function rowBoxes() {
				return Array.prototype.slice.call( table.querySelectorAll( 'tbody .bn-bulk-cb' ) );
			}

			// Disable Apply until a verb is chosen AND at least one row is ticked, so a
			// no-op click is not silently swallowed (the submit guard below still fires
			// as a backstop, since a disabled attribute can be defeated). A select with
			// no bulk_action control (some tables) leaves the verb condition satisfied.
			function syncApply() {
				if ( ! applyBtn ) {
					return;
				}
				var hasAction  = actionSelect ? !! actionSelect.value : true;
				var anyChecked = rowBoxes().some( function ( box ) { return box.checked; } );
				applyBtn.disabled = ! ( hasAction && anyChecked );
			}

			if ( selectAll ) {
				selectAll.addEventListener( 'change', function () {
					rowBoxes().forEach( function ( box ) {
						box.checked = selectAll.checked;
					} );
					syncApply();
				} );
			}

			if ( actionSelect ) {
				actionSelect.addEventListener( 'change', syncApply );
			}

			// Keep the header checkbox state in sync with the row selection, and the
			// Apply button in sync with whether anything is ticked.
			table.addEventListener( 'change', function ( e ) {
				if ( ! e.target.classList || ! e.target.classList.contains( 'bn-bulk-cb' ) ) {
					return;
				}
				// Reflect the new row selection on Apply regardless of whether this
				// table has a header select-all checkbox.
				syncApply();
				if ( ! selectAll ) {
					return;
				}
				var boxes   = rowBoxes();
				var checked = boxes.filter( function ( box ) { return box.checked; } ).length;
				selectAll.checked       = checked > 0 && checked === boxes.length;
				selectAll.indeterminate = checked > 0 && checked < boxes.length;
			} );

			// Don't submit an empty bulk action (no verb chosen, or no rows picked).
			if ( form ) {
				form.addEventListener( 'submit', function ( e ) {
					var action     = form.querySelector( '[name="bulk_action"]' );
					var anyChecked = rowBoxes().some( function ( box ) { return box.checked; } );
					if ( ! action || ! action.value || ! anyChecked ) {
						e.preventDefault();
					}
				} );
			}

			// Set the initial state: with nothing ticked, Apply starts disabled.
			syncApply();
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initBulkSelect );
	} else {
		initBulkSelect();
	}
}() );
