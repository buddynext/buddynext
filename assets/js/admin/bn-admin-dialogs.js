/**
 * BuddyNext admin — shared dialog helpers (confirm modal + toast).
 *
 * Replaces native browser `confirm()` / `alert()` per Wbcom plugin-dev rule:
 * "NEVER emit browser alerts. Global toast utility only" and
 * "No `window.alert()` / `window.confirm()` anywhere".
 *
 * Usage:
 *   1. Declarative (no JS): add `data-bn-confirm="Are you sure?"` to any
 *      <a>, <button>, or <form>. Click is intercepted; user gets a modal.
 *      Optional: data-bn-confirm-tone="danger" | "warning" | "neutral",
 *                data-bn-confirm-title="…",
 *                data-bn-confirm-ok="Delete",
 *                data-bn-confirm-cancel="Cancel".
 *
 *   2. Imperative:
 *      bnConfirm({ title, message, tone, okLabel, cancelLabel })
 *        .then(ok => ok && doThing());
 *      bnToast('Saved', 'success' | 'error' | 'info');
 *
 * Loaded by AssetService on every BuddyNext admin page so handlers fire
 * regardless of which tab is rendering.
 */
( function () {
	'use strict';

	var { __ } = wp.i18n;

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				if ( key === 'class' ) {
					node.className = attrs[ key ];
				} else if ( key === 'role' || key.indexOf( 'aria-' ) === 0 || key.indexOf( 'data-' ) === 0 ) {
					node.setAttribute( key, attrs[ key ] );
				} else {
					node[ key ] = attrs[ key ];
				}
			} );
		}
		if ( text != null ) {
			node.textContent = text;
		}
		return node;
	}

	// ── Modal ─────────────────────────────────────────────────────────────

	function ensureRoot( id, className ) {
		var root = document.getElementById( id );
		if ( root ) {
			return root;
		}
		root = el( 'div', { id: id, 'class': className || '' } );
		document.body.appendChild( root );
		return root;
	}

	function createModal( opts ) {
		var tone = ( opts.tone || 'neutral' ).toString();

		var backdrop = el( 'div', { 'class': 'bn-dialog-backdrop', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'bn-dialog-title' } );
		var dialog   = el( 'div', { 'class': 'bn-dialog bn-dialog--' + tone } );

		var head  = el( 'header', { 'class': 'bn-dialog__head' } );
		var title = el( 'h2', { 'class': 'bn-dialog__title', id: 'bn-dialog-title' }, opts.title || __( 'Confirm', 'buddynext' ) );
		head.appendChild( title );

		var body = el( 'div', { 'class': 'bn-dialog__body' }, opts.message || '' );

		var foot      = el( 'footer', { 'class': 'bn-dialog__foot' } );
		var cancelBtn = el( 'button', { type: 'button', 'class': 'bn-dialog__cancel' }, opts.cancelLabel || __( 'Cancel', 'buddynext' ) );
		var okBtn     = el( 'button', { type: 'button', 'class': 'bn-dialog__ok',     'data-tone': tone }, opts.okLabel || __( 'Confirm', 'buddynext' ) );
		foot.appendChild( cancelBtn );
		foot.appendChild( okBtn );

		dialog.appendChild( head );
		dialog.appendChild( body );
		dialog.appendChild( foot );
		backdrop.appendChild( dialog );

		var root = ensureRoot( 'bn-admin-dialog-root' );
		while ( root.firstChild ) {
			root.removeChild( root.firstChild );
		}
		root.appendChild( backdrop );

		return {
			backdrop: backdrop,
			okBtn:    okBtn,
			cancelBtn: cancelBtn,
			destroy:  function () {
				while ( root.firstChild ) {
					root.removeChild( root.firstChild );
				}
			},
		};
	}

	function bnConfirm( opts ) {
		opts = opts || {};
		return new Promise( function ( resolve ) {
			var m = createModal( opts );
			var lastActive = document.activeElement;

			function close( result ) {
				document.removeEventListener( 'keydown', onKey );
				m.destroy();
				if ( lastActive && typeof lastActive.focus === 'function' ) {
					lastActive.focus();
				}
				resolve( result );
			}

			function onKey( e ) {
				if ( e.key === 'Escape' ) {
					close( false );
				} else if ( e.key === 'Enter' ) {
					close( true );
				}
			}

			m.okBtn.addEventListener( 'click', function () { close( true ); } );
			m.cancelBtn.addEventListener( 'click', function () { close( false ); } );
			m.backdrop.addEventListener( 'click', function ( e ) {
				if ( e.target === m.backdrop ) { close( false ); }
			} );
			document.addEventListener( 'keydown', onKey );

			setTimeout( function () { m.okBtn.focus(); }, 0 );
		} );
	}

	// ── Toast ─────────────────────────────────────────────────────────────

	function bnToast( message, tone, timeoutMs ) {
		var root = ensureRoot( 'bn-admin-toast-root', 'bn-toast-root' );
		var toast = el( 'div', { 'class': 'bn-toast bn-toast--' + ( tone || 'info' ), role: tone === 'error' ? 'alert' : 'status' }, message );
		root.appendChild( toast );

		// Force reflow so the transition fires.
		// eslint-disable-next-line no-unused-expressions
		toast.offsetWidth;
		toast.classList.add( 'is-visible' );

		var ms = typeof timeoutMs === 'number' ? timeoutMs : ( tone === 'error' ? 6000 : 4000 );
		setTimeout( function () {
			toast.classList.remove( 'is-visible' );
			setTimeout( function () {
				if ( toast.parentNode ) { toast.parentNode.removeChild( toast ); }
			}, 250 );
		}, ms );
	}

	// ── Declarative click interception ────────────────────────────────────

	function delegate( e ) {
		var target = e.target.closest( '[data-bn-confirm]' );
		if ( ! target ) {
			return;
		}
		if ( target.dataset.bnConfirmed === '1' ) {
			delete target.dataset.bnConfirmed;
			return;
		}

		e.preventDefault();
		e.stopImmediatePropagation();

		bnConfirm( {
			title:       target.dataset.bnConfirmTitle  || __( 'Confirm action', 'buddynext' ),
			message:     target.dataset.bnConfirm,
			tone:        target.dataset.bnConfirmTone   || 'neutral',
			okLabel:     target.dataset.bnConfirmOk     || __( 'Confirm', 'buddynext' ),
			cancelLabel: target.dataset.bnConfirmCancel || __( 'Cancel', 'buddynext' ),
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			target.dataset.bnConfirmed = '1';
			if ( target.tagName === 'FORM' ) {
				target.submit();
			} else {
				target.click();
			}
		} );
	}

	// ── Notice handling — auto-clear URL params + auto-fade ───────────────

	/**
	 * Strip listed query-string params from the current URL via
	 * history.replaceState so a refresh doesn't re-show the notice.
	 * Notices opt in by adding `data-bn-clear-param="updated tested reset"`
	 * (space-separated list).
	 */
	function stripNoticeParams() {
		var nodes = document.querySelectorAll( '[data-bn-clear-param]' );
		if ( ! nodes.length || ! window.history || ! window.history.replaceState ) {
			return;
		}
		var url     = new URL( window.location.href );
		var changed = false;
		nodes.forEach( function ( node ) {
			node.getAttribute( 'data-bn-clear-param' ).split( /\s+/ ).forEach( function ( p ) {
				if ( p && url.searchParams.has( p ) ) {
					url.searchParams.delete( p );
					changed = true;
				}
			} );
		} );
		if ( changed ) {
			window.history.replaceState( {}, '', url.toString() );
		}
	}

	/**
	 * Auto-dismiss notices that opt in with `data-bn-auto-dismiss="ms"`
	 * (default 5000ms). Smoothly fades + removes the element.
	 */
	function autoDismissNotices() {
		document.querySelectorAll( '[data-bn-auto-dismiss]' ).forEach( function ( node ) {
			var ms = parseInt( node.getAttribute( 'data-bn-auto-dismiss' ), 10 );
			if ( isNaN( ms ) || ms <= 0 ) { ms = 5000; }
			setTimeout( function () {
				node.style.transition = 'opacity 250ms ease, transform 250ms ease';
				node.style.opacity    = '0';
				node.style.transform  = 'translateY(-4px)';
				setTimeout( function () {
					if ( node.parentNode ) { node.parentNode.removeChild( node ); }
				}, 280 );
			}, ms );
		} );
	}

	function init() {
		document.addEventListener( 'click',  delegate, true );
		document.addEventListener( 'submit', delegate, true );

		document.querySelectorAll( '[data-bn-toast]' ).forEach( function ( node ) {
			bnToast( node.dataset.bnToast, node.dataset.bnToastTone || 'info' );
			node.removeAttribute( 'data-bn-toast' );
		} );

		stripNoticeParams();
		autoDismissNotices();

		// Navigate-on-change select (used by Hub's wide-layout picker).
		document.querySelectorAll( '[data-bn-navigate-on-change]' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				if ( select.value ) {
					window.location.href = select.value;
				}
			} );
		} );

		// Rail filter — type-to-narrow a list. Looks up the controlled
		// container via aria-controls and hides items whose visible text
		// doesn't contain the query. Group headings hide when every item
		// in their group is filtered out (computed via the next-siblings
		// chain — a group is a .bn-email-editor__rail-group followed by
		// .bn-split__rail-item rows until the next group div).
		document.querySelectorAll( '[data-bn-rail-filter]' ).forEach( function ( input ) {
			var targetId = input.getAttribute( 'aria-controls' );
			var target   = targetId ? document.getElementById( targetId ) : null;
			if ( ! target ) { return; }

			input.addEventListener( 'input', function () {
				var q = input.value.trim().toLowerCase();
				var items = target.querySelectorAll( '.bn-split__rail-item' );
				items.forEach( function ( a ) {
					var text = a.textContent.toLowerCase();
					a.hidden = q !== '' && text.indexOf( q ) === -1;
				} );
				// Toggle group visibility based on whether any sibling is shown.
				target.querySelectorAll( '.bn-email-editor__rail-group' ).forEach( function ( g ) {
					var any = false;
					var sib = g.nextElementSibling;
					while ( sib && ! sib.classList.contains( 'bn-email-editor__rail-group' ) ) {
						if ( ! sib.hidden ) { any = true; break; }
						sib = sib.nextElementSibling;
					}
					g.hidden = ! any;
				} );
			} );
		} );
	}

	window.bnConfirm = bnConfirm;
	window.bnToast   = bnToast;

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
