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

import { store, getContext, getElement } from '@wordpress/interactivity';
import { bnConfirm } from '../shell/dialog.js';

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

// Debounce timer for the New-message recipient search.
let composeSearchTimer = 0;

// Curated common emoji for the composer picker. Emoji are message content, so
// the set lives here in JS rather than in PHP chrome.
const EMOJI_SET = [
	'😀', '😁', '😂', '🤣', '😊', '😍', '😘', '😎', '🤩', '🤗',
	'🤔', '😐', '😴', '😢', '😭', '😡', '👍', '👎', '👏', '🙌',
	'🙏', '💪', '🔥', '✨', '🎉', '❤️', '💔', '💯', '👀', '🚀',
	'☕', '🍕', '🎂', '🌟', '✅', '❌', '⚡', '💬', '👋', '🤝',
];

/**
 * Insert text at the cursor in a textarea, keep focus, and refresh autosize.
 *
 * @param {HTMLTextAreaElement|null} el   Target textarea.
 * @param {string}                   text Text to insert.
 * @return {void}
 */
function insertAtCursor( el, text ) {
	if ( ! el ) {
		return;
	}
	const start = el.selectionStart != null ? el.selectionStart : el.value.length;
	const end   = el.selectionEnd != null ? el.selectionEnd : el.value.length;
	el.value    = el.value.slice( 0, start ) + text + el.value.slice( end );
	const pos   = start + text.length;
	el.focus();
	el.setSelectionRange( pos, pos );
	el.style.height = 'auto';
	el.style.height = Math.min( el.scrollHeight, 160 ) + 'px';
}

/**
 * Build a non-result status line for the recipient picker (hint / empty).
 *
 * @param {string} text Message text.
 * @return {HTMLElement} The <li> node.
 */
function composeMessage( text ) {
	const li = document.createElement( 'li' );
	li.className = 'bn-dm-compose__hint';
	li.textContent = text || '';
	return li;
}

/**
 * Build one tile in the "your photos" media grid (DOM only).
 *
 * @param {Object} media Media item { id, thumbnail_url|file_url, title }.
 * @return {HTMLElement} The <li> tile.
 */
function buildMediaTile( media ) {
	const thumb = media.thumbnail_url || media.file_url || '';
	const li = document.createElement( 'li' );
	li.className = 'bn-dm-media__tile';
	li.setAttribute( 'role', 'option' );
	li.tabIndex = 0;
	li.dataset.mediaId = String( media.id || 0 );
	li.dataset.thumb   = thumb;
	li.dataset.title   = media.title || '';
	const img = document.createElement( 'img' );
	img.src = thumb;
	img.alt = media.title || '';
	img.loading = 'lazy';
	li.appendChild( img );
	return li;
}

/**
 * Build a hint/empty line for the media grid.
 *
 * @param {string} text Message.
 * @return {HTMLElement} The <li> node.
 */
function mediaHint( text ) {
	const li = document.createElement( 'li' );
	li.className = 'bn-dm-media__hint';
	li.textContent = text || '';
	return li;
}

/**
 * Build one recipient-picker result row (DOM nodes only — no innerHTML).
 *
 * @param {Object} member Member row { user_id, display_name, handle, avatar_url }.
 * @return {HTMLElement} The <li> option node.
 */
function buildComposeResult( member ) {
	const li = document.createElement( 'li' );
	li.className = 'bn-dm-compose__result';
	li.setAttribute( 'role', 'option' );
	li.tabIndex = 0;
	li.dataset.userId = String( member.user_id || 0 );
	li.dataset.userName = member.display_name || '';

	const avatar = document.createElement( 'span' );
	avatar.className = 'bn-avatar';
	avatar.dataset.size = 'sm';
	if ( member.avatar_url ) {
		const img = document.createElement( 'img' );
		img.src = member.avatar_url;
		img.alt = '';
		img.width = 28;
		img.height = 28;
		img.loading = 'lazy';
		avatar.appendChild( img );
	}

	const meta = document.createElement( 'span' );
	meta.className = 'bn-dm-compose__result-meta';
	const name = document.createElement( 'span' );
	name.className = 'bn-dm-compose__result-name';
	name.textContent = member.display_name || '';
	const handle = document.createElement( 'span' );
	handle.className = 'bn-dm-compose__result-handle';
	handle.textContent = member.handle ? '@' + member.handle : '';
	meta.appendChild( name );
	meta.appendChild( handle );

	li.appendChild( avatar );
	li.appendChild( meta );
	return li;
}

