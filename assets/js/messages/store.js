/**
 * BuddyNext native messaging store (`buddynext/messages`).
 *
 * BuddyNext owns the /messages/ UI end-to-end and consumes the WPMediaVerse
 * messaging engine at the API level only (mvs/v1) — no MVS screens are embedded.
 * The conversation rail and thread are server-rendered via the dm-* partials;
 * this store drives the live interactions: sending, polling for new messages,
 * read receipts, reactions, replies, and conversation delete. Opening a
 * conversation, switching tabs, and search use the rail's real <a href> links
 * (server re-render), so the store only renders message bubbles client-side.
 *
 * Config arrives via the wrapper's data-wp-context: { mvsRest, nonce, userId,
 * activeConvId, messagesUrl }.
 *
 * @package BuddyNext
 */

import { store, getContext } from '@wordpress/interactivity';

/**
 * Build the request headers for an mvs/v1 call.
 *
 * @param {Object} ctx Interactivity context.
 * @return {Object} Headers.
 */
function headers( ctx ) {
	return {
		'Content-Type': 'application/json',
		'X-WP-Nonce': ctx.nonce || '',
	};
}

/**
 * Append plain text to an element, converting newlines to <br> via real DOM
 * nodes (no innerHTML — the body can never inject markup).
 *
 * @param {HTMLElement} el    Target element.
 * @param {string}      value Raw text.
 * @return {void}
 */
function appendText( el, value ) {
	const lines = ( value == null ? '' : String( value ) ).split( '\n' );
	lines.forEach( ( line, i ) => {
		if ( i > 0 ) {
			el.appendChild( document.createElement( 'br' ) );
		}
		el.appendChild( document.createTextNode( line ) );
	} );
}

/**
 * The thread message-log element (append target), or null.
 *
 * @return {HTMLElement|null} Log element.
 */
function logEl() {
	return document.querySelector( '[data-wp-interactive="buddynext/messages"] [role="log"]' );
}

/**
 * Render a message bubble matching templates/parts/dm-message.php.
 *
 * @param {Object} msg    Message row { id, sender_id, content|body, created_at }.
 * @param {number} viewer Viewing user ID.
 * @return {HTMLElement} The bubble node.
 */
function buildMessageNode( msg, viewer ) {
	const isMine = parseInt( msg.sender_id, 10 ) === parseInt( viewer, 10 );
	const body   = msg.body != null ? msg.body : ( msg.content || '' );

	const wrap = document.createElement( 'div' );
	wrap.className = 'bn-dm-msg' + ( isMine ? ' is-mine' : '' );
	wrap.dataset.msgId = String( msg.id || 0 );

	const content = document.createElement( 'div' );
	content.className = 'bn-dm-msg__content';

	const bubble = document.createElement( 'div' );
	bubble.className = 'bn-dm-bubble' + ( isMine ? ' is-mine' : '' );
	// Plain text + <br> via DOM nodes (no innerHTML) — a sent bubble can never
	// inject markup. The server bubble allows br/em/strong via wp_kses.
	appendText( bubble, body );
	content.appendChild( bubble );

	// Hover action bar — cloned from the server-rendered <template> so the icon,
	// label, and markup have a single source of truth and reply works on
	// client-built (sent/polled) messages. Clicks go through onThreadClick.
	const tpl = document.getElementById( 'bn-dm-msg-actions-tpl' );
	if ( tpl && 'content' in tpl ) {
		content.appendChild( tpl.content.cloneNode( true ) );
	}

	const meta = document.createElement( 'div' );
	meta.className = 'bn-dm-msg__meta';
	const time = document.createElement( 'time' );
	time.className = 'bn-dm-msg__time';
	const stamp = /^\d+$/.test( String( msg.created_at ) ) ? Number( msg.created_at ) * 1000 : msg.created_at;
	const ts    = msg.created_at ? new Date( stamp ) : new Date();
	time.dateTime = ts.toISOString();
	time.textContent = ts.toLocaleTimeString( [], { hour: 'numeric', minute: '2-digit' } );
	meta.appendChild( time );
	content.appendChild( meta );

	wrap.appendChild( content );
	return wrap;
}

