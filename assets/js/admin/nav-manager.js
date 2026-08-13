/**
 * BuddyNext — Navigation Manager admin script.
 *
 * Wires up the three-panel layout:
 *   - Scope sidebar selection (Main / Profile / Space / Mobile).
 *   - Per-scope config-panel switching for the selected nav item.
 *   - Visibility toggle visual sync.
 *   - Add-custom-tab inline form.
 *   - Drag-reorder via jQuery UI Sortable (always available in WP admin).
 *   - URL-slug conflict probe via GET /buddynext/v1/admin/slug-check.
 *
 * Configuration is provided through window.bnNavManager (set by
 * wp_localize_script in NavManager::enqueue_assets()):
 *   firstSlug    — Slug of the initial active main-scope config panel.
 *   restUrl      — REST base URL ending in 'buddynext/v1/'.
 *   restNonce    — Fresh wp_rest nonce sent via X-WP-Nonce header.
 *   i18n.slugHint — Default URL-slug hint text.
 *   i18n.slugFree — Hint copy when the slug is available.
 *   i18n.slugWarn — Hint copy when an existing page already uses the slug.
 *   i18n.slugBlock — Hint copy when the slug is reserved/blocked.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

( function () {
	'use strict';

	var wpI18n = ( window.wp && window.wp.i18n ) || {};
	var __ = wpI18n.__ || function ( s ) { return s; };

	var cfg = window.bnNavManager || {};
	var i18n = cfg.i18n || {};
	var restUrl = cfg.restUrl || '';
	var restNonce = cfg.restNonce || '';

	// ── Scope switching ──────────────────────────────────────────────────────

	function showScope( scope ) {
		document.querySelectorAll( '.bn-scope-item' ).forEach( function ( el ) {
			el.classList.remove( 'bn-scope-active' );
		} );
		var activeItem = document.querySelector( '.bn-scope-item[data-scope="' + scope + '"]' );
		if ( activeItem ) {
			activeItem.classList.add( 'bn-scope-active' );
		}

		document.querySelectorAll( '[data-scope-panel]' ).forEach( function ( panel ) {
			panel.hidden = ( panel.dataset.scopePanel !== scope );
		} );

		document.querySelectorAll( '[data-config-scope]' ).forEach( function ( ctr ) {
			ctr.hidden = ( ctr.dataset.configScope !== scope );
		} );

		var firstBtn = document.querySelector(
			'[data-scope-panel="' + scope + '"] .bn-config-btn'
		);
		if ( firstBtn ) {
			showPanel( scope, firstBtn.dataset.slug );
		}
	}

	document.querySelectorAll( '.bn-scope-item[data-scope]' ).forEach( function ( item ) {
		item.addEventListener( 'click', function () {
			showScope( this.dataset.scope );
		} );
		item.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ' ' === e.key ) {
				e.preventDefault();
				showScope( this.dataset.scope );
			}
		} );
	} );

	// ── Config panel switching ───────────────────────────────────────────────

	var activeSlug = cfg.firstSlug || '';

	function showPanel( scope, slug ) {
		var ctr = document.querySelector( '[data-config-scope="' + scope + '"]' );
		if ( ctr ) {
			ctr.querySelectorAll( '.bn-config-card' ).forEach( function ( el ) {
				el.hidden = true;
			} );
		}

		var scopePanel = document.querySelector( '[data-scope-panel="' + scope + '"]' );
		if ( scopePanel ) {
			scopePanel.querySelectorAll( '.bn-config-btn' ).forEach( function ( b ) {
				b.classList.remove( 'bn-config-btn-active' );
			} );
			// Mark the list row whose item is open in the inspector so the
			// list and the config panel visibly agree on the selection.
			scopePanel.querySelectorAll( '.bn-drag-row' ).forEach( function ( row ) {
				row.classList.toggle( 'is-selected', row.dataset.slug === slug );
			} );
		}

		var panelId = 'bn-config-' + scope + '-' + slug;
		var panel = document.getElementById( panelId );
		if ( panel ) {
			panel.hidden = false;
			activeSlug = slug;
		}

		var btn = document.querySelector(
			'[data-scope-panel="' + scope + '"] .bn-config-btn[data-slug="' + slug + '"]'
		);
		if ( btn ) {
			btn.classList.add( 'bn-config-btn-active' );
		}
	}

	if ( activeSlug ) {
		showPanel( 'main', activeSlug );
	}

	document.querySelectorAll( '.bn-config-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			showPanel( this.dataset.scope, this.dataset.slug );
		} );
	} );

	// ── Toggle switch visual sync ────────────────────────────────────────────

	document.querySelectorAll( '.bn-toggle-input' ).forEach( function ( chk ) {
		chk.addEventListener( 'change', function () {
			var toggle = this.nextElementSibling;
			var row = this.closest( '.bn-drag-row' );
			if ( this.checked ) {
				if ( toggle ) {
					toggle.setAttribute( 'aria-checked', 'true' );
				}
				if ( row ) {
					row.removeAttribute( 'data-row-hidden' );
				}
			} else {
				if ( toggle ) {
					toggle.setAttribute( 'aria-checked', 'false' );
				}
				if ( row ) {
					row.setAttribute( 'data-row-hidden', '' );
				}
			}
		} );
	} );

	// ── Add Custom Tab toggle ────────────────────────────────────────────────

	document.querySelectorAll( '[data-action="bn-open-add-tab"]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var scope = this.dataset.scope;
			var formEl = document.getElementById( 'bn-add-tab-form-' + scope );
			if ( formEl ) {
				formEl.hidden = false;
				var firstInput = formEl.querySelector( 'input[type="text"]' );
				if ( firstInput ) {
					firstInput.focus();
				}
			}
		} );
	} );

	document.querySelectorAll( '.bn-cancel-add-tab' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var scope = this.dataset.scope;
			var formEl = document.getElementById( 'bn-add-tab-form-' + scope );
			if ( formEl ) {
				formEl.hidden = true;
				formEl.querySelectorAll( 'input' ).forEach( function ( inp ) {
					inp.value = '';
				} );
			}
		} );
	} );

	// ── Validate a custom link's URL before it is saved ──────────────────────
	// A bare word ("test") would be minted into a broken http://test link on the
	// server, so guide the owner to a real destination here instead of letting a
	// dead tab save. Accepts absolute URLs, /paths, #anchors, {tokens} (profile/
	// space scopes) or a dotted host. Mirrors NavManager::sanitize_tab_url().
	( function () {
		var i18n = ( window.wp && window.wp.i18n ) || {};
		var __   = i18n.__ || function ( s ) { return s; };
		function usable( v ) {
			v = ( v || '' ).trim();
			if ( ! v ) { return false; }
			if ( /\{(space_url|space_id|profile_url|user_id|slug)\}/.test( v ) ) { return true; }
			if ( /^[a-z][a-z0-9+.\-]*:/i.test( v ) ) { return true; }
			if ( '/' === v.charAt( 0 ) || '#' === v.charAt( 0 ) ) { return true; }
			return -1 !== v.indexOf( '.' );
		}
		document.querySelectorAll( '.bn-add-tab-inline-actions button[type="submit"]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				var form = btn.closest( '[id^="bn-add-tab-form-"]' );
				if ( ! form ) { return; }
				var url = form.querySelector( 'input[name*="[url]"]' );
				if ( ! url ) { return; }
				var msg = form.querySelector( '.bn-url-error' );
				if ( usable( url.value ) ) {
					if ( msg ) { msg.remove(); }
					return;
				}
				e.preventDefault();
				if ( ! msg ) {
					msg = document.createElement( 'p' );
					msg.className = 'description bn-url-error';
					msg.style.color = 'var(--bn-danger, #d63638)';
					url.insertAdjacentElement( 'afterend', msg );
				}
				msg.textContent = ( url.value || '' ).trim()
					? __( 'Enter a full web address (https://example.com) or a path on this site (/support).', 'buddynext' )
					: __( 'Enter a URL for this link.', 'buddynext' );
				url.focus();
			} );
		} );
	}() );

	// ── Drag-reorder via jQuery UI Sortable (per scope) ──────────────────────

	if ( window.jQuery && window.jQuery.fn.sortable ) {
		var $ = window.jQuery;
		// Mobile is included: the bar now honours the saved order
		// (NavOverrides::apply_mobile_items). Its Create slot is the one exception —
		// it is centred by arithmetic, so it must keep the same number of slots on
		// each side. It renders with a pinned, non-draggable handle and is excluded
		// from the sortable below, rather than being draggable into a position the
		// renderer would silently undo.
		var scopes = [ 'main', 'profile', 'space', 'mobile', 'account' ];
		scopes.forEach( function ( sc ) {
			var listId = '#bn-nav-sortable-' + sc;
			if ( $( listId ).length ) {
				$( listId ).sortable( {
					handle: '.bn-drag-row__handle',
					// The pinned Create row cannot be picked up, and nothing can be
					// dropped onto its index — it stays in the middle.
					items: '> .bn-drag-row:not(.bn-drag-row--pinned)',
					// The handle is a <button>, which is in jQuery UI Sortable's
					// default cancel list ("input,textarea,button,select,option,a"),
					// so a mousedown on it aborts the drag and the row never moves.
					// Clear cancel — the handle option already limits drag start to
					// the handle, so nothing else can initiate a sort.
					cancel: '',
					axis: 'y',
					containment: 'parent',
					update: function () {
						$( listId + ' .bn-drag-row' ).each( function ( i ) {
							var slug = this.dataset.slug;
							var panelId = 'bn-config-' + sc + '-' + slug;
							var orderInput = document.querySelector(
								'#' + panelId + ' input[type="number"]'
							);
							if ( orderInput ) {
								orderInput.value = ( i + 1 ) * 10;
							}
						} );
					}
				} ).disableSelection();
			}
		} );
	}

	// ── Slug conflict detection ──────────────────────────────────────────────

	function bnSetSlugHint( hintEl, status ) {
		hintEl.className = 'bn-cf-hint bn-cf-hint--' + status;
		if ( 'free' === status ) {
			hintEl.textContent = i18n.slugFree || __( 'Slug is available', 'buddynext' );
		} else if ( 'warn' === status ) {
			hintEl.textContent = i18n.slugWarn || __( 'An existing page uses this slug, it will become unreachable', 'buddynext' );
		} else {
			hintEl.textContent = i18n.slugBlock || __( 'This slug is reserved or used by another hub', 'buddynext' );
		}
	}

	// The selector and the regex both looked for `…[main][hub][url_slug]`, a name
	// this admin has never rendered — the field is `bn_hub[hub][slug]`. So the
	// query matched zero inputs and the whole live-check was dead code: the REST
	// endpoint, the debounce and the three localized strings all shipped and none
	// of them could ever run.
	document.querySelectorAll( 'input[name^="bn_hub"][name$="[slug]"]' ).forEach( function ( input ) {
		var match = input.name.match( /^bn_hub\[([^\]]+)\]\[slug\]$/ );
		if ( ! match ) {
			return;
		}
		var hub = match[ 1 ];
		var hintEl = input.parentNode ? input.parentNode.querySelector( '.bn-cf-hint' ) : null;
		var timer = null;

		if ( ! hintEl ) {
			return;
		}

		input.addEventListener( 'input', function () {
			var slugVal = input.value.trim();
			window.clearTimeout( timer );

			if ( '' === slugVal ) {
				hintEl.className = 'bn-cf-hint';
				hintEl.textContent = i18n.slugHint || '';
				return;
			}

			timer = window.setTimeout( function () {
				if ( ! restUrl ) {
					return;
				}

				var url = restUrl + 'admin/slug-check?slug=' +
					encodeURIComponent( slugVal ) +
					'&context=' + encodeURIComponent( hub );

				window.fetch( url, {
					method: 'GET',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': restNonce,
						Accept: 'application/json'
					}
				} )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( json ) {
						if ( json && json.status ) {
							bnSetSlugHint( hintEl, json.status );
						}
					} )
					.catch( function () {} );
			}, 300 );
		} );
	} );
}() );