/**
 * Map a mvs-pro group participant list into the enriched roster shape the
 * members panel renders (mirrors MessagesData::roster on the PHP side).
 *
 * @param {Array}  participants Engine participants ({ id|user_id, role, display_name }).
 * @param {number} userId       Viewing user id.
 * @param {Object} i18n         Localised labels.
 * @return {{ viewerIsAdmin: boolean, members: Array }}
 */
function mapGroupMembers( participants, userId, i18n ) {
	const list = Array.isArray( participants ) ? participants : [];
	const uid  = parseInt( userId, 10 );
	const me   = list.find( ( p ) => parseInt( p.id != null ? p.id : p.user_id, 10 ) === uid );
	const viewerIsAdmin = !! me && me.role === 'admin';
	const members = list.map( ( p ) => {
		const id      = parseInt( p.id != null ? p.id : p.user_id, 10 ) || 0;
		const isAdmin = p.role === 'admin';
		const isSelf  = id === uid;
		return {
			id,
			name: p.display_name || p.name || '',
			role: p.role || 'member',
			role_label: isAdmin ? ( i18n.roleAdmin || 'Admin' ) : ( i18n.roleMember || 'Member' ),
			role_action_label: isAdmin ? ( i18n.makeMember || 'Make member' ) : ( i18n.makeAdmin || 'Make admin' ),
			is_admin: isAdmin,
			is_self: isSelf,
			can_manage: viewerIsAdmin && ! isSelf,
		};
	} );
	return { viewerIsAdmin, members };
}

/**
 * Update the live group context (roster, admin flag, member count) from a
 * mvs-pro group response shape.
 *
 * @param {Object} ctx  Interactivity context.
 * @param {Object} data Group shape ({ participants, member_count }).
 * @return {boolean} True when applied.
 */
function applyGroupShape( ctx, data ) {
	if ( ! data || ! Array.isArray( data.participants ) ) {
		return false;
	}
	const mapped = mapGroupMembers( data.participants, ctx.userId, ctx.i18n || {} );
	ctx.activeMembers = mapped.members;
	ctx.activeIsAdmin = mapped.viewerIsAdmin;
	ctx.memberCount = data.member_count || mapped.members.length;
	return true;
}

/**
 * Normalise a message's media into { type, thumbnail, url, title }, or null.
 * Handles the local optimistic shape (msg.media), mvs media_share, and the
 * legacy WP attachment shape.
 *
 * @param {Object} msg Message row.
 * @return {Object|null} Normalised media.
 */
