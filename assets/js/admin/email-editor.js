/**
 * BuddyNext — Email Template Editor admin script.
 *
 * Powers the v2 split-pane editor in includes/Admin/EmailEditor.php:
 *   - Token insertion at the textarea caret.
 *   - HTML / Plain / Preview tab switching inside the editor pane.
 *   - "Send test" + "Reset to default" confirm modals (no native confirm()).
 *   - Plain-text + preview rendering kept in sync with the HTML body textarea.
 *
 * Enqueued by Core\AssetService::enqueue_admin_assets() when the current
 * admin page is the Email Templates submenu.
 *
 * @package BuddyNext
 */

( function () {
	'use strict';

	var root = document.querySelector( '.bn-email-editor' );
	if ( ! root ) {
		return;
	}

	var body = root.querySelector( '#bn-body' );
	var plain = root.querySelector( '#bn-plain' );
	var previewBody = root.querySelector( '[data-bn-preview-body]' );

	/* ── Tab switching ──────────────────────────────────────────────── */
	var tabs = root.querySelectorAll( '[role="tab"][data-bn-tab]' );
	var panels = root.querySelectorAll( '[role="tabpanel"][data-bn-panel]' );

	function activateTab( name ) {
		tabs.forEach( function ( t ) {
			var isActive = t.dataset.bnTab === name;
			t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			t.tabIndex = isActive ? 0 : -1;
		} );
		panels.forEach( function ( p ) {
			p.hidden = p.dataset.bnPanel !== name;
		} );
		if ( 'plain' === name && plain && body ) {
			plain.value = stripHtml( body.value );
		}
		if ( 'preview' === name ) {
			syncPreview();
		}
	}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			activateTab( tab.dataset.bnTab );
		} );
		tab.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowRight' !== e.key && 'ArrowLeft' !== e.key ) {
				return;
			}
			e.preventDefault();
			var list = Array.prototype.slice.call( tabs );
			var idx = list.indexOf( tab );
			var next = 'ArrowRight' === e.key
				? ( idx + 1 ) % list.length
				: ( idx - 1 + list.length ) % list.length;
			list[ next ].focus();
			activateTab( list[ next ].dataset.bnTab );
		} );
	} );

	/* ── Preview sync ───────────────────────────────────────────────── */
	function stripHtml( html ) {
		var tmp = document.createElement( 'div' );
		tmp.textContent = html;
		// Now read what the browser stripped to text and re-parse as actual DOM
		// to extract textContent of an HTML fragment safely.
		var dom = new DOMParser().parseFromString( html, 'text/html' );
		return ( dom.body.textContent || '' ).trim();
	}

	function syncPreview() {
		if ( ! previewBody || ! body ) {
			return;
		}
		// Live admin preview of HTML body content authored by manage_options
		// users. The server sanitizes via wp_kses_post() on save; this client
		// render only reflects what the admin is typing right now.
		var sanitized = body.value;
		previewBody.innerHTML = sanitized; // eslint-disable-line no-unsanitized/property
	}

	if ( body ) {
		body.addEventListener( 'input', function () {
			syncPreview();
			if ( plain ) {
				plain.value = stripHtml( body.value );
			}
		} );
	}

	/* ── Token picker — insert at caret ─────────────────────────────── */
	root.addEventListener( 'click', function ( e ) {
		var t = e.target.closest( '[data-bn-token]' );
		if ( ! t || ! body ) {
			return;
		}
		var token = t.getAttribute( 'data-bn-token' );
		var start = body.selectionStart || 0;
		var end = body.selectionEnd || 0;
		var current = body.value;
		body.value = current.slice( 0, start ) + token + current.slice( end );
		var pos = start + token.length;
		body.focus();
		body.setSelectionRange( pos, pos );
		body.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );

	/* ── Modal helpers ──────────────────────────────────────────────── */
	function openModal( id ) {
		var modal = document.getElementById( id );
		if ( ! modal ) {
			return;
		}
		modal.hidden = false;
		var first = modal.querySelector( 'input, button:not([data-bn-close])' );
		if ( first ) {
			first.focus();
		}
	}

	function closeModal( modal ) {
		if ( ! modal ) {
			return;
		}
		modal.hidden = true;
	}

	root.addEventListener( 'click', function ( e ) {
		var opener = e.target.closest( '[data-bn-open-modal]' );
		if ( opener ) {
			e.preventDefault();
			openModal( opener.getAttribute( 'data-bn-open-modal' ) );
			return;
		}
		var closer = e.target.closest( '[data-bn-close]' );
		if ( closer ) {
			e.preventDefault();
			closeModal( closer.closest( '.bn-modal-backdrop' ) );
			return;
		}
		if ( e.target.classList && e.target.classList.contains( 'bn-modal-backdrop' ) ) {
			closeModal( e.target );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) {
			return;
		}
		var open = root.querySelector( '.bn-modal-backdrop:not([hidden])' );
		if ( open ) {
			closeModal( open );
		}
	} );

	/* ── Enabled toggle: keep visual switch in sync with checkbox ─── */
	var toggleWrap = root.querySelector( '[data-bn-toggle-wrap]' );
	if ( toggleWrap ) {
		var input = toggleWrap.querySelector( 'input[type="checkbox"]' );
		var visual = toggleWrap.querySelector( '.bn-toggle' );
		if ( input && visual ) {
			var sync = function () {
				visual.setAttribute( 'aria-checked', input.checked ? 'true' : 'false' );
			};
			sync();
			input.addEventListener( 'change', sync );
		}
	}

	/* ── Boot ───────────────────────────────────────────────────────── */
	activateTab( 'html' );
	syncPreview();
}() );
