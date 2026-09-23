/**
 * BuddyNext admin — Activity screen bulk confirm.
 *
 * The Activity bulk bar (Delete / Hide / Restore) submits through the shared
 * bulk-select behaviour; this adds a count- and action-aware confirmation via the
 * shared bnConfirm() modal (never window.confirm()) for the two consequential
 * actions, Delete and Hide. Restore is reversible and submits without a prompt.
 * Empty action / empty selection are already blocked by bulk-select.js.
 *
 * @package BuddyNext\Admin
 */

( function () {
	var i18n    = window.wp && window.wp.i18n;
	var __      = i18n ? i18n.__ : function ( s ) {
		return s; };
	var _n      = i18n ? i18n._n : function ( s, p, n ) {
		return 1 === n ? s : p; };
	var sprintf = i18n ? i18n.sprintf : function ( s ) {
		return s; };

	function init() {
		var form = document.getElementById( 'bn-activity-bulk' );
		if ( ! form || 'function' !== typeof window.bnConfirm ) {
			return;
		}

		form.addEventListener(
			'submit',
			function ( e ) {
				var actionEl = form.querySelector( '[name="bulk_action"]' );
				var action   = actionEl ? actionEl.value : '';
				if ( 'delete' !== action && 'hide' !== action ) {
					return; // Restore (or nothing chosen): no prompt.
				}

				var count = Array.prototype.slice
				.call( document.querySelectorAll( 'table[data-bn-bulk="bn-activity-bulk"] .bn-bulk-cb' ) )
				.filter(
					function ( box ) {
						return box.checked; }
				).length;
				if ( 0 === count ) {
					return; // bulk-select.js blocks this too.
				}

				e.preventDefault();

				var opts = 'delete' === action
				? {
					title:       __( 'Delete posts?', 'buddynext' ),
					message:     sprintf( _n( 'Delete %d post? This cannot be undone.', 'Delete %d posts? This cannot be undone.', count, 'buddynext' ), count ),
					tone:        'danger',
					okLabel:     __( 'Delete', 'buddynext' ),
					cancelLabel: __( 'Cancel', 'buddynext' ),
				}
				: {
					title:       __( 'Hide posts?', 'buddynext' ),
					message:     sprintf( _n( 'Hide %d post from the feed?', 'Hide %d posts from the feed?', count, 'buddynext' ), count ),
					tone:        'warning',
					okLabel:     __( 'Hide', 'buddynext' ),
					cancelLabel: __( 'Cancel', 'buddynext' ),
				};

				window.bnConfirm( opts ).then(
					function ( confirmed ) {
						if ( confirmed ) {
								// form.submit() bypasses the submit event, so this posts directly.
								form.submit();
						}
					}
				);
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
