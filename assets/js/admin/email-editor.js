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

	const { __ } = wp.i18n;

	var root = document.querySelector( '.bn-email-editor' );
	if ( ! root ) {
		return;
	}

	var body = root.querySelector( '#bn-body' );
	var plain = root.querySelector( '#bn-plain' );
	var subjectInput = root.querySelector( '#bn-subject' );
	var previewFrame = root.querySelector( '[data-bn-preview-iframe]' );
	var previewCfg = window.bnEmailEditorPreview || { shell: '{{BNBODY}}', sample: {} };

	// Replace {{token}} occurrences with sample values so the preview reads as a
	// real, ready-to-send email — never raw placeholders. Known tokens use the
	// localized sample map; any leftover *_name / *_url / other token still gets a
	// sensible humanized stand-in so nothing renders as "{{...}}".
	function fillTokens( str ) {
		if ( ! str ) {
			return '';
		}
		return String( str ).replace( /\{\{\s*([a-z0-9_]+)\s*\}\}/gi, function ( match, key ) {
			var lower = key.toLowerCase();
			if ( Object.prototype.hasOwnProperty.call( previewCfg.sample, lower ) ) {
				return previewCfg.sample[ lower ];
			}
			if ( /_url$/.test( lower ) ) {
				return '#';
			}
			if ( /_name$/.test( lower ) ) {
				return __( 'Jordan Lee', 'buddynext' );
			}
			return lower.replace( /_/g, ' ' );
		} );
	}

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
		if ( ! previewFrame || ! body ) {
			return;
		}
		// Render the authored body inside the REAL branded shell (same header +
		// footer as a genuine send), with sample tokens filled. The server still
		// sanitizes via wp_kses_post() on save; this is a faithful live preview.
		var subject = subjectInput ? fillTokens( subjectInput.value ) : '';
		var bodyHtml = fillTokens( body.value );
		var html = String( previewCfg.shell )
			.replace( '{{BNSUBJECT}}', subject )
			.replace( '{{BNBODY}}', bodyHtml );
		previewFrame.setAttribute( 'srcdoc', html );
	}

	if ( body ) {
		body.addEventListener( 'input', function () {
			syncPreview();
			if ( plain ) {
				plain.value = stripHtml( body.value );
			}
		} );
	}

	if ( subjectInput ) {
		subjectInput.addEventListener( 'input', syncPreview );
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
