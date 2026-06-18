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

	// ── Drag-reorder via jQuery UI Sortable (per scope) ──────────────────────

	if ( window.jQuery && window.jQuery.fn.sortable ) {
		var $ = window.jQuery;
		// Mobile is omitted: its bottom bar is a fixed 5-slot strip (centre
		// Create stays centred), so order is never applied — a drag handle
		// there would be a no-op. See NavOverrides::apply_mobile_items().
		var scopes = [ 'main', 'profile', 'space' ];
		scopes.forEach( function ( sc ) {
			var listId = '#bn-nav-sortable-' + sc;
			if ( $( listId ).length ) {
				$( listId ).sortable( {
					handle: '.bn-drag-row__handle',
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
			hintEl.textContent = i18n.slugFree || 'Slug is available';
		} else if ( 'warn' === status ) {
			hintEl.textContent = i18n.slugWarn || 'An existing page uses this slug, it will become unreachable';
		} else {
			hintEl.textContent = i18n.slugBlock || 'This slug is reserved or used by another hub';
		}
	}

	document.querySelectorAll( 'input[name$="[url_slug]"]' ).forEach( function ( input ) {
		var match = input.name.match( /\[main\]\[([^\]]+)\]\[url_slug\]/ );
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
