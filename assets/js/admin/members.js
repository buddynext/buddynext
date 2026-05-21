/**
 * BuddyNext admin Members page interactions.
 *
 * Wires up:
 *   - Row "more" dropdown toggle on the Members list table.
 *   - Confirm-modal flow for destructive row actions (suspend).
 *   - Edit-member tab switcher with sessionStorage persistence.
 *   - Repeater group add / remove / renumber on the member edit form.
 *
 * Enqueued by BuddyNext\Admin\Members on its own admin page hook_suffix.
 *
 * @package BuddyNext\Admin
 */
( function () {
	'use strict';

	// ── List page: row dropdown menu ─────────────────────────────────────
	function initRowMenus() {
		var triggers = document.querySelectorAll( '.bn-more-btn' );
		if ( ! triggers.length ) {
			return;
		}

		triggers.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var menu = btn.closest( '.bn-more-menu' );
				if ( ! menu ) {
					return;
				}
				document.querySelectorAll( '.bn-more-menu.open' ).forEach( function ( open ) {
					if ( open !== menu ) {
						open.classList.remove( 'open' );
					}
				} );
				menu.classList.toggle( 'open' );
			} );
		} );

		document.addEventListener( 'click', function () {
			document.querySelectorAll( '.bn-more-menu.open' ).forEach( function ( open ) {
				open.classList.remove( 'open' );
			} );
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				document.querySelectorAll( '.bn-more-menu.open' ).forEach( function ( open ) {
					open.classList.remove( 'open' );
				} );
			}
		} );
	}

	// ── Destructive confirm modal (replaces native confirm()) ────────────
	function initConfirmModal() {
		var modal = document.getElementById( 'bn-members-confirm-modal' );
		if ( ! modal ) {
			return;
		}

		var backdrop  = modal;
		var panel     = modal.querySelector( '.bn-modal__panel' );
		var titleEl   = modal.querySelector( '[data-bn-confirm-title]' );
		var bodyEl    = modal.querySelector( '[data-bn-confirm-body]' );
		var confirmEl = modal.querySelector( '[data-bn-confirm-accept]' );
		var cancelEls = modal.querySelectorAll( '[data-bn-confirm-cancel]' );

		var pendingForm = null;

		function open( form ) {
			pendingForm = form;
			var title = form.getAttribute( 'data-bn-confirm-title' ) || '';
			var body  = form.getAttribute( 'data-bn-confirm-body' ) || '';
			var label = form.getAttribute( 'data-bn-confirm-label' ) || '';
			if ( titleEl ) { titleEl.textContent = title; }
			if ( bodyEl )  { bodyEl.textContent = body; }
			if ( confirmEl && label ) { confirmEl.textContent = label; }
			backdrop.hidden = false;
			if ( confirmEl ) {
				window.setTimeout( function () { confirmEl.focus(); }, 0 );
			}
		}

		function close() {
			pendingForm = null;
			backdrop.hidden = true;
		}

		document.querySelectorAll( 'form[data-bn-confirm="1"]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				if ( form.dataset.bnConfirmed === '1' ) {
					return;
				}
				e.preventDefault();
				open( form );
			} );
		} );

		if ( confirmEl ) {
			confirmEl.addEventListener( 'click', function () {
				if ( pendingForm ) {
					pendingForm.dataset.bnConfirmed = '1';
					pendingForm.submit();
				}
				close();
			} );
		}

		cancelEls.forEach( function ( el ) {
			el.addEventListener( 'click', close );
		} );

		backdrop.addEventListener( 'click', function ( e ) {
			if ( e.target === backdrop ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! backdrop.hidden ) {
				close();
			}
		} );

		if ( panel ) {
			panel.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
			} );
		}
	}

	// ── Edit-member tab switcher ─────────────────────────────────────────
	function initEditTabs() {
		var tabBar = document.querySelector( '.bn-tabs[data-bn-edit-tabs]' );
		if ( ! tabBar ) {
			return;
		}
		var userId    = tabBar.getAttribute( 'data-user-id' ) || '0';
		var storageKey = 'bn-edit-tab-' + userId;
		var tabs      = tabBar.querySelectorAll( '.bn-tab' );
		var panels    = document.querySelectorAll( '.bn-tab-panel' );

		function activate( slug ) {
			tabs.forEach( function ( t ) {
				var isActive = t.dataset.panel === slug;
				t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				if ( isActive ) {
					t.setAttribute( 'tabindex', '0' );
				} else {
					t.setAttribute( 'tabindex', '-1' );
				}
			} );
			panels.forEach( function ( p ) {
				p.classList.toggle( 'is-active', p.id === 'bn-panel-' + slug );
			} );
			try { window.sessionStorage.setItem( storageKey, slug ); } catch ( err ) {}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activate( tab.dataset.panel );
			} );
			tab.addEventListener( 'keydown', function ( e ) {
				var ordered = Array.prototype.slice.call( tabs );
				var idx     = ordered.indexOf( tab );
				if ( 'ArrowRight' === e.key && idx < ordered.length - 1 ) {
					e.preventDefault();
					ordered[ idx + 1 ].focus();
					activate( ordered[ idx + 1 ].dataset.panel );
				} else if ( 'ArrowLeft' === e.key && idx > 0 ) {
					e.preventDefault();
					ordered[ idx - 1 ].focus();
					activate( ordered[ idx - 1 ].dataset.panel );
				}
			} );
		} );

		try {
			var last = window.sessionStorage.getItem( storageKey );
			if ( last && document.getElementById( 'bn-panel-' + last ) ) {
				activate( last );
			}
		} catch ( err ) {}
	}

	// ── Repeater groups on the edit form ─────────────────────────────────
	function initRepeaters() {
		var entryWord = ( window.bnMembersI18n && window.bnMembersI18n.entry ) || 'Entry';
		var containers = document.querySelectorAll( '[data-bn-repeater]' );
		if ( ! containers.length ) {
			return;
		}

		containers.forEach( function ( container ) {
			var groupKey = container.getAttribute( 'data-bn-repeater' );
			if ( ! groupKey ) {
				return;
			}
			var tpl    = document.getElementById( 'bn-repeater-tpl-' + groupKey );
			var addBtn = document.querySelector( '[data-bn-repeater-add="' + groupKey + '"]' );
			if ( ! tpl || ! addBtn ) {
				return;
			}

			function applyIdx( node, idx ) {
				if ( node.nodeType !== 1 ) {
					return;
				}
				[ 'id', 'name', 'for' ].forEach( function ( attr ) {
					var val = node.getAttribute( attr );
					if ( val && val.indexOf( '__idx__' ) !== -1 ) {
						node.setAttribute( attr, val.replace( /__idx__/g, String( idx ) ) );
					}
				} );
				node.childNodes.forEach( function ( child ) { applyIdx( child, idx ); } );
			}

			function renumber() {
				container.querySelectorAll( '.bn-repeater-entry' ).forEach( function ( entry, i ) {
					var lbl = entry.querySelector( '.bn-repeater-entry-label' );
					if ( lbl ) {
						lbl.textContent = entryWord + ' ' + ( i + 1 );
					}
				} );
			}

			function bindRemove( btn ) {
				if ( ! btn ) { return; }
				btn.addEventListener( 'click', function () {
					if ( container.querySelectorAll( '.bn-repeater-entry' ).length > 1 ) {
						btn.closest( '.bn-repeater-entry' ).remove();
						renumber();
					}
				} );
			}

			container.querySelectorAll( '.bn-repeater-remove' ).forEach( bindRemove );

			addBtn.addEventListener( 'click', function () {
				var idx      = container.querySelectorAll( '.bn-repeater-entry' ).length;
				var newEntry = document.importNode( tpl.content, true ).firstElementChild;
				applyIdx( newEntry, idx );
				var lbl = newEntry.querySelector( '.bn-repeater-entry-label' );
				if ( lbl ) {
					lbl.textContent = entryWord + ' ' + ( idx + 1 );
				}
				bindRemove( newEntry.querySelector( '.bn-repeater-remove' ) );
				container.appendChild( newEntry );
			} );
		} );
	}

	function ready( fn ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		initRowMenus();
		initConfirmModal();
		initEditTabs();
		initRepeaters();
	} );
}() );
