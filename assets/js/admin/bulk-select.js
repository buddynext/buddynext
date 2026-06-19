/**
 * BuddyNext admin — shared bulk-select wiring for hand-rolled list tables.
 *
 * Any admin table marked `data-bn-bulk="{form-id}"` gets:
 *   - a header select-all checkbox (thead) that toggles every row checkbox
 *     (.bn-bulk-cb in tbody), with indeterminate state on partial selection;
 *   - a submit guard on the associated bulk <form id="{form-id}"> so it won't
 *     POST without a chosen action AND at least one selected row.
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
			var formId    = table.getAttribute( 'data-bn-bulk' );
			var form      = formId ? document.getElementById( formId ) : null;
			var selectAll = table.querySelector( 'thead input[type="checkbox"]' );

			function rowBoxes() {
				return Array.prototype.slice.call( table.querySelectorAll( 'tbody .bn-bulk-cb' ) );
			}

			if ( selectAll ) {
				selectAll.addEventListener( 'change', function () {
					rowBoxes().forEach( function ( box ) {
						box.checked = selectAll.checked;
					} );
				} );
			}

			// Keep the header checkbox state in sync with the row selection.
			table.addEventListener( 'change', function ( e ) {
				if ( ! e.target.classList || ! e.target.classList.contains( 'bn-bulk-cb' ) ) {
					return;
				}
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
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initBulkSelect );
	} else {
		initBulkSelect();
	}
}() );
