/**
 * BuddyNext post composer store.
 *
 * Split out of feed/store.js: registers buddynext/post-composer - compose,
 * schedule, polls, media upload, localStorage drafts, link-preview detection and
 * the share-to-feed / ?compose= mention prefill. Loaded as a relative side-effect
 * import from feed/store.js, so it registers exactly where @buddynext/feed is
 * enqueued; the file moved, the loading did not.
 *
 * The site-timezone + field helpers it shares with the post-card and feed stores
 * live in ./shared.js (one module instance); the composer-only helpers and the
 * _mediaState / _linkPreviewState singletons stay here.
 *
 * @package BuddyNext
 */

import { store, getContext, getElement, withScope } from '@wordpress/interactivity';
import { bnToast } from '@buddynext/shell-dialog';
import { restFetch } from '@buddynext/rest-client';
import { onNavReady } from '@buddynext/nav-init';
import { mediaPreview, mediaKind, uploadMedia, deleteMedia, validateMedia } from '@buddynext/upload-core';
import { t, fmt, prependFeedCard, clearField, autoResizeTextarea, toUtcSqlDatetime } from '@buddynext/feed-shared';
import { bnClampPopoverToViewport } from '@buddynext/popover';

/* ── Post composer ───────────────────────────────────────────────────────── */

// Module-level media state — shared between native event handler and store actions.
// WP Interactivity API getContext() doesn't work in native addEventListener callbacks.
const _mediaState = { ids: [], previews: [] };

// A human byte size for the document size-limit message. Integer units are
// enough here (the ceiling is always a round MB), so no decimals to localise.
function formatBytes( bytes ) {
	const n = Number( bytes ) || 0;
	if ( n >= 1073741824 ) { return Math.round( n / 1073741824 ) + ' GB'; }
	if ( n >= 1048576 ) { return Math.round( n / 1048576 ) + ' MB'; }
	if ( n >= 1024 ) { return Math.round( n / 1024 ) + ' KB'; }
	return n + ' B';
}

/**
 * Map a WPMediaVerse document-upload refusal to a member-facing line.
 *
 * The upload endpoint can answer with ~23 distinct WP_Error codes, and naming
 * all of them here would be a maintenance treadmill against a plugin we do not
 * version. So this names only the ones where MediaVerse's own wording gives the
 * member the WRONG IDEA, and lets the rest through — MV's message is specific
 * and already translated, which is better than a generic line of ours.
 *
 * Three groups earn a translation:
 *
 *   permission  - "try again" is the one instruction guaranteed not to work.
 *                 A refusal stays refused however many times you retry, and
 *                 telling someone otherwise wastes their time twice.
 *   server-fault - a failed insert or a failed store is OURS. Phrasing it like
 *                 the member did something wrong sends them hunting for a
 *                 problem with their file that does not exist.
 *   type        - "not allowed" (the owner disallowed it) and "unsupported"
 *                 (we cannot read it) are different facts with different
 *                 remedies, and were previously collapsed into one.
 *
 * The final fallback deliberately no longer promises a retry: new codes keep
 * arriving from a plugin we do not control, and "Please try again" is the wrong
 * default for a refusal.
 *
 * @param {string} code          WPMediaVerse error code.
 * @param {string} serverMessage MediaVerse's own message, already translated.
 * @return {string}
 */
function documentErrorMessage( code, serverMessage ) {
	switch ( code ) {
		case 'mvs_documents_unavailable':
			return t( 'documentsUnavailable', 'Documents are not available on your account.' );
		case 'mvs_document_too_large':
			return t( 'documentTooLargeServer', 'That document is over the size limit.' );
		case 'mvs_document_type_not_allowed':
			return t( 'documentTypeNotAllowed', 'That file type is not allowed here.' );
		case 'mvs_document_type_unsupported':
		case 'mvs_document_type_mismatch':
			return t( 'documentTypeUnsupported', 'That file type cannot be read, so it cannot be attached.' );
		case 'mvs_document_scan_failed':
			return t( 'documentScanFailed', 'That file could not be accepted.' );
		case 'mvs_documents_read_only':
			return t( 'documentsReadOnly', 'Document uploads are paused on this site right now. You can still view existing documents.' );

		// Permission. Retrying never helps, so the message must not suggest it.
		case 'mvs_document_forbidden':
		case 'mvs_document_unauthorized':
		case 'mvs_document_upload_forbidden':
		case 'mvs_document_drive_forbidden':
		case 'mvs_document_folder_forbidden':
			return t( 'documentNotPermitted', 'You do not have permission to add documents here.' );

		// Ours, not theirs.
		case 'mvs_document_insert_failed':
		case 'mvs_document_store_failed':
		case 'mvs_document_upload_failed':
			return t( 'documentServerFault', 'Something went wrong on our side and the document was not saved. Nothing you did caused this.' );
	}

	return serverMessage || t( 'documentUploadFailed', 'That document could not be uploaded.' );
}

