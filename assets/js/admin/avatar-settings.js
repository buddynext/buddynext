/**
 * BuddyNext — Avatar & Cover settings JS.
 *
 * Wires the "Select from Media Library" buttons to the WordPress media picker
 * for the default avatar and default cover image inputs, and routes destructive
 * "Remove" form submits through a small in-page confirm modal (no native
 * confirm() dialog).
 *
 * Enqueued by AvatarSettings::enqueue_assets() on the Members admin page
 * when the active tab is "avatar-settings". Depends on jQuery + wp.media.
 *
 * @package BuddyNext
 * @since   1.0.0
 */
( function ( $, wp ) {
	'use strict';

	if ( ! $ || ! wp || ! wp.media ) {
		return;
	}

	var L10n = window.bnAvatarSettingsL10n || {};

	function openMediaPicker( inputId, previewId ) {
		var frame = wp.media( {
			title: L10n.pickerTitle || 'Select Image',
			button: { text: L10n.pickerButton || 'Use this image' },
			multiple: false,
			library: { type: 'image' },
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			$( '#' + inputId ).val( att.url );
			$( '#' + previewId ).attr( 'src', att.url ).show();
		} );
		frame.open();
	}

	$( function () {
		$( '#bn-pick-avatar' ).on( 'click', function ( e ) {
			e.preventDefault();
			openMediaPicker( 'bn_default_avatar_url', 'bn-avatar-preview' );
		} );
		$( '#bn-pick-cover' ).on( 'click', function ( e ) {
			e.preventDefault();
			openMediaPicker( 'bn_default_cover_url', 'bn-cover-preview' );
		} );
	} );

	// ── Confirm modal for destructive remove submits ───────────────────────
	function ensureModal() {
		var existing = document.getElementById( 'bn-av-confirm-modal' );
		if ( existing ) {
			return existing;
		}
		var modal = document.createElement( 'div' );
		modal.id = 'bn-av-confirm-modal';
		modal.className = 'bn-modal-backdrop';
		modal.hidden = true;
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		modal.setAttribute( 'aria-labelledby', 'bn-av-confirm-title' );

		var panel = document.createElement( 'div' );
		panel.className = 'bn-modal__panel';
		panel.setAttribute( 'data-tone', 'danger' );

		var title = document.createElement( 'h2' );
		title.id = 'bn-av-confirm-title';
		title.className = 'bn-modal__title';
		title.textContent = L10n.confirmTitle || 'Confirm';

		var msg = document.createElement( 'p' );
		msg.className = 'bn-modal__msg';

		var actions = document.createElement( 'div' );
		actions.className = 'bn-modal__actions';

		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'bn-btn';
		cancel.setAttribute( 'data-variant', 'ghost' );
		cancel.textContent = L10n.cancel || 'Cancel';

		var confirm = document.createElement( 'button' );
		confirm.type = 'button';
		confirm.className = 'bn-btn';
		confirm.setAttribute( 'data-variant', 'danger' );
		confirm.id = 'bn-av-confirm-yes';
		confirm.textContent = L10n.confirm || 'Remove';

		actions.appendChild( cancel );
		actions.appendChild( confirm );
		panel.appendChild( title );
		panel.appendChild( msg );
		panel.appendChild( actions );
		modal.appendChild( panel );
		document.body.appendChild( modal );

		cancel.addEventListener( 'click', function () {
			modal.hidden = true;
			modal._pending = null;
		} );
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) {
				modal.hidden = true;
				modal._pending = null;
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( ! modal.hidden && 'Escape' === e.key ) {
				modal.hidden = true;
				modal._pending = null;
			}
		} );
		confirm.addEventListener( 'click', function () {
			var pending = modal._pending;
			modal.hidden = true;
			modal._pending = null;
			if ( pending && pending.form ) {
				if ( pending.button && pending.button.name ) {
					var hidden = document.createElement( 'input' );
					hidden.type = 'hidden';
					hidden.name = pending.button.name;
					hidden.value = pending.button.value || '1';
					pending.form.appendChild( hidden );
				}
				pending.form.submit();
			}
		} );
		return modal;
	}

	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '[data-bn-confirm]' );
		if ( ! trigger ) {
			return;
		}
		var form = trigger.closest( 'form' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		var modal = ensureModal();
		modal.querySelector( '.bn-modal__msg' ).textContent = trigger.dataset.bnConfirm;
		modal._pending = { form: form, button: trigger };
		modal.hidden = false;
		var yes = document.getElementById( 'bn-av-confirm-yes' );
		if ( yes ) {
			yes.focus();
		}
	}, true );
}( window.jQuery, window.wp ) );
