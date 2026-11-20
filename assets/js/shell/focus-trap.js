/* BuddyNext — focus primitives shared by the front end and wp-admin.
 *
 * trapFocus() keeps Tab inside an open dialog; keepFocus() keeps keyboard focus
 * on a control that disables or hides itself while its request runs (Follow,
 * Connect, every busy-bound button). Side-effect free on import: no globals, no
 * listeners until a caller asks, so wp-admin can load it without the front-end
 * dialog module (which owns window.bnConfirm / bnToast there).
 *
 * @package BuddyNext
 */

/** Open traps, innermost last. Only the top one handles Tab. */
const trapStack = [];

/**
 * Focus-trap helper. Keeps Tab cycling inside the modal until it closes.
 *
 * Tab is handled at document level for the topmost trap, not on the container:
 * when a focused button disables itself while its request runs (the Share
 * dialog's "Create share link", every busy-bound submit) the browser drops focus
 * to <body>, and a container-only listener never saw the next Tab, so focus
 * walked through the page behind the open dialog. While focus is lost the trap
 * also returns it: to that button once it is enabled again, or to the dialog
 * panel when the focused control was removed.
 *
 * @param {HTMLElement} container The modal panel.
 * @return {() => void} Cleanup function — call to release the trap.
 */
export function trapFocus( container ) {
	let lastInside = null;

	function lost() {
		const active = document.activeElement;
		return ! active || active === document.body || ! document.contains( active );
	}

	function onFocusIn( ev ) {
		if ( container.contains( ev.target ) ) {
			lastInside = ev.target;
		}
	}

	function onKey( ev ) {
		if ( ev.key !== 'Tab' || trapStack[ trapStack.length - 1 ] !== onKey ) {
			return;
		}
		// Only controls that are actually rendered count. A dialog can hold controls
		// that stay hidden until a state changes (the Share dialog's link row appears
		// only after a link is created); counting those made an invisible element the
		// "last" stop, so the wrap never fired and Tab walked out of the dialog.
		// Only real Tab stops count: a button with tabindex="-1" (the lightbox
		// backdrop) or an SVG <use href> icon matched the query but Tab never lands
		// on it, so it became "first" and Shift+Tab walked out past the real first.
		const focusables = Array.prototype.filter.call(
			container.querySelectorAll(
				'button:not([disabled]), a[href], area[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
			),
			function ( el ) {
				return el.tabIndex >= 0 && el.getClientRects().length > 0;
			}
		);
		if ( ! focusables.length ) {
			return;
		}
		const first  = focusables[ 0 ];
		const last   = focusables[ focusables.length - 1 ];
		const active = document.activeElement;
		// Focus on something that is not one of the stops - the panel itself, where a
		// modal parks focus when it opens, or <body> after a button disabled itself -
		// wraps to the matching end instead of letting the browser leave the dialog.
		const inList = focusables.indexOf( active ) !== -1;
		if ( ev.shiftKey && ( active === first || ! inList ) ) {
			ev.preventDefault();
			last.focus();
		} else if ( ! ev.shiftKey && ( active === last || ! inList ) ) {
			ev.preventDefault();
			first.focus();
		}
	}

	// Focus lost while the trap is open: a busy button coming back enabled gets it
	// back; a control that was removed (a "Remove" row) hands it to the dialog
	// panel, so a screen reader stays inside the dialog instead of the page.
	const observer = new MutationObserver( function () {
		if ( ! lastInside || ! lost() ) {
			return;
		}
		if ( ! container.contains( lastInside ) ) {
			const panel = container.matches( '[role="dialog"],[role="alertdialog"]' )
				? container
				: container.querySelector( '[role="dialog"],[role="alertdialog"]' ) || container;
			if ( ! panel.hasAttribute( 'tabindex' ) ) {
				panel.setAttribute( 'tabindex', '-1' );
			}
			lastInside = null;
			panel.focus();
		} else if ( ! lastInside.disabled && lastInside.getClientRects().length ) {
			lastInside.focus();
		}
	} );
	observer.observe( container, { subtree: true, childList: true, attributes: true, attributeFilter: [ 'disabled' ] } );

	trapStack.push( onKey );
	container.addEventListener( 'focusin', onFocusIn );
	document.addEventListener( 'keydown', onKey, true );
	return function () {
		const i = trapStack.indexOf( onKey );
		if ( -1 !== i ) {
			trapStack.splice( i, 1 );
		}
		observer.disconnect();
		container.removeEventListener( 'focusin', onFocusIn );
		document.removeEventListener( 'keydown', onKey, true );
	};
}

