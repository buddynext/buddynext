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
import { bnConfirm, bnReportDialog, bnToast } from '../shell/dialog.js';
import { restFetch } from '../shell/rest-client.js';
// Shared client-side thumbnail only — DM upload stays on MediaVerse's own
// conversation-scoped (privacy:'dm') endpoint; this just unifies the fast
// small preview so a large attachment doesn't decode full-res into the chip.
import { makeThumb } from '../media/upload-core.js';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_messages) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/messages namespace below; each lookup keeps the English literal as
 * a fallback so the UI never breaks if the state is absent. fmt() fills
 * sprintf-style '%s'/'%d' placeholders. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%[sd]/g, () => String( vals[ i++ ] ?? '' ) ); }

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

// Open-thread polling state. Only ONE conversation polls at a time: switching
// threads (or closing one under client-side nav) must replace the previous
// interval, never stack a second one that keeps hitting the old conversation
// forever. Held at module scope because data-wp-init gives no teardown hook.
let threadPollTimer          = null; // window.setInterval id for the open thread.
let threadPollFn             = null; // The current thread's poll function (for refocus catch-up).
let threadPollVisibilityBound = false; // visibilitychange listener bound once.

/**
 * Stop the open-thread poll, if any.
 *
 * @return {void}
 */
function stopThreadPoll() {
	if ( threadPollTimer ) {
		window.clearInterval( threadPollTimer );
		threadPollTimer = null;
	}
	threadPollFn = null;
}

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
 * Fill the conversation info panel's "Shared photos" grid from the image media
 * already rendered in the thread — no extra request, just reads the loaded
 * `.bn-dm-bubble__media` thumbnails. Covers media in messages loaded so far.
 *
 * @return {void}
 */
