/* BuddyNext — Settings page admin JS.
 *
 * Two features wired here:
 *
 *   1. Settings search — filters visible fields/sections by title and
 *      visible text on the current tab. Cmd/Ctrl+K focuses the input.
 *      Cross-tab discovery is handled by a small static index that
 *      surfaces "found on the {Other} tab" hints when the query
 *      matches a known tab label.
 *
 *   2. Webhook endpoint manager — wires the `[data-bn-webhooks]` card
 *      rendered by Settings::render_webhook_endpoints() to the existing
 *      OutboundWebhookController REST routes (POST /webhooks,
 *      DELETE /webhooks/{id}, POST /webhooks/{id}/test).
 */
( function () {
	'use strict';

	// ─── Settings search ──────────────────────────────────────────────────
	function initSettingsSearch() {
		var input = document.querySelector( '[data-bn-admin-search]' );
		if ( ! input ) {
			return;
		}
		var status = document.querySelector( '[data-bn-admin-search-status]' );

		// Cmd/Ctrl + K focus shortcut — Slack/Linear convention.
		document.addEventListener( 'keydown', function ( e ) {
			if ( ( e.key === 'k' || e.key === 'K' ) && ( e.metaKey || e.ctrlKey ) ) {
				e.preventDefault();
				input.focus();
				input.select();
			}
		} );

		var apply = function () {
			var q = ( input.value || '' ).trim().toLowerCase();
			var fields = document.querySelectorAll( '.bn-field, .bn-section, [class*="bn-section__card"]' );
			var matched = 0;
			fields.forEach( function ( el ) {
				if ( q === '' ) {
					el.hidden = false;
					return;
				}
				var txt = ( el.textContent || '' ).toLowerCase();
				var hit = txt.indexOf( q ) !== -1;
				el.hidden = ! hit;
				if ( hit ) { matched++; }
			} );
			if ( ! status ) { return; }
			if ( q === '' ) {
				status.textContent = '';
				return;
			}
			if ( matched === 0 ) {
				status.textContent = 'No matches on this tab. Try another tab from the bar above.';
			} else {
				status.textContent = matched === 1 ? '1 match on this tab.' : matched + ' matches on this tab.';
			}
		};
		input.addEventListener( 'input', apply );
	}

	// ─── Webhook endpoint manager ─────────────────────────────────────────
	function initWebhookManager() {
		var card = document.querySelector( '[data-bn-webhooks]' );
		if ( ! card ) {
			return;
		}
		var restUrl   = card.dataset.bnRestUrl   || '';
		var restNonce = card.dataset.bnRestNonce || '';
		var urlInput  = card.querySelector( '[data-bn-webhook-url]' );
		var addBtn    = card.querySelector( '[data-bn-webhook-add]' );
		var tbody     = card.querySelector( '[data-bn-webhook-tbody]' );
		var status    = card.querySelector( '[data-bn-webhook-status]' );

		function setStatus( msg, isError ) {
			if ( ! status ) { return; }
			status.textContent = msg;
			status.style.color = isError ? 'var(--bn-danger, #dc2626)' : 'var(--bn-ink-3, #6b7280)';
		}

		function selectedEvents() {
			return Array.prototype.map.call(
				card.querySelectorAll( '[data-bn-webhook-event]:checked' ),
				function ( cb ) { return cb.value; }
			);
		}

		// Add new endpoint.
		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				var url    = ( urlInput.value || '' ).trim();
				var events = selectedEvents();
				if ( ! url ) {
					setStatus( 'Enter a URL first.', true );
					return;
				}
				if ( events.length === 0 ) {
					setStatus( 'Pick at least one event.', true );
					return;
				}
				addBtn.disabled = true;
				setStatus( 'Registering…' );
				fetch( restUrl, {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
					body:    JSON.stringify( { url: url, events: events } ),
				} )
				.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
				.then( function ( res ) {
					addBtn.disabled = false;
					if ( ! res.ok ) {
						setStatus( ( res.body && res.body.message ) || 'Registration failed.', true );
						return;
					}
					setStatus( 'Endpoint registered. Reload to see it in the table.' );
					urlInput.value = '';
					card.querySelectorAll( '[data-bn-webhook-event]:checked' ).forEach( function ( cb ) { cb.checked = false; } );
					// Reload to re-fetch the server-rendered table — simpler than
					// hand-building a row + keeps server-side numbering authoritative.
					window.location.reload();
				} )
				.catch( function () {
					addBtn.disabled = false;
					setStatus( 'Network error. Try again.', true );
				} );
			} );
		}

		function escHtml( s ) {
			var d = document.createElement( 'div' );
			d.textContent = ( s === null || s === undefined ) ? '' : String( s );
			return d.innerHTML;
		}

		// Test + delete + view-log via event delegation.
		if ( tbody ) {
			tbody.addEventListener( 'click', function ( e ) {
				var testBtn = e.target.closest( '[data-bn-webhook-test]' );
				var rmBtn   = e.target.closest( '[data-bn-webhook-remove]' );
				var logBtn  = e.target.closest( '[data-bn-webhook-log]' );
				if ( logBtn ) {
					var logId  = logBtn.dataset.bnWebhookLog;
					var logRow = tbody.querySelector( '[data-bn-webhook-log-row="' + logId + '"]' );
					if ( ! logRow ) { return; }
					var cell = logRow.querySelector( '.bn-webhook-log-cell' );
					if ( logBtn.getAttribute( 'aria-expanded' ) === 'true' ) {
						logRow.hidden = true;
						logBtn.setAttribute( 'aria-expanded', 'false' );
						return;
					}
					logBtn.setAttribute( 'aria-expanded', 'true' );
					logRow.hidden = false;
					cell.textContent = 'Loading…';
					fetch( restUrl + '/' + logId + '/log', { headers: { 'X-WP-Nonce': restNonce } } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						var items = ( data && data.items ) || [];
						if ( ! items.length ) { cell.textContent = 'No deliveries logged yet.'; return; }
						var rows = items.map( function ( it ) {
							return '<tr><td>' + escHtml( it.event ) + '</td><td>' + escHtml( it.status ) +
								'</td><td>' + escHtml( it.response_code ) + '</td><td>' + escHtml( it.created_at ) + '</td></tr>';
						} ).join( '' );
						cell.innerHTML = '<table class="bn-webhook-log-table"><thead><tr><th>Event</th>' +
							'<th>Status</th><th>Code</th><th>Time</th></tr></thead><tbody>' + rows + '</tbody></table>';
					} )
					.catch( function () { cell.textContent = 'Could not load delivery log.'; } );
					return;
				}
				if ( testBtn ) {
					var testId = testBtn.dataset.bnWebhookTest;
					testBtn.disabled = true;
					setStatus( 'Sending test payload…' );
					fetch( restUrl + '/' + testId + '/test', {
						method: 'POST',
						headers: { 'X-WP-Nonce': restNonce },
					} )
					.then( function ( r ) {
						testBtn.disabled = false;
						if ( r.ok ) {
							setStatus( 'Test sent. Check the receiving endpoint.' );
						} else {
							setStatus( 'Test failed (HTTP ' + r.status + ').', true );
						}
					} )
					.catch( function () {
						testBtn.disabled = false;
						setStatus( 'Network error.', true );
					} );
				} else if ( rmBtn ) {
					window.bnConfirm( {
						title: 'Remove webhook',
						message: 'Remove this webhook endpoint? This cannot be undone.',
						tone: 'danger',
						okLabel: 'Remove',
					} ).then( function ( ok ) {
						if ( ! ok ) {
							return;
						}
						var rmId = rmBtn.dataset.bnWebhookRemove;
						var row  = rmBtn.closest( '[data-bn-webhook-row]' );
						rmBtn.disabled = true;
						setStatus( 'Removing…' );
						fetch( restUrl + '/' + rmId, {
							method: 'DELETE',
							headers: { 'X-WP-Nonce': restNonce },
						} )
						.then( function ( r ) {
							rmBtn.disabled = false;
							if ( r.ok ) {
								if ( row ) { row.remove(); }
								setStatus( 'Removed.' );
							} else {
								setStatus( 'Remove failed.', true );
							}
						} )
						.catch( function () {
							rmBtn.disabled = false;
							setStatus( 'Network error.', true );
						} );
					} );
				}
			} );
		}
	}

	/**
	 * Copy-to-clipboard for the social-login redirect URIs (and any
	 * [data-bn-copy] button pointing at an input id). Falls back to select+focus
	 * when the async clipboard API is unavailable.
	 */
	function initCopyButtons() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-bn-copy]' ) : null;
			if ( ! btn ) { return; }
			e.preventDefault();
			var input = document.getElementById( btn.getAttribute( 'data-bn-copy' ) );
			if ( ! input ) { return; }
			var done = function () {
				if ( ! btn.getAttribute( 'data-label' ) ) { btn.setAttribute( 'data-label', btn.textContent ); }
				btn.textContent = 'Copied';
				setTimeout( function () { btn.textContent = btn.getAttribute( 'data-label' ); }, 1600 );
			};
			input.focus();
			input.select();
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( input.value ).then( done ).catch( function () {
					try { document.execCommand( 'copy' ); done(); } catch ( _e ) {}
				} );
			} else {
				try { document.execCommand( 'copy' ); done(); } catch ( _e ) {}
			}
		} );
	}

	// ─── Companion installer ──────────────────────────────────────────────
	// Wires the [data-bn-companions] add-on list (rendered by
	// Settings::render_tab_integrations) to the companions/install REST route.
	// Endpoint, nonce, and i18n strings come from data-* attributes on the list
	// so this file stays free of server-rendered values.
	function initCompanions() {
		var list = document.querySelector( '[data-bn-companions]' );
		if ( ! list ) {
			return;
		}
		var endpoint = list.getAttribute( 'data-rest' );
		var nonce    = list.getAttribute( 'data-nonce' );
		var i18n     = {
			installing: list.getAttribute( 'data-i18n-installing' ) || 'Installing…',
			installed:  list.getAttribute( 'data-i18n-installed' ) || 'Installed — reloading…',
			failed:     list.getAttribute( 'data-i18n-failed' ) || 'Install failed.',
			network:    list.getAttribute( 'data-i18n-network' ) || 'Install failed — network error.'
		};

		list.querySelectorAll( '.bn-companion-install' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var row  = btn.closest( '.bn-addon-row' );
				var msg  = row ? row.querySelector( '.bn-companion-msg' ) : null;
				var orig = btn.textContent;
				btn.disabled    = true;
				btn.textContent = i18n.installing;
				if ( msg ) { msg.textContent = ''; }
				fetch( endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( { slug: btn.getAttribute( 'data-slug' ) } )
				} ).then( function ( r ) {
					return r.json().then( function ( d ) { return { ok: r.ok, d: d }; } );
				} ).then( function ( res ) {
					if ( res.ok ) {
						btn.textContent = i18n.installed;
						window.location.reload();
					} else {
						btn.disabled    = false;
						btn.textContent = orig;
						if ( msg ) { msg.textContent = ( res.d && res.d.message ) ? res.d.message : i18n.failed; }
					}
				} ).catch( function () {
					btn.disabled    = false;
					btn.textContent = orig;
					if ( msg ) { msg.textContent = i18n.network; }
				} );
			} );
		} );
	}

	/**
	 * Keep each colour swatch (input[type=color]) in sync with its paired hex
	 * text field so picking a colour updates the hex and typing a valid hex
	 * updates the swatch. The text field is canonical (it saves).
	 */
	function initColorFields() {
		var swatches = document.querySelectorAll( 'input[type="color"][data-bn-color-for]' );
		Array.prototype.forEach.call( swatches, function ( swatch ) {
			var target = document.getElementById( swatch.getAttribute( 'data-bn-color-for' ) );
			if ( ! target ) {
				return;
			}
			swatch.addEventListener( 'input', function () {
				target.value = swatch.value;
			} );
			target.addEventListener( 'input', function () {
				var v = target.value.trim();
				if ( /^#?[0-9a-fA-F]{6}$/.test( v ) ) {
					swatch.value = '#' === v.charAt( 0 ) ? v : '#' + v;
				}
			} );
		} );
	}

	// ─── Secret fields (Generate / Rotate / Show) ────────────────────────
	// Wires the webhook shared-secret controls. Copy is handled by the generic
	// initCopyButtons() above (data-bn-copy). Generation is client-side via the
	// Web Crypto API — a strong 32-byte secret never leaves the browser until
	// the owner clicks Save, so we never round-trip a half-formed value.
	function initSecretFields() {
		function randomSecret() {
			var bytes = new Uint8Array( 32 );
			( window.crypto || window.msCrypto ).getRandomValues( bytes );
			var hex = '';
			for ( var i = 0; i < bytes.length; i++ ) {
				hex += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
			}
			return hex;
		}

		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target.closest ) { return; }

			var gen = e.target.closest( '[data-bn-secret-generate]' );
			if ( gen ) {
				e.preventDefault();
				var genTarget = document.getElementById( gen.getAttribute( 'data-bn-secret-generate' ) );
				if ( ! genTarget ) { return; }
				genTarget.value = randomSecret();
				genTarget.type  = 'text';
				var group = gen.closest( '[data-bn-secret-group]' );
				var msg   = group ? group.querySelector( '[data-bn-secret-msg]' ) : null;
				var reveal = group ? group.querySelector( '[data-bn-secret-reveal]' ) : null;
				if ( reveal ) { reveal.textContent = reveal.getAttribute( 'data-hide-label' ) || 'Hide'; reveal.setAttribute( 'aria-pressed', 'true' ); }
				if ( msg ) { msg.textContent = msg.getAttribute( 'data-generated-label' ) || 'New secret generated. Click Save Settings to apply, then copy it into your receiving service.'; }
				return;
			}

			var rev = e.target.closest( '[data-bn-secret-reveal]' );
			if ( rev ) {
				e.preventDefault();
				var revTarget = document.getElementById( rev.getAttribute( 'data-bn-secret-reveal' ) );
				if ( ! revTarget ) { return; }
				var show = 'password' === revTarget.type;
				revTarget.type = show ? 'text' : 'password';
				rev.textContent = show
					? ( rev.getAttribute( 'data-hide-label' ) || 'Hide' )
					: ( rev.getAttribute( 'data-show-label' ) || 'Show' );
				rev.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initSettingsSearch();
			initWebhookManager();
			initCopyButtons();
			initCompanions();
			initColorFields();
			initSecretFields();
		} );
	} else {
		initSettingsSearch();
		initWebhookManager();
		initCopyButtons();
		initCompanions();
		initColorFields();
		initSecretFields();
	}
} )();
