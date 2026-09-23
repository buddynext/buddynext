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

	const { __ } = wp.i18n;

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
		closeBtn.setAttribute( 'aria-label', __( 'Close', 'buddynext' ) );
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
		foot.appendChild( makeBtn( 'ghost',  'md', { 'data-bn-cat-confirm-cancel': '' }, __( 'Cancel', 'buddynext' ) ) );
		foot.appendChild( makeBtn( 'danger', 'md', { 'data-bn-cat-confirm-ok':     '' }, __( 'Delete', 'buddynext' ) ) );

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
			bd.querySelector( '[data-bn-cat-confirm-title]' ).textContent   = trigger.dataset.bnConfirmTitle || __( 'Confirm', 'buddynext' );
			bd.querySelector( '[data-bn-cat-confirm-message]' ).textContent = trigger.dataset.bnConfirm || '';
			bd.querySelector( '[data-bn-cat-confirm-ok]' ).textContent      = trigger.dataset.bnConfirmOk || __( 'Delete', 'buddynext' );
			bd.querySelectorAll( '[data-bn-cat-confirm-cancel]' ).forEach( function ( btn ) {
				if ( 'BUTTON' === btn.tagName && btn.className === 'bn-btn' ) {
					btn.textContent = trigger.dataset.bnConfirmCancel || __( 'Cancel', 'buddynext' );
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

	// ── Featured-spaces picker: reorder (buttons + drag), remove, search-add,
	//    save via POST /settings/featured-spaces. Owner data, saved on change. ──
	( function initFeaturedPicker() {
		var root = document.querySelector( '[data-bn-featured-picker]' );
		if ( ! root ) {
			return;
		}
		var rest    = root.getAttribute( 'data-rest' );
		var nonce   = root.getAttribute( 'data-nonce' );
		var limit   = parseInt( root.getAttribute( 'data-limit' ), 10 ) || 6;
		var list    = root.querySelector( '[data-bn-featured-list]' );
		var empty   = root.querySelector( '[data-bn-featured-empty]' );
		var search  = root.querySelector( '[data-bn-featured-search]' );
		var results = root.querySelector( '[data-bn-featured-results]' );
		var status  = root.querySelector( '[data-bn-featured-status]' );

		function ids() {
			return Array.prototype.map.call(
				list.querySelectorAll( 'li[data-space-id]' ),
				function ( li ) { return parseInt( li.getAttribute( 'data-space-id' ), 10 ); }
			);
		}
		function syncEmpty() {
			if ( empty ) { empty.hidden = list.children.length > 0; }
		}
		function say( msg ) {
			if ( status ) { status.textContent = msg; }
		}
		function save() {
			say( __( 'Saving…', 'buddynext' ) );
			fetch( rest + '/settings/featured-spaces', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( { ids: ids() } )
			} ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } )
				.then( function () { say( __( 'Saved.', 'buddynext' ) ); } )
				.catch( function () { say( __( 'Could not save. Please retry.', 'buddynext' ) ); } );
		}
		function makeItem( id, name ) {
			var li = document.createElement( 'li' );
			li.className = 'bn-featured-picker__item';
			li.setAttribute( 'data-space-id', String( id ) );
			li.setAttribute( 'draggable', 'true' );
			var nameEl = document.createElement( 'span' );
			nameEl.className = 'bn-featured-picker__name';
			nameEl.textContent = name; // user string via textContent — no innerHTML.
			var actions = document.createElement( 'span' );
			actions.className = 'bn-featured-picker__actions';
			[ [ 'up', '↑', __( 'Move up', 'buddynext' ) ], [ 'down', '↓', __( 'Move down', 'buddynext' ) ], [ 'remove', '×', __( 'Remove from featured', 'buddynext' ) ] ].forEach( function ( spec ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'bn-btn';
				b.setAttribute( 'data-variant', 'ghost' );
				b.setAttribute( 'data-size', 'sm' );
				b.setAttribute( 'data-bn-featured-' + spec[0], '' );
				b.setAttribute( 'aria-label', spec[2] );
				b.textContent = spec[1];
				actions.appendChild( b );
			} );
			li.appendChild( nameEl );
			li.appendChild( actions );
			return li;
		}

		// Reorder + remove (event delegation).
		list.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( 'button' );
			if ( ! btn ) { return; }
			var li = btn.closest( 'li[data-space-id]' );
			if ( ! li ) { return; }
			if ( btn.hasAttribute( 'data-bn-featured-up' ) && li.previousElementSibling ) {
				list.insertBefore( li, li.previousElementSibling );
				save();
			} else if ( btn.hasAttribute( 'data-bn-featured-down' ) && li.nextElementSibling ) {
				list.insertBefore( li.nextElementSibling, li );
				save();
			} else if ( btn.hasAttribute( 'data-bn-featured-remove' ) ) {
				li.remove();
				syncEmpty();
				save();
			}
		} );

		// Drag reorder.
		var dragging = null;
		list.addEventListener( 'dragstart', function ( e ) {
			dragging = e.target.closest( 'li[data-space-id]' );
			if ( dragging ) { dragging.classList.add( 'is-dragging' ); }
		} );
		list.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			var over = e.target.closest( 'li[data-space-id]' );
			if ( ! over || ! dragging || over === dragging ) { return; }
			var rect = over.getBoundingClientRect();
			var after = ( e.clientY - rect.top ) / rect.height > 0.5;
			list.insertBefore( dragging, after ? over.nextElementSibling : over );
		} );
		list.addEventListener( 'dragend', function () {
			if ( dragging ) { dragging.classList.remove( 'is-dragging' ); dragging = null; save(); }
		} );

		// Search-to-add (debounced).
		var timer = null;
		function renderResults( spaces ) {
			results.textContent = '';
			if ( ! spaces.length ) { results.hidden = true; return; }
			spaces.forEach( function ( s ) {
				var opt = document.createElement( 'button' );
				opt.type = 'button';
				opt.className = 'bn-featured-picker__result';
				opt.setAttribute( 'role', 'option' );
				opt.setAttribute( 'data-space-id', String( s.id ) );
				opt.textContent = s.name;
				opt.addEventListener( 'click', function () {
					if ( ids().indexOf( parseInt( s.id, 10 ) ) !== -1 ) {
						say( __( 'That space is already featured.', 'buddynext' ) );
						return;
					}
					if ( list.children.length >= limit ) {
						say( __( 'You can feature up to', 'buddynext' ) + ' ' + limit + ' ' + __( 'spaces. Remove one first.', 'buddynext' ) );
						return;
					}
					list.appendChild( makeItem( s.id, s.name ) );
					syncEmpty();
					results.hidden = true;
					search.value = '';
					save();
				} );
				results.appendChild( opt );
			} );
			results.hidden = false;
		}
		if ( search ) {
			search.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				var q = search.value.trim();
				if ( q.length < 2 ) { results.hidden = true; return; }
				timer = window.setTimeout( function () {
					// roots_only=1: a featured sub-space renders on no front-end featured
					// surface (SpaceService::featured_spaces() is root-only), so do not
					// offer one here to be picked and silently ignored.
					fetch( rest + '/spaces?search=' + encodeURIComponent( q ) + '&per_page=8&roots_only=1', {
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': nonce }
					} ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } )
						.then( function ( data ) {
							// GET /spaces returns a bare array; tolerate an envelope too.
							var rows = Array.isArray( data ) ? data : ( ( data && ( data.spaces || data.items ) ) || [] );
							renderResults( rows );
						} )
						.catch( function () { results.hidden = true; } );
				}, 250 );
			} );
			document.addEventListener( 'click', function ( e ) {
				if ( ! root.contains( e.target ) ) { results.hidden = true; }
			} );
		}
	}() );
}() );
