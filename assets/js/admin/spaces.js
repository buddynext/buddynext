/**
 * BuddyNext — Admin Spaces page script.
 *
 * Replaces the inline onsubmit="return confirm(...)" with a v2 modal flow:
 *   - Clicking the per-row "Delete" button opens a confirm modal scoped to
 *     that row.
 *   - "Cancel" or backdrop click closes the modal.
 *   - "Delete permanently" submits the associated hidden delete form.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

( function () {
	'use strict';

	var modal = document.querySelector( '[data-bn-modal="delete-space"]' );
	if ( ! modal ) {
		return;
	}

	var confirmBtn = modal.querySelector( '[data-bn-confirm-delete]' );
	var activeForm = null;

	function openModal( form ) {
		activeForm = form;
		modal.hidden = false;
		var closeBtn = modal.querySelector( '.bn-modal__close' );
		if ( closeBtn ) {
			closeBtn.focus();
		}
	}

	function closeModal() {
		modal.hidden = true;
		activeForm = null;
	}

	// Wire up the per-row "Delete" triggers.
	document.querySelectorAll( '[data-bn-delete-space-trigger]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var form = this.closest( 'form' );
			if ( form ) {
				openModal( form );
			}
		} );
	} );

	// Close on cancel / X / backdrop.
	modal.querySelectorAll( '[data-bn-modal-close]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', closeModal );
	} );
	modal.addEventListener( 'click', function ( e ) {
		if ( e.target === modal ) {
			closeModal();
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! modal.hidden ) {
			closeModal();
		}
	} );

	// Confirm submits the active form.
	if ( confirmBtn ) {
		confirmBtn.addEventListener( 'click', function () {
			if ( activeForm ) {
				activeForm.submit();
			}
		} );
	}
}() );
