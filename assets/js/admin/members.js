/**
 * BuddyNext admin Members page interactions.
 *
 * Wires up:
 *   - (Row "more" dropdown wiring lives in the shared more-menu.js.)
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

	var i18n = ( window.wp && window.wp.i18n ) || {};
	var __ = i18n.__ || function ( s ) { return s; };

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
		var reasonWrap  = modal.querySelector( '[data-bn-confirm-reason-wrap]' );
		var reasonField = modal.querySelector( '[data-bn-confirm-reason-field]' );

		var pendingForm = null;

		function open( form ) {
			pendingForm = form;
			var title = form.getAttribute( 'data-bn-confirm-title' ) || '';
			var body  = form.getAttribute( 'data-bn-confirm-body' ) || '';
			var label = form.getAttribute( 'data-bn-confirm-label' ) || '';
			if ( titleEl ) { titleEl.textContent = title; }
			if ( bodyEl )  { bodyEl.textContent = body; }
			if ( confirmEl && label ) { confirmEl.textContent = label; }
			// Optional reason field — shown only for forms that opt in
			// (data-bn-confirm-reason). Reset on every open so a prior entry
			// doesn't leak into the next action.
			if ( reasonWrap ) {
				var wantsReason = form.getAttribute( 'data-bn-confirm-reason' ) === '1';
				reasonWrap.hidden = ! wantsReason;
				if ( reasonField ) { reasonField.value = ''; }
			}
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
					// Carry the optional reason into the submitting form as a hidden
					// field so the server handler can persist it.
					if ( reasonWrap && ! reasonWrap.hidden && reasonField ) {
						var hidden = pendingForm.querySelector( 'input[name="reason"]' );
						if ( ! hidden ) {
							hidden = document.createElement( 'input' );
							hidden.type = 'hidden';
							hidden.name = 'reason';
							pendingForm.appendChild( hidden );
						}
						hidden.value = reasonField.value;
					}
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
		var entryWord = ( window.bnMembersI18n && window.bnMembersI18n.entry ) || __( 'Entry', 'buddynext' );
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

	// ── Profile Fields builder: type-driven options + searchable controls ─
	//
	// The type <select> carries data-bn-pf-opts-wrap / data-bn-pf-search-wrap
	// (and each <option> carries data-is-choice / data-is-searchable-capable).
	// We show the options editor for choice types and the "Searchable in the
	// member directory" control only for searchable-capable types — and clear
	// the checkbox when it is hidden so a non-searchable type can never persist
	// is_searchable=1. The type matrix is also exposed as window.bnProfileFieldTypes
	// (slug => {isChoice, isSearchableCapable, valueKind}) as a fallback source.
	function pfTypeMeta( selectEl ) {
		var opt = selectEl.options[ selectEl.selectedIndex ];
		var meta = { isChoice: false, isSearchableCapable: false };

		if ( opt ) {
			if ( opt.hasAttribute( 'data-is-choice' ) ) {
				meta.isChoice = '1' === opt.getAttribute( 'data-is-choice' );
			}
			if ( opt.hasAttribute( 'data-is-searchable-capable' ) ) {
				meta.isSearchableCapable = '1' === opt.getAttribute( 'data-is-searchable-capable' );
			}
		}

		// Fall back to the localized matrix when option attributes are absent.
		var matrix = window.bnProfileFieldTypes;
		if ( opt && matrix && matrix[ opt.value ] ) {
			if ( ! ( opt && opt.hasAttribute( 'data-is-choice' ) ) ) {
				meta.isChoice = ! ! matrix[ opt.value ].isChoice;
			}
			if ( ! ( opt && opt.hasAttribute( 'data-is-searchable-capable' ) ) ) {
				meta.isSearchableCapable = ! ! matrix[ opt.value ].isSearchableCapable;
			}
		}

		return meta;
	}

	// Visibility levels whose values reach a search mirror at all. A field only
	// visible to followers, connections or its owner is never indexed — see
	// ProfileService::sync_search_mirror(), which routes by visibility and writes
	// no mirror for those three.
	var PF_SEARCHABLE_VISIBILITY = [ 'public', 'members' ];

	// The visibility <select> living in the same form as this type <select>.
	function pfVisibilityOf( selectEl ) {
		var form = selectEl.form || selectEl.closest( 'form' );

		return form ? form.querySelector( 'select[name="visibility"]' ) : null;
	}

	function pfSyncSearchControl( selectEl ) {
		var wrapId = selectEl.getAttribute( 'data-bn-pf-search-wrap' );
		if ( ! wrapId ) {
			return;
		}
		var wrap = document.getElementById( wrapId );
		if ( ! wrap ) {
			return;
		}

		/*
		 * Two gates, one rule: only offer the control where it can do something.
		 *
		 * The type gate was already here. The VISIBILITY gate was not, so an owner
		 * could set a field to Followers only, tick "Searchable in the member
		 * directory", save — and nothing happened, with nothing saying why. The
		 * combination is unsatisfiable: those values never reach an index.
		 *
		 * Hidden rather than explained. This is a configuration screen, not a
		 * place to warn people about choices we could simply not offer — and it
		 * is exactly how the type gate has always behaved, so one panel now has
		 * one behaviour instead of two.
		 *
		 * The checkbox is cleared when hidden so the impossible state cannot
		 * persist. The server enforces the same rule, because a UI gate is a
		 * convenience and never the authority.
		 */
		var vis        = pfVisibilityOf( selectEl );
		var visValue   = vis ? vis.value : 'public';
		var applicable = pfTypeMeta( selectEl ).isSearchableCapable
			&& -1 !== PF_SEARCHABLE_VISIBILITY.indexOf( visValue );

		wrap.style.display = applicable ? '' : 'none';
		if ( ! applicable ) {
			var box = wrap.querySelector( 'input[type="checkbox"][name="is_searchable"]' );
			if ( box ) {
				box.checked = false;
			}
		}
	}

	function initProfileFieldBuilder() {
		var selects = document.querySelectorAll( 'select[data-bn-pf-search-wrap]' );
		if ( ! selects.length ) {
			return;
		}

		selects.forEach( function ( sel ) {
			// Reflect the initial state on load.
			pfSyncSearchControl( sel );
			sel.addEventListener( 'change', function () {
				pfSyncSearchControl( sel );
			} );

			// Visibility decides it too, so the control has to follow that select
			// as well — otherwise switching a field to Followers only would leave a
			// ticked checkbox on screen that the save then ignores.
			var vis = pfVisibilityOf( sel );
			if ( vis ) {
				vis.addEventListener( 'change', function () {
					pfSyncSearchControl( sel );
				} );
			}
		} );
	}

	/**
	 * Give the avatar/cover "Remove" toggles immediate visual feedback.
	 *
	 * Removal applies on save (the checkbox is read server-side), but until
	 * then the preview stayed fully rendered, so ticking the box looked inert.
	 * Dim the preview and reveal the pending-removal note while it is checked.
	 */
	function initRemoveMediaToggles() {
		var toggles = [
			{ box: 'bn-remove-avatar', preview: '.bn-avatar-preview', note: 'bn-remove-avatar-note' },
			{ box: 'bn-remove-cover', preview: '.bn-cover-preview', note: 'bn-remove-cover-note' }
		];
		toggles.forEach( function ( t ) {
			var box     = document.getElementById( t.box );
			var preview = document.querySelector( t.preview );
			var note    = document.getElementById( t.note );
			if ( ! box || ! preview ) {
				return;
			}
			function sync() {
				preview.classList.toggle( 'bn-edit-media--removing', box.checked );
				if ( note ) {
					note.hidden = ! box.checked;
				}
			}
			box.addEventListener( 'change', sync );
			sync();
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
		initConfirmModal();
		initEditTabs();
		initRepeaters();
		initProfileFieldBuilder();
		initRemoveMediaToggles();
	} );
}() );
