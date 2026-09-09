/* BuddyNext — one keyboard-accessibility primitive for every modal.
 *
 * The modal bugs all shared a root cause: each feature re-implemented dialog
 * behaviour, or skipped it. Share got a bespoke module-scope trap; Report and
 * block-confirm got nothing; the shell bnConfirm/bnPrompt frames are a third
 * pattern. A keyboard or screen-reader member learned one behaviour from Share
 * and was trapped by the next dialog with the same visual language.
 *
 * This is THE primitive, and it is store-agnostic. Every BuddyNext modal renders
 * as a `.bn-modal-backdrop` that Interactivity shows/hides by toggling either the
 * `hidden` attribute or an `is-hidden` CSS class (see isHidden()). This controller
 * watches both across the whole document: when a modal opens it moves focus in,
 * traps Tab, and remembers the opener; when it closes it releases the trap and
 * returns focus. One document
 * Escape handler closes the topmost open modal through that modal's own close
 * control — the store-bound `.bn-modal__close`, or the backdrop — so no
 * per-store code is needed and every modal, present and future, behaves the
 * same. Feature modules render markup; they do not each re-solve focus and
 * Escape.
 *
 * @package BuddyNext
 */

import { trapFocus } from '@buddynext/shell-dialog';

/** Every modal in the product is one of these. */
const SELECTOR = '.bn-modal-backdrop';

/** backdrop element -> { trigger, release }, in open order (topmost = last). */
const openModals = new Map();

/**
 * Whether a backdrop is currently hidden (closed).
 *
 * BuddyNext modals hide themselves TWO ways: most toggle the `hidden` attribute
 * (data-wp-bind--hidden), but the DM modals and the media-tab modals toggle an
 * `is-hidden` CSS class instead (data-wp-class--is-hidden). Keying on `hidden`
 * alone made boot() treat every class-toggled backdrop as OPEN at page load — five
 * ghost modals on /messages/ kept openModals permanently non-empty, so Escape was
 * captured and routed into a hidden panel instead of reaching the member's actual
 * context (card 10264295485 round-3). Recognise both conventions here, at the one
 * seam, so a modal is correctly seen as closed no matter which its template uses.
 *
 * @param {Element} el Backdrop element.
 * @return {boolean}
 */
function isHidden( el ) {
	return el.hasAttribute( 'hidden' ) || el.classList.contains( 'is-hidden' );
}

/**
 * The element that should receive focus and hold the trap — the dialog panel
 * (the backdrop itself when it carries role="dialog", otherwise the inner
 * panel), falling back to the backdrop.
 *
 * @param {Element} backdrop Backdrop element.
 * @return {Element}
 */
function panelOf( backdrop ) {
	if ( backdrop.matches( '[role="dialog"]' ) ) {
		return backdrop;
	}
	return backdrop.querySelector( '[role="dialog"]' ) || backdrop.querySelector( '.bn-modal__panel' ) || backdrop;
}

/**
 * A modal just opened: remember the opener, move focus in, and trap Tab. Idempotent
 * — a backdrop already tracked is left alone, so a repeated open cannot leak a
 * second trap or lose the original focus target (the bug in the first Share fix).
 *
 * @param {Element} backdrop Backdrop element.
 * @return {void}
 */