/**
 * Append a message node and scroll the log to the bottom (skipping duplicates).
 *
 * @param {Object} msg    Message row.
 * @param {number} viewer Viewing user ID.
 * @return {void}
 */
function appendMessage( msg, viewer ) {
	const log = logEl();
	if ( ! log ) {
		return;
	}
	if ( log.querySelector( '.bn-dm-msg[data-msg-id="' + ( msg.id || 0 ) + '"]' ) ) {
		return; // already rendered (e.g. our own send echoed back by the poll)
	}
	log.appendChild( buildMessageNode( msg, viewer ) );
	log.scrollTop = log.scrollHeight;
}

const { actions } = store( 'buddynext/messages', {
	actions: {
		// ── Composer ──────────────────────────────────────────────────────────
		onMessageInput( event ) {
			const el = event.target;
			el.style.height = 'auto';
			el.style.height = Math.min( el.scrollHeight, 160 ) + 'px';
		},
		onInputKeydown( event ) {
			// Enter sends; Shift+Enter inserts a newline.
			if ( 'Enter' === event.key && ! event.shiftKey ) {
				event.preventDefault();
				actions.sendMessage( event );
			}
		},
		*sendMessage( event ) {
			if ( event && event.preventDefault ) {
				event.preventDefault();
			}
			const ctx    = getContext();
			const convId = parseInt( ctx.activeConvId, 10 ) || 0;
			const input  = document.getElementById( 'bn-dm-input' );
			const text   = input ? input.value.trim() : '';
			if ( ! convId || '' === text ) {
				return;
			}

			const payload = { content: text };
			if ( ctx.replyToId ) {
				payload.parent_id = parseInt( ctx.replyToId, 10 );
			}

			if ( input ) {
				input.value = '';
				input.style.height = 'auto';
			}
			actions.clearReply();

			try {
				const res = yield fetch( ctx.mvsRest + '/conversations/' + convId + '/messages', {
					method: 'POST',
					headers: headers( ctx ),
					body: JSON.stringify( payload ),
				} );
				if ( res.ok ) {
					const msg = yield res.json();
					appendMessage( msg, ctx.userId );
				}
			} catch ( _e ) {}
		},

		// ── Message action bar (delegated) ──────────────────────────────────────
		// One click handler on the server-rendered log covers both server- and
		// client-rendered messages, since the Interactivity API does not hydrate
		// nodes appended at runtime. Buttons carry a data-bn-action verb.
		onThreadClick( event ) {
			const trigger = event.target.closest( '[data-bn-action]' );
			if ( ! trigger ) {
				return;
			}
			const action = trigger.dataset.bnAction;
			if ( 'reply' === action ) {
				actions.setReply( trigger );
			}
		},

		// ── Reply ─────────────────────────────────────────────────────────────
		setReply( trigger ) {
			const ctx = getContext();
			const msg = trigger.closest( '.bn-dm-msg' );
			if ( ! msg ) {
				return;
			}
			ctx.replyToId   = parseInt( msg.dataset.msgId, 10 ) || 0;
			const bubble    = msg.querySelector( '.bn-dm-bubble' );
			ctx.replyToText = bubble ? bubble.textContent.trim().replace( /\s+/g, ' ' ).slice( 0, 120 ) : '';
			const input = document.getElementById( 'bn-dm-input' );
			if ( input ) {
				input.focus();
			}
		},
		clearReply() {
			const ctx = getContext();
			ctx.replyToId = 0;
			ctx.replyToText = '';
		},

		// ── Reactions ───────────────────────────────────────────────────────────
		*toggleReaction( event ) {
			const ctx = getContext();
			const btn = event.target.closest( '[data-msg-id]' );
			if ( ! btn ) {
				return;
			}
			const msgId = parseInt( btn.dataset.msgId, 10 ) || 0;
			const emoji = btn.dataset.emoji || '';
			if ( ! msgId || '' === emoji ) {
				return;
			}
			const pressed = btn.getAttribute( 'aria-pressed' ) === 'true';
			try {
				yield fetch(
					ctx.mvsRest + '/messages/' + msgId + '/reactions' + ( pressed ? '?emoji=' + encodeURIComponent( emoji ) : '' ),
					{
						method: pressed ? 'DELETE' : 'POST',
						headers: headers( ctx ),
						body: pressed ? null : JSON.stringify( { emoji } ),
					}
				);
				btn.setAttribute( 'aria-pressed', pressed ? 'false' : 'true' );
			} catch ( _e ) {}
		},

		// ── Delete conversation ──────────────────────────────────────────────────
		openDeleteConfirm() {
			getContext().confirmOpen = true;
		},
		closeDeleteConfirm() {
			getContext().confirmOpen = false;
		},
		stopPropagation( event ) {
			event.stopPropagation();
		},
		*confirmDeleteConversation() {
			const ctx    = getContext();
			const convId = parseInt( ctx.activeConvId, 10 ) || 0;
			if ( ! convId ) {
				return;
			}
			try {
				yield fetch( ctx.mvsRest + '/conversations/' + convId, {
					method: 'DELETE',
					headers: headers( ctx ),
				} );
			} catch ( _e ) {}
			window.location.href = ctx.messagesUrl || '?';
		},
		openThreadOptions() {
			// Surfaces the delete confirm for now; richer thread options are Pro.
			getContext().confirmOpen = true;
		},

		// ── Rail search / tabs (progressive enhancement over server links) ───────
		onPanelSearchInput( event ) {
			const term = ( event.target.value || '' ).toLowerCase().trim();
			document.querySelectorAll( '.bn-dm-rail__item' ).forEach( ( item ) => {
				const hit = ! term || ( item.textContent || '' ).toLowerCase().includes( term );
				item.style.display = hit ? '' : 'none';
			} );
		},
		switchPanelTab() {
			// The tab is a real <a href> — let the browser navigate (server re-render).
		},

		// ── Composer extras (graceful: focus the input; rich picker/upload is Pro) ─
		openEmojiPicker() {
			const input = document.getElementById( 'bn-dm-input' );
			if ( input ) {
				input.focus();
			}
		},
		openAttachment() {
			const input = document.getElementById( 'bn-dm-input' );
			if ( input ) {
				input.focus();
			}
		},

		// ── Read receipts ─────────────────────────────────────────────────────────
		*markRead() {
			const ctx    = getContext();
			const convId = parseInt( ctx.activeConvId, 10 ) || 0;
			if ( ! convId ) {
				return;
			}
			try {
				yield fetch( ctx.mvsRest + '/conversations/' + convId + '/read', {
					method: 'POST',
					headers: headers( ctx ),
				} );
			} catch ( _e ) {}
		},
	},
	callbacks: {
		// Mark the open thread read on mount, then poll for new messages.
		*initThread() {
			const ctx    = getContext();
			const convId = parseInt( ctx.activeConvId, 10 ) || 0;
			if ( ! convId ) {
				return;
			}
			const log = logEl();
			if ( log ) {
				log.scrollTop = log.scrollHeight;
			}
			yield actions.markRead();

			let since = new Date().toISOString();
			const poll = async () => {
				try {
					const res = await fetch(
						ctx.mvsRest + '/messages/poll?since=' + encodeURIComponent( since ) + '&conversation_id=' + convId,
						{ headers: { 'X-WP-Nonce': ctx.nonce || '' } }
					);
					if ( res.ok ) {
						const data = await res.json();
						( data.messages || [] ).forEach( ( m ) => {
							if ( parseInt( m.conversation_id, 10 ) === convId ) {
								appendMessage( m, ctx.userId );
							}
						} );
						if ( data.server_time ) {
							since = data.server_time;
						}
					}
				} catch ( _e ) {}
			};
			window.setInterval( poll, 5000 );
		},
	},
} );