function collectSharedMedia() {
	const grid = document.querySelector( '.bn-dm-info__media' );
	if ( ! grid ) {
		return;
	}

	const imgs = document.querySelectorAll( '.bn-dm-bubble__media[data-type="image"] img' );
	grid.textContent = '';

	if ( ! imgs.length ) {
		const empty = document.createElement( 'li' );
		empty.className = 'bn-dm-info__media-empty';
		empty.textContent = t( 'noPhotosShared', 'No photos shared yet.' );
		grid.appendChild( empty );
		return;
	}

	imgs.forEach( ( img ) => {
		const li   = document.createElement( 'li' );
		li.className = 'bn-dm-info__media-tile';
		const a    = document.createElement( 'a' );
		const link = img.closest( 'a' );
		a.href   = ( link && link.href ) || img.src;
		a.target = '_blank';
		a.rel    = 'noopener';
		const thumb = document.createElement( 'img' );
		thumb.src     = img.src;
		thumb.alt     = img.alt || '';
		thumb.loading = 'lazy';
		a.appendChild( thumb );
		li.appendChild( a );
		grid.appendChild( li );
	} );
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
 * @return {{ viewerIsAdmin: boolean, members: Array }}
 */
function mapGroupMembers( participants, userId ) {
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
			role_label: isAdmin ? t( 'roleAdmin', 'Admin' ) : t( 'roleMember', 'Member' ),
			role_action_label: isAdmin ? t( 'makeMember', 'Make member' ) : t( 'makeAdmin', 'Make admin' ),
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
	const mapped = mapGroupMembers( data.participants, ctx.userId );
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
		// MediaVerse signs `url`/`download_url` through its access-controlled
		// serve endpoint for the conversation viewer; `permalink` is legacy
		// fallback only (no longer shipped for 'dm' media).
		return {
			id: parseInt( s.id, 10 ) || 0,
			type: s.type || 'image',
			thumbnail: s.thumbnail || '',
			url: s.url || s.permalink || '',
			downloadUrl: s.download_url || s.url || s.permalink || '',
			title: s.title || '',
		};
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

	// Media renders BARE (outside the text bubble) — image/video/audio/file get
	// their own presentation, never the blue chat-bubble wrap. Matches
	// templates/parts/dm-message.php.
	const media = normalizeMedia( msg );
	if ( media ) {
		const wrapM = document.createElement( 'div' );
		wrapM.className = 'bn-dm-msg__media';
		wrapM.dataset.type = media.type;
		if ( ( 'image' === media.type || 'video' === media.type ) && media.id && ( media.thumbnail || media.url ) ) {
			// Canonical BN media tile so the shared lightbox
			// (assets/js/media/lightbox.js) opens it IN-PAGE. data-bn-dm flags it
			// as private DM media so the lightbox hides the social chrome.
			const tile = document.createElement( 'button' );
			tile.type = 'button';
			tile.className = 'bn-media-tile bn-media-tile--' + media.type;
			tile.setAttribute( 'data-bn-media-id', String( media.id ) );
			tile.setAttribute( 'data-media-type', media.type );
			tile.setAttribute( 'data-media-src', media.url || media.thumbnail );
			tile.setAttribute( 'data-bn-dm', '1' );
			const img = document.createElement( 'img' );
			img.className = 'bn-media-tile__img';
			img.src = media.thumbnail || media.url;
			img.alt = media.title || '';
			img.loading = 'lazy';
			tile.appendChild( img );
			wrapM.appendChild( tile );
		} else if ( 'audio' === media.type && media.url ) {
			const audio = document.createElement( 'audio' );
			audio.className = 'bn-dm-msg__audio';
			audio.controls = true;
			audio.preload = 'none';
			audio.src = media.url;
			wrapM.appendChild( audio );
		} else if ( media.url ) {
			const a = document.createElement( 'a' );
			a.className = 'bn-dm-msg__file';
			a.href = media.downloadUrl || media.url;
			a.target = '_blank';
			a.rel = 'noopener';
			const span = document.createElement( 'span' );
			span.textContent = media.title || t( 'attachment', 'Attachment' );
			a.appendChild( span );
			wrapM.appendChild( a );
		}
		if ( wrapM.firstChild ) {
			content.appendChild( wrapM );
		}
	}

	// Text bubble — emitted only when there is actual text (a media-only message
	// is just the bare media, no empty blue bubble).
	if ( body && '' !== body.trim() ) {
		const bubble = document.createElement( 'div' );
		bubble.className = 'bn-dm-bubble' + ( isMine ? ' is-mine' : '' );
		// Plain text + <br> via DOM nodes (no innerHTML) — a sent bubble can
		// never inject markup.
		appendText( bubble, body );
		content.appendChild( bubble );
	}

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
	document.querySelectorAll( '.bn-dm-msg__react-wrap.is-open, .bn-dm-msg__react-wrap.is-down' ).forEach( ( w ) => w.classList.remove( 'is-open', 'is-down' ) );
}

/**
 * Hide the composer emoji picker and detach its outside-click / Esc listeners.
 *
 * @param {HTMLElement} pop The .bn-dm-emoji-pop element.
 * @return {void}
 */
function closeEmojiPop( pop ) {
	if ( ! pop || pop.hidden ) {
		return;
	}
	pop.hidden = true;
	if ( pop._bnDismiss ) {
		document.removeEventListener( 'mousedown', pop._bnDismiss, true );
		document.removeEventListener( 'keydown', pop._bnDismiss, true );
		pop._bnDismiss = null;
	}
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
		restFetch( '/messages/' + msgId + '/reactions?emoji=' + encodeURIComponent( slug ), {
			base: ctx.mvsRest,
			nonce: ctx.nonce,
			method: 'DELETE',
			toastOnError: false,
		} );
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
		restFetch( '/messages/' + msgId + '/reactions', {
			base: ctx.mvsRest,
			nonce: ctx.nonce,
			method: 'POST',
			body: { emoji: slug },
			toastOnError: false,
		} );
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

const messagesStore = store( 'buddynext/messages', {
	state: {
		// ── Compose modal (DM ↔ group) ────────────────────────────────────────
		get composeIsGroup() { return getContext().composeMode === 'group'; },
		get composeIsDm() { return getContext().composeMode !== 'group'; },
		get composeTitle() {
			return getContext().composeMode === 'group'
				? t( 'composeNewGroup', 'New group' )
				: t( 'composeNewMessage', 'New message' );
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
			return n === 1
				? t( 'memberCountSingular', '1 member' )
				: fmt( t( 'memberCountPlural', '%d members' ), n );
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
			const res = yield restFetch( '/conversations/' + convId + '/messages', {
				base: ctx.mvsRest,
				nonce: ctx.nonce,
				method: 'POST',
				body: payload,
				toastOnError: false,
			} );
			if ( res.ok ) {
				ok = true;
				const msg = res.data || {};
				if ( pendingMedia && ! msg.media && ! msg.media_share ) {
					msg.media = pendingMedia;
				}
				appendMessage( msg, ctx.userId );
			}

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

				// Surface a reason-aware notice so a denied send is never silent —
				// the recipient may have blocked the sender or hit a limit after the
				// thread opened. Reasons mirror the mvs/v1 send endpoint error codes.
				const reason = ( res.data && res.data.error ) || '';
				let denyMsg;
				switch ( reason ) {
					case 'blocked':
						denyMsg = t( 'sendDeniedBlocked', 'You can no longer message this person.' );
						break;
					case 'dms_disabled':
						denyMsg = t( 'sendDeniedDmsDisabled', 'This person isn’t accepting messages right now.' );
						break;
					case 'connections_only':
					case 'mutual_follow_required':
						denyMsg = t( 'sendDeniedConnectionsOnly', 'This person only accepts messages from their connections.' );
						break;
					case 'rate_limited':
						denyMsg = t( 'sendDeniedRateLimited', 'You’re sending messages too quickly — please wait a moment.' );
						break;
					case 'content_too_long':
						denyMsg = t( 'sendDeniedTooLong', 'That message is too long to send.' );
						break;
					case 'not_participant':
						denyMsg = t( 'sendDeniedNotParticipant', 'You can no longer post to this conversation.' );
						break;
					case 'duplicate_message':
						denyMsg = '';
						break; // dedupe guard — the message already went through.
					default:
						denyMsg = t( 'sendDeniedGeneric', 'Your message couldn’t be sent. Please try again.' );
						break;
				}
				if ( denyMsg ) {
					bnToast( denyMsg, { tone: 'danger' } );
				}
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
					// Flip the picker below the trigger when there is not enough
					// room above it inside the scrolling log (topmost messages),
					// so it never clips at the top of the thread pane.
					const pop = wrap.querySelector( '.bn-dm-msg__react-pop' );
					const log = logEl();
					if ( pop && log ) {
						const need = pop.offsetHeight + 8;
						const room = trigger.getBoundingClientRect().top - log.getBoundingClientRect().top;
						wrap.classList.toggle( 'is-down', room < need );
					}
				}
			} else if ( 'react-pick' === action ) {
				applyReaction( getContext(), msgEl, trigger.dataset.slug || '' );
				closeReactPops();
			} else if ( 'react-toggle' === action ) {
				applyReaction( getContext(), msgEl, trigger.dataset.slug || '' );
			} else if ( 'report' === action ) {
				closeReactPops();
				actions.reportMessage( msgEl );
			} else if ( 'delete' === action ) {
				closeReactPops();
				actions.deleteMessage( msgEl );
			} else if ( 'unsend' === action ) {
				closeReactPops();
				actions.unsendMessage( msgEl );
			}
		},

		// ── Report a message ──────────────────────────────────────────────────
		// The moderation queue already handles object_type=message server-side
		// (ModerationController → bn_reports); this is the missing member-facing
		// surface to CREATE such a report. Posts to the BuddyNext /reports
		// endpoint (default base + global nonce — not the mvs messaging base).
		async reportMessage( msgEl ) {
			const msgId = msgEl ? ( parseInt( msgEl.dataset.msgId, 10 ) || 0 ) : 0;
			if ( ! msgId ) {
				return;
			}
			const result = await bnReportDialog( { title: t( 'reportMessageTitle', 'Report this message' ) } );
			if ( result === null ) {
				return; // Cancelled.
			}
			try {
				const res = await restFetch( '/reports', {
					method:       'POST',
					toastOnError: false,
					body:         {
						object_type: 'message',
						object_id:   msgId,
						reason:      result.reason,
						notes:       result.notes,
					},
				} );
				if ( res.ok || res.status === 201 ) {
					bnToast( t( 'reportMessageSuccess', 'Message reported. Our moderators will review it.' ), { tone: 'success' } );
				} else {
					bnToast( t( 'reportMessageFailed', 'Could not report this message. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'reportMessageFailed', 'Could not report this message. Try again.' ), { tone: 'danger' } );
			}
		},

		// ── Delete / Unsend a message ─────────────────────────────────────────
		// Both hit the WPMediaVerse messaging engine (mvsRest base) and are
		// sender-only there. Delete leaves a "message deleted" tombstone for
		// everyone (any time); Unsend recalls a recent message entirely (within
		// the engine's time window → 410 when it has passed).
		async deleteMessage( msgEl ) {
			const ctx   = getContext();
			const msgId = msgEl ? ( parseInt( msgEl.dataset.msgId, 10 ) || 0 ) : 0;
			if ( ! msgId ) {
				return;
			}
			const ok = await bnConfirm( {
				title:        t( 'deleteMsgTitle', 'Delete this message?' ),
				body:         t( 'deleteMsgBody', 'This removes the message for everyone and leaves a "message deleted" note. This cannot be undone.' ),
				confirmLabel: t( 'deleteMsgConfirm', 'Delete' ),
				tone:         'danger',
			} );
			if ( ! ok ) {
				return;
			}
			try {
				const res = await restFetch( '/messages/' + msgId, {
					base:         ctx.mvsRest,
					nonce:        ctx.nonce,
					method:       'DELETE',
					toastOnError: false,
				} );
				if ( res.ok ) {
					// Tombstone the bubble in place; a later poll/load shows the
					// server-rendered deleted state consistently for both sides.
					msgEl.classList.add( 'is-deleted' );
					const media = msgEl.querySelector( '.bn-dm-msg__media' );
					if ( media ) {
						media.remove();
					}
					const bubble = msgEl.querySelector( '.bn-dm-bubble' );
					if ( bubble ) {
						bubble.textContent = t( 'msgDeleted', 'This message was deleted' );
					}
				} else if ( res.status === 403 ) {
					bnToast( t( 'deleteMsgNotSender', 'You can only delete your own messages.' ), { tone: 'danger' } );
				} else {
					bnToast( t( 'deleteMsgFailed', 'Could not delete the message. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'deleteMsgFailed', 'Could not delete the message. Try again.' ), { tone: 'danger' } );
			}
		},
		async unsendMessage( msgEl ) {
			const ctx   = getContext();
			const msgId = msgEl ? ( parseInt( msgEl.dataset.msgId, 10 ) || 0 ) : 0;
			if ( ! msgId ) {
				return;
			}
			const ok = await bnConfirm( {
				title:        t( 'unsendMsgTitle', 'Unsend this message?' ),
				body:         t( 'unsendMsgBody', 'This removes the message for everyone, with no trace. Unsend only works for a short time after sending.' ),
				confirmLabel: t( 'unsendMsgConfirm', 'Unsend' ),
				tone:         'danger',
			} );
			if ( ! ok ) {
				return;
			}
			try {
				const res = await restFetch( '/messages/' + msgId + '/unsend', {
					base:         ctx.mvsRest,
					nonce:        ctx.nonce,
					method:       'DELETE',
					toastOnError: false,
				} );
				if ( res.ok ) {
					msgEl.remove();
				} else if ( res.status === 410 ) {
					bnToast( t( 'unsendMsgExpired', 'The time to unsend this message has passed. You can still delete it.' ), { tone: 'danger' } );
				} else if ( res.status === 403 ) {
					bnToast( t( 'unsendMsgNotSender', 'You can only unsend your own messages.' ), { tone: 'danger' } );
				} else {
					bnToast( t( 'unsendMsgFailed', 'Could not unsend the message. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'unsendMsgFailed', 'Could not unsend the message. Try again.' ), { tone: 'danger' } );
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

		// ── Conversation info panel (1:1) ─────────────────────────────────────────
		openInfoPanel() {
			const ctx = getContext();
			ctx.infoPanelOpen = true;
			// Fill the shared-photos grid from the images already loaded in the thread.
			collectSharedMedia();
		},
		closeInfoPanel() {
			getContext().infoPanelOpen = false;
		},
		async blockRecipient() {
			const ctx = getContext();
			const uid = parseInt( ctx.recipientId, 10 ) || 0;
			if ( ctx.infoBusy || ! uid ) {
				return;
			}
			const name = ctx.recipientName || t( 'thisMember', 'this member' );
			const ok = await bnConfirm( {
				title:        fmt( t( 'blockTitle', 'Block %s?' ), name ),
				body:         t( 'blockBody', 'They will not be able to message you, and you will not see each other across the community. You can unblock them later from their profile.' ),
				confirmLabel: t( 'blockConfirm', 'Block' ),
				tone:         'danger',
			} );
			if ( ! ok ) {
				return;
			}
			ctx.infoBusy = true;
			try {
				const res = await restFetch( '/users/' + uid + '/block', { method: 'POST', toastOnError: false } );
				if ( res.ok || res.status === 201 ) {
					bnToast( fmt( t( 'blockSuccess', '%s blocked.' ), name ), { tone: 'success' } );
					ctx.infoPanelOpen = false;
					// You can no longer message a blocked member — leave the thread.
					if ( ctx.messagesUrl ) {
						window.location.href = ctx.messagesUrl;
					}
				} else {
					bnToast( fmt( t( 'blockFailed', 'Could not block %s. Try again.' ), name ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( fmt( t( 'blockFailed', 'Could not block %s. Try again.' ), name ), { tone: 'danger' } );
			} finally {
				ctx.infoBusy = false;
			}
		},
		async reportRecipient() {
			const ctx = getContext();
			const uid = parseInt( ctx.recipientId, 10 ) || 0;
			if ( ctx.infoBusy || ! uid ) {
				return;
			}
			const result = await bnReportDialog( { title: t( 'reportConversationTitle', 'Report this conversation' ) } );
			if ( result === null ) {
				return;
			}
			ctx.infoBusy = true;
			try {
				const res = await restFetch( '/reports', {
					method:       'POST',
					toastOnError: false,
					body:         {
						object_type: 'user',
						object_id:   uid,
						reason:      result.reason,
						notes:       result.notes,
					},
				} );
				if ( res.ok || res.status === 201 ) {
					bnToast( t( 'reportConversationSuccess', 'Reported. Our moderators will review it.' ), { tone: 'success' } );
					ctx.infoPanelOpen = false;
				} else {
					bnToast( t( 'reportConversationFailed', 'Could not submit the report. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'reportConversationFailed', 'Could not submit the report. Try again.' ), { tone: 'danger' } );
			} finally {
				ctx.infoBusy = false;
			}
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
			yield restFetch( '/conversations/' + convId, {
				base: ctx.mvsRest,
				nonce: ctx.nonce,
				method: 'DELETE',
			} );
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
				close.setAttribute( 'aria-label', t( 'emojiPickerClose', 'Close emoji picker' ) );
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
						closeEmojiPop( pop );
						return;
					}
					const opt = e.target.closest( '[data-emoji-char]' );
					if ( opt ) {
						insertAtCursor( document.getElementById( 'bn-dm-input' ), opt.dataset.emojiChar );
						closeEmojiPop( pop );
					}
				} );
			}

			if ( pop.hidden ) {
				pop.hidden = false;
				// Dismiss on outside-click or Esc, like every FB/IG popover. The
				// listeners are deferred a tick so the opening click itself doesn't
				// immediately close it, and detached again by closeEmojiPop().
				pop._bnDismiss = ( e ) => {
					if ( 'keydown' === e.type ) {
						if ( 'Escape' === e.key ) {
							closeEmojiPop( pop );
						}
						return;
					}
					if ( ! e.target.closest || ! e.target.closest( '.bn-dm-emoji-wrap' ) ) {
						closeEmojiPop( pop );
					}
				};
				setTimeout( () => {
					document.addEventListener( 'mousedown', pop._bnDismiss, true );
					document.addEventListener( 'keydown', pop._bnDismiss, true );
				}, 0 );
			} else {
				closeEmojiPop( pop );
			}
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
				const res = await restFetch(
					'/media?per_page=24&author=' + ( parseInt( ctx.userId, 10 ) || 0 ),
					{ base: ctx.mvsRest, nonce: ctx.nonce, toastOnError: false }
				);
				if ( ! res.ok ) {
					return;
				}
				const data  = res.data;
				const list  = Array.isArray( data ) ? data : ( data.items || [] );
				const items = list.filter( ( m ) => ( m.media_type || 'image' ) === 'image' && ( m.thumbnail_url || m.file_url ) );
				grid.dataset.loaded = '1';
				grid.replaceChildren(
					...( items.length ? items.map( buildMediaTile ) : [ mediaHint( t( 'mediaEmpty', 'No photos to share yet.' ) ) ] )
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
			// Fast small client preview (shared core) — replaces a full-res object
			// URL so a large image doesn't decode megapixels into the tiny chip.
			ctx.attachmentPreview = ( file.type.indexOf( 'image/' ) === 0 )
				? await makeThumb( file )
				: '';

			const fd = new FormData();
			fd.append( 'file', file );
			fd.append( 'privacy', 'dm' ); // Conversation-scoped MediaVerse media: visible only to the DM's participants, never on any public surface, activity feed, or wall.

			try {
				// FormData sets its own multipart Content-Type boundary — pass it through untouched.
				const res = await restFetch( '/media', {
					base: ctx.mvsRest,
					nonce: ctx.nonce,
					method: 'POST',
					body: fd,
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					actions.clearAttachment();
					return;
				}
				const media = res.data || {};
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
			const res = yield restFetch( '/conversations/' + convId + '/accept', {
				base: ctx.mvsRest,
				nonce: ctx.nonce,
				method: 'POST',
			} );
			if ( res.ok ) {
				// Reload so the composer replaces the request banner.
				window.location.href = ctx.messagesUrl + convId + '/';
			}
		},
		*declineRequest() {
			const ctx    = getContext();
			const convId = parseInt( ctx.activeConvId, 10 ) || 0;
			if ( ! convId ) {
				return;
			}
			const res = yield restFetch( '/conversations/' + convId + '/decline', {
				base: ctx.mvsRest,
				nonce: ctx.nonce,
				method: 'POST',
			} );
			// Only leave the thread once the decline actually succeeded — navigating
			// unconditionally made a failed decline look done (restFetch surfaces the
			// error toast, which a redirect would otherwise discard).
			if ( res.ok ) {
				window.location.href = ctx.messagesUrl || '?';
			}
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
				const res = yield restFetch( '/groups', {
					base: ctx.mvsProRest,
					nonce: ctx.nonce,
					method: 'POST',
					body: {
						title: String( ctx.groupName || '' ).trim(),
						participant_ids: members,
					},
					toastOnError: false,
				} );
				const data = res.data;
				if ( res.ok && data && data.id ) {
					const base = ctx.messagesUrl || '?';
					window.location.href = base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'conversation=' + data.id;
					return;
				}
				ctx.groupBusy = false;
				bnToast( t( 'groupCreateFailed', 'Could not create the group.' ), { tone: 'danger' } );
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( t( 'groupCreateFailed', 'Could not create the group.' ), { tone: 'danger' } );
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
			const opts = { base: ctx.mvsProRest, nonce: ctx.nonce, method, toastOnError: false };
			if ( body ) { opts.body = body; }
			const res  = yield restFetch( '/groups/' + ctx.activeConvId + path, opts );
			return { ok: res.ok, data: res.data };
		},
		*renameGroup() {
			const ctx = getContext();
			if ( ctx.groupBusy ) { return; }
			ctx.groupBusy = true;
			try {
				const r = yield actions.groupApi( 'PUT', '', { title: String( ctx.activeGroupName || '' ).trim() } );
				ctx.groupBusy = false;
				if ( r.ok ) { applyGroupShape( ctx, r.data ); bnToast( t( 'groupRenamed', 'Group renamed.' ), { tone: 'success' } ); }
				else { bnToast( t( 'groupActionFailed', 'Something went wrong.' ), { tone: 'danger' } ); }
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( t( 'groupActionFailed', 'Something went wrong.' ), { tone: 'danger' } );
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
				else { bnToast( t( 'groupActionFailed', 'Something went wrong.' ), { tone: 'danger' } ); }
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( t( 'groupActionFailed', 'Something went wrong.' ), { tone: 'danger' } );
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
				else { bnToast( t( 'groupActionFailed', 'Something went wrong.' ), { tone: 'danger' } ); }
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( t( 'groupActionFailed', 'Something went wrong.' ), { tone: 'danger' } );
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
					const res = await restFetch( '/members?per_page=8&search=' + encodeURIComponent( term ), {
						base: ctx.bnRest,
						nonce: ctx.nonce,
						toastOnError: false,
					} );
					if ( ! res.ok ) { return; }
					const data = res.data;
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
					bnToast( t( 'groupActionFailed', 'Something went wrong.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( t( 'groupActionFailed', 'Something went wrong.' ), { tone: 'danger' } );
			}
		},
		*leaveGroup() {
			const ctx = getContext();
			if ( ctx.groupBusy ) { return; }
			const ok = yield bnConfirm( {
				title: t( 'groupLeaveConfirm', 'Leave this group?' ),
				body: t( 'groupLeaveBody', 'You will stop receiving messages from this conversation.' ),
				confirmLabel: t( 'groupLeaveOk', 'Leave' ),
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			ctx.groupBusy = true;
			try {
				yield restFetch( '/groups/' + ctx.activeConvId + '/leave', {
					base: ctx.mvsProRest,
					nonce: ctx.nonce,
					method: 'POST',
					toastOnError: false,
				} );
				window.location.href = ctx.messagesUrl || '/messages/';
			} catch ( _e ) {
				ctx.groupBusy = false;
				bnToast( t( 'groupActionFailed', 'Something went wrong.' ), { tone: 'danger' } );
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

			if ( '' === term ) {
				list.replaceChildren( composeMessage( t( 'composeHint', 'Search for a person to message.' ) ) );
				return;
			}

			composeSearchTimer = setTimeout( async () => {
				try {
					const res = await restFetch( '/members?per_page=8&search=' + encodeURIComponent( term ), {
						base: ctx.bnRest,
						nonce: ctx.nonce,
						toastOnError: false,
					} );
					if ( ! res.ok ) {
						return;
					}
					const data    = res.data;
					const members = ( data && data.items ? data.items : [] ).filter(
						( m ) => parseInt( m.user_id, 10 ) !== parseInt( ctx.userId, 10 )
					);
					if ( ! members.length ) {
						list.replaceChildren( composeMessage( t( 'composeNone', 'No people found.' ) ) );
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
					list.replaceChildren( composeMessage( t( 'composeHint', 'Search for a person to message.' ) ) );
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
			yield restFetch( '/conversations/' + convId + '/read', {
				base: ctx.mvsRest,
				nonce: ctx.nonce,
				method: 'POST',
				toastOnError: false,
			} );
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

			// The thread element this poll belongs to. When client-side nav swaps
			// it out (back to the rail, or a different conversation) the ref goes
			// disconnected, which is our cue to stop polling a closed thread.
			const { ref: threadEl } = getElement();

			let since = new Date().toISOString();
			const poll = async () => {
				try {
					const res = await restFetch(
						'/messages/poll?since=' + encodeURIComponent( since ) + '&conversation_id=' + convId,
						{ base: ctx.mvsRest, nonce: ctx.nonce, toastOnError: false }
					);
					if ( res.ok ) {
						const data = res.data || {};
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

			// Replace any poll left running for a previously open conversation so
			// switching threads never stacks intervals.
			stopThreadPoll();
			threadPollFn = poll;
			threadPollTimer = window.setInterval( () => {
				// Thread closed/swapped out — stop hitting the server for it.
				if ( threadEl && ! threadEl.isConnected ) {
					stopThreadPoll();
					return;
				}
				// Backgrounded tab: skip the request. A hidden DM thread does not
				// need 12 polls/min, and that idle traffic is real at scale. We
				// catch up immediately on refocus via the listener below.
				if ( document.hidden ) {
					return;
				}
				poll();
			}, 5000 );

			// Bind once: on refocus, poll straight away so the thread is current
			// without waiting for the next 5s tick.
			if ( ! threadPollVisibilityBound ) {
				document.addEventListener( 'visibilitychange', () => {
					if ( ! document.hidden && threadPollFn ) {
						threadPollFn();
					}
				} );
				threadPollVisibilityBound = true;
			}
		},
	},
} );

// The server merges the injected dictionary into this namespace's state; read
// it once here so every t()/fmt() lookup above resolves against translated copy.
I18N = ( messagesStore.state && messagesStore.state.i18n ) || {};

// Local alias so action methods can call sibling actions (sendMessage, etc.).
const { actions } = messagesStore;
