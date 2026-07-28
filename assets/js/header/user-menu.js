/**
 * BuddyNext — Account menu disclosure.
 *
 * The caret was a button with no behaviour at all: the dropdown was opened
 * purely by CSS, on :hover and :focus-within. Clicking the caret focused it,
 * which opened the menu, and clicking it AGAIN could not close it — focus never
 * left, so :focus-within stayed true. The only way out was to click somewhere
 * else on the page. A control that opens on click and refuses to close on the
 * next click reads as broken, and it is the one interaction every account menu
 * on every mainstream site supports.
 *
 * This makes the caret a real menu button: click toggles, Escape closes and
 * returns focus, an outside click closes, and moving focus out of the widget
 * closes. Hover-to-open is kept for pointer users, so nothing that worked
 * before stops working.
 *
 * Delegated from the document, because the header section renders through a
 * block, a shortcode, or a per-theme shim, on hub pages AND ordinary theme
 * pages, and survives client-side navigation — there is no single mount point
 * to bind to.
 *
 * @package BuddyNext
 */

( function () {
	'use strict';

	var OPEN_CLASS = 'is-open';

	/**
	 * The widget root for a given node, or null.
	 *
	 * @param {Node} node Starting node.
	 * @return {HTMLElement|null} Root element.
	 */
	function rootOf( node ) {
		return node && node.closest ? node.closest( '.bn-header-user' ) : null;
	}

	/**
	 * Open or close one menu.
	 *
	 * @param {HTMLElement} root Widget root.
	 * @param {boolean}     open Desired state.
	 * @return {void}
	 */
	function setOpen( root, open ) {
		var caret = root.querySelector( '.bn-header-user__caret' );

		root.classList.toggle( OPEN_CLASS, open );

		if ( caret ) {
			caret.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	/**
	 * Close every open menu except an optional one to keep.
	 *
	 * @param {HTMLElement|null} keep Root to leave alone.
	 * @return {void}
	 */
	function closeAll( keep ) {
		var open = document.querySelectorAll( '.bn-header-user.' + OPEN_CLASS );
		var i;

		for ( i = 0; i < open.length; i++ ) {
			if ( open[ i ] !== keep ) {
				setOpen( open[ i ], false );
			}
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var caret = event.target.closest ? event.target.closest( '.bn-header-user__caret' ) : null;

		if ( caret ) {
			var root = rootOf( caret );
			if ( ! root ) {
				return;
			}

			// The caret is a real button inside no form, but stop the event from
			// also reaching the document handler below, which would immediately
			// close what this click just opened.
			event.preventDefault();
			event.stopPropagation();

			var willOpen = ! root.classList.contains( OPEN_CLASS );
			closeAll( root );
			setOpen( root, willOpen );

			return;
		}

		// A click anywhere else closes — including inside the dropdown, where the
		// items are links that navigate away.
		closeAll( null );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key && 'Esc' !== event.key ) {
			return;
		}

		var root = rootOf( document.activeElement );
		var open = document.querySelector( '.bn-header-user.' + OPEN_CLASS );

		if ( ! open ) {
			return;
		}

		setOpen( open, false );

		// Return focus to the control that opened it, so a keyboard user is not
		// dropped at the top of the document.
		var caret = ( root || open ).querySelector( '.bn-header-user__caret' );
		if ( caret ) {
			caret.focus();
		}
	} );

	// Tabbing out of the widget closes it. Deferred one tick because at focusout
	// time document.activeElement is still the element being left.
	document.addEventListener( 'focusout', function ( event ) {
		var root = rootOf( event.target );

		if ( ! root || ! root.classList.contains( OPEN_CLASS ) ) {
			return;
		}

		window.setTimeout( function () {
			if ( ! root.contains( document.activeElement ) ) {
				setOpen( root, false );
			}
		}, 0 );
	} );
}() );
