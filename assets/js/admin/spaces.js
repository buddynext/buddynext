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

	// ── Category subtab — confirm-before-delete via lightweight modal. ───
	// Built with document.createElement so no inline <script> sits in PHP
	// and no innerHTML carries user-supplied strings. The dataset attributes
	// from the delete button populate the dialog text via textContent.
	var catConfirm = null;
	function makeBtn( variant, size, attrs, label ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'bn-btn';
		b.setAttribute( 'data-variant', variant );
		b.setAttribute( 'data-size', size );
		Object.keys( attrs || {} ).forEach( function ( k ) { b.setAttribute( k, attrs[ k ] ); } );
		b.textContent = label;
		return b;
	}
	function ensureCatConfirm() {
		if ( catConfirm ) { return catConfirm; }
		var bd = document.createElement( 'div' );
		bd.className = 'bn-modal-backdrop';
		bd.setAttribute( 'role', 'dialog' );
		bd.setAttribute( 'aria-modal', 'true' );
		bd.hidden = true;

		var panel = document.createElement( 'div' );
		panel.className = 'bn-modal__panel';
		panel.setAttribute( 'data-tone', 'danger' );
		panel.setAttribute( 'data-size', 'sm' );

		var head  = document.createElement( 'header' );
		head.className = 'bn-modal__head';
		var title = document.createElement( 'h2' );
		title.className = 'bn-modal__title';
		title.setAttribute( 'data-bn-cat-confirm-title', '' );
		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'bn-modal__close';
		closeBtn.setAttribute( 'aria-label', 'Close' );
		closeBtn.setAttribute( 'data-bn-cat-confirm-cancel', '' );
		closeBtn.textContent = '×';
		head.appendChild( title );
		head.appendChild( closeBtn );

		var body  = document.createElement( 'div' );
		body.className = 'bn-modal__body';
		var msg   = document.createElement( 'p' );
		msg.setAttribute( 'data-bn-cat-confirm-message', '' );
		body.appendChild( msg );

		var foot  = document.createElement( 'div' );
		foot.className = 'bn-modal__foot';
		foot.appendChild( makeBtn( 'ghost',  'md', { 'data-bn-cat-confirm-cancel': '' }, 'Cancel' ) );
		foot.appendChild( makeBtn( 'danger', 'md', { 'data-bn-cat-confirm-ok':     '' }, 'Delete' ) );

		panel.appendChild( head );
		panel.appendChild( body );
		panel.appendChild( foot );
		bd.appendChild( panel );
		document.body.appendChild( bd );
		catConfirm = bd;
		return bd;
	}

	var pendingCatForm = null;
	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '[data-bn-confirm][data-bn-confirm-ok]' );
		if ( trigger && trigger.closest( '[data-bn-cat-delete-form]' ) ) {
			e.preventDefault();
			pendingCatForm = trigger.form;
			var bd  = ensureCatConfirm();
			bd.querySelector( '[data-bn-cat-confirm-title]' ).textContent   = trigger.dataset.bnConfirmTitle || 'Confirm';
			bd.querySelector( '[data-bn-cat-confirm-message]' ).textContent = trigger.dataset.bnConfirm || '';
			bd.querySelector( '[data-bn-cat-confirm-ok]' ).textContent      = trigger.dataset.bnConfirmOk || 'Delete';
			bd.querySelectorAll( '[data-bn-cat-confirm-cancel]' ).forEach( function ( btn ) {
				if ( 'BUTTON' === btn.tagName && btn.className === 'bn-btn' ) {
					btn.textContent = trigger.dataset.bnConfirmCancel || 'Cancel';
				}
			} );
			bd.hidden = false;
			return;
		}
		if ( e.target.closest( '[data-bn-cat-confirm-ok]' ) ) {
			if ( pendingCatForm ) { pendingCatForm.submit(); }
			return;
		}
		if ( e.target.closest( '[data-bn-cat-confirm-cancel]' ) ) {
			pendingCatForm = null;
			if ( catConfirm ) { catConfirm.hidden = true; }
			return;
		}
		if ( catConfirm && e.target === catConfirm ) {
			pendingCatForm = null;
			catConfirm.hidden = true;
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && catConfirm && ! catConfirm.hidden ) {
			pendingCatForm = null;
			catConfirm.hidden = true;
		}
	} );

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
