/**
 * BuddyNext — Member Types admin tab JS.
 *
 * Powers the Member Types manager UI:
 *   - Auto-generates slug from the name field.
 *   - Live updates the badge preview when name/colours change.
 *   - Toggles row "more" menus (single-open behaviour).
 *   - Delegated confirm modal for destructive form submits via [data-bn-confirm].
 *
 * Enqueued by MemberTypesManager::enqueue_assets() on the Members admin page
 * when the active tab is "member-types".
 *
 * @package BuddyNext
 * @since   1.0.0
 */
( function () {
	'use strict';

	function init() {
		// Auto-generate slug from name when the slug field is empty.
		var nameInput = document.getElementById( 'bn-type-name' );
		var slugInput = document.getElementById( 'bn-type-slug' );
		if ( nameInput && slugInput && ! slugInput.value ) {
			nameInput.addEventListener( 'input', function () {
				slugInput.value = nameInput.value
					.toLowerCase()
					.replace( /[^a-z0-9]+/g, '-' )
					.replace( /^-|-$/g, '' );
			} );
		}

		// Live badge preview.
		var previewFallback =
			( window.bnMemberTypesL10n && window.bnMemberTypesL10n.previewLabel ) ||
			'Preview';

		function updatePreview() {
			var badge = document.getElementById( 'bn-badge-preview' );
			if ( ! badge ) {
				return;
			}
			var nameEl = document.getElementById( 'bn-type-name' );
			var bgEl   = document.getElementById( 'bn-type-color' );
			var fgEl   = document.getElementById( 'bn-type-text-color' );
			var name   = nameEl ? nameEl.value : '';
			var bg     = bgEl ? bgEl.value : '';
			var fg     = fgEl ? fgEl.value : '';
			if ( bg ) {
				badge.style.background = bg;
			}
			if ( fg ) {
				badge.style.color = fg;
			}
			var label = badge.querySelector( '.bn-badge-label' );
			if ( label ) {
				label.textContent = name || previewFallback;
			}
		}

		[ 'bn-type-name', 'bn-type-color', 'bn-type-text-color' ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.addEventListener( 'input', updatePreview );
			}
		} );
		updatePreview();

		// "More" menu toggles (single-open).
		document.querySelectorAll( '.bn-more-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var menu = btn.closest( '.bn-more-menu' );
				if ( ! menu ) {
					return;
				}
				document.querySelectorAll( '.bn-more-menu.open' ).forEach( function ( m ) {
					if ( m !== menu ) {
						m.classList.remove( 'open' );
					}
				} );
				menu.classList.toggle( 'open' );
			} );
		} );
		document.addEventListener( 'click', function () {
			document.querySelectorAll( '.bn-more-menu.open' ).forEach( function ( m ) {
				m.classList.remove( 'open' );
			} );
		} );
	}

	// Delegated confirm handler for destructive form submits.
	// Routes through a small in-page modal so UX stays on the design system
	// instead of triggering a browser chrome dialog.
	function ensureModal() {
		var existing = document.getElementById( 'bn-mt-confirm-modal' );
		if ( existing ) {
			return existing;
		}
		var modal = document.createElement( 'div' );
		modal.id = 'bn-mt-confirm-modal';
		modal.className = 'bn-modal-backdrop';
		modal.hidden = true;
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		modal.setAttribute( 'aria-labelledby', 'bn-mt-confirm-title' );

		var panel = document.createElement( 'div' );
		panel.className = 'bn-modal__panel';
		panel.setAttribute( 'data-tone', 'danger' );

		var title = document.createElement( 'h2' );
		title.id = 'bn-mt-confirm-title';
		title.className = 'bn-modal__title';
		title.textContent =
			( window.bnMemberTypesL10n && window.bnMemberTypesL10n.confirmTitle ) ||
			'Confirm';

		var msg = document.createElement( 'p' );
		msg.className = 'bn-modal__msg';

		var actions = document.createElement( 'div' );
		actions.className = 'bn-modal__actions';

		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'bn-btn';
		cancel.setAttribute( 'data-variant', 'ghost' );
		cancel.textContent =
			( window.bnMemberTypesL10n && window.bnMemberTypesL10n.cancel ) || 'Cancel';

		var confirm = document.createElement( 'button' );
		confirm.type = 'button';
		confirm.className = 'bn-btn';
		confirm.setAttribute( 'data-variant', 'danger' );
		confirm.id = 'bn-mt-confirm-yes';
		confirm.textContent =
			( window.bnMemberTypesL10n && window.bnMemberTypesL10n.confirm ) || 'Delete';

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
				// Re-submit the form, bypassing the confirm interceptor.
				pending.bypass = true;
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
		var modal = ensureModal();
		if ( modal._pending && modal._pending.bypass ) {
			modal._pending = null;
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		modal.querySelector( '.bn-modal__msg' ).textContent = trigger.dataset.bnConfirm;
		modal._pending = { form: form, button: trigger };
		modal.hidden = false;
		var yes = document.getElementById( 'bn-mt-confirm-yes' );
		if ( yes ) {
			yes.focus();
		}
	}, true );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