/** Real Tab stops, as trapFocus counts them. */
const FOCUSABLE = 'button, a[href], area[href], input, select, textarea, [tabindex]';

/**
 * Whether an element is a visible, enabled Tab stop.
 *
 * @param {Element} el Candidate.
 * @return {boolean}
 */
function usable( el ) {
	return ! el.disabled && el.tabIndex >= 0 && el.getClientRects().length > 0;
}

/**
 * Keep keyboard focus on a control that changes under it.
 *
 * Interactivity updates buttons in place: `data-wp-bind--disabled="context.busy"`
 * disables the button the member just pressed, and the browser drops focus to
 * <body>, so a keyboard or screen-reader member is sent back to the top of the
 * page. Connect is worse: the pressed button is hidden and "Pending" appears.
 *
 * When the focused control becomes disabled, it gets focus back the moment it is
 * enabled again. When it becomes hidden, focus moves to the nearest visible
 * control beside it (next sibling first, then previous, then one level up) -
 * Connect hands focus to Pending, Pending back to Connect. Any focus the member
 * moves themselves cancels the restore. Dialogs are left to trapFocus().
 *
 * @param {HTMLElement} root Subtree to watch (document.body for the whole page).
 * @return {() => void} Cleanup function.
 */
export function keepFocus( root ) {
	let last    = null; // Element that most recently received focus.
	let pending = null; // Control that lost focus to a disabled/hidden change.

	function lost() {
		const active = document.activeElement;
		return ! active || active === document.body || active === pending;
	}

	function onFocusIn( ev ) {
		last    = ev.target;
		pending = null;
	}

	/**
	 * Nearest usable control around a hidden one, bounded by its Interactivity
	 * region so focus never jumps to an unrelated part of the page.
	 *
	 * @param {Element} el Hidden control.
	 * @return {Element|null}
	 */
	function neighbour( el ) {
		const stop = el.closest( '[data-wp-key],[data-wp-interactive]' );
		let node   = el;
		while ( node && node !== root ) {
			for ( const dir of [ 'nextElementSibling', 'previousElementSibling' ] ) {
				for ( let sib = node[ dir ]; sib; sib = sib[ dir ] ) {
					const hit = sib.matches( FOCUSABLE ) && usable( sib )
						? sib
						: Array.prototype.find.call( sib.querySelectorAll( FOCUSABLE ), usable );
					if ( hit ) {
						return hit;
					}
				}
			}
			if ( node === stop ) {
				break;
			}
			node = node.parentElement;
		}
		return null;
	}

	const observer = new MutationObserver( function ( mutations ) {
		// An open dialog owns focus; its trap restores it.
		if ( trapStack.length ) {
			return;
		}
		// The browser may still report the disabled button as active until its next
		// render, so "still on it" counts as losing focus too.
		const active = document.activeElement;
		if (
			! pending &&
			last &&
			( active === last || lost() ) &&
			mutations.some( ( m ) => m.target === last || m.target.contains( last ) ) &&
			( last.disabled || ! last.getClientRects().length )
		) {
			pending = last;
		}
		if ( ! pending ) {
			return;
		}
		if ( ! document.contains( pending ) ) {
			pending = null;
			return;
		}
		if ( pending.getClientRects().length === 0 ) {
			const next = neighbour( pending );
			if ( next ) {
				pending = null;
				next.focus();
			}
		} else if ( ! pending.disabled ) {
			const el = pending;
			pending  = null;
			el.focus();
		}
	} );
	observer.observe( root, { subtree: true, attributes: true, attributeFilter: [ 'disabled', 'hidden' ] } );
	document.addEventListener( 'focusin', onFocusIn, true );

	return function () {
		observer.disconnect();
		document.removeEventListener( 'focusin', onFocusIn, true );
	};
}