function normalizeMedia( msg ) {
	if ( msg.media && typeof msg.media === 'object' ) {
		return msg.media;
	}
	if ( msg.media_share && typeof msg.media_share === 'object' ) {
		const s = msg.media_share;
		return { type: s.type || 'image', thumbnail: s.thumbnail || '', url: s.permalink || '', title: s.title || '' };
	}
	if ( msg.attachment && typeof msg.attachment === 'object' ) {
		const a = msg.attachment;
		const isImg = ( a.mime || '' ).indexOf( 'image/' ) === 0;
		return { type: isImg ? 'image' : 'file', thumbnail: a.thumbnail || a.url || '', url: a.url || '', title: a.name || '' };
	}
	return null;
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

	// Private media attachment (mvs media_share), rendered above the text to
	// match templates/parts/dm-message.php.
	const media = normalizeMedia( msg );
	if ( media ) {
		const wrapM = document.createElement( 'div' );
		wrapM.className = 'bn-dm-bubble__media';
		wrapM.dataset.type = media.type;
		if ( 'image' === media.type && media.thumbnail ) {
			const a = document.createElement( 'a' );
			a.href = media.url || media.thumbnail;
			if ( media.url ) {
				a.target = '_blank';
				a.rel = 'noopener';
			}
			const img = document.createElement( 'img' );
			img.src = media.thumbnail;
			img.alt = media.title || '';
			img.loading = 'lazy';
			a.appendChild( img );
			wrapM.appendChild( a );
		} else if ( media.url ) {
			const a = document.createElement( 'a' );
			a.className = 'bn-dm-bubble__file';
			a.href = media.url;
			a.target = '_blank';
			a.rel = 'noopener';
			const span = document.createElement( 'span' );
			span.textContent = media.title || 'Attachment';
			a.appendChild( span );
			wrapM.appendChild( a );
		}
		if ( wrapM.firstChild ) {
			bubble.appendChild( wrapM );
		}
	}

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

	// Empty reactions container (hidden until the viewer reacts), matching the
	// server markup so applyReaction can append chips on a just-sent bubble.
	const reactions = document.createElement( 'div' );
	reactions.className = 'bn-dm-msg__reactions';
	reactions.hidden = true;
	content.appendChild( reactions );

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

/**
 * Close every open reaction picker in the thread.
 *
 * @return {void}
 */
function closeReactPops() {
	document.querySelectorAll( '.bn-dm-msg__react-wrap.is-open' ).forEach( ( w ) => w.classList.remove( 'is-open' ) );
}

/**
 * Read/round a reaction chip's count.
 *
 * @param {HTMLElement} chip Chip element.
 * @return {number} Count.
 */
function chipCount( chip ) {
	const el = chip.querySelector( '.bn-dm-msg__reaction-count' );
	return el ? parseInt( el.textContent, 10 ) || 0 : 0;
}

/**
 * Apply a reaction to a message: optimistic chip update + mvs/v1 call.
 *
 * MVS stores one reaction per user, so picking a new slug replaces the old one
 * in a single POST; picking the same slug again removes it (DELETE).
 *
 * @param {Object}      ctx   Interactivity context.
 * @param {HTMLElement} msgEl The .bn-dm-msg element.
 * @param {string}      slug  Reaction slug.
 * @return {void}
 */
function applyReaction( ctx, msgEl, slug ) {
	if ( ! msgEl || ! slug ) {
		return;
	}
	const msgId = parseInt( msgEl.dataset.msgId, 10 ) || 0;
	const box   = msgEl.querySelector( '.bn-dm-msg__reactions' );
	if ( ! msgId || ! box ) {
		return;
	}

	const chipOf  = ( s ) => box.querySelector( '.bn-dm-msg__reaction[data-slug="' + s + '"]' );
	const mineNow = box.querySelector( '.bn-dm-msg__reaction.is-mine' );
	const mySlug  = mineNow ? mineNow.dataset.slug : null;

	const removeChip = ( chip ) => {
		const n = chipCount( chip ) - 1;
		if ( n <= 0 ) {
			chip.remove();
		} else {
			chip.querySelector( '.bn-dm-msg__reaction-count' ).textContent = String( n );
			chip.classList.remove( 'is-mine' );
			chip.setAttribute( 'aria-pressed', 'false' );
		}
	};

	if ( mySlug === slug ) {
		// Toggle my reaction off.
		removeChip( mineNow );
		fetch( ctx.mvsRest + '/messages/' + msgId + '/reactions?emoji=' + encodeURIComponent( slug ), {
			method: 'DELETE',
			headers: headers( ctx ),
		} ).catch( () => {} );
	} else {
		// Drop my previous reaction (if any), then add/boost the new one.
		if ( mineNow ) {
			removeChip( mineNow );
		}
		let chip = chipOf( slug );
		if ( chip ) {
			chip.querySelector( '.bn-dm-msg__reaction-count' ).textContent = String( chipCount( chip ) + 1 );
			chip.classList.add( 'is-mine' );
			chip.setAttribute( 'aria-pressed', 'true' );
		} else {
			chip = buildReactionChip( msgEl, slug );
			if ( chip ) {
				box.appendChild( chip );
			}
		}
		fetch( ctx.mvsRest + '/messages/' + msgId + '/reactions', {
			method: 'POST',
			headers: headers( ctx ),
			body: JSON.stringify( { emoji: slug } ),
		} ).catch( () => {} );
	}

	box.hidden = ! box.querySelector( '.bn-dm-msg__reaction' );
}

/**
 * Build a "mine" reaction chip, cloning the glyph from this message's picker.
 *
 * @param {HTMLElement} msgEl The .bn-dm-msg element.
 * @param {string}      slug  Reaction slug.
 * @return {HTMLElement|null} The chip, or null if the glyph is unavailable.
 */
function buildReactionChip( msgEl, slug ) {
	const opt = msgEl.querySelector( '.bn-dm-msg__react-opt[data-slug="' + slug + '"]' );
	const btn = document.createElement( 'button' );
	btn.type = 'button';
	btn.className = 'bn-dm-msg__reaction is-mine';
	btn.dataset.bnAction = 'react-toggle';
	btn.dataset.slug = slug;
	btn.setAttribute( 'aria-pressed', 'true' );
	btn.setAttribute( 'aria-label', slug.charAt( 0 ).toUpperCase() + slug.slice( 1 ) + ' (1)' );
	if ( opt && opt.firstElementChild ) {
		btn.appendChild( opt.firstElementChild.cloneNode( true ) );
	}
	const count = document.createElement( 'span' );
	count.className = 'bn-dm-msg__reaction-count';
	count.textContent = '1';
	btn.appendChild( count );
	return btn;
}

const { actions } = store( 'buddynext/messages', {
	state: {
		// ── Compose modal (DM ↔ group) ────────────────────────────────────────
		get composeIsGroup() { return getContext().composeMode === 'group'; },
		get composeIsDm() { return getContext().composeMode !== 'group'; },
		get composeTitle() {
			const ctx = getContext();
			const i18n = ctx.i18n || {};
			return ctx.composeMode === 'group'
				? ( i18n.composeNewGroup || 'New group' )
				: ( i18n.composeNewMessage || 'New message' );
		},
		get groupHasNoMembers() { return ( getContext().groupMembers || [] ).length === 0; },
		get createGroupDisabled() {
			const ctx = getContext();
			return !! ctx.groupBusy || ( ctx.groupMembers || [] ).length < 1;
		},
		// Live group thread-header values (kept in sync by the members panel).
		get headerGroupName() { return getContext().activeGroupName || ''; },
		get headerGroupStatus() {
			const n = parseInt( getContext().memberCount, 10 ) || 0;
			return n === 1 ? '1 member' : n + ' members';
		},
	},
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
			const ctx     = getContext();
			const convId  = parseInt( ctx.activeConvId, 10 ) || 0;
			const input   = document.getElementById( 'bn-dm-input' );
			const text    = input ? input.value.trim() : '';
			const mediaId = parseInt( ctx.attachmentId, 10 ) || 0;
			// A message needs either text or an attachment.
			if ( ! convId || ( '' === text && ! mediaId ) ) {
				return;
			}

			const payload = { content: text };
			if ( ctx.replyToId ) {
				payload.parent_id = parseInt( ctx.replyToId, 10 );
			}
			if ( mediaId ) {
				payload.media_id = mediaId;
			}

			// Snapshot everything so a failed send can be fully restored — never
			// silently eat a member's text or attachment on a flaky connection.
			const snapshot = {
				text: text,
				replyToId: ctx.replyToId,
				replyToText: ctx.replyToText,
				attachmentId: ctx.attachmentId,
				attachmentName: ctx.attachmentName,
				attachmentPreview: ctx.attachmentPreview,
			};
			const pendingMedia = mediaId
				? { type: 'image', thumbnail: ctx.attachmentPreview || '', url: '', title: ctx.attachmentName || '' }
				: null;

			// Optimistically clear the composer for a snappy feel.
			if ( input ) {
				input.value = '';
				input.style.height = 'auto';
			}
			actions.clearReply();
			actions.clearAttachment();

			let ok = false;
			try {
				const res = yield fetch( ctx.mvsRest + '/conversations/' + convId + '/messages', {
					method: 'POST',
					headers: headers( ctx ),
					body: JSON.stringify( payload ),
				} );
				if ( res.ok ) {
					ok = true;
					const msg = yield res.json();
					if ( pendingMedia && ! msg.media && ! msg.media_share ) {
						msg.media = pendingMedia;
					}
					appendMessage( msg, ctx.userId );
				}
			} catch ( _e ) {}

			if ( ! ok ) {
				// Restore the composer so the member can retry — no data loss.
				if ( input ) {
					input.value = snapshot.text;
					input.style.height = 'auto';
					input.style.height = Math.min( input.scrollHeight, 160 ) + 'px';
					input.focus();
				}
				ctx.replyToId         = snapshot.replyToId;
				ctx.replyToText       = snapshot.replyToText;
				ctx.attachmentId      = snapshot.attachmentId;
				ctx.attachmentName    = snapshot.attachmentName;
				ctx.attachmentPreview = snapshot.attachmentPreview;
				ctx.attachmentVisible = !! ( parseInt( snapshot.attachmentId, 10 ) || 0 );
			}
		},

		// ── Message action bar (delegated) ──────────────────────────────────────
		// One click handler on the server-rendered log covers both server- and
		// client-rendered messages, since the Interactivity API does not hydrate
		// nodes appended at runtime. Buttons carry a data-bn-action verb.
		onThreadClick( event ) {
			const trigger = event.target.closest( '[data-bn-action]' );
			if ( ! trigger ) {
				closeReactPops(); // Click elsewhere in the log dismisses any open picker.
				return;
			}
			const action = trigger.dataset.bnAction;
			const msgEl  = trigger.closest( '.bn-dm-msg' );

			if ( 'reply' === action ) {
				actions.setReply( trigger );
			} else if ( 'react' === action ) {
				const wrap = trigger.closest( '.bn-dm-msg__react-wrap' );
				const open = wrap && wrap.classList.contains( 'is-open' );
				closeReactPops();
				if ( wrap && ! open ) {
					wrap.classList.add( 'is-open' );
				}
			} else if ( 'react-pick' === action ) {
				applyReaction( getContext(), msgEl, trigger.dataset.slug || '' );
				closeReactPops();
			} else if ( 'react-toggle' === action ) {
				applyReaction( getContext(), msgEl, trigger.dataset.slug || '' );
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

		// Reactions are handled by applyReaction() via the delegated onThreadClick
		// (data-bn-action react / react-pick / react-toggle).

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
		openEmojiPicker( event ) {
			const wrap = event.target.closest( '.bn-dm-emoji-wrap' );
			const pop  = wrap ? wrap.querySelector( '.bn-dm-emoji-pop' ) : null;
			if ( ! pop ) {
				return;
			}
			if ( ! pop.dataset.built ) {
				// Close (×) control — always visible, themed via CSS so it reads in
				// light + dark and on mobile (previously there was no close button).
				const close = document.createElement( 'button' );
				close.type = 'button';
				close.className = 'bn-dm-emoji-pop__close';
				close.setAttribute( 'aria-label', ( window.wp && window.wp.i18n ) ? window.wp.i18n.__( 'Close emoji picker', 'buddynext' ) : 'Close emoji picker' );
				close.textContent = '×';
				pop.appendChild( close );

				// Emoji grid lives in its own child so the close button is not a grid
				// cell (which would shove a column off and break containment).
				const grid = document.createElement( 'div' );
				grid.className = 'bn-dm-emoji-pop__grid';
				EMOJI_SET.forEach( ( ch ) => {
					const b = document.createElement( 'button' );
					b.type = 'button';
					b.className = 'bn-dm-emoji-pop__item';
					b.dataset.emojiChar = ch;
					b.textContent = ch;
					grid.appendChild( b );
				} );
				pop.appendChild( grid );

				pop.dataset.built = '1';
				pop.addEventListener( 'click', ( e ) => {
					if ( e.target.closest( '.bn-dm-emoji-pop__close' ) ) {
						pop.hidden = true;
						return;
					}
					const opt = e.target.closest( '[data-emoji-char]' );
					if ( opt ) {
						insertAtCursor( document.getElementById( 'bn-dm-input' ), opt.dataset.emojiChar );
						pop.hidden = true;
					}
				} );
			}
			pop.hidden = ! pop.hidden;
		},
		// Open the "share a photo" picker (your media + upload new).
		async openMediaPicker() {
			const ctx = getContext();
			ctx.mediaPickerOpen = true;
			const grid = document.querySelector( '.bn-dm-media__grid' );
			if ( ! grid || grid.dataset.loaded ) {
				return;
			}
			try {
				const res = await fetch(
					ctx.mvsRest + '/media?per_page=24&author=' + ( parseInt( ctx.userId, 10 ) || 0 ),
					{ headers: headers( ctx ) }
				);
				if ( ! res.ok ) {
					return;
				}
				const data  = await res.json();
				const list  = Array.isArray( data ) ? data : ( data.items || [] );
				const items = list.filter( ( m ) => ( m.media_type || 'image' ) === 'image' && ( m.thumbnail_url || m.file_url ) );
				grid.dataset.loaded = '1';
				grid.replaceChildren(
					...( items.length ? items.map( buildMediaTile ) : [ mediaHint( ( ctx.i18n && ctx.i18n.mediaEmpty ) || '' ) ] )
				);
			} catch ( _e ) {}
		},
		closeMediaPicker() {
			getContext().mediaPickerOpen = false;
		},
		onMediaPick( event ) {
			const tile = event.target.closest( '[data-media-id]' );
			if ( ! tile ) {
				return;
			}
			const ctx = getContext();
			ctx.attachmentId      = parseInt( tile.dataset.mediaId, 10 ) || 0;
			ctx.attachmentPreview = tile.dataset.thumb || '';
			ctx.attachmentName    = tile.dataset.title || '';
			ctx.attachmentVisible = !! ctx.attachmentId;
			ctx.mediaPickerOpen   = false; // Reuse existing media — no duplicate upload.
		},
		openAttachment() {
			const f = document.getElementById( 'bn-dm-file' );
			if ( f ) {
				f.click();
			}
		},
		async onFileSelected( event ) {
			const ctx  = getContext();
			const file = event.target.files && event.target.files[ 0 ];
			if ( ! file ) {
				return;
			}
			ctx.attachmentName    = file.name;
			ctx.attachmentVisible = true;
			ctx.attachmentId      = 0;
			ctx.attachmentPreview = ( file.type.indexOf( 'image/' ) === 0 && window.URL )
				? URL.createObjectURL( file )
				: '';

			const fd = new FormData();
			fd.append( 'file', file );
			fd.append( 'privacy', 'private' ); // DM attachments are private MediaVerse media.

			try {
				// FormData sets its own multipart Content-Type boundary — send only the nonce.
				const res = await fetch( ctx.mvsRest + '/media', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.nonce || '' },
					body: fd,
				} );
				if ( ! res.ok ) {
					actions.clearAttachment();
					return;
				}
				const media = await res.json();
				ctx.attachmentId = parseInt( media.id || media.media_id || 0, 10 ) || 0;
				const thumb = media.thumbnail || media.thumbnail_url || media.thumb_large || media.source_url || media.file_url || media.url || '';
				if ( thumb ) {
					ctx.attachmentPreview = thumb;
				}
				if ( ctx.attachmentId ) {
					ctx.mediaPickerOpen = false; // Uploaded — close the picker, show the composer chip.
				} else {
					actions.clearAttachment();
				}
			} catch ( _e ) {
				actions.clearAttachment();
			}
			event.target.value = '';
		},
		clearAttachment() {
			const ctx = getContext();
			ctx.attachmentId      = 0;
			ctx.attachmentName    = '';
			ctx.attachmentPreview = '';
			ctx.attachmentVisible = false;
			const f = document.getElementById( 'bn-dm-file' );
			if ( f ) {
				f.value = '';
			}
		},

		// ── Message requests ────────────────────────────────────────────────────
		*acceptRequest() {
			const ctx    = getContext();
			const convId = parseInt( ctx.activeConvId, 10 ) || 0;
			if ( ! convId ) {
				return;
			}
			try {
				const res = yield fetch( ctx.mvsRest + '/conversations/' + convId + '/accept', {
					method: 'POST',
					headers: headers( ctx ),
				} );
				if ( res.ok ) {
					// Reload so the composer replaces the request banner.
					window.location.href = ctx.messagesUrl + convId + '/';
				}
			} catch ( _e ) {}
		},
		*declineRequest() {
			const ctx    = getContext();
			const convId = parseInt( ctx.activeConvId, 10 ) || 0;
			if ( ! convId ) {
				return;
			}
			try {
				yield fetch( ctx.mvsRest + '/conversations/' + convId + '/decline', {
					method: 'POST',
					headers: headers( ctx ),
				} );
			} catch ( _e ) {}
			window.location.href = ctx.messagesUrl || '?';
		},

		// ── New message (recipient picker) ──────────────────────────────────────
		openCompose() {
			getContext().composeOpen = true;
			// Focus the search field once the modal is shown.
			setTimeout( () => {
				const input = document.getElementById( 'bn-dm-compose-search' );
				if ( input ) {
					input.focus();
				}
			}, 0 );
		},
		closeCompose() {
			getContext().composeOpen = false;
		},
		setComposeDm() {
			getContext().composeMode = 'dm';
		},
		setComposeGroup() {
			getContext().composeMode = 'group';
		},
		setGroupName( event ) {
			getContext().groupName = String( event.target.value || '' );
		},
		removeGroupMember( event ) {
			const btn = event.target.closest( '[data-id]' );
			if ( ! btn ) { return; }
			const id  = parseInt( btn.dataset.id, 10 ) || 0;
			const ctx = getContext();
			ctx.groupMembers = ( ctx.groupMembers || [] ).filter( ( m ) => parseInt( m.id, 10 ) !== id );
		},
		*createGroup() {
			const ctx     = getContext();
			const members = ( ctx.groupMembers || [] ).map( ( m ) => parseInt( m.id, 10 ) ).filter( Boolean );
			if ( ctx.groupBusy || members.length < 1 ) { return; }
			ctx.groupBusy = true;
			try {
				const res = yield fetch( ctx.mvsProRest + '/groups', {
					method: 'POST',
					headers: headers( ctx ),
					body: JSON.stringify( {
						title: String( ctx.groupName || '' ).trim(),
						participant_ids: members,
					} ),
				} );
				const data = yield res.json();
				if ( res.ok && data && data.id ) {
					const base = ctx.messagesUrl || '?';
					window.location.href = base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'conversation=' + data.id;
					return;
				}
				ctx.groupBusy = false;
				bnToast( ( ctx.i18n && ctx.i18n.groupCreateFailed ) || 'Could not create the group.', { tone: 'danger' } );
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( ( ctx.i18n && ctx.i18n.groupCreateFailed ) || 'Could not create the group.', { tone: 'danger' } );
			}
		},

		// ── Group members panel (manage) ──────────────────────────────────────
		openGroupPanel() {
			getContext().groupPanelOpen = true;
		},
		closeGroupPanel() {
			getContext().groupPanelOpen = false;
		},
		onGroupNameInput( event ) {
			getContext().activeGroupName = String( event.target.value || '' );
		},
		*groupApi( method, path, body ) {
			const ctx = getContext();
			const opts = { method, headers: headers( ctx ) };
			if ( body ) { opts.body = JSON.stringify( body ); }
			const res  = yield fetch( ctx.mvsProRest + '/groups/' + ctx.activeConvId + path, opts );
			const data = yield res.json();
			return { ok: res.ok, data };
		},
		*renameGroup() {
			const ctx = getContext();
			if ( ctx.groupBusy ) { return; }
			ctx.groupBusy = true;
			try {
				const r = yield actions.groupApi( 'PUT', '', { title: String( ctx.activeGroupName || '' ).trim() } );
				ctx.groupBusy = false;
				if ( r.ok ) { applyGroupShape( ctx, r.data ); bnToast( 'Group renamed.', { tone: 'success' } ); }
				else { bnToast( ( ctx.i18n && ctx.i18n.groupActionFailed ) || 'Something went wrong.', { tone: 'danger' } ); }
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( ( ctx.i18n && ctx.i18n.groupActionFailed ) || 'Something went wrong.', { tone: 'danger' } );
			}
		},
		*toggleMemberRole( event ) {
			const btn = event.target.closest( '[data-id]' );
			if ( ! btn ) { return; }
			const ctx  = getContext();
			if ( ctx.groupBusy ) { return; }
			const id   = parseInt( btn.dataset.id, 10 ) || 0;
			const next = btn.dataset.role === 'admin' ? 'member' : 'admin';
			ctx.groupBusy = true;
			try {
				const r = yield actions.groupApi( 'PUT', '/participants/' + id + '/role', { role: next } );
				ctx.groupBusy = false;
				if ( r.ok ) { applyGroupShape( ctx, r.data ); }
				else { bnToast( ( ctx.i18n && ctx.i18n.groupActionFailed ) || 'Something went wrong.', { tone: 'danger' } ); }
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( ( ctx.i18n && ctx.i18n.groupActionFailed ) || 'Something went wrong.', { tone: 'danger' } );
			}
		},
		*removeMember( event ) {
			const btn = event.target.closest( '[data-id]' );
			if ( ! btn ) { return; }
			const ctx = getContext();
			if ( ctx.groupBusy ) { return; }
			const id  = parseInt( btn.dataset.id, 10 ) || 0;
			ctx.groupBusy = true;
			try {
				const r = yield actions.groupApi( 'DELETE', '/participants/' + id, null );
				ctx.groupBusy = false;
				if ( r.ok ) { applyGroupShape( ctx, r.data ); }
				else { bnToast( ( ctx.i18n && ctx.i18n.groupActionFailed ) || 'Something went wrong.', { tone: 'danger' } ); }
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( ( ctx.i18n && ctx.i18n.groupActionFailed ) || 'Something went wrong.', { tone: 'danger' } );
			}
		},
		onGroupAddSearch( event ) {
			const ctx  = getContext();
			const term = ( event.target.value || '' ).trim();
			const list = document.querySelector( '.bn-dm-group__add-results' );
			if ( ! list ) { return; }
			clearTimeout( composeSearchTimer );
			if ( '' === term ) { list.replaceChildren(); return; }
			composeSearchTimer = setTimeout( async () => {
				try {
					const url = ctx.bnRest + '/members?per_page=8&search=' + encodeURIComponent( term );
					const res = await fetch( url, { headers: headers( ctx ) } );
					if ( ! res.ok ) { return; }
					const data = await res.json();
					const have = new Set( ( ctx.activeMembers || [] ).map( ( m ) => parseInt( m.id, 10 ) ) );
					const members = ( data && data.items ? data.items : [] ).filter(
						( m ) => ! have.has( parseInt( m.user_id, 10 ) )
					);
					list.replaceChildren( ...members.map( buildComposeResult ) );
				} catch ( _e ) {}
			}, 250 );
		},
		*onGroupAddResultClick( event ) {
			const row = event.target.closest( '[data-user-id]' );
			if ( ! row ) { return; }
			const ctx = getContext();
			if ( ctx.groupBusy ) { return; }
			const uid = parseInt( row.dataset.userId, 10 ) || 0;
			if ( ! uid ) { return; }
			ctx.groupBusy = true;
			try {
				const r = yield actions.groupApi( 'POST', '/participants', { user_id: uid } );
				ctx.groupBusy = false;
				if ( r.ok ) {
					applyGroupShape( ctx, r.data );
					const search = document.getElementById( 'bn-dm-group-add' );
					if ( search ) { search.value = ''; }
					const list = document.querySelector( '.bn-dm-group__add-results' );
					if ( list ) { list.replaceChildren(); }
				} else {
					bnToast( ( ctx.i18n && ctx.i18n.groupActionFailed ) || 'Something went wrong.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( ( ctx.i18n && ctx.i18n.groupActionFailed ) || 'Something went wrong.', { tone: 'danger' } );
			}
		},
		*leaveGroup() {
			const ctx = getContext();
			if ( ctx.groupBusy ) { return; }
			const ok = yield bnConfirm( {
				title: ( ctx.i18n && ctx.i18n.groupLeaveConfirm ) || 'Leave this group?',
				body: ( ctx.i18n && ctx.i18n.groupLeaveBody ) || 'You will stop receiving messages from this conversation.',
				confirmLabel: ( ctx.i18n && ctx.i18n.groupLeaveOk ) || 'Leave',
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			ctx.groupBusy = true;
			try {
				yield fetch( ctx.mvsProRest + '/groups/' + ctx.activeConvId + '/leave', {
					method: 'POST',
					headers: headers( ctx ),
				} );
				window.location.href = ctx.messagesUrl || '/messages/';
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( ( ctx.i18n && ctx.i18n.groupActionFailed ) || 'Something went wrong.', { tone: 'danger' } );
			}
		},
		onComposeSearch( event ) {
			const ctx  = getContext();
			const term = ( event.target.value || '' ).trim();
			const list = document.querySelector( '.bn-dm-compose__results' );
			if ( ! list ) {
				return;
			}

			clearTimeout( composeSearchTimer );

			const i18n = ctx.i18n || {};
			if ( '' === term ) {
				list.replaceChildren( composeMessage( i18n.composeHint || '' ) );
				return;
			}

			composeSearchTimer = setTimeout( async () => {
				try {
					const url = ctx.bnRest + '/members?per_page=8&search=' + encodeURIComponent( term );
					const res = await fetch( url, { headers: headers( ctx ) } );
					if ( ! res.ok ) {
						return;
					}
					const data    = await res.json();
					const members = ( data && data.items ? data.items : [] ).filter(
						( m ) => parseInt( m.user_id, 10 ) !== parseInt( ctx.userId, 10 )
					);
					if ( ! members.length ) {
						list.replaceChildren( composeMessage( i18n.composeNone || '' ) );
						return;
					}
					list.replaceChildren( ...members.map( buildComposeResult ) );
				} catch ( _e ) {}
			}, 250 );
		},
		onComposeResultClick( event ) {
			const row = event.target.closest( '[data-user-id]' );
			if ( ! row ) {
				return;
			}
			const uid = parseInt( row.dataset.userId, 10 ) || 0;
			if ( ! uid ) {
				return;
			}
			const ctx = getContext();

			// Group mode: collect the member as a chip instead of navigating.
			if ( ctx.composeMode === 'group' ) {
				const existing = ( ctx.groupMembers || [] ).some( ( m ) => parseInt( m.id, 10 ) === uid );
				if ( ! existing ) {
					ctx.groupMembers = [ ...( ctx.groupMembers || [] ), { id: uid, name: row.dataset.userName || '' } ];
				}
				const search = document.getElementById( 'bn-dm-compose-search' );
				if ( search ) {
					search.value = '';
					search.focus();
				}
				const list = document.querySelector( '.bn-dm-compose__results' );
				if ( list ) {
					list.replaceChildren( composeMessage( ( ctx.i18n && ctx.i18n.composeHint ) || '' ) );
				}
				return;
			}

			const base = ctx.messagesUrl || '?';
			window.location.href = base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'to=' + uid;
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
		// Anchor the messages two-pane to the viewport. The plugin renders inside
		// the active theme's chrome (admin bar + theme header) of variable height,
		// so 100vh-based CSS overflows. Measure the root's actual top offset and
		// feed it to the CSS height formula as --bn-msg-chrome, keeping the rail
		// list + thread body as the only scrollers (composer / compose CTA stay
		// in view). Re-measures on resize.
		fitViewport() {
			const { ref } = getElement();
			if ( ! ref ) {
				return;
			}
			const sync = () => {
				const top  = Math.round( ref.getBoundingClientRect().top + window.scrollY );
				// Include the content column's bottom padding so nothing sits below
				// the pane (the page never scrolls), and on mobile that padding is
				// the bottom-nav clearance (88px) — keeping the composer above it.
				const main = ref.closest( '.bn-app__main' );
				const padB = main ? ( parseInt( getComputedStyle( main ).paddingBottom, 10 ) || 0 ) : 0;
				ref.style.setProperty( '--bn-msg-chrome', ( top + padB ) + 'px' );
			};
			sync();
			window.addEventListener( 'resize', sync, { passive: true } );
		},

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