function activate( backdrop ) {
	if ( openModals.has( backdrop ) ) {
		return;
	}

	const trigger = document.activeElement;
	const panel   = panelOf( backdrop );

	// Focus synchronously — not in requestAnimationFrame. We are only called from
	// the observer once the `hidden` attribute has ALREADY been removed, so the
	// panel is visible now and focus() is not a no-op. rAF would also be throttled
	// to a standstill in a background/unfocused tab, which is exactly where a
	// screen-reader user may be, so it must not gate the focus move.
	if ( ! panel.hasAttribute( 'tabindex' ) ) {
		panel.setAttribute( 'tabindex', '-1' );
	}
	if ( ! panel.contains( document.activeElement ) ) {
		panel.focus();
	}
	const release = trapFocus( backdrop );

	let released = false;
	openModals.set( backdrop, {
		trigger,
		teardown() {
			if ( ! released ) {
				released = true;
				release();
			}
			// Return focus to the opener, but only if it is still there AND still
			// visible. A modal opened from an overflow menu that collapses on open
			// leaves its trigger in the DOM but hidden; focusing a hidden element
			// silently dumps focus to <body>, so in that case we leave the browser's
			// default rather than pretend we restored it. offsetParent is null for a
			// display:none element (and for position:fixed, which no trigger is).
			if (
				trigger &&
				typeof trigger.focus === 'function' &&
				document.contains( trigger ) &&
				null !== trigger.offsetParent
			) {
				trigger.focus();
			}
		},
	} );
}

/**
 * A modal just closed: release the trap and return focus to whatever opened it.
 *
 * @param {Element} backdrop Backdrop element.
 * @return {void}
 */
function deactivate( backdrop ) {
	const entry = openModals.get( backdrop );
	if ( ! entry ) {
		return;
	}
	openModals.delete( backdrop );
	entry.teardown();
}

/**
 * Reconcile a backdrop's tracked state with its current visibility.
 *
 * @param {Element} backdrop Backdrop element.
 * @return {void}
 */
function sync( backdrop ) {
	if ( isHidden( backdrop ) ) {
		deactivate( backdrop );
	} else {
		activate( backdrop );
	}
}

/**
 * The topmost open modal — the last one still visible.
 *
 * @return {Element|null}
 */
function topmost() {
	let top = null;
	openModals.forEach( function ( _entry, backdrop ) {
		if ( ! isHidden( backdrop ) ) {
			top = backdrop;
		}
	} );
	return top;
}

/**
 * Escape closes the topmost modal through its own close control, so the close
 * runs the owning store's action (open-state + any teardown) exactly as a click
 * would. Guarded by open state, so Escape is not swallowed when no modal is up.
 *
 * @param {KeyboardEvent} ev Key event.
 * @return {void}
 */
function onEscape( ev ) {
	if ( 'Escape' !== ev.key || ! openModals.size ) {
		return;
	}
	const top = topmost();
	if ( ! top ) {
		return;
	}
	ev.preventDefault();

	// Prefer the explicit close button (bound to the store's close action). Fall
	// back to the backdrop, whose click handler closes stores that only wired
	// backdrop-close.
	const closeBtn = top.querySelector( '.bn-modal__close' );
	if ( closeBtn ) {
		closeBtn.click();
		return;
	}
	top.click();
}

/**
 * Start watching. Attribute changes are cheap to observe (filtered to `hidden`),
 * and the observer lives on document.body so it survives the Interactivity
 * router swapping region content — modals rendered by a client-side navigation
 * are picked up with no re-init.
 *
 * @return {void}
 */
function boot() {
	if ( window.__bnModalA11y ) {
		return; // One controller per document.
	}
	window.__bnModalA11y = true;

	document.addEventListener( 'keydown', onEscape );

	const observer = new MutationObserver( function ( mutations ) {
		mutations.forEach( function ( m ) {
			const el = m.target;
			if ( el && el.matches && el.matches( SELECTOR ) ) {
				sync( el );
			}
		} );
	} );
	// Watch BOTH hide conventions: the `hidden` attribute and the `is-hidden` class
	// (see isHidden()). The callback filters to .bn-modal-backdrop before doing any
	// work, so the extra `class` mutations from unrelated elements are a cheap
	// matches() no-op.
	observer.observe( document.body, {
		subtree: true,
		attributes: true,
		attributeFilter: [ 'hidden', 'class' ],
	} );

	// Any modal that is already open at load (rare — most start hidden).
	document.querySelectorAll( SELECTOR ).forEach( function ( backdrop ) {
		if ( ! isHidden( backdrop ) ) {
			activate( backdrop );
		}
	} );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
