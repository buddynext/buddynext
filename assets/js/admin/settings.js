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

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initSettingsSearch();
			initWebhookManager();
			initCopyButtons();
		} );
	} else {
		initSettingsSearch();
		initWebhookManager();
		initCopyButtons();
	}
} )();