// Inline SVG for a media KIND with no visual frame — a video whose poster could
// not be captured, or an audio file. Matches the icon set (play / music) rather
// than showing a broken <img>, mirroring the WPMediaVerse upload dialogs. viewBox
// 0 0 24 24, currentColor stroke so it inherits the tile's colour.
function bnMediaKindIconSvg( kind ) {
	const svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
	svg.setAttribute( 'viewBox', '0 0 24 24' );
	svg.setAttribute( 'width', '28' );
	svg.setAttribute( 'height', '28' );
	svg.setAttribute( 'aria-hidden', 'true' );
	const paths = 'audio' === kind
		? [ [ 'path', { d: 'M9 18V5l12-2v13', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' } ],
			[ 'circle', { cx: '6', cy: '18', r: '3', fill: 'none', stroke: 'currentColor', 'stroke-width': '2' } ],
			[ 'circle', { cx: '18', cy: '16', r: '3', fill: 'none', stroke: 'currentColor', 'stroke-width': '2' } ] ]
		: [ [ 'path', { d: 'M6 4l15 8-15 8V4z', fill: 'currentColor', stroke: 'none' } ] ];
	paths.forEach( ( [ tag, attrs ] ) => {
		const el = document.createElementNS( 'http://www.w3.org/2000/svg', tag );
		Object.keys( attrs ).forEach( ( k ) => el.setAttribute( k, attrs[ k ] ) );
		svg.appendChild( el );
	} );
	return svg;
}

/* ── Link preview detection ──────────────────────────────────────────────
 * As the user types, the first http(s) URL in the composer is detected and
 * its Open Graph card fetched (debounced) from buddynext/v1/link-preview.
 * The result is stored on the composer context so the preview card renders
 * and link_url/link_meta ride along in the submit payload. A dismissed URL
 * is remembered so it isn't re-fetched on the next keystroke.
 */
const _linkPreviewState = { url: '', dismissed: '', timer: null, pending: null, resolvePending: null };
const LINK_PREVIEW_DEBOUNCE_MS = 700;

/**
 * How long submit() will wait for an in-flight preview before posting without it.
 *
 * A member who pastes a link and posts immediately would otherwise beat the
 * debounced fetch and get a bare card that only fills in on the next page load.
 * Waiting briefly closes that gap without ever making the post depend on the
 * remote host: when the wait expires the post goes out regardless, and the
 * server-side queue resolves the preview afterwards.
 */
const LINK_PREVIEW_SUBMIT_WAIT_MS = 2500;

/**
 * Promise that settles when the in-flight preview fetch finishes, or null when
 * none is running. Re-armed by maybeDetectLink() on every new URL.
 *
 * @return {Promise|null} Pending preview promise.
 */
function pendingLinkPreview() {
	return _linkPreviewState.pending;
}

function armPendingPreview() {
	if ( _linkPreviewState.resolvePending ) {
		_linkPreviewState.resolvePending();
	}
	_linkPreviewState.pending = new Promise( ( resolve ) => {
		_linkPreviewState.resolvePending = resolve;
	} );
}

function settlePendingPreview() {
	if ( _linkPreviewState.resolvePending ) {
		_linkPreviewState.resolvePending();
		_linkPreviewState.resolvePending = null;
	}
	_linkPreviewState.pending = null;
}
const URL_RE = /(https?:\/\/[^\s<>"']+)/i;

function detectFirstUrl( text ) {
	const m = URL_RE.exec( String( text || '' ) );
	if ( ! m ) { return ''; }
	// Trim trailing punctuation that is unlikely to be part of the URL.
	return m[ 1 ].replace( /[.,;:!?)\]]+$/, '' );
}

function maybeDetectLink( ctx ) {
	// Respect the site-owner toggle exposed on the composer context.
	if ( ! ctx.linkPreviewEnabled ) { return; }

	const url = detectFirstUrl( ctx.content );

	// No URL anymore → clear any shown card (unless it was manually dismissed).
	if ( ! url ) {
		if ( ctx.linkUrl ) {
			ctx.linkUrl = ''; ctx.linkTitle = ''; ctx.linkDesc = ''; ctx.linkThumb = ''; ctx.linkMeta = null;
		}
		_linkPreviewState.url = '';
		settlePendingPreview();
		return;
	}

	// Same URL already previewed or explicitly dismissed → nothing to do.
	if ( url === ctx.linkUrl || url === _linkPreviewState.dismissed || url === _linkPreviewState.url ) {
		return;
	}

	_linkPreviewState.url = url;
	// Arm the gate BEFORE the debounce, not inside it: a member who pastes and
	// posts within the debounce window must still be waited for, and at that
	// point the fetch has not started yet.
	armPendingPreview();
	clearTimeout( _linkPreviewState.timer );
	// withScope() restores the Interactivity scope for a callback that runs from
	// a timer, outside the call stack — required for `ctx.*` writes to be seen
	// reactively.
	//
	// NOTE: this alone does NOT make the composer preview render. Confirmed
	// still broken with it in place: /link-preview returns 200 with full
	// title/description/thumbnail, and the card stays display:none with an empty
	// title. Root cause not yet identified — do not assume it is the scope.
	_linkPreviewState.timer = setTimeout( withScope( async () => {
		// Bail if the URL changed again during the debounce window.
		if ( detectFirstUrl( ctx.content ) !== url ) { settlePendingPreview(); return; }
		try {
			const res  = await restFetch(
				'/link-preview?url=' + encodeURIComponent( url ),
				{ nonce: ctx.restNonce, toastOnError: false }
			);
			if ( ! res.ok ) { return; }
			const data = res.data;
			// Only render a card if the URL is still the active one.
			if ( detectFirstUrl( ctx.content ) !== url ) { return; }
			ctx.linkUrl   = url;
			ctx.linkTitle = data.title || '';
			ctx.linkDesc  = data.description || '';
			ctx.linkThumb = data.thumbnail || '';
			ctx.linkMeta  = {
				title:       data.title || '',
				description: data.description || '',
				thumbnail:   data.thumbnail || '',
			};
		} catch ( _e ) {
			// Network/preview failure degrades silently — the URL still posts as text.
		} finally {
			// Release submit()'s gate on every outcome, including the early
			// returns above. A gate that can be left armed would hold the member
			// on "Posting…" for the full wait on a failure — trading one stall
			// for a smaller one.
			settlePendingPreview();
		}
	} ), LINK_PREVIEW_DEBOUNCE_MS );
}

// Resolved at call time (not module-eval time) so the injected i18n dictionary —
// read from the buddynext/feed store further down — is already populated.
function privacyLabels() {
	return {
		public:        t( 'privacyPublic', 'Public' ),
		followers:     t( 'privacyFollowers', 'Followers' ),
		connections:   t( 'privacyConnections', 'Connections' ),
		private:       t( 'privacyPrivate', 'Only me' ),
		space_members: t( 'privacySpaceMembers', 'Space members' ),
	};
}

/* ── Composer drafts (localStorage-backed) ───────────────────────────────
 * Stored as JSON at `bn_composer_draft_{user_id}`. We debounce writes by
 * 1.5s after the last keystroke to avoid hammering localStorage on every
 * character. A successful publish clears the draft. The server-sync seam
 * is intentionally minimal: drafts only round-trip to /me/drafts when
 * localStorage carries the `bn_composer_cloud_sync = 1` flag — defaulted
 * off so the local-only path is the fast path.
 */

const DRAFT_DEBOUNCE_MS = 1500;
const _draftTimers      = new Map();
let   _draftStatusTimer = null;

function draftKey( userId ) {
	return 'bn_composer_draft_' + ( parseInt( userId, 10 ) || 0 );
}

function readDraft( userId ) {
	try {
		const raw = window.localStorage.getItem( draftKey( userId ) );
		if ( ! raw ) {
			return null;
		}
		return JSON.parse( raw );
	} catch ( _e ) {
		return null;
	}
}

function writeDraft( userId, payload ) {
	try {
		window.localStorage.setItem( draftKey( userId ), JSON.stringify( payload ) );
		return true;
	} catch ( _e ) {
		return false;
	}
}

function clearDraft( userId ) {
	try {
		window.localStorage.removeItem( draftKey( userId ) );
	} catch ( _e ) {}
}

function setDraftStatus( ctx, status, transient ) {
	if ( ! ctx ) {
		return;
	}
	ctx.draftStatus = status;
	if ( _draftStatusTimer ) {
		clearTimeout( _draftStatusTimer );
		_draftStatusTimer = null;
	}
	if ( transient ) {
		_draftStatusTimer = setTimeout( () => {
			ctx.draftStatus = '';
		}, 2000 );
	}
}

/**
 * Clear every composer sub-form — the context state AND the DOM inputs.
 *
 * The composer has three sub-forms (schedule, poll, announcement) and their
 * reset coverage had drifted apart. submit() cleared only the schedule; cancel()
 * cleared none of them beyond flipping composerType. That left two ways for a
 * post to inherit the previous one's settings:
 *
 *   - After posting a poll the panel stayed open with the old options and end
 *     date, so the next post silently reused them.
 *   - After cancelling, composerType went back to 'text' — which merely HIDES
 *     the poll and announcement panels — while the typed values sat in the DOM
 *     waiting to reappear the moment the panel was reopened.
 *
 * Clearing state is not enough on its own: the poll options and the three
 * date inputs are plain DOM, not bound to context (submit() reads the options
 * with querySelectorAll), so they have to be emptied explicitly.
 *
 * Everything lives here rather than inline at each call site, because the
 * inline version is exactly how this drifted: the schedule reset was added when
 * someone hit the bug with schedules, and poll and announcement kept it.
 *
 * @param {Object} ctx Interactivity context for the composer.
 * @return {void}
 */
function resetComposerSubForms( ctx ) {
	if ( ! ctx ) {
		return;
	}

	// Back to the default mode. This is what closes the poll and announcement
	// panels, both of which are shown by composerType.
	ctx.composerType = 'text';

	ctx.scheduleOpen          = false;
	ctx.scheduledAt           = '';
	ctx.announcementExpiresAt = '';

	// Clear any attached document so the next post starts empty.
	ctx.documentId        = 0;
	ctx.documentName      = '';
	ctx.documentUploading = false;

	document.querySelectorAll(
		'#bn-composer-schedule-at, #bn-composer-announce-expiry, #bn-composer-poll-end, .bn-composer__poll-option'
	).forEach( function ( el ) {
		el.value = '';
	} );
}

function scheduleDraftSave( ctx ) {
	const userId = parseInt( ctx.userId, 10 );
	if ( userId <= 0 ) {
		return;
	}
	const key = String( userId );
	if ( _draftTimers.has( key ) ) {
		clearTimeout( _draftTimers.get( key ) );
	}
	setDraftStatus( ctx, t( 'savingDraft', 'Saving draft…' ), false );
	const draftTimer = setTimeout( () => {
		/*
		 * The draft deliberately does NOT carry composerType.
		 *
		 * A draft exists to stop a member losing what they TYPED. The panel they
		 * happened to have open is not data: poll options and the announcement and
		 * schedule dates are plain DOM read at submit time (see
		 * resetComposerSubForms), so none of them are in this payload. Restoring
		 * composerType therefore restored an EMPTY poll -- a panel with none of the
		 * content that would have justified bringing it back.
		 *
		 * Worse, it could not be turned off. Only onInput() saves a draft, so
		 * togglePoll / toggleAnnouncement / openLink changed the live state without
		 * rewriting storage; the draft stayed pinned at whatever panel was open the
		 * last time the member typed, and restoreDraftsOnLoad re-applied it on every
		 * page load, on every surface that renders a composer -- the main feed and
		 * inside every space. One customer downgraded to escape it (Zoho #41288).
		 *
		 * Adding a save call to each toggle would have fixed the symptom and left
		 * the trap: the next panel someone adds forgets again, silently. Not storing
		 * it removes the whole class -- poll, announcement, link and photo at once --
		 * and existing poisoned drafts heal themselves on the next load, because the
		 * restore below no longer reads the field.
		 */
		const payload = {
			content:      ctx.content || '',
			privacy:      ctx.privacy || 'public',
			spaceId:      ctx.spaceId || 0,
			savedAt:      Date.now(),
		};
		if ( ( payload.content || '' ).trim() === '' ) {
			// Empty content -> drop any stale draft instead of saving '' forever.
			clearDraft( userId );
			ctx.hasDraft = false;
			setDraftStatus( ctx, '', false );
		} else {
			writeDraft( userId, payload );
			ctx.hasDraft = true;
			setDraftStatus( ctx, t( 'draftSaved', 'Draft saved' ), true );
		}
		_draftTimers.delete( key );

		// Cloud-sync seam (off by default). When the user opts in via
		// localStorage.bn_composer_cloud_sync = '1', the draft also POSTs
		// to /me/drafts so it survives across devices. The endpoint
		// existence and shape are documented in CommentDraftController;
		// the local path keeps working even if the endpoint isn't shipped.
		try {
			if ( window.localStorage.getItem( 'bn_composer_cloud_sync' ) === '1' ) {
				restFetch( '/me/drafts', {
					method:  'POST',
					nonce:   ctx.restNonce,
					toastOnError: false,
					body:    { payload },
				} ).catch( () => {} );
			}
		} catch ( _e ) {}
	}, DRAFT_DEBOUNCE_MS );
	_draftTimers.set( key, draftTimer );
}

// "Share to feed" from a member profile lands on the feed with ?mention=<handle>.
// Captured once at module load (before any draft/hydration callback) so the
// prefill is order-independent and survives stripping the param from the URL.
let pendingMentionHandle = '';
try {
	const bnMentionParam = new URLSearchParams( window.location.search ).get( 'mention' );
	if ( bnMentionParam ) {
		pendingMentionHandle = bnMentionParam.replace( /^@+/, '' ).trim();
	}
} catch ( _e ) {}

// Restore drafts into composers on initial DOM load. Composers stamp the
// user_id into their data-wp-context so we don't have to query a separate
// global. Each composer is keyed by the user_id of the current viewer so
// switching accounts on the same browser keeps drafts isolated.
function restoreDraftsOnLoad() {
	// ?compose= deep-link target. Written by the mobile "Create post" nav item,
	// the hashtag "post with this tag" action, and the share/extras entry points,
	// but previously had no consumer (silent no-op). "1" = just open the composer;
	// any other value pre-fills that text (e.g. "#tag ").
	let composeParam = null;
	try { composeParam = new URLSearchParams( window.location.search ).get( 'compose' ); }
	catch ( _e ) { composeParam = null; }

	const composers = document.querySelectorAll( '[data-wp-interactive="buddynext/post-composer"]:not([data-bn-draft-wired])' );
	composers.forEach( ( el ) => {
		el.dataset.bnDraftWired = '1';
		let ctxData;
		try { ctxData = JSON.parse( el.getAttribute( 'data-wp-context' ) || '{}' ); }
		catch ( _e ) { return; }
		const userId = parseInt( ctxData.userId, 10 );
		if ( userId <= 0 ) {
			return;
		}

		const textarea = el.querySelector( '.bn-composer__prompt' );

		// Share-to-feed: open the main feed composer pre-filled with the
		// @mention and focused, so the member just adds their words and posts.
		// Takes precedence over any stored draft (the click is an explicit
		// fresh-post intent) and only targets the general feed composer, never
		// a space composer.
		if ( pendingMentionHandle && ( ctxData.spaceId === null || ctxData.spaceId === undefined ) ) {
			const prefill = '@' + pendingMentionHandle + ' ';
			if ( textarea ) {
				textarea.value = prefill;
			}
			ctxData.content = prefill;
			try { el.setAttribute( 'data-wp-context', JSON.stringify( ctxData ) ); }
			catch ( _e ) {}
			if ( textarea ) {
				window.requestAnimationFrame( () => {
					textarea.focus();
					try { textarea.setSelectionRange( prefill.length, prefill.length ); } catch ( _e ) {}
					textarea.scrollIntoView( { block: 'center' } );
				} );
			}
			return;
		}

		// ?compose= deep-link: open the general feed composer ready to type.
		// "?compose=1" focuses it; "?compose=<text>" pre-fills that text (the
		// hashtag "post with this tag" flow). General feed composer only, never
		// a space composer. Focus runs in rAF so it lands after the value is set.
		if ( composeParam !== null && ( ctxData.spaceId === null || ctxData.spaceId === undefined ) ) {
			const composePrefill = '1' === composeParam ? '' : composeParam;
			if ( composePrefill && textarea ) {
				textarea.value  = composePrefill;
				ctxData.content = composePrefill;
				try { el.setAttribute( 'data-wp-context', JSON.stringify( ctxData ) ); }
				catch ( _e ) {}
			}
			if ( textarea ) {
				window.requestAnimationFrame( () => {
					textarea.focus();
					const composeEnd = textarea.value.length;
					try { textarea.setSelectionRange( composeEnd, composeEnd ); } catch ( _e ) {}
					textarea.scrollIntoView( { block: 'center' } );
				} );
			}
			// A text prefill is an explicit "start this post" intent — skip the
			// draft restore so it isn't overwritten. A bare "?compose=1" falls
			// through so any saved draft is still restored under the focus.
			if ( composePrefill ) {
				return;
			}
		}

		const draft = readDraft( userId );
		if ( ! draft || ! draft.content ) {
			return;
		}
		// Pre-fill the textarea so the user sees their draft immediately,
		// even before WP Interactivity hydrates the store.
		if ( textarea ) {
			textarea.value = draft.content;
		}
		// Patch the data-wp-context JSON so the hydrated state matches.
		ctxData.content      = draft.content;
		// composerType is deliberately NOT restored -- see scheduleDraftSave().
		// A draft from before this change may still carry one; ignoring it here is
		// what heals those drafts without a migration.
		ctxData.privacy      = draft.privacy || ctxData.privacy;
		ctxData.hasDraft     = true;
		ctxData.draftStatus  = t( 'draftRestored', 'Draft restored' );
		try { el.setAttribute( 'data-wp-context', JSON.stringify( ctxData ) ); }
		catch ( _e ) {}
	} );

	// Drop ?mention= from the URL so a refresh / shared link doesn't re-trigger
	// the prefill. The handle is already captured in pendingMentionHandle.
	if ( pendingMentionHandle ) {
		try {
			const url = new URL( window.location.href );
			url.searchParams.delete( 'mention' );
			window.history.replaceState( {}, '', url.toString() );
		} catch ( _e ) {}
	}

	// Drop ?compose= for the same reason — a refresh or shared link shouldn't
	// re-open/re-prefill the composer once it's been consumed.
	if ( composeParam !== null ) {
		try {
			const url = new URL( window.location.href );
			url.searchParams.delete( 'compose' );
			window.history.replaceState( {}, '', url.toString() );
		} catch ( _e ) {}
	}
}

onNavReady( restoreDraftsOnLoad );

store( 'buddynext/post-composer', {
	state: {
		get open() {
			try { return !! getContext().composerOpen; } catch ( _e ) { return false; }
		},
		get submitting() {
			try { return !! getContext().submitting; } catch ( _e ) { return false; }
		},
		get isPoll() {
			try { return getContext().composerType === 'poll'; } catch ( _e ) { return false; }
		},
		get isNotPoll() {
			try { return getContext().composerType !== 'poll'; } catch ( _e ) { return true; }
		},
		get isScheduled() {
			try { return !! getContext().scheduleOpen; } catch ( _e ) { return false; }
		},
		get isNotScheduled() {
			try { return ! getContext().scheduleOpen; } catch ( _e ) { return true; }
		},
		get isAnnouncement() {
			try { return getContext().composerType === 'announcement'; } catch ( _e ) { return false; }
		},
		get isNotAnnouncement() {
			try { return getContext().composerType !== 'announcement'; } catch ( _e ) { return true; }
		},
		get privacyOpen() {
			try { return !! getContext().privacyOpen; } catch ( _e ) { return false; }
		},
		get hasMedia() {
			try { return ( getContext().mediaIds || [] ).length > 0; } catch ( _e ) { return false; }
		},
		get mediaPreviews() {
			try { return getContext().mediaPreviews || []; } catch ( _e ) { return []; }
		},
		get mediaUploading() {
			try { return !! getContext().mediaUploading; } catch ( _e ) { return false; }
		},
		get hasDocument() {
			try { return ( getContext().documentId || 0 ) > 0; } catch ( _e ) { return false; }
		},
		get documentName() {
			try { return getContext().documentName || ''; } catch ( _e ) { return ''; }
		},
		get documentUploading() {
			try { return !! getContext().documentUploading; } catch ( _e ) { return false; }
		},
		get hasLinkPreview() {
			try { return !! ( getContext().linkUrl || '' ); } catch ( _e ) { return false; }
		},
		get hasLinkThumb() {
			try { return !! ( getContext().linkThumb || '' ); } catch ( _e ) { return false; }
		},
		get linkDomain() {
			try {
				const u = getContext().linkUrl || '';
				if ( ! u ) { return ''; }
				return new URL( u ).hostname.replace( /^www\./, '' );
			} catch ( _e ) { return ''; }
		},
		get errorMessage() {
			try { return getContext().errorMessage || ''; } catch ( _e ) { return ''; }
		},
		get retryHidden() {
			// Hide the Retry button when there's no error, OR when the error is
			// non-retryable (e.g. a 403 — retrying can never succeed).
			try {
				const ctx = getContext();
				return ! ( ctx.errorMessage || '' ) || false === ctx.errorRetryable;
			} catch ( _e ) { return true; }
		},
		get hasNoError() {
			try { return ! ( getContext().errorMessage || '' ); } catch ( _e ) { return true; }
		},
		get resendVerifyHidden() {
			// Show the "Resend verification email" affordance only when the block
			// was specifically an unverified-email 403.
			try { return ! getContext().errorEmailUnverified; } catch ( _e ) { return true; }
		},
		get appealHidden() {
			// Show the appeal link only when the server said the block was a
			// suspension, which it signals by shipping the URL in the error data.
			try { return ! getContext().errorAppealUrl; } catch ( _e ) { return true; }
		},
		get appealUrl() {
			try { return getContext().errorAppealUrl || ''; } catch ( _e ) { return ''; }
		},
		get hasNoVoiceError() {
			try { return ! ( getContext().voiceError || '' ); } catch ( _e ) { return true; }
		},
		get voiceError() {
			try { return getContext().voiceError || ''; } catch ( _e ) { return ''; }
		},
		get privacyLabel() {
			try {
				const ctx = getContext();
				return privacyLabels()[ ctx.privacy ] || t( 'privacyPublic', 'Public' );
			} catch ( _e ) { return t( 'privacyPublic', 'Public' ); }
		},
		get isPrivacyPublic() {
			try { return getContext().privacy === 'public'; } catch ( _e ) { return false; }
		},
		get isPrivacyFollowers() {
			try { return getContext().privacy === 'followers'; } catch ( _e ) { return false; }
		},
		get isPrivacyConnections() {
			try { return getContext().privacy === 'connections'; } catch ( _e ) { return false; }
		},
		get isPrivacyPrivate() {
			try { return getContext().privacy === 'private'; } catch ( _e ) { return false; }
		},
		get submitLabel() {
			try { return getContext().submitting ? t( 'posting', 'Posting…' ) : t( 'post', 'Post' ); } catch ( _e ) { return t( 'post', 'Post' ); }
		},
		get draftStatusHidden() {
			try { return ! ( getContext().draftStatus || '' ); } catch ( _e ) { return true; }
		},
		get draftDiscardHidden() {
			try { return ! getContext().hasDraft; } catch ( _e ) { return true; }
		},
		get voiceSubmitLabel() {
			try { return getContext().submitting ? t( 'scheduling', 'Scheduling…' ) : t( 'scheduleRoom', 'Schedule room' ); } catch ( _e ) { return t( 'scheduleRoom', 'Schedule room' ); }
		},
	},
	actions: {
		open() {
			getContext().composerOpen = true;
		},
		openOnEnter( event ) {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				getContext().composerOpen = true;
			}
		},
		openPhoto() {
			const ctx        = getContext();
			ctx.composerOpen = true;
			ctx.composerType = 'photo';
		},

		/**
		 * Trigger the hidden file input from a dedicated "add media" button.
		 * Separated from openPhoto() to avoid file picker firing on page load.
		 */
		pickMedia() {
			const composerEl = document.querySelector( '[data-wp-interactive="buddynext/post-composer"]' );
			const fileInput  = document.querySelector( '.bn-composer__file-input' );
			if ( ! fileInput || ! composerEl ) {
				return;
			}

			// Read REST config from the composer element's data-wp-context.
			const ctxData = JSON.parse( composerEl.getAttribute( 'data-wp-context' ) || '{}' );
			const nonce   = ctxData.restNonce || '';

			// Defensive guard: uploads route through WPMediaVerse. The Image button
			// is only rendered when the engine is active (server-side check in
			// composer.php), but if pickMedia is reached while disabled, degrade
			// gracefully instead of POSTing to a non-existent mvs/v1 route.
			if ( false === ctxData.mediaEnabled ) {
				bnToast( t( 'imageUploadsUnavailable', 'Image uploads are not available on this site.' ), { tone: 'info' } );
				return;
			}

			// Wire the change handler natively — WP Interactivity API directives
			// don't reliably fire on hidden inputs triggered via .click().
			if ( ! fileInput._bnWired ) {
				fileInput._bnWired = true;
				fileInput.addEventListener( 'change', async function () {
					const files     = fileInput.files;
					const MAX_MEDIA = 4;

					if ( ! files || ! files.length ) {
						return;
					}

					const remaining = MAX_MEDIA - _mediaState.ids.length;
					if ( remaining <= 0 ) {
						bnToast( fmt( t( 'maxImagesPerPost', 'You can attach at most %d images per post.' ), MAX_MEDIA ), { tone: 'info' } );
						return;
					}
					if ( files.length > remaining ) {
						bnToast(
							remaining === 1
								? t( 'oneMoreImage', 'Only 1 more image can be added.' )
								: fmt( t( 'moreImages', 'Only %d more images can be added.' ), remaining ),
							{ tone: 'info' }
						);
					}

					// Show preview area.
					const previewArea = document.querySelector( '.bn-composer__media-preview' );
					if ( previewArea ) {
						previewArea.hidden = false;
					}

					const uploadCount = Math.min( files.length, remaining );
					for ( let i = 0; i < uploadCount; i++ ) {
						const file = files[ i ];

						// Validate type + size client-side BEFORE the upload — the same
						// guard the media-gallery and album pickers already run. Without
						// it the composer streamed oversized/wrong-type files all the way
						// to the server before rejection (wasted bandwidth, no feedback).
						const invalid = validateMedia( file, {
							badTypeMsg:  t( 'mediaBadType', 'Only images, video and audio can be attached.' ),
							tooLargeMsg: t( 'mediaTooLarge', 'That file is too large to upload.' ),
						} );
						if ( invalid ) {
							bnToast( invalid, { tone: 'danger' } );
							continue;
						}

						// Shared upload core: one BuddyNext-owned, owner-gated path
						// (buddynext/v1/me/media) for every surface, plus a fast small
						// client preview so a large file never blanks the tile. The
						// engine REST is never called directly from the client.
						// mediaPreview() returns an image thumbnail OR a captured video
						// frame; '' for audio (and for a video whose frame can't be
						// grabbed), where we paint a kind icon instead of a broken <img>.
						const kind     = mediaKind( file );
						const thumbUrl = await mediaPreview( file );

						// Show the tile IMMEDIATELY with a spinner overlay, using the
						// local thumbnail — the member sees the image the instant they
						// pick it, with a clear in-flight state, instead of a blank gap
						// until the upload round-trip returns (the old code appended the
						// tile only AFTER the await). The tile is finalized (spinner
						// cleared, remove wired) on success, or removed on failure. Built
						// via DOM (not innerHTML) so a URL/id can never break out of an
						// attribute.
						let thumb       = null;
						let thumbRemove = null;
						let thumbImg    = null;
						if ( previewArea ) {
							thumb = document.createElement( 'div' );
							thumb.className = 'bn-composer__media-thumb is-uploading bn-composer__media-thumb--' + kind;
							// A visual poster (image thumb or captured video frame) renders
							// as an <img>; audio and un-grabbable video render a kind icon so
							// the tile is never a broken image. A video with a frame also
							// carries the --video class for the CSS play badge overlay.
							if ( thumbUrl ) {
								thumbImg = document.createElement( 'img' );
								thumbImg.src = thumbUrl;
								thumbImg.alt = '';
								thumbImg.width = 80;
								thumbImg.height = 80;
								thumbImg.loading = 'lazy';
								thumbImg.decoding = 'async';
								thumb.appendChild( thumbImg );
							} else {
								const icon = document.createElement( 'span' );
								icon.className = 'bn-composer__media-kind';
								icon.appendChild( bnMediaKindIconSvg( kind ) );
								thumb.appendChild( icon );
							}
							const spinner = document.createElement( 'span' );
							spinner.className = 'bn-composer__media-spinner';
							spinner.setAttribute( 'aria-hidden', 'true' );
							thumbRemove = document.createElement( 'button' );
							thumbRemove.className = 'bn-composer__media-remove';
							thumbRemove.type = 'button';
							thumbRemove.textContent = '×';
							// No removing until the upload lands and has a real media id.
							thumbRemove.hidden = true;
							// The poster/icon is already appended above; add the overlays.
							thumb.append( spinner, thumbRemove );
							previewArea.appendChild( thumb );
						}

						const out = await uploadMedia( file, {
							nonce,
							// Stage every composer upload PRIVATE, whatever the privacy
							// picker currently says. The file lands before the member has
							// finished choosing an audience — and may never be posted at
							// all — so anything else publishes it during a decision that
							// has not been made. The bridge derives the real privacy from
							// the post on create and on edit
							// (WPMediaVerseBridge::on_post_privacy_changed), so a public
							// post still opens its own media; this only closes the window
							// in between, and the one an abandoned draft leaves open
							// forever.
							privacy: 'private',
							// Send the captured video frame so the feed uses the real
							// poster, not the engine's default film-strip fallback.
							thumbnail: ( 'video' === kind && thumbUrl ) ? thumbUrl : '',
							// The space this composer is posting into, so the engine files
							// the media on that space's drive. Without it the file lands on
							// the uploader's personal drive, where the post's space_members
							// privacy has nothing to resolve against.
							spaceId: parseInt( ctx.spaceId, 10 ) || 0,
						} );

						if ( out.ok ) {
							const mediaId = out.mediaId;
							const preview = thumbUrl || out.thumb || '';

							_mediaState.ids.push( mediaId );
							_mediaState.previews.push( { id: mediaId, url: preview, name: file.name } );

							// Finalize the tile: clear the uploading state and wire the
							// remove button now that a real media id exists.
							if ( thumb ) {
								thumb.classList.remove( 'is-uploading' );
								thumb.dataset.mediaId = mediaId;
								const spin = thumb.querySelector( '.bn-composer__media-spinner' );
								if ( spin ) { spin.remove(); }
								// If we showed a kind icon (no client frame - audio, or a
								// video whose frame we could not grab) but the engine
								// produced a real poster, upgrade the tile to that image.
								if ( ! thumbImg && out.thumb ) {
									const kindEl = thumb.querySelector( '.bn-composer__media-kind' );
									if ( kindEl ) { kindEl.remove(); }
									const posterImg = document.createElement( 'img' );
									posterImg.src = out.thumb;
									posterImg.alt = '';
									posterImg.width = 80;
									posterImg.height = 80;
									posterImg.loading = 'lazy';
									posterImg.decoding = 'async';
									thumb.insertBefore( posterImg, thumb.firstChild );
								}
								if ( thumbRemove ) {
									thumbRemove.hidden = false;
									thumbRemove.dataset.mediaId = mediaId;
									thumbRemove.addEventListener( 'click', function () {
										_mediaState.ids = _mediaState.ids.filter( ( id ) => id !== mediaId );
										_mediaState.previews = _mediaState.previews.filter( ( p ) => p.id !== mediaId );
										thumb.remove();
										if ( ! _mediaState.ids.length && previewArea ) {
											previewArea.hidden = true;
										}
										// Delete the already-uploaded file so removing the preview
										// doesn't orphan it on the server (best-effort).
										deleteMedia( mediaId, nonce );
									} );
								}
							}
						} else {
							// Upload failed: drop the placeholder tile so a broken image
							// is not left behind, then surface why.
							if ( thumb ) {
								thumb.remove();
								if ( previewArea && ! _mediaState.ids.length ) {
									previewArea.hidden = true;
								}
							}
							// Surface the real status. 404 = media engine inactive.
							// eslint-disable-next-line no-console
							console.error( '[BuddyNext] Media upload failed:', out.status );
							bnToast(
								404 === out.status
									? t( 'mediaEngineInactive', 'Image uploads are unavailable (media engine not active).' )
									: fmt( t( 'uploadFailedError', 'Could not upload %s (error %d).' ), ( file.name || t( 'image', 'image' ) ), out.status || 0 ),
								{ tone: 'danger' }
							);
						}
					}

					fileInput.value = '';
				} );
			}

			fileInput.click();
		},

		removeMedia( event ) {
			const ctx     = getContext();
			const btn     = event.target.closest( '[data-media-id]' );
			const mediaId = btn ? parseInt( btn.dataset.mediaId, 10 ) : 0;
			if ( ! mediaId ) {
				return;
			}
			ctx.mediaIds     = ( ctx.mediaIds || [] ).filter( ( id ) => id !== mediaId );
			ctx.mediaPreviews = ( ctx.mediaPreviews || [] ).filter( ( p ) => p.id !== mediaId );
			_mediaState.ids      = _mediaState.ids.filter( ( id ) => id !== mediaId );
			_mediaState.previews = _mediaState.previews.filter( ( p ) => p.id !== mediaId );
			// Delete the orphaned upload from the server (best-effort, BN endpoint).
			deleteMedia( mediaId, ctx.restNonce );
		},
		/**
		 * Attach a document. Uploads straight to WPMediaVerse's document endpoint
		 * (not BN's /me/media) and keeps only the returned id — the feed card is
		 * resolved per-viewer, so the composer never holds a title it might render
		 * to the wrong audience. One document per post.
		 */
		pickDocument() {
			const composerEl = document.querySelector( '[data-wp-interactive="buddynext/post-composer"]' );
			const docInput   = document.querySelector( '.bn-composer__doc-input' );
			if ( ! docInput || ! composerEl ) {
				return;
			}
			const ctxData = JSON.parse( composerEl.getAttribute( 'data-wp-context' ) || '{}' );
			if ( false === ctxData.docEnabled ) {
				bnToast( t( 'documentsUnavailable', 'Documents are not available on your account.' ), { tone: 'info' } );
				return;
			}
			const nonce   = ctxData.restNonce || '';
			const maxSize = ctxData.docMaxSize || 0;
			// MVS's document endpoint sits under mvs-pro/v1, derived from the
			// composer's own REST root so it works whatever the permalink shape.
			const uploadUrl = String( ctxData.restUrl || '' ).replace( '/buddynext/v1', '/mvs-pro/v1' ) + '/documents/upload';

			if ( ! docInput._bnWired ) {
				docInput._bnWired = true;
				// withScope restores the Interactivity scope so getContext() works
				// inside this native change handler (it does not otherwise).
				docInput.addEventListener( 'change', withScope( async function () {
					const file = docInput.files && docInput.files[ 0 ];
					if ( ! file ) {
						return;
					}
					if ( maxSize > 0 && file.size > maxSize ) {
						bnToast( fmt( t( 'documentTooLarge', 'That document is over the %s limit.' ), formatBytes( maxSize ) ), { tone: 'info' } );
						docInput.value = '';
						return;
					}

					const ctx = getContext();
					ctx.documentUploading = true;
					ctx.documentName      = file.name;
					ctx.documentId        = 0;

					try {
						const fd = new FormData();
						fd.append( 'file', file );
						const res  = await fetch( uploadUrl, {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'X-WP-Nonce': nonce },
							body: fd,
						} );
						const data = await res.json().catch( () => ( {} ) );

						if ( res.ok && data && data.id ) {
							ctx.documentId   = data.id;
							ctx.documentName = data.title || file.name;
						} else {
							ctx.documentId   = 0;
							ctx.documentName = '';
							bnToast( documentErrorMessage( data && data.code, data && data.message ), { tone: 'error' } );
						}
					} catch ( _e ) {
						ctx.documentId   = 0;
						ctx.documentName = '';
						bnToast( t( 'documentUploadFailed', 'That document could not be uploaded. Please try again.' ), { tone: 'error' } );
					} finally {
						ctx.documentUploading = false;
						docInput.value        = '';
					}
				} ) );
			}
			docInput.click();
		},
		removeDocument() {
			const ctx             = getContext();
			ctx.documentId        = 0;
			ctx.documentName      = '';
			ctx.documentUploading = false;
		},
		togglePoll() {
			const ctx        = getContext();
			ctx.composerOpen = true;
			ctx.composerType = ctx.composerType === 'poll' ? 'text' : 'poll';
		},
		toggleSchedule() {
			const ctx          = getContext();
			ctx.composerOpen   = true;
			ctx.scheduleOpen   = ! ctx.scheduleOpen;
			// Clear any chosen datetime when the affordance is closed so a
			// re-opened-then-cancelled composer never silently schedules.
			if ( ! ctx.scheduleOpen ) {
				ctx.scheduledAt = '';
				const input = document.querySelector( '.bn-composer__schedule-input' );
				if ( input ) { input.value = ''; }
			}
		},
		setScheduledAt( event ) {
			// <input type="datetime-local"> yields local wall-clock time with no
			// zone (e.g. "2026-06-01T14:30"). Convert to a UTC "Y-m-d H:i:s"
			// string — the format the Free PostService / Pro integration expect.
			const ctx = getContext();
			const raw = ( event && event.target && event.target.value ) || '';
			ctx.scheduledAt = raw ? toUtcSqlDatetime( raw ) : '';
		},
		openLink() {
			const ctx        = getContext();
			ctx.composerOpen = true;
			ctx.composerType = 'link';
		},
		toggleAnnouncement() {
			const ctx        = getContext();
			ctx.composerOpen = true;
			ctx.composerType = ctx.composerType === 'announcement' ? 'text' : 'announcement';
			if ( ctx.composerType !== 'announcement' ) {
				ctx.announcementExpiresAt = '';
				const input = document.querySelector( '#bn-composer-announce-expiry' );
				if ( input ) { input.value = ''; }
			}
		},
		setAnnouncementExpiry( event ) {
			// <input type="datetime-local"> → UTC "Y-m-d H:i:s" (what PostService expects).
			const ctx = getContext();
			const raw = ( event && event.target && event.target.value ) || '';
			ctx.announcementExpiresAt = raw ? toUtcSqlDatetime( raw ) : '';
		},
		onInput( event ) {
			const ctx     = getContext();
			ctx.content   = event.target.value;
			autoResizeTextarea( event.target );
			scheduleDraftSave( ctx );
			maybeDetectLink( ctx );
		},
		removeLinkPreview() {
			const ctx = getContext();
			// User dismissed the card — clear it and remember the URL so we don't
			// immediately re-fetch the same link on the next keystroke.
			_linkPreviewState.dismissed = ctx.linkUrl || '';
			ctx.linkUrl   = '';
			ctx.linkTitle = '';
			ctx.linkDesc  = '';
			ctx.linkThumb = '';
			ctx.linkMeta  = null;
		},
		discardDraft() {
			const ctx = getContext();
			const userId = parseInt( ctx.userId, 10 );
			if ( userId > 0 ) {
				clearDraft( userId );
			}
			ctx.content     = '';
			ctx.hasDraft    = false;
			setDraftStatus( ctx, '', false );
			const textarea = document.querySelector( '[data-wp-interactive="buddynext/post-composer"] .bn-composer__prompt' );
			if ( textarea ) {
				clearField( textarea );
				autoResizeTextarea( textarea );
			}
		},
		* submit() {
			const ctx     = getContext();
			const content = ( ctx.content || '' ).trim();
			// Already submitting: swallow the repeat click, no message.
			if ( ctx.submitting ) {
				return;
			}
			// Allow media-only posts, but an empty composer (no text AND no attached
			// media) must not silently no-op — surface a validation message so the
			// member gets feedback, mirroring the poll-options check below. For a
			// poll the "title" the tester expects is the main question textarea.
			if ( ! content && ! _mediaState.ids.length && ! ( ( ctx.documentId || 0 ) > 0 ) ) {
				ctx.errorMessage   = 'poll' === ctx.composerType
					? t( 'pollNeedsQuestion', 'Add a question for your poll.' )
					: t( 'composerEmpty', 'Write something to share.' );
				ctx.errorRetryable = false;
				return;
			}
			// Don't post while a document is still uploading — the id isn't ready.
			if ( ctx.documentUploading ) {
				ctx.errorMessage   = t( 'documentStillUploading', 'Wait for the document to finish uploading.' );
				ctx.errorRetryable = false;
				return;
			}
			ctx.errorMessage = '';
			ctx.errorAppealUrl = '';
			ctx.errorRetryable = true;
			ctx.submitting   = true;

			// The member pasted a link and hit Post before the debounced preview
			// fetch resolved. Wait for it — briefly — so the card posts complete
			// instead of appearing bare and filling in on the next page load.
			//
			// The wait is capped and the post goes out either way: the server
			// queues the scrape when link_meta is empty, so the preview still
			// arrives, just later. Nothing here can make posting depend on the
			// remote host answering — that was the original defect.
			if ( ctx.linkPreviewEnabled && ! ctx.linkMeta && detectFirstUrl( content ) ) {
				const inFlight = pendingLinkPreview();
				if ( inFlight ) {
					yield Promise.race( [
						inFlight,
						new Promise( ( resolve ) => setTimeout( resolve, LINK_PREVIEW_SUBMIT_WAIT_MS ) ),
					] );
				}
			}

			// Collect poll options and media attachments.
			const body = {
				content,
				privacy: ctx.privacy || 'public',
				type:    ctx.composerType || 'text',
			};

			// When the composer is rendered inside a space, post INTO that space —
			// the context carries spaceId (composer partial), and the REST
			// controller reads `space_id`. Without this the post silently landed
			// in the global feed (space_id null) instead of the space.
			const composerSpaceId = parseInt( ctx.spaceId, 10 ) || 0;
			if ( composerSpaceId > 0 ) {
				body.space_id = composerSpaceId;
			}

			// Admin announcement: carry the optional auto-expire datetime.
			if ( ctx.composerType === 'announcement' && ctx.announcementExpiresAt ) {
				body.announcement_expires_at = ctx.announcementExpiresAt;
			}

			// Attach media IDs from WPMediaVerse uploads (stored in module-level state).
			if ( _mediaState.ids.length ) {
				body.media_ids = [ ..._mediaState.ids ];
				if ( body.type === 'photo' || body.type === 'text' ) {
					body.type = 'photo';
				}
			}
			// A document attachment: send its id and type the post 'document' so
			// the feed renders the link-out card (the server resolves the card per
			// viewer from this id). Document wins the type over a bare text post.
			if ( ( ctx.documentId || 0 ) > 0 ) {
				body.document_id = ctx.documentId;
				body.type        = 'document';
			}
			if ( ctx.composerType === 'poll' ) {
				const optionInputs = document.querySelectorAll( '.bn-composer__poll-option' );
				const options = [];
				optionInputs.forEach( ( el ) => {
					const val = el.value.trim();
					if ( val ) {
						options.push( { label: val } );
					}
				} );
				if ( options.length < 2 ) {
					ctx.submitting   = false;
					ctx.errorMessage = t( 'pollMinOptions', 'Add at least two poll options.' );
					return;
				}
				body.options = options.map( ( o ) => o.label );

				// Optional poll deadline → UTC "Y-m-d H:i:s" (same conversion as
				// scheduled posts). After this moment the server rejects new votes
				// and the card renders a closed state.
				const endInput = document.querySelector( '.bn-composer__poll-end-input' );
				const endRaw   = endInput ? endInput.value.trim() : '';
				if ( endRaw ) {
					body.poll_end_date = toUtcSqlDatetime( endRaw );
				}
			}

			// Link preview: when a card was resolved for a URL in the content,
			// carry link_url + link_meta so the post stores and renders the
			// preview immediately. When link_meta is empty the server QUEUES the
			// scrape (it no longer fetches inline — that made saving a post
			// depend on a third-party server answering), so the preview arrives
			// shortly after instead of never.
			if ( ctx.linkUrl ) {
				body.link_url = ctx.linkUrl;
				if ( ctx.linkMeta ) {
					body.link_meta = ctx.linkMeta;
				}
				if ( body.type === 'text' ) {
					body.type = 'link';
				}
			} else if ( ctx.linkPreviewEnabled ) {
				// The preview did not resolve within the wait above (cold cache, a
				// slow host, or an unreachable one). Attach the URL anyway so the
				// server can queue the scrape and the card fills in — losing the
				// embed entirely would be worse than showing it a moment late. A
				// manually dismissed URL is respected and still posts as plain text.
				const pendingUrl = detectFirstUrl( ctx.content );
				if ( pendingUrl && pendingUrl !== _linkPreviewState.dismissed ) {
					body.link_url = pendingUrl;
					if ( body.type === 'text' ) {
						body.type = 'link';
					}
				}
			}

			// Scheduled posts: when a future publish datetime is set, send it as a
			// UTC "Y-m-d H:i:s" string. The Free PostService stores it (status flips
			// to "scheduled"); Pro's ScheduledPostsIntegration intercepts the row.
			const scheduledAt = ( ctx.scheduledAt || '' ).trim();
			if ( ctx.scheduleOpen && scheduledAt ) {
				body.scheduled_at = scheduledAt;
			}

			try {
				const res = yield restFetch( '/posts', {
					method:  'POST',
					nonce:   ctx.restNonce,
					toastOnError: false,
					body,
				} );
				if ( res.ok ) {
					const userId = parseInt( ctx.userId, 10 );
					if ( userId > 0 ) {
						// Cancel any pending debounced draft save first — otherwise
						// it fires after clearDraft() and re-writes the draft, so the
						// content reappears in the composer after the reload.
						const draftKey = String( userId );
						if ( _draftTimers.has( draftKey ) ) {
							clearTimeout( _draftTimers.get( draftKey ) );
							_draftTimers.delete( draftKey );
						}
						clearDraft( userId );
					}
					// Empty the composer right away. The prompt textarea is
					// input-only (no data-wp-bind--value), so resetting ctx.content
					// alone leaves the typed text on screen — and the browser would
					// restore it on reload. Clear both so the field is visibly empty
					// and a duplicate submit is not invited.
					ctx.content     = '';
					ctx.hasDraft    = false;
					setDraftStatus( ctx, '', false );
					document.querySelectorAll( '[data-wp-interactive="buddynext/post-composer"] .bn-composer__prompt' ).forEach( function ( ta ) { clearField( ta ); autoResizeTextarea( ta ); } );

					// The media was consumed into the post — clear the staged set and its
					// previews WITHOUT deleting from the server (the post now owns them).
					// This also stops a later cancel()/removeMedia from orphan-deleting a
					// posted file, and clears lingering preview thumbs after a post.
					_mediaState.ids      = [];
					_mediaState.previews = [];
					document.querySelectorAll( '.bn-composer__media-preview' ).forEach( function ( area ) {
						area.hidden = true;
						area.querySelectorAll( '.bn-composer__media-thumb' ).forEach( function ( el ) { el.remove(); } );
					} );

					// Reset every sub-form — schedule, poll and announcement — so the next
					// post cannot inherit this one's settings. Previously only the
					// schedule was cleared, which left a posted poll's panel open with its
					// options and end date intact.
					resetComposerSubForms( ctx );

					const created     = res.data || {};
					const isScheduled = !! body.scheduled_at || 'scheduled' === created.status;
					// Pre-moderation can hold the post (status=pending): it is NOT
					// published, so say so instead of claiming "Post published".
					const isPending   = 'pending' === created.status;
					if ( window.bnToast ) {
						let msg = t( 'postPublished', 'Post published' );
						if ( isPending ) {
							msg = t( 'postSubmittedForReview', 'Your post was submitted for review.' );
						} else if ( isScheduled ) {
							msg = t( 'postScheduled', 'Post scheduled' );
						}
						window.bnToast( msg, 'success' );
					}

					// Live post → prepend the server-rendered card in place (no
					// reload). Held/scheduled posts aren't in the live feed, so just
					// reset. If the card html is missing (or there's no feed list on
					// this page), fall back to a reload so the new state still shows.
					if ( isPending || isScheduled ) {
						ctx.submitting = false;
					} else if ( prependFeedCard( created.html ) ) {
						ctx.submitting = false;
					} else {
						setTimeout( function () { window.location.reload(); }, 500 );
					}
					return;
				}
				const data = res.data;
				// A 401/403 (or rest_forbidden) means the user cannot post here —
				// retrying will always fail, so show a permission message and hide
				// the Retry affordance. Other errors stay retryable.
				const nonRetryable = res.status === 401 || res.status === 403 || ( data && data.code === 'rest_forbidden' );
				// An unverified email is a recoverable block: the member can resend
				// the verification link and post once verified. Surface a resend
				// affordance instead of a dead-end permission message.
				const emailUnverified = !! ( data && data.code === 'email_unverified' );
				let msg = nonRetryable
					? t( 'noPermissionToPost', 'You don’t have permission to post here.' )
					: t( 'postPublishFailed', 'Could not publish your post. Try again.' );
				if ( data && data.message ) { msg = data.message; }
				ctx.errorMessage       = msg;
				ctx.errorRetryable     = ! nonRetryable;
				ctx.errorEmailUnverified = emailUnverified;
				// A suspension is a dead end for Retry but not for the member:
				// the server ships the appeal URL so we can offer the way out.
				ctx.errorAppealUrl     = ( data && data.data && data.data.appeal_url ) || '';
				ctx.submitting         = false;
			} catch ( _e ) {
				ctx.errorMessage   = t( 'networkError', 'Network error. Try again.' );
				ctx.errorRetryable = true;
				ctx.submitting     = false;
			}
		},
		togglePrivacy() {
			const ctx        = getContext();
			ctx.privacyOpen  = ! ctx.privacyOpen;
			if ( ctx.privacyOpen ) {
				// Keep the popover inside the viewport. The "Posting to" chip's
				// position shifts with the action-row layout, so a static CSS inset
				// overflows one edge or the other on mobile — the shared clamp
				// measures after paint and nudges it back in (either edge).
				try {
					const trigger = getElement().ref;
					const wrap    = trigger ? trigger.closest( '.bn-composer__privacy-wrap' ) : null;
					const pop     = wrap ? wrap.querySelector( '.bn-composer__privacy-pop' ) : null;
					bnClampPopoverToViewport( pop );
				} catch ( _e ) {}
			}
		},
		* resendVerification( event ) {
			if ( event && typeof event.preventDefault === 'function' ) { event.preventDefault(); }
			const ctx = getContext();
			const btn = event && event.target ? event.target.closest( 'button' ) : null;
			if ( btn ) { btn.disabled = true; }
			try {
				const res = yield restFetch( '/auth/verify/resend', {
					method:       'POST',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					bnToast( t( 'verifyResent', 'Verification email sent. Check your inbox.' ), { tone: 'success' } );
				} else {
					const data = res.data || {};
					bnToast( data.message || t( 'verifyResendFailed', 'Could not resend the verification email. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'verifyResendFailed', 'Could not resend the verification email. Try again.' ), { tone: 'danger' } );
			} finally {
				if ( btn ) { btn.disabled = false; }
			}
		},
		* submitVoice() {
			const ctx = getContext();
			if ( ctx.submitting ) { return; }
			const fields = {};
			document.querySelectorAll( '[data-bn-voice-field]' ).forEach( ( el ) => {
				fields[ el.dataset.bnVoiceField ] = el.value.trim();
			} );
			if ( ! fields.title || ! fields.scheduled_at ) {
				ctx.voiceError = t( 'voiceTitleTimeRequired', 'Title and start time are required.' );
				return;
			}
			ctx.voiceError = '';
			ctx.submitting = true;
			const body = {
				type:      'voice_room',
				content:   ( fields.title + ( fields.description ? '\n\n' + fields.description : '' ) ).trim(),
				privacy:   ctx.privacy || 'public',
				link_meta: {
					title:        fields.title,
					scheduled_at: fields.scheduled_at,
					duration:     parseInt( fields.duration || '30', 10 ),
				},
			};
			// Carry the space context so a scheduled voice room lands in the space
			// feed (mirrors submit()); otherwise space_id is null and it only
			// shows in the global feed.
			const voiceSpaceId = parseInt( ctx.spaceId, 10 ) || 0;
			if ( voiceSpaceId > 0 ) {
				body.space_id = voiceSpaceId;
			}
			try {
				const res = yield restFetch( '/posts', {
					method:  'POST',
					nonce:   ctx.restNonce,
					toastOnError: false,
					body,
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( t( 'voiceRoomScheduled', 'Voice room scheduled' ), 'success' ); }
					// A scheduled voice room is not in the live feed (it surfaces at
					// its start time), so there is nothing to prepend — just reset the
					// form instead of a jarring full-page reload.
					ctx.voiceError = '';
					ctx.submitting = false;
					document.querySelectorAll( '[data-bn-voice-field]' ).forEach( ( el ) => { el.value = ''; } );
					return;
				}
				ctx.voiceError = t( 'voiceScheduleFailed', 'Could not schedule the voice room. Try again.' );
				ctx.submitting = false;
			} catch ( _e ) {
				ctx.voiceError = t( 'networkError', 'Network error. Try again.' );
				ctx.submitting = false;
			}
		},
		cancel() {
			const ctx          = getContext();
			ctx.composerOpen   = false;
			ctx.content        = '';
			ctx.submitting     = false;
			// Sets composerType back to 'text' AND empties the sub-form inputs.
			// Flipping composerType alone only hides the poll/announcement panels;
			// the typed values are plain DOM and would reappear on reopen.
			resetComposerSubForms( ctx );
			// Abandoning the composer: delete any staged-but-unposted uploads so they
			// don't orphan on the server (best-effort). submit() consumes the ids into
			// the post and resets _mediaState itself, so nothing is deleted post-post.
			if ( _mediaState.ids.length ) {
				_mediaState.ids.forEach( ( id ) => deleteMedia( id, ctx.restNonce ) );
			}
			// Clear module-level media state + remove DOM previews.
			_mediaState.ids      = [];
			_mediaState.previews = [];
			const previewArea = document.querySelector( '.bn-composer__media-preview' );
			if ( previewArea ) {
				previewArea.hidden = true;
				previewArea.querySelectorAll( '.bn-composer__media-thumb' ).forEach( ( el ) => el.remove() );
			}
		},
		setPrivacy( event ) {
			const ctx    = getContext();
			const target = event && event.target ? event.target.closest( '[data-privacy]' ) : null;
			const value  = target ? target.getAttribute( 'data-privacy' ) : ( event && event.target ? event.target.value : '' );
			if ( value ) {
				ctx.privacy = value;
			}
			ctx.privacyOpen = false;
		},
		/**
		 * Dismiss the privacy dropdown when a click lands outside its wrapper —
		 * including on another composer tool (Poll, Schedule, Event, Media), the
		 * textarea, or anywhere else on the page. Bound to the document via
		 * data-wp-on-document--click on the composer root, mirroring the
		 * post-card closePopups() pattern so the audience selector behaves like a
		 * standard popover (Facebook/LinkedIn) rather than lingering open.
		 *
		 * @param {MouseEvent} event The document click event.
		 */
		closePrivacyOnOutside( event ) {
			const ctx = getContext();
			if ( ! ctx || ! ctx.privacyOpen ) {
				return;
			}
			const ref = getElement()?.ref || null;
			if ( ! ref ) {
				return;
			}
			const wrap = ref.querySelector( '.bn-composer__privacy-wrap' );
			if ( ! wrap || ! wrap.contains( event.target ) ) {
				ctx.privacyOpen = false;
			}
		},
	},
	callbacks: {
		// Re-apply a restored draft into the LIVE store once Interactivity has
		// hydrated. restoreDraftsOnLoad() patches the data-wp-context attribute
		// on initial load / nav, but if Interactivity hydrates first the live
		// context keeps draftStatus='' (and the textarea binding wins), so the
		// "Draft restored" status never appears. Running here via data-wp-init
		// (post-hydration) sets the live context directly, so the status +
		// restored content are reliable regardless of hydration ordering.
		restoreDraft() {
			const ctx = getContext();
			const userId = parseInt( ctx.userId, 10 );
			if ( ! ( userId > 0 ) ) {
				return;
			}
			// A share-to-feed mention prefill wins over a stored draft — don't
			// clobber the @mention the member just chose to share.
			if ( pendingMentionHandle && ( ctx.spaceId === null || ctx.spaceId === undefined ) ) {
				return;
			}
			const draft = readDraft( userId );
			if ( ! draft || ! draft.content ) {
				return;
			}
			ctx.content      = draft.content;
			// composerType deliberately not restored -- see scheduleDraftSave().
			// This is the store-side twin of the same restore in
			// restoreDraftsOnLoad(); both had to stop reading the field or the
			// pinned panel would simply come back through the other one.
			ctx.privacy      = draft.privacy || ctx.privacy;
			ctx.hasDraft     = true;
			ctx.draftStatus  = t( 'draftRestored', 'Draft restored' );
		},
	},
} );
