/**
 * BuddyNext post-card store and comment renderer.
 *
 * Split out of feed/store.js: registers buddynext/post-card - reactions, the
 * reaction-summary popover, comment threads (buildCommentNode, up to
 * COMMENT_MAX_DEPTH), bookmark, share trigger, delete, pin, report, and the
 * schedule/reschedule controls on a member's own cards. Every helper here is used
 * only by this store; the two it shared with the feed store (escapeHtml) and with
 * the composer (the site-timezone + field helpers) live in ./shared.js.
 *
 * Loaded as a relative side-effect import from feed/store.js, so it registers
 * exactly where @buddynext/feed is enqueued; the file moved, the loading did not.
 *
 * @package BuddyNext
 */

import { store, getContext, getElement } from '@wordpress/interactivity';
import { bnConfirm, bnReportDialog, bnToast } from '@buddynext/shell-dialog';
import { restFetch } from '@buddynext/rest-client';
import { t, fmt, prependFeedCard, bnApplyFilters, escapeHtml, siteTzOffset, clearField, toUtcSqlDatetime, toSiteInputValue, siteNowInputValue, bnEmojiAssetBase } from '@buddynext/feed-shared';
import { bnClampPopoverToViewport } from '@buddynext/popover';

/**
 * Neutral reaction glyph for a slug with no vendored emoji asset. Mirrors the SSR
 * fallback in templates/parts/post-reaction-summary.php: an unresolvable (orphaned)
 * reaction slug must NEVER render as raw text on a card — a bare "orphanslug"-style
 * token is the "slug on activity" defect — so both sides show the same small smile.
 */
const REACTION_FALLBACK_GLYPH =
	'<span class="bn-post-card__reaction-fallback" aria-hidden="true">' +
	'<svg class="bn-icon bn-icon--smile bn-icon--sm" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" ' +
	'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
	'<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/>' +
	'<line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg></span>';

/* ── Comment helpers (vanilla DOM — outside WP Interactivity API scope) ── */

/**
 * The owner-enabled reaction set for the comment picker.
 *
 * Mirrors the post-card picker (templates/parts/post-actions.php), which renders
 * from ReactionService::reaction_types(). The set is serialised server-side onto
 * .bn-comment-list[data-reactions] (templates/parts/post-comments-list.php) as
 * [{ slug, label, char, color, emoji_url }] — the owner-chosen subset plus any Pro
 * custom slugs. Hardcoding the six built-ins here meant a reaction the owner
 * disabled still rendered (and the server silently coerced the click), and Pro
 * custom reactions never appeared.
 *
 * The six built-ins remain as a fallback only for when the attribute is absent or
 * unparseable (e.g. a stale cached template), so the picker never renders empty.
 *
 * @param {Element|null} list The .bn-comment-list container for this post.
 * @return {Array<{slug: string, label: string, char: string, color: string, emoji_url: string}>} Reaction set.
 */
function resolveReactionSet( list ) {
	const raw = list ? list.dataset.reactions : '';

	if ( raw ) {
		try {
			const parsed = JSON.parse( raw );
			if ( Array.isArray( parsed ) && parsed.length ) {
				return parsed
					.filter( ( r ) => r && r.slug )
					.map( ( r ) => ( {
						slug:      String( r.slug ),
						label:     String( r.label || r.slug ),
						char:      String( r.char || '' ),
						color:     String( r.color || '' ),
						emoji_url: String( r.emoji_url || '' ),
					} ) );
			}
		} catch ( _e ) {
			// Fall through to the built-in set below.
		}
	}

	const base = list && list.dataset.emojiBase ? list.dataset.emojiBase : '';
	return [
		{ slug: 'like',  label: t( 'reactionLike',  'Like' ) },
		{ slug: 'love',  label: t( 'reactionLove',  'Love' ) },
		{ slug: 'haha',  label: t( 'reactionHaha',  'Haha' ) },
		{ slug: 'wow',   label: t( 'reactionWow',   'Wow' ) },
		{ slug: 'sad',   label: t( 'reactionSad',   'Sad' ) },
		{ slug: 'angry', label: t( 'reactionAngry', 'Angry' ) },
	].map( ( r ) => ( {
		...r,
		char:      '',
		color:     '',
		emoji_url: base ? base + r.slug + '.svg' : '',
	} ) );
}

/**
 * The serialized reaction meta ({slug,label,char,color,emoji_url}) for `type`,
 * resolved from the post card the currently-evaluating directive lives in. Used by
 * the react-trigger icon getters so the button shows the reaction's own mark (SVG
 * emoji or color glyph) for ANY registered reaction, not only the six built-ins.
 *
 * @param {string} type Reaction slug, or falsy for "no reaction".
 * @return {{slug:string,label:string,char:string,color:string,emoji_url:string}|null}
 */
function reactionMetaFor( type ) {
	if ( ! type ) {
		return null;
	}
	try {
		// getContext() is reliable inside a derived-state getter; getElement() is
		// not (it is for actions/callbacks), so resolve the post's reaction list by
		// id the same way setReactionIcon() does rather than by DOM proximity.
		const postId = getContext().postId;
		const list   = document.querySelector( '.bn-comment-list[data-comment-list="' + postId + '"]' );
		return resolveReactionSet( list ).find( ( r ) => r.slug === type ) || null;
	} catch ( _e ) {
		return null;
	}
}

function timeAgo( dateStr ) {
	// The API returns a naive MySQL UTC datetime ("YYYY-MM-DD HH:MM:SS", no zone).
	// `new Date()` parses a space-separated, zoneless string as LOCAL time, which
	// shifts the result by the viewer's UTC offset (a fresh comment shows "5h ago"
	// for a UTC+5 browser). Normalise to ISO-8601 UTC so the instant is correct in
	// every timezone. Server stores UTC via current_time('mysql', true).
	const raw = String( dateStr );
	const iso = /[zZ]|[+-]\d\d:?\d\d$/.test( raw ) ? raw : raw.replace( ' ', 'T' ) + 'Z';
	const secs = Math.floor( ( Date.now() - new Date( iso ).getTime() ) / 1000 );
	if ( secs < 60 )     return t( 'timeJustNow', 'just now' );
	if ( secs < 3600 )   return fmt( t( 'timeMinutesAgo', '%dm ago' ), Math.floor( secs / 60 ) );
	if ( secs < 86400 )  return fmt( t( 'timeHoursAgo', '%dh ago' ), Math.floor( secs / 3600 ) );
	if ( secs < 604800 ) return fmt( t( 'timeDaysAgo', '%dd ago' ), Math.floor( secs / 86400 ) );

	// Past a week, switch to a calendar date — the same cutoff and shape as the
	// server-rendered byline (buddynext_time_ago()), so a comment loaded by JS
	// and one rendered by PHP never read differently.
	return siteDate( new Date( iso ) );
}

/**
 * A Date -> an absolute day/month(/year) string in the SITE's timezone.
 *
 * Never the viewer's clock: the offset comes from WP's timezone setting, and the
 * date is then formatted as UTC on the shifted instant so Intl does not apply a
 * second, device-local shift. Month names come from Intl in the page's language,
 * matching what PHP's date_i18n() produces server-side. The year is shown only
 * when it is not the current one.
 *
 * @param {Date} date Instant to format.
 * @return {string} e.g. "20 July" or "11 June 2025".
 */
function siteDate( date ) {
	const shifted = new Date( date.getTime() + siteTzOffset() * 1000 );
	const nowSite = new Date( Date.now() + siteTzOffset() * 1000 );
	const lang    = document.documentElement.getAttribute( 'lang' ) || undefined;

	const withYear = shifted.getUTCFullYear() !== nowSite.getUTCFullYear();
	const opts     = { day: 'numeric', month: 'long', timeZone: 'UTC' };
	if ( withYear ) {
		opts.year = 'numeric';
	}

	let parts;
	try {
		parts = new Intl.DateTimeFormat( lang, opts ).formatToParts( shifted );
	} catch ( e ) {
		// An unusable lang tag must not blank the timestamp.
		parts = new Intl.DateTimeFormat( undefined, opts ).formatToParts( shifted );
	}

	// Assembled in PHP's order rather than taking Intl's locale ordering: the
	// server byline renders 'j F' / 'j F Y' ("8 July", "11 June 2025"), and a
	// comment row inserted by JS sitting next to a byline rendered by PHP must
	// not read "July 8". Only the month NAME comes from Intl, which is what we
	// actually need it for — a translated name matching PHP's date_i18n().
	const get   = ( type ) => ( parts.find( ( x ) => x.type === type ) || {} ).value || '';
	const day   = get( 'day' );
	const month = get( 'month' );
	const year  = withYear ? ' ' + get( 'year' ) : '';

	return day + ' ' + month + year;
}


/**
 * Rebuild a post card's reaction-summary chip strip after a toggle.
 *
 * The chip strip (templates/parts/post-reaction-summary.php) is SSR-only with no
 * Interactivity bindings, so a client-side react/un-react left its per-type
 * counts stale until reload. The /reactions/toggle response now returns the
 * authoritative `count` plus a per-type `summary` ({ slug, count, emoji }), so
 * rebuild the chips in place: replace the trigger's chip spans, refresh the
 * total, and hide the strip when the last reaction is removed.
 *
 * The strip is now SSR-present even at zero reactions (rendered hidden), so a
 * 0 → 1 reaction reveals and fills it in place — no reload needed. When the last
 * reaction is removed the strip is hidden again.
 *
 * @param {Element|null} cardEl The .bn-post-card being reacted on.
 * @param {Object}       body   The /reactions/toggle response body ({ count, summary }).
 * @return {void}
 */
function updateReactionSummary( cardEl, body ) {
	if ( ! cardEl ) return;

	const strip = cardEl.querySelector( '.bn-post-card__reaction-summary' );
	const total = Number( body && body.count ) || 0;

	if ( total <= 0 ) {
		if ( strip ) strip.hidden = true; // Last reaction removed.
		return;
	}
	if ( ! strip ) return; // Defensive: the strip is SSR-present (hidden at zero) on every card.

	strip.hidden = false;
	const trigger = strip.querySelector( '.bn-post-card__reactors-trigger' );
	if ( ! trigger ) return;

	const rows = Array.isArray( body.summary ) ? body.summary : [];
	let chips = '';
	rows.forEach( ( row ) => {
		const count = Number( row && row.count ) || 0;
		if ( count < 1 ) return;
		// row.emoji is server-rendered, sanitized emoji markup (IconService).
		const emoji = row.emoji ? String( row.emoji ) : REACTION_FALLBACK_GLYPH;
		chips +=
			'<span class="bn-post-card__summary-chip bn-post-card__summary-chip--reaction">' +
			emoji + ' ' + count + '</span>';
	} );
	if ( ! chips ) {
		// No per-type rows (fallback): show the aggregate total only.
		chips = '<span class="bn-post-card__summary-chip">' + total + '</span>';
	}

	trigger.innerHTML = chips;
	trigger.setAttribute( 'data-bn-count', String( total ) );
}

/**
 * Maximum visual nesting depth. Replies deeper than this are flattened
 * to depth = MAX_DEPTH with an "@parent" mention prefix injected by the
 * server so the conversation stays readable on narrow screens.
 */
// Maximum reply depth shown in the threaded view. Beyond this, the
// `Reply` button reuses the deepest ancestor's parent_id so the new
// reply appears at the cap level (Discord-style fold-back rather than
// infinite nesting). Server enforces the same cap during list().
const COMMENT_MAX_DEPTH = 5;

// The reaction picker is anchored to its post card and opens upward, so when
// the card scrolls up under the sticky header an open picker overlaps the
// header. Track the open picker's context and dismiss it on scroll (the
// behaviour every major feed uses for reaction poppers). One is open at a time.
let bnOpenReactionCtx     = null;
let bnReactionScrollBound = false;

// Scroll position when the picker opened, and how far the page must actually
// move before that counts as "the member scrolled away".
//
// Closing on ANY scroll event killed the picker the moment it opened. Clicking
// React focuses the trigger, and the browser scrolls a partially-visible focused
// element into view on mousedown; that scroll is dispatched on the NEXT frame,
// i.e. after the open handler has already set bnOpenReactionCtx. Measured: open
// at t=2.9ms, one scroll event at t=871.6ms, closed at t=871.5ms. A 1px
// window.scrollBy() closes it, so any momentum tick, rubber-band, or layout
// shift from a lazy-loading image did it too — the picker looked like it simply
// never opened.
//
// A threshold rather than a timer: it does not matter WHEN the page moved, only
// whether it moved enough to carry the card away from the reader.
let bnOpenReactionScrollY = 0;
const BN_REACTION_SCROLL_CLOSE_PX = 24;

/**
 * Build a comment DOM node using safe DOM methods (no innerHTML for user content).
 *
 * The node renders a single comment plus all its nested replies (recursive).
 * Soft-deleted comments render a "This comment was deleted" placeholder but
 * preserve the reply tree so the conversation stays coherent.
 *
 * @param {Object}  comment       Comment data from the REST API.
 * @param {number}  currentUserId Current user's WordPress ID.
 * @param {number}  postId        Post ID the comment belongs to.
 * @param {string}  restUrl       buddynext/v1 REST root.
 * @param {string}  nonce         WP REST nonce.
 * @param {number}  depth         0 = top-level, 1 = reply, 2 = reply-of-reply (capped).
 * @return {HTMLElement}
 */
/* prependFeedCard() and bnApplyFilters() moved to ./shared.js so the split store
 * files (composer, share-modal, post-card) reach one instance. */

/**
 * Append a "Resend verification email" control to a comment/reply error box.
 *
 * Mirrors the composer's resend affordance: when a comment or reply is refused
 * with an email_unverified 403, the member can request a fresh verification
 * link inline instead of hunting for it in the composer. Same endpoint the
 * composer uses (POST /auth/verify/resend).
 *
 * @param {HTMLElement} container Error element to append the button to.
 * @param {string}      nonce     REST nonce.
 * @return {void}
 */
/**
 * Surface why a reaction was refused, and where the member can go about it.
 *
 * Both reaction paths used to throw the server's answer away. The comment one
 * showed a generic "Could not update your reaction. Try again." - actively
 * misleading against a suspension, because retrying can never succeed - and the
 * post one showed NOTHING AT ALL: it reverted the optimistic state silently, so
 * the member's reaction just undid itself with no explanation.
 *
 * Reactions are the highest-frequency interaction in the product, so this is the
 * most likely place a suspended member first hits the wall, and it was the one
 * place that told them nothing. The server has always sent the reason and the
 * appeal URL from InteractionGuard; this reads them.
 *
 * @param {Object} res      restFetch result.
 * @param {string} fallback Message to use when the server sent none.
 * @return {void}
 */
function reactionFailureToast( res, fallback ) {
	const data      = ( res && res.data ) || {};
	const message   = data.message ? String( data.message ) : fallback;
	const appealUrl = ( data.data && data.data.appeal_url ) || '';

	bnToast( message, {
		tone: 'danger',
		action: appealUrl
			? { href: appealUrl, label: t( 'reviewAccountStatus', 'Review your account status' ) }
			: null,
	} );
}

/**
 * Append an "account status" link to a comment/reply error box.
 *
 * A suspension 403 can never succeed on retry, so the generic Retry button is a
 * dead control. The appeal page is where the member can actually do something
 * about it, and nothing in the UI linked to it — a suspended member could only
 * reach it by already knowing the URL. The server now ships that URL in the
 * error data, so this renders it rather than guessing the path.
 *
 * @param {HTMLElement} container Error element to append the link to.
 * @param {string}      url       Appeal page URL, from the error data.
 * @return {void}
 */
function appendAppealLink( container, url ) {
	if ( ! url ) {
		return;
	}
	const link = document.createElement( 'a' );
	link.className = 'bn-comment-submit-error__retry';
	link.href = url;
	link.textContent = t( 'reviewAccountStatus', 'Review your account status' );
	container.appendChild( link );
}

function appendResendVerifyButton( container, nonce ) {
	const btn = document.createElement( 'button' );
	btn.type = 'button';
	btn.className = 'bn-comment-submit-error__retry';
	btn.textContent = t( 'resendVerification', 'Resend verification email' );
	btn.addEventListener( 'click', async () => {
		btn.disabled = true;
		try {
			const res = await restFetch( '/auth/verify/resend', { method: 'POST', nonce, toastOnError: false } );
			if ( res.ok ) {
				bnToast( t( 'verifyResent', 'Verification email sent. Check your inbox.' ), { tone: 'success' } );
			} else {
				const data = res.data || {};
				bnToast( data.message || t( 'verifyResendFailed', 'Could not resend the verification email. Try again.' ), { tone: 'danger' } );
			}
		} catch ( _e ) {
			bnToast( t( 'verifyResendFailed', 'Could not resend the verification email. Try again.' ), { tone: 'danger' } );
		} finally {
			btn.disabled = false;
		}
	} );
	container.appendChild( btn );
}

function buildCommentNode( comment, currentUserId, postId, restUrl, nonce, depth ) {
	if ( typeof depth !== 'number' ) {
		// Back-compat: callers that still pass a boolean isReply.
		depth = depth ? 1 : 0;
	}
	const cappedDepth = Math.min( depth, COMMENT_MAX_DEPTH );

	// Let extensions transform the comment data before it renders. Reassigned so
	// every downstream read uses the filtered object; runs on nested replies too
	// (buildCommentNode recurses below).
	comment = bnApplyFilters( 'buddynext.comment', comment, { currentUserId, postId, depth: cappedDepth } );

	const wrap = document.createElement( 'div' );
	wrap.className = 'bn-comment-card';
	wrap.dataset.commentId = comment.id;
	wrap.dataset.depth     = String( cappedDepth );
	if ( comment.pinned ) {
		wrap.classList.add( 'bn-comment-card--pinned' );
	}
	if ( comment.is_deleted ) {
		wrap.classList.add( 'bn-comment-card--deleted' );
	}

	// Avatar: the member's avatar image when the REST payload provides one
	// (author_avatar_url, BN-managed via AvatarService), with the initials circle
	// as the fallback — mirroring how post bylines render. Previously comments
	// only ever showed initials, so a member's real avatar never appeared.
	const avatar = document.createElement( 'div' );
	avatar.className = 'bn-comment__avatar';
	avatar.setAttribute( 'aria-hidden', 'true' );
	const initials = ( comment.author_name || 'U' ).split( ' ' ).map( ( w ) => w[ 0 ] || '' ).join( '' ).slice( 0, 2 ).toUpperCase();
	if ( comment.author_avatar_url ) {
		const img = document.createElement( 'img' );
		img.src    = comment.author_avatar_url;
		img.alt    = '';
		img.width  = 32;
		img.height = 32;
		img.loading = 'lazy';
		// Fall back to initials if the avatar URL fails to load.
		img.addEventListener( 'error', function () {
			img.remove();
			avatar.textContent = initials;
		} );
		avatar.appendChild( img );
	} else {
		avatar.textContent = initials;
	}
	wrap.appendChild( avatar );

	const body = document.createElement( 'div' );
	body.className = 'bn-comment__body';

	// Header: author name + timestamp + pinned badge + edited marker.
	const header = document.createElement( 'div' );
	header.className = 'bn-comment__header';
	const authorSpan = document.createElement( 'span' );
	authorSpan.className = 'bn-comment__author';
	authorSpan.textContent = comment.author_name || t( 'user', 'User' );
	header.appendChild( authorSpan );

	// Server-provided author badges/roles (built via the buddynext_comment_author_meta_html
	// filter on the REST side, where it is kses_post-escaped). Rendered here so extensions
	// that add author chips actually appear — previously this field reached the client unused.
	if ( comment.author_meta_html ) {
		const authorMeta = document.createElement( 'span' );
		authorMeta.className = 'bn-comment__author-meta';
		authorMeta.innerHTML = comment.author_meta_html;
		header.appendChild( authorMeta );
	}
	if ( comment.pinned ) {
		const pinBadge = document.createElement( 'span' );
		pinBadge.className = 'bn-comment__pinned-badge';
		pinBadge.textContent = t( 'pinned', 'Pinned' );
		header.appendChild( pinBadge );
	}
	const timeEl = document.createElement( 'time' );
	timeEl.className = 'bn-comment__time';
	timeEl.textContent = timeAgo( comment.created_at );
	header.appendChild( timeEl );
	if ( comment.is_edited && ! comment.is_deleted ) {
		const editedMark = document.createElement( 'span' );
		editedMark.className = 'bn-comment__edited';
		editedMark.textContent = t( 'edited', '(edited)' );
		header.appendChild( editedMark );
	}
	body.appendChild( header );

	// Content paragraph (or placeholder for soft-deleted comments).
	const para = document.createElement( 'p' );
	para.className = 'bn-comment__content';
	if ( comment.is_deleted ) {
		para.textContent  = t( 'commentDeleted', 'This comment was deleted.' );
		para.dataset.placeholder = '1';
	} else if ( comment.content_html ) {
		// Server-formatted + sanitized markup (escaped user text with @mention
		// and #hashtag links baked in) — mirrors how post bodies render. Falls
		// back to plain text if an older response omits content_html.
		para.innerHTML = comment.content_html;
	} else {
		para.textContent = comment.content;
	}
	body.appendChild( para );

	// ── Actions row ────────────────────────────────────────────────────────
	const actions = document.createElement( 'div' );
	actions.className = 'bn-comment__actions';
	body.appendChild( actions );

	const isOwn       = parseInt( comment.user_id, 10 ) === currentUserId;
	const canEdit     = ( comment.can_edit ?? isOwn ) && ! comment.is_deleted;
	const canDelete   = ( comment.can_delete ?? isOwn ) && ! comment.is_deleted;
	const canPin      = !! comment.can_pin && ! comment.is_deleted;
	// Reply is allowed at every depth — beyond MAX_DEPTH the new reply
	// attaches to the deepest visible ancestor (fold-back) so the indent
	// doesn't keep growing. The server flattens consistently when listing.
	const canReply    = currentUserId > 0 && ! comment.is_deleted;
	const canReport   = currentUserId > 0 && ! isOwn && ! comment.is_deleted;

	// React button — opens a 6-emoji picker on hover or click. Matches the
	// post-card reaction picker (templates/parts/post-actions.php). Emoji
	// SVGs are served from the vendor base URL exposed on the
	// .bn-comment-list[data-emoji-base] attribute (set by
	// templates/parts/post-comments-list.php).
	let reactBtn = null;
	// Reactions are a site-owner feature toggle (Settings → Features). When the
	// owner disables it the comment list carries data-reactions-enabled="0" and
	// no per-comment React control renders — matching the post-card gate and the
	// REST toggle 403.
	const bnReactList    = document.querySelector( `.bn-comment-list[data-comment-list="${ postId }"]` );
	const bnReactionsOn  = ! bnReactList || bnReactList.dataset.reactionsEnabled !== '0';
	if ( ! comment.is_deleted && bnReactionsOn ) {
		// Resolve the owner-enabled reaction set (and each slug's label/glyph) via
		// the comment-list container keyed by postId. wrap.closest() can't be used
		// here because the wrap is not yet attached to the DOM at this point.
		const list      = document.querySelector( `.bn-comment-list[data-comment-list="${ postId }"]` );
		const REACTIONS = resolveReactionSet( list );
		const REACTION_META   = Object.fromEntries( REACTIONS.map( ( r ) => [ r.slug, r ] ) );
		const REACTION_LABELS = Object.fromEntries( REACTIONS.map( ( r ) => [ r.slug, r.label ] ) );

		// Render one reaction glyph into `parent`. Prefers the bundled Fluent SVG;
		// Pro custom slugs have none, so they fall back to a colour-tinted text
		// glyph (char, else the label's initial) exactly like the post card.
		const setReactionIcon = ( parent, type, size ) => {
			parent.replaceChildren();
			const meta = type ? REACTION_META[ type ] : null;
			if ( ! meta ) {
				parent.textContent = '♡';
				return;
			}
			const px = size || 16;
			if ( meta.emoji_url ) {
				const img  = document.createElement( 'img' );
				img.src    = meta.emoji_url;
				img.alt    = meta.label || '';
				img.width  = px;
				img.height = px;
				parent.appendChild( img );
				return;
			}
			// .bn-reaction-glyph sizes itself to 100% of its parent, which works on
			// the post card (the parent .bn-reaction-icon is a fixed box) but not
			// here — neither .bn-comment__like-icon nor .bn-comment__react-option is
			// sized. Give it an explicit box so it fills the same slot as the emoji.
			const glyph = document.createElement( 'span' );
			glyph.className   = 'bn-reaction-glyph';
			glyph.textContent = meta.char || ( meta.label || meta.slug ).charAt( 0 ).toUpperCase();
			glyph.style.width  = px + 'px';
			glyph.style.height = px + 'px';
			if ( /^#[0-9a-fA-F]{6}$/.test( meta.color ) ) {
				glyph.style.color = meta.color;
			}
			parent.appendChild( glyph );
		};

		// Tint the react button with the chosen reaction's own colour. The colour
		// already ships in the serialised reaction set the list carries, so this
		// covers every registered reaction — the stylesheet used to hardcode a tint
		// for five built-in slugs, which left a custom reaction rendering in the
		// default colour on a comment while the post trigger carried its artwork.
		// Same #rrggbb validation the glyph above uses; anything else falls back to
		// the generic reacted colour in CSS.
		const setReactionTint = ( btn, type ) => {
			const meta = type ? REACTION_META[ type ] : null;
			const hex  = meta && /^#[0-9a-fA-F]{6}$/.test( meta.color ) ? meta.color : '';
			btn.style.setProperty( '--bn-comment-reaction-color', hex );
		};

		const wrapBtn = document.createElement( 'span' );
		wrapBtn.className = 'bn-comment__react-wrap';

		reactBtn = document.createElement( 'button' );
		reactBtn.type = 'button';
		reactBtn.className = 'bn-comment__like-btn';
		// Default to the first ENABLED slug, not a hardcoded 'like' — the owner can
		// disable 'like', in which case it has no glyph or label to render.
		const defaultReaction = REACTIONS.length ? REACTIONS[ 0 ].slug : 'like';
		reactBtn.dataset.reaction = comment.viewer_liked ? ( comment.viewer_reaction || defaultReaction ) : '';
		// Explicit binary state hook ("0"/"1") — distinct from aria-pressed so the
		// liked state is always a defined attribute, never an empty string.
		reactBtn.dataset.liked = comment.viewer_liked ? '1' : '0';
		reactBtn.setAttribute( 'aria-pressed', comment.viewer_liked ? 'true' : 'false' );

		const reactIcon = document.createElement( 'span' );
		reactIcon.className = 'bn-comment__like-icon';
		setReactionIcon( reactIcon, reactBtn.dataset.reaction );
		setReactionTint( reactBtn, reactBtn.dataset.reaction );

		const reactLabel = document.createElement( 'span' );
		reactLabel.className = 'bn-comment__like-label';
		reactLabel.textContent = reactBtn.dataset.reaction
			? ( REACTION_LABELS[ reactBtn.dataset.reaction ] || t( 'react', 'React' ) )
			: t( 'react', 'React' );

		const reactCount = document.createElement( 'span' );
		reactCount.className = 'bn-comment__like-count';
		reactCount.textContent = String( comment.like_count || 0 );

		reactBtn.appendChild( reactIcon );
		reactBtn.appendChild( document.createTextNode( ' ' ) );
		reactBtn.appendChild( reactLabel );
		reactBtn.appendChild( document.createTextNode( ' ' ) );
		reactBtn.appendChild( reactCount );

		// Reaction picker dropdown — one option per owner-enabled reaction.
		const picker = document.createElement( 'div' );
		picker.className = 'bn-comment__react-picker';
		picker.hidden = true;
		picker.setAttribute( 'role', 'toolbar' );
		picker.setAttribute( 'aria-label', t( 'chooseReaction', 'Choose reaction' ) );
		REACTIONS.forEach( ( reaction ) => {
			const type = reaction.slug;
			const opt = document.createElement( 'button' );
			opt.type = 'button';
			opt.className = 'bn-comment__react-option';
			opt.setAttribute( 'aria-label', reaction.label );
			opt.title = reaction.label;
			opt.dataset.reaction = type;
			setReactionIcon( opt, type, 28 );
			// Keyboard activation only: mouse/touch selection is handled by the
			// picker-level 'pointerdown' listener below (so the choice lands before
			// any blur-driven close). `e.detail === 0` distinguishes a keyboard
			// Enter/Space click from a synthetic pointer click, avoiding a
			// double-toggle on pointer devices.
			opt.addEventListener( 'click', ( e ) => {
				if ( 0 === e.detail ) {
					sendReaction( type );
				}
			} );
			picker.appendChild( opt );
		} );

		wrapBtn.appendChild( reactBtn );
		wrapBtn.appendChild( picker );

		let hoverTimer = null;
		// Unhide first, THEN clamp: a hidden element has no measurable box, so a
		// clamp computed before this would read zeroes and do nothing. The picker
		// is `inset-inline-start: 0` on its wrapper, so on a narrow screen a
		// nested reply's indentation pushes it off the end edge with the later
		// reactions unreachable -- the same overflow the post-card picker already
		// guards against, now through the same helper.
		const openPicker  = () => {
			clearTimeout( hoverTimer );
			picker.hidden = false;
			bnClampPopoverToViewport( picker );
		};
		const closePicker = () => { hoverTimer = setTimeout( () => { picker.hidden = true; }, 200 ); };
		// Hover reveal for pointer devices. Touch devices never fire these, so the
		// click handler below also opens the picker — otherwise the six specific
		// reactions would be unreachable on mobile (only a default like worked).
		wrapBtn.addEventListener( 'mouseenter', openPicker );
		wrapBtn.addEventListener( 'mouseleave', closePicker );
		wrapBtn.addEventListener( 'focusin', openPicker );
		wrapBtn.addEventListener( 'focusout', closePicker );

		// Click on the React button:
		//   • picker already open  → quick-toggle a default like / clear current.
		//   • picker closed (touch or no-hover) → open it so the user can pick one
		//     of the six reactions. A second click then quick-toggles.
		reactBtn.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			if ( picker.hidden ) {
				if ( currentUserId <= 0 ) {
					sendReaction( defaultReaction ); // surfaces the sign-in toast.
					return;
				}
				openPicker();
				return;
			}
			// The first ENABLED reaction, never a hardcoded 'like'. The server's
			// coerce_to_enabled() would store the right slug anyway, so this was only
			// ever cosmetic — but on a site where the owner has disabled Like, the
			// button painted the generic glyph for a beat before the response landed.
			sendReaction( reactBtn.dataset.reaction ? null : defaultReaction );
		} );

		// Selecting an emoji must fire even if a blur/leave would otherwise hide the
		// picker first — pointerdown lands before the close timer, so the choice is
		// never swallowed. (The per-option 'click' binding above still covers
		// keyboard activation.)
		picker.addEventListener( 'pointerdown', ( e ) => {
			const opt = e.target.closest( '.bn-comment__react-option' );
			if ( ! opt ) { return; }
			e.preventDefault();
			clearTimeout( hoverTimer );
			sendReaction( opt.dataset.reaction );
		} );

		// Close the picker when focus/pointer moves elsewhere on the page so an
		// opened-by-click picker (touch) does not get stuck open.
		document.addEventListener( 'click', ( e ) => {
			if ( ! picker.hidden && ! wrapBtn.contains( e.target ) ) {
				picker.hidden = true;
			}
		} );

		async function sendReaction( type ) {
			if ( currentUserId <= 0 ) {
				bnToast( t( 'signInToReact', 'Sign in to react to comments.' ), { tone: 'info' } );
				return;
			}
			picker.hidden = true;
			const prev = reactBtn.dataset.reaction;
			const next = ( null === type || prev === type ) ? '' : type;
			// Optimistic UI.
			reactBtn.dataset.reaction = next;
			reactBtn.dataset.liked = next ? '1' : '0';
			reactBtn.setAttribute( 'aria-pressed', next ? 'true' : 'false' );
			setReactionIcon( reactIcon, next );
			setReactionTint( reactBtn, next );
			reactLabel.textContent = next ? ( REACTION_LABELS[ next ] || t( 'react', 'React' ) ) : t( 'react', 'React' );
			const cur = parseInt( reactCount.textContent || '0', 10 );
			let delta = 0;
			if ( ! prev && next ) { delta = 1; }
			else if ( prev && ! next ) { delta = -1; }
			reactCount.textContent = String( Math.max( 0, cur + delta ) );

			try {
				const res = await restFetch( '/reactions/toggle', {
					method:  'POST',
					nonce,
					toastOnError: false,
					body:    {
						object_type: 'comment',
						object_id:   comment.id,
						emoji:       next || prev || 'like',
					},
				} );
				if ( ! res.ok ) {
					const err = new Error( 'reaction failed' );
					err.bnResponse = res;
					throw err;
				}
			} catch ( _e ) {
				// Rollback to prev.
				reactBtn.dataset.reaction = prev;
				reactBtn.dataset.liked = prev ? '1' : '0';
				reactBtn.setAttribute( 'aria-pressed', prev ? 'true' : 'false' );
				setReactionIcon( reactIcon, prev );
				setReactionTint( reactBtn, prev );
				reactLabel.textContent = prev ? ( REACTION_LABELS[ prev ] || t( 'react', 'React' ) ) : t( 'react', 'React' );
				reactCount.textContent = String( cur );
				reactionFailureToast( _e && _e.bnResponse, t( 'reactionUpdateFailed', 'Could not update your reaction. Try again.' ) );
			}
		}

		actions.appendChild( wrapBtn );
	}

	// Reply button.
	if ( canReply ) {
		const replyBtn = document.createElement( 'button' );
		replyBtn.type = 'button';
		replyBtn.className = 'bn-comment__reply-btn';
		replyBtn.textContent = t( 'reply', 'Reply' );
		actions.appendChild( replyBtn );
	}

	// Edit button — opens inline editor.
	if ( canEdit ) {
		const editBtn = document.createElement( 'button' );
		editBtn.type = 'button';
		editBtn.className = 'bn-comment__edit-btn';
		editBtn.textContent = t( 'edit', 'Edit' );
		editBtn.addEventListener( 'click', () => {
			if ( body.querySelector( '.bn-comment__edit-form' ) ) {
				return;
			}
			const editForm = document.createElement( 'div' );
			editForm.className = 'bn-comment__edit-form';
			const ta = document.createElement( 'textarea' );
			ta.className = 'bn-comment-form__input';
			ta.value = para.textContent || '';
			ta.rows = 2;
			const saveBtn = document.createElement( 'button' );
			saveBtn.type = 'button';
			saveBtn.className = 'bn-comment-form__submit';
			saveBtn.textContent = t( 'save', 'Save' );
			const cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'bn-comment__reply-cancel';
			cancelBtn.textContent = t( 'cancel', 'Cancel' );
			editForm.appendChild( ta );
			// Footer action row so the emoji trigger + Save + Cancel sit on one
			// line instead of each stretching full-width down the column (the
			// edit-form is flex-direction:column, so bare children stretch).
			const editActions = document.createElement( 'div' );
			editActions.className = 'bn-comment__edit-actions';
			// Emoji insert — shown only when the site-owner emoji picker is
			// enabled (signalled by the composer's option-gated trigger being
			// present on the page). The shared initEmojiPicker() handler wires
			// the click + insertion into this specific editor's textarea.
			if ( document.querySelector( '.bn-composer .bn-emoji-trigger' ) ) {
				const taField = 'bn-comment-edit-' + comment.id;
				ta.dataset.bnEmojiField = taField;
				const emojiBtn = document.createElement( 'button' );
				emojiBtn.type = 'button';
				emojiBtn.className = 'bn-emoji-trigger bn-comment__emoji-trigger';
				emojiBtn.dataset.bnEmojiTarget = '[data-bn-emoji-field="' + taField + '"]';
				emojiBtn.setAttribute( 'aria-label', t( 'insertEmoji', 'Insert emoji' ) );
				emojiBtn.setAttribute( 'aria-haspopup', 'true' );
				emojiBtn.setAttribute( 'aria-expanded', 'false' );
				emojiBtn.title = t( 'insertEmoji', 'Insert emoji' );
				// Reuse the bundled "grin" SVG glyph as the trigger face so no
				// emoji character is hardcoded; falls back to a text label.
				const emojiBase = bnEmojiAssetBase();
				if ( emojiBase ) {
					const gi = document.createElement( 'img' );
					gi.src = emojiBase + 'grin.svg';
					gi.alt = '';
					gi.width = 18;
					gi.height = 18;
					emojiBtn.appendChild( gi );
				} else {
					emojiBtn.textContent = t( 'emoji', 'Emoji' );
				}
				editActions.appendChild( emojiBtn );
			}
			editActions.appendChild( saveBtn );
			editActions.appendChild( cancelBtn );
			editForm.appendChild( editActions );
			para.hidden = true;
			body.insertBefore( editForm, actions );
			ta.focus();
			cancelBtn.addEventListener( 'click', () => {
				editForm.remove();
				para.hidden = false;
			} );
			saveBtn.addEventListener( 'click', async () => {
				const next = ta.value.trim();
				if ( ! next ) {
					return;
				}
				try {
					const res = await restFetch( '/comments/' + comment.id, {
						method:  'PUT',
						nonce,
						toastOnError: false,
						body:    { content: next },
					} );
					if ( res.ok ) {
						const updated = res.data;
						para.textContent = updated.content;
						if ( ! body.querySelector( '.bn-comment__edited' ) ) {
							const editedMark = document.createElement( 'span' );
							editedMark.className = 'bn-comment__edited';
							editedMark.textContent = t( 'edited', '(edited)' );
							header.appendChild( editedMark );
						}
						editForm.remove();
						para.hidden = false;
						bnToast( t( 'commentUpdated', 'Comment updated' ), { tone: 'success' } );
					} else {
						bnToast( t( 'commentUpdateFailed', 'Could not update comment. Try again.' ), { tone: 'danger' } );
					}
				} catch ( _e ) {
					bnToast( t( 'commentUpdateFailed', 'Could not update comment. Try again.' ), { tone: 'danger' } );
				}
			} );
		} );
		actions.appendChild( editBtn );
	}

	// Delete button.
	if ( canDelete ) {
		const delBtn = document.createElement( 'button' );
		delBtn.type = 'button';
		delBtn.className = 'bn-comment__delete-btn';
		delBtn.textContent = t( 'delete', 'Delete' );
		delBtn.addEventListener( 'click', async () => {
			const ok = await bnConfirm( {
				title: t( 'deleteCommentTitle', 'Delete this comment?' ),
				body: t( 'cannotBeUndone', 'This cannot be undone.' ),
				confirmLabel: t( 'delete', 'Delete' ),
				tone: 'danger',
			} );
			if ( ! ok ) {
				return;
			}
			const res = await restFetch( '/comments/' + comment.id, {
				method: 'DELETE', nonce, toastOnError: false,
			} );
			if ( res.ok ) {
				// Soft-delete: replace text + grey out, preserve thread.
				wrap.classList.add( 'bn-comment-card--deleted' );
				para.textContent = t( 'commentDeleted', 'This comment was deleted.' );
				para.dataset.placeholder = '1';
				para.hidden = false;
				actions.remove();
				adjustCommentCount( postId, -1 );
				bnToast( t( 'commentDeletedToast', 'Comment deleted' ), { tone: 'success' } );
			} else {
				bnToast( t( 'commentDeleteFailed', 'Could not delete comment. Try again.' ), { tone: 'danger' } );
			}
		} );
		actions.appendChild( delBtn );
	}

	// Pin button (moderators only).
	if ( canPin ) {
		const pinBtn = document.createElement( 'button' );
		pinBtn.type = 'button';
		pinBtn.className = 'bn-comment__pin-btn';
		pinBtn.textContent = comment.pinned ? t( 'unpin', 'Unpin' ) : t( 'pin', 'Pin' );
		pinBtn.addEventListener( 'click', async () => {
			const wasPinned = wrap.classList.contains( 'bn-comment-card--pinned' );
			try {
				const res = await restFetch( '/comments/' + comment.id + '/pin', {
					method:  wasPinned ? 'DELETE' : 'POST',
					nonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					wrap.classList.toggle( 'bn-comment-card--pinned', ! wasPinned );
					pinBtn.textContent = wasPinned ? t( 'pin', 'Pin' ) : t( 'unpin', 'Unpin' );
					const existing = header.querySelector( '.bn-comment__pinned-badge' );
					if ( wasPinned && existing ) {
						existing.remove();
					} else if ( ! wasPinned && ! existing ) {
						const pinBadge = document.createElement( 'span' );
						pinBadge.className = 'bn-comment__pinned-badge';
						pinBadge.textContent = t( 'pinned', 'Pinned' );
						header.insertBefore( pinBadge, timeEl );
					}
					bnToast( wasPinned ? t( 'commentUnpinned', 'Comment unpinned' ) : t( 'commentPinned', 'Comment pinned' ), { tone: 'success' } );
				} else {
					bnToast( t( 'pinStatusFailed', 'Could not change pin status. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'pinStatusFailed', 'Could not change pin status. Try again.' ), { tone: 'danger' } );
			}
		} );
		actions.appendChild( pinBtn );
	}

	// Report button — visible for non-owner comments.
	if ( canReport ) {
		const reportBtn = document.createElement( 'button' );
		reportBtn.type = 'button';
		reportBtn.className = 'bn-comment__report-btn';
		reportBtn.setAttribute( 'aria-label', t( 'reportComment', 'Report this comment' ) );
		reportBtn.textContent = t( 'report', 'Report' );
		reportBtn.addEventListener( 'click', async () => {
			const result = await bnReportDialog( {
				title: t( 'reportComment', 'Report this comment' ),
			} );
			if ( result === null ) {
				return;
			}
			try {
				const res = await restFetch( '/reports', {
					method:  'POST',
					nonce,
					toastOnError: false,
					body:    {
						object_type: 'comment',
						object_id:   comment.id,
						reason:      result.reason,
						notes:       result.notes,
					},
				} );
				// Reflect the resolved state on the control (mirrors the post-level
				// report, which flips to "Reported"): disable + relabel so the user
				// sees it landed instead of a button that re-opens the dialog.
				const markReported = () => {
					reportBtn.disabled = true;
					reportBtn.classList.add( 'is-reported' );
					reportBtn.textContent = t( 'reported', 'Reported' );
				};
				if ( res.ok || res.status === 201 ) {
					markReported();
					bnToast( t( 'reportSubmitted', 'Report submitted. Thanks for keeping the community safe.' ), { tone: 'success' } );
				} else if ( res.status === 409 ) {
					// Already reported — show it as resolved, not a failure to retry.
					markReported();
					const data = res.data || {};
					bnToast( data.message || t( 'reportAlready', 'You already reported this.' ), { tone: 'info' } );
				} else {
					// Surface the server's reason instead of a generic failure the
					// user reads as "retry".
					const data = res.data || {};
					bnToast( data.message || t( 'reportFailed', 'Could not submit report. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'reportFailed', 'Could not submit report. Try again.' ), { tone: 'danger' } );
			}
		} );
		actions.appendChild( reportBtn );
	}

	// ── Reply form + nested replies ────────────────────────────────────────
	let repliesEl = null;
	if ( canReply ) {
		const replyForm = document.createElement( 'div' );
		replyForm.className = 'bn-comment__reply-form';
		replyForm.hidden = true;

		const replyTextarea = document.createElement( 'textarea' );
		// Dedicated reply class (shares the input rule via a comma selector in
		// bn-feed.css) so it no longer collides with the post-level comment
		// input's class.
		replyTextarea.className = 'bn-comment__reply-input';
		replyTextarea.placeholder = t( 'writeReply', 'Write a reply...' );
		replyTextarea.rows = 1;
		replyForm.appendChild( replyTextarea );

		const replySubmit = document.createElement( 'button' );
		replySubmit.type = 'button';
		replySubmit.className = 'bn-comment-form__submit';
		replySubmit.setAttribute( 'aria-label', t( 'postReply', 'Post reply' ) );
		replySubmit.textContent = t( 'reply', 'Reply' );
		replyForm.appendChild( replySubmit );

		const replyCancel = document.createElement( 'button' );
		replyCancel.type = 'button';
		replyCancel.className = 'bn-comment__reply-cancel';
		replyCancel.textContent = t( 'cancel', 'Cancel' );
		replyForm.appendChild( replyCancel );

		body.appendChild( replyForm );

		actions.querySelector( '.bn-comment__reply-btn' )?.addEventListener( 'click', () => {
			replyForm.hidden = ! replyForm.hidden;
			if ( ! replyForm.hidden ) {
				replyTextarea.focus();
			}
		} );
		replyCancel.addEventListener( 'click', () => { replyForm.hidden = true; } );

		replySubmit.addEventListener( 'click', async () => {
			const content = replyTextarea.value.trim();
			if ( ! content ) {
				return;
			}
			try {
				const res = await restFetch( '/comments', {
					method:  'POST',
					nonce,
					toastOnError: false,
					body:    { object_type: 'post', object_id: postId, content, parent_id: comment.id },
				} );
				if ( res.ok ) {
					const reply = res.data;
					if ( ! repliesEl ) {
						repliesEl = document.createElement( 'div' );
						repliesEl.className = 'bn-comment__replies';
						body.appendChild( repliesEl );
					}
					repliesEl.appendChild( buildCommentNode( reply, currentUserId, postId, restUrl, nonce, cappedDepth + 1 ) );
					clearField( replyTextarea );
					replyForm.hidden = true;
					adjustCommentCount( postId, 1 );
				} else {
					const data = res.data || {};
					if ( 'email_unverified' === data.code ) {
						// Blocked because the member has not verified their email.
						// Show the reason inline with a Resend link (mirrors the
						// composer) so they can fix it without leaving the thread.
						let errEl = replyForm.querySelector( '.bn-comment-submit-error' );
						if ( ! errEl ) {
							errEl = document.createElement( 'div' );
							errEl.className = 'bn-comment-submit-error';
							errEl.setAttribute( 'role', 'alert' );
							replyForm.insertBefore( errEl, replyForm.firstChild );
						}
						while ( errEl.firstChild ) { errEl.removeChild( errEl.firstChild ); }
						const span = document.createElement( 'span' );
						span.textContent = data.message || t( 'replyFailed', 'Could not post reply. Try again.' );
						errEl.appendChild( span );
						appendResendVerifyButton( errEl, nonce );
					} else {
						bnToast( data.message || t( 'replyFailed', 'Could not post reply. Try again.' ), { tone: 'danger' } );
					}
				}
			} catch ( _e ) {
				bnToast( t( 'replyFailed', 'Could not post reply. Try again.' ), { tone: 'danger' } );
			}
		} );
	}

	// Render nested replies recursively.
	const replies = comment.replies || [];
	if ( replies.length > 0 ) {
		repliesEl = document.createElement( 'div' );
		repliesEl.className = 'bn-comment__replies';
		replies.forEach( ( r ) => {
			repliesEl.appendChild( buildCommentNode( r, currentUserId, postId, restUrl, nonce, cappedDepth + 1 ) );
		} );
		body.appendChild( repliesEl );
	}

	wrap.appendChild( body );

	// Final hook: extensions can decorate (or replace) the rendered comment node.
	return bnApplyFilters( 'buddynext.commentNode', wrap, comment, { currentUserId, postId, depth: cappedDepth } );
}

function adjustCommentCount( postId, delta ) {
	const card = document.querySelector( 'article[data-post-id="' + postId + '"]' );
	const btn  = card?.querySelector( '[data-wp-on--click="actions.openComments"]' );
	const span = btn?.querySelector( '.bn-post-card__action-count' );
	if ( ! span || ! btn ) {
		return;
	}
	// Single source of truth for the comment count: every add (top-level +
	// reply) and delete routes through here, so it owns the visible chip, its
	// hidden state, and the button's accessible name in lock-step. (Delete is
	// a vanilla-JS handler with no Interactivity context, so a reactive
	// binding could never stay in sync with it.)
	const n = Math.max( 0, parseInt( span.textContent || '0', 10 ) + delta );
	span.textContent = String( n );
	span.hidden = ( 0 === n );
	btn.setAttribute( 'aria-label', 1 === n ? t( 'oneComment', '1 comment' ) : fmt( t( 'manyComments', '%d comments' ), n ) );
}

/* ── Reactors popover row builder ─────────────────────────────────────────
 * The "who reacted" popover shell is SSR-present in each post card
 * (post-reaction-summary.php) and toggled reactively (state.reactorsHidden).
 * Its rows are inherently dynamic data fetched from /reactions/list, so they
 * are built here with safe DOM methods and appended into the SSR list — never
 * carrying data-wp-* directives (the rows are JS-built; per the Interactivity
 * contract directives stay on the server-rendered shell). */
function buildReactorRow( r, emojiBase ) {
	const li = document.createElement( 'li' );
	li.className = 'bn-reactors-popover__item';
	if ( r.avatar_url ) {
		const img = document.createElement( 'img' );
		img.src = r.avatar_url;
		img.alt = '';
		img.width = 32;
		img.height = 32;
		img.className = 'bn-reactors-popover__avatar';
		li.appendChild( img );
	}
	const name = document.createElement( 'span' );
	name.className = 'bn-reactors-popover__name';
	name.textContent = r.display_name || fmt( t( 'userNumber', 'User #%s' ), r.user_id );
	li.appendChild( name );
	if ( r.emoji && emojiBase ) {
		const img = document.createElement( 'img' );
		img.src = emojiBase + r.emoji + '.svg';
		img.alt = r.emoji;
		img.width = 18;
		img.height = 18;
		img.className = 'bn-reactors-popover__emoji';
		li.appendChild( img );
	}
	return li;
}

/* ── Post card ───────────────────────────────────────────────────────────── */

/**
 * Fetch + render a post's comment thread into its [data-comment-list] region.
 *
 * Extracted to a module-level generator so both the `loadComments` and
 * `openComments` actions can `yield*` it. Cross-action generator calls
 * (`this.loadComments()` / `actions.loadComments()`) are unreliable in the
 * Interactivity API runtime — `this` is undefined inside an action generator
 * and the store-wrapped action is not `yield*`-iterable — so the shared logic
 * lives here and takes the already-resolved element context as a parameter.
 *
 * @param {Object} ctx Element context from getContext() (postId, restUrl, …).
 */
/**
 * Append a page of comments to the list and (re)build the "View more" control.
 *
 * The REST endpoint paginates top-level comments and returns the grand `total`;
 * the web client previously fetched only page 1 (per_page=20) and ignored the
 * total, so a 500-comment thread stranded 480 comments. This renders the page's
 * items, then shows a "View N more comments" button when more remain.
 *
 * @param {Element} listEl The [data-comment-list] container.
 * @param {Object}  data   REST response ({ items, total, page, per_page }).
 * @param {Object}  ctx    Post-card context (ids, nonce, restUrl).
 * @return {void}
 */
function bnRenderCommentPage( listEl, data, ctx ) {
	const items   = data.items || [];
	const total   = Number( data.total ) || items.length;
	const page    = Number( data.page ) || Number( listEl.dataset.page ) || 1;
	const perPage = Number( data.per_page ) || 20;

	// Drop any prior load-more button before appending this page's items.
	const oldBtn = listEl.querySelector( '.bn-comment-loadmore' );
	if ( oldBtn ) listEl.removeChild( oldBtn );

	items.forEach( ( comment ) => {
		listEl.appendChild(
			buildCommentNode( comment, ctx.currentUserId, ctx.postId, ctx.restUrl, ctx.reactNonce, 0 )
		);
	} );

	const shown = page * perPage;
	if ( shown < total ) {
		const remaining = total - shown;
		const btn = document.createElement( 'button' );
		btn.type      = 'button';
		btn.className = 'bn-comment-loadmore';
		btn.textContent = t( 'viewMoreComments', 'View more comments' ) + ' (' + remaining + ')';
		btn.addEventListener( 'click', () => bnLoadMoreComments( listEl, ctx, btn ) );
		listEl.appendChild( btn );
	}
}

/**
 * Fetch and append the next page of comments (the load-more click handler).
 *
 * @param {Element} listEl The [data-comment-list] container.
 * @param {Object}  ctx    Post-card context.
 * @param {Element} btn    The load-more button (disabled while fetching).
 * @return {Promise<void>}
 */
async function bnLoadMoreComments( listEl, ctx, btn ) {
	const nextPage = ( Number( listEl.dataset.page ) || 1 ) + 1;
	btn.disabled    = true;
	btn.textContent = t( 'loadingComments', 'Loading…' );

	try {
		const res = await restFetch(
			'/comments?object_type=post&object_id=' + ctx.postId + '&per_page=20&page=' + nextPage,
			{ nonce: ctx.reactNonce, toastOnError: false }
		);
		if ( res.ok ) {
			listEl.dataset.page = String( nextPage );
			bnRenderCommentPage( listEl, res.data, ctx );
		} else {
			btn.disabled    = false;
			btn.textContent = t( 'retry', 'Retry' );
		}
	} catch ( _e ) {
		btn.disabled    = false;
		btn.textContent = t( 'retry', 'Retry' );
	}
}

function* bnLoadComments( ctx ) {
	const listEl = document.querySelector( '[data-comment-list="' + ctx.postId + '"]' );
	if ( ! listEl || listEl.dataset.loaded ) {
		return;
	}

	// Skeleton rows while we fetch — three placeholder bars so the region
	// does not collapse and the user knows the thread is loading.
	while ( listEl.firstChild ) {
		listEl.removeChild( listEl.firstChild );
	}
	for ( let s = 0; s < 3; s++ ) {
		const sk = document.createElement( 'div' );
		sk.className = 'bn-comment-skeleton';
		const skAvatar = document.createElement( 'span' );
		skAvatar.className = 'bn-skeleton bn-comment-skeleton__avatar';
		const skLine   = document.createElement( 'span' );
		skLine.className   = 'bn-skeleton bn-comment-skeleton__line';
		sk.appendChild( skAvatar );
		sk.appendChild( skLine );
		listEl.appendChild( sk );
	}

	try {
		const res = yield restFetch(
			'/comments?object_type=post&object_id=' + ctx.postId + '&per_page=20&page=1',
			{ nonce: ctx.reactNonce, toastOnError: false }
		);
		while ( listEl.firstChild ) {
			listEl.removeChild( listEl.firstChild );
		}
		if ( res.ok ) {
			listEl.dataset.loaded = '1';
			listEl.dataset.page   = '1';
			bnRenderCommentPage( listEl, res.data, ctx );
		} else {
			const err = document.createElement( 'div' );
			err.className = 'bn-comment-error';
			err.setAttribute( 'role', 'alert' );
			err.textContent = t( 'commentsLoadFailed', 'Could not load comments. ' );
			const retry = document.createElement( 'button' );
			retry.type = 'button';
			retry.className = 'bn-comment-error__retry';
			retry.textContent = t( 'retry', 'Retry' );
			retry.addEventListener( 'click', () => {
				delete listEl.dataset.loaded;
				ctx.commentsOpen = false;
				setTimeout( () => { ctx.commentsOpen = true; }, 0 );
			} );
			err.appendChild( retry );
			listEl.appendChild( err );
		}
	} catch ( _e ) {
		while ( listEl.firstChild ) {
			listEl.removeChild( listEl.firstChild );
		}
		const err = document.createElement( 'div' );
		err.className = 'bn-comment-error';
		err.setAttribute( 'role', 'alert' );
		err.textContent = t( 'commentsNetworkError', 'Network error. Comments could not be loaded.' );
		listEl.appendChild( err );
	}
}

store( 'buddynext/post-card', {
	state: {
		// Reaction icon class — applied to the reaction button inner span to indicate current reaction type.
		get reactionIconClass() {
			try {
				// One generic "reacted" marker rather than a per-slug modifier: the
				// reacted mark itself (emoji image or glyph) is data-driven below, so
				// the class only needs to hide the idle heart and size the swap-in.
				return getContext().reactionType
					? 'bn-post-card__react-icon bn-post-card__react-icon--reacted'
					: 'bn-post-card__react-icon';
			} catch ( _e ) {
				return 'bn-post-card__react-icon';
			}
		},
		// CSS background-image for the reacted mark — the reaction's bundled Fluent
		// SVG (built-ins + any custom that ships one). Empty for a glyph-only custom
		// reaction, which the .bn-reaction-glyph span renders instead.
		get reactionIconUrl() {
			try {
				const meta = reactionMetaFor( getContext().reactionType );
				return meta && meta.emoji_url ? 'url("' + meta.emoji_url + '")' : '';
			} catch ( _e ) { return ''; }
		},
		// Letter glyph for a Pro custom reaction with no bundled SVG (else empty, so
		// the glyph span stays hidden and the SVG background-image is used).
		get reactionGlyphChar() {
			try {
				const meta = reactionMetaFor( getContext().reactionType );
				if ( ! meta || meta.emoji_url ) { return ''; }
				return meta.char || ( meta.label || meta.slug ).charAt( 0 ).toUpperCase();
			} catch ( _e ) { return ''; }
		},
		get reactionGlyphColor() {
			try {
				const meta = reactionMetaFor( getContext().reactionType );
				return ( meta && ! meta.emoji_url && /^#[0-9a-fA-F]{6}$/.test( meta.color ) ) ? meta.color : '';
			} catch ( _e ) { return ''; }
		},
		get showReactionPicker() {
			try { return !! getContext().reactionPickerOpen; } catch ( _e ) { return false; }
		},
		get reactionPickerClass() {
			const base = 'bn-post-card__emoji-picker';
			try {
				return getContext().reactionPickerBelow ? base + ' bn-post-card__emoji-picker--below' : base;
			} catch ( _e ) { return base; }
		},
		get bookmarked() {
			try { return !! getContext().bookmarked; } catch ( _e ) { return false; }
		},
		// The "Pinned" label shows only when the post is pinned AND this surface is
		// one a pin belongs to (profile / space). Keeps a profile- or space-pinned
		// post from claiming "Pinned" in the global feed, and stops a pin performed
		// from the home feed from surfacing the label there.
		get pinBadgeVisible() {
			try { const c = getContext(); return !! c.isPinned && !! c.showPinBadge; } catch ( _e ) { return false; }
		},
		get showContent() {
			try { return !! getContext().showContent; } catch ( _e ) { return true; }
		},
		get optionsOpen() {
			try { return !! getContext().optionsOpen; } catch ( _e ) { return false; }
		},
		get hasReported() {
			try { return !! getContext().hasReported; } catch ( _e ) { return false; }
		},
		get reactionType() {
			try { return getContext().reactionType || null; } catch ( _e ) { return null; }
		},
		// Label shown on the React button — the current reaction's name, or the
		// default "React" when the viewer has not reacted.
		get reactionLabel() {
			try {
				const ctx = getContext();
				return ctx.reactionLabel || ctx.reactDefaultLabel || t( 'react', 'React' );
			} catch ( _e ) { return t( 'react', 'React' ); }
		},
		get pollOptionPctText() {
			try {
				const ctx = getContext();
				const opt = ( ctx.pollOptions || [] ).find( ( o ) => o.id === ctx.optionId );
				return opt ? opt.pct + '%' : '0%';
			} catch ( _e ) { return '0%'; }
		},
		get pollFillStyle() {
			try {
				const ctx = getContext();
				const opt = ( ctx.pollOptions || [] ).find( ( o ) => o.id === ctx.optionId );
				return 'width:' + ( opt ? opt.pct : 0 ) + '%';
			} catch ( _e ) { return 'width:0%'; }
		},
		get pollOptionBtnClass() {
			try {
				const ctx     = getContext();
				const isVoted = ctx.pollVotedOptionId && ctx.pollVotedOptionId === ctx.optionId;
				return 'bn-post-card__poll-option' + ( isVoted ? ' is-voted' : '' );
			} catch ( _e ) { return 'bn-post-card__poll-option'; }
		},
		get pollTotalVotesText() {
			try {
				const n = getContext().pollTotalVotes || 0;
				return n === 1 ? t( 'oneVote', '1 vote' ) : fmt( t( 'manyVotes', '%d votes' ), n );
			} catch ( _e ) { return fmt( t( 'manyVotes', '%d votes' ), 0 ); }
		},
		get reactBtnClass() {
			try {
				return getContext().reactionType
					? 'bn-post-card__action-btn bn-post-card__action-btn--react is-reacted'
					: 'bn-post-card__action-btn bn-post-card__action-btn--react';
			} catch ( _e ) {
				return 'bn-post-card__action-btn bn-post-card__action-btn--react';
			}
		},
		get bodyClass() {
			try {
				return getContext().showContent
					? 'bn-post-card__body'
					: 'bn-post-card__body bn-post-card__body--blurred';
			} catch ( _e ) {
				return 'bn-post-card__body';
			}
		},
		get bookmarkBtnClass() {
			try {
				return getContext().bookmarked
					? 'bn-post-card__action-btn is-bookmarked'
					: 'bn-post-card__action-btn';
			} catch ( _e ) {
				return 'bn-post-card__action-btn';
			}
		},
		get commentsHidden() {
			try { return ! getContext().commentsOpen; } catch ( _e ) { return true; }
		},
		// "Who reacted" popover — single source is context.reactorsOpen.
		get reactorsHidden() {
			try { return ! getContext().reactorsOpen; } catch ( _e ) { return true; }
		},
		get reactorsExpanded() {
			try { return !! getContext().reactorsOpen; } catch ( _e ) { return false; }
		},
		get reactorsHeading() {
			try {
				const n = getContext().reactionCount || 0;
				return n === 1 ? t( 'oneReaction', '1 reaction' ) : fmt( t( 'manyReactions', '%d reactions' ), n );
			} catch ( _e ) { return ""; }
		},
		get shareBtnClass() {
			try {
				return getContext().shareShared
					? 'bn-post-card__action-btn is-shared'
					: 'bn-post-card__action-btn';
			} catch ( _e ) {
				return 'bn-post-card__action-btn';
			}
		},
		get shareLabel() {
			try {
				const ctx   = getContext();
				const count = ctx.shareCount || 0;
				if ( ctx.shareShared ) {
					return count > 0 ? fmt( t( 'sharedWithCount', 'Shared \u00b7 %d' ), count ) : t( 'shared', 'Shared' );
				}
				return count > 0 ? fmt( t( 'shareWithCount', 'Share \u00b7 %d' ), count ) : t( 'share', 'Share' );
			} catch ( _e ) {
				return t( 'share', 'Share' );
			}
		},
	},
	callbacks: {
		/**
		 * Fires on every post-card mount. Auto-loads the comment thread when the
		 * server seeded `commentsOpen` true — the single-post permalink
		 * `/p/{id}/`, where the thread is expanded by default. Every other
		 * surface seeds false, so this is a no-op there until the member clicks
		 * Comment.
		 *
		 * This guard made the whole callback DEAD CODE for a while: commentsOpen
		 * was hardcoded false on every surface, so the early return always fired
		 * and the body never ran — while this docblock went on describing the
		 * permalink behaviour as though it worked, which is worse than no comment
		 * at all. Both halves are live again. If the seed is ever pinned false
		 * everywhere, DELETE this callback rather than leave it reading as a
		 * working feature.
		 */
		* initPostCard() {
			const ctx = getContext();
			if ( ! ctx || ! ctx.commentsOpen ) {
				return;
			}
			// Defer one tick so the list element is mounted before fetch runs,
			// then delegate to the shared loader (same logic the click path
			// uses) instead of mirroring it inline.
			yield new Promise( ( r ) => setTimeout( r, 0 ) );
			yield* bnLoadComments( ctx );
		},
	},
	actions: {
		toggleReactionPicker() {
			const ctx     = getContext();
			const willOpen = ! ctx.reactionPickerOpen;

			// Collision-avoid the sticky header: the picker opens upward by
			// default, which paints over the fixed header when there isn't room
			// above the React trigger. Measure the actual sticky/fixed header
			// (heights vary by theme) and flip the picker to open downward when an
			// upward picker would cross into that band — standard popper flip.
			if ( willOpen ) {
				try {
					const ref  = getElement()?.ref || null;
					const rect = ref ? ref.getBoundingClientRect() : null;
					// Find the top sticky/fixed chrome by probing what paints at the
					// top edge, so we don't hard-code a header selector or height.
					let headerBottom = 0;
					const probe = document.elementsFromPoint
						? document.elementsFromPoint( Math.round( window.innerWidth / 2 ), 2 )
						: [];
					for ( const node of probe ) {
						const pos = getComputedStyle( node ).position;
						if ( 'fixed' === pos || 'sticky' === pos ) {
							headerBottom = Math.max( headerBottom, node.getBoundingClientRect().bottom );
						}
					}
					// Picker is a single row (~52px) plus an 8px gap. Flip below when
					// an upward picker would not clear the header band.
					ctx.reactionPickerBelow = !! rect && ( rect.top - 60 ) < ( headerBottom + 4 );

					// Clamp against the END edge, the other half of the same
					// problem. The picker is pinned to its trigger's start edge
					// on mobile, which fixes the START overflow but says nothing
					// about the right: Pro sites can register custom reaction
					// slugs, and a set well above the default 6 runs straight off
					// a 375px screen. The measurement lives in
					// bnClampPopoverToViewport() so the comment/reply picker --
					// a separate implementation that had no clamp at all -- runs
					// the same rule rather than a second copy of it.
					if ( rect ) {
						bnClampPopoverToViewport(
							ref.parentElement
								? ref.parentElement.querySelector( '.bn-post-card__emoji-picker' )
								: null
						);
					}
				} catch ( _e ) {
					ctx.reactionPickerBelow = false;
				}
			}

			ctx.reactionPickerOpen = willOpen;

			// Remember which picker is open and dismiss it on scroll so it never
			// floats over the sticky header once its card scrolls away.
			bnOpenReactionCtx = ctx.reactionPickerOpen ? ctx : null;
			// Remember where the page was when this picker opened, so the
			// focus-scroll the click itself causes cannot be mistaken for the
			// member scrolling away.
			bnOpenReactionScrollY = window.scrollY;
			if ( ! bnReactionScrollBound ) {
				bnReactionScrollBound = true;
				window.addEventListener(
					'scroll',
					() => {
						if ( ! bnOpenReactionCtx ) {
							return;
						}
						if ( Math.abs( window.scrollY - bnOpenReactionScrollY ) < BN_REACTION_SCROLL_CLOSE_PX ) {
							return;
						}
						bnOpenReactionCtx.reactionPickerOpen = false;
						bnOpenReactionCtx = null;
					},
					{ passive: true }
				);
			}
		},
		* setReaction( event ) {
			const ctx    = getContext();
			const optEl  = event.target.closest( '[data-reaction-type]' );
			// Capture the card now — after the async yield, the picker option may
			// be gone; the summary chip strip lives on this card element.
			const cardEl = event.target.closest( '.bn-post-card' );
			const type   = optEl?.dataset.reactionType || 'like';
			// The picker option carries the translated reaction label (title /
			// aria-label), so the React button label can mirror the icon without
			// duplicating the label map in JS.
			const label  = optEl?.getAttribute( 'title' ) || optEl?.getAttribute( 'aria-label' ) || type;

			ctx.reactionPickerOpen = false;
			const newType = ctx.reactionType === type ? null : type;
			const prev    = ctx.reactionType;
			const prevLbl = ctx.reactionLabel;

			// Optimistic update — apply immediately, revert on failure. The label
			// follows the type: the chosen reaction's name, or the default when
			// toggling the reaction off.
			ctx.reactionType  = newType;
			ctx.reactionLabel = newType ? label : ( ctx.reactDefaultLabel || 'React' );

			try {
				const res = yield restFetch( '/reactions/toggle', {
					method:  'POST',
					nonce:   ctx.reactNonce,
					toastOnError: false,
					body:    { object_type: 'post', object_id: ctx.postId, emoji: newType },
				} );
				if ( ! res.ok ) {
					ctx.reactionType  = prev; // Revert on failure.
					ctx.reactionLabel = prevLbl;
					// Previously this reverted in total silence, so the member's
					// reaction appeared to undo itself for no reason.
					reactionFailureToast( res, t( 'reactionUpdateFailed', 'Could not update your reaction. Try again.' ) );
				} else {
					// Rebuild the SSR-only reaction-summary chips from the
					// authoritative count + per-type breakdown so they reflect
					// the toggle without a page reload.
					updateReactionSummary( cardEl, res.data );
				}
			} catch ( _e ) {
				ctx.reactionType  = prev; // Revert on error.
				ctx.reactionLabel = prevLbl;
			}
		},
		* toggleBookmark() {
			const ctx    = getContext();
			const method = ctx.bookmarked ? 'DELETE' : 'POST';
			const prev   = ctx.bookmarked;
			ctx.bookmarked = ! prev;
			try {
				const res = yield restFetch( '/posts/' + ctx.postId + '/bookmark', {
					method,
					nonce: ctx.bookmarkNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( ctx.bookmarked ? t( 'saved', 'Saved' ) : t( 'removedFromSaved', 'Removed from saved' ) ); }
				} else {
					// Reverting the optimistic star with no message looks like a
					// stray click. Revert AND say why — the server's reason when it
					// gives one (the attempted action decides the fallback wording).
					const wanted = ! prev;
					ctx.bookmarked = prev;
					const data     = res.data || {};
					const fallback = wanted
						? t( 'bookmarkAddFailed', 'Could not save this post. Try again.' )
						: t( 'bookmarkRemoveFailed', 'Could not remove this post from saved. Try again.' );
					bnToast( data.message || fallback, { tone: 'danger' } );
				}
			} catch ( _e ) {
				const wanted   = ! prev;
				ctx.bookmarked = prev;
				bnToast(
					wanted
						? t( 'bookmarkAddFailed', 'Could not save this post. Try again.' )
						: t( 'bookmarkRemoveFailed', 'Could not remove this post from saved. Try again.' ),
					{ tone: 'danger' }
				);
			}
		},
		revealContent() {
			getContext().showContent = true;
		},
		toggleOptionsMenu() {
			const ctx      = getContext();
			ctx.optionsOpen = ! ctx.optionsOpen;
		},
		/**
		 * Toggle this card's "who reacted" popover. The panel is SSR-present
		 * (post-reaction-summary.php) and its visibility binds reactively to
		 * context.reactorsOpen (state.reactorsHidden), so this action only flips
		 * the flag and lazy-loads the reactor list once. Closing other cards'
		 * open popovers is handled by closePopups via the document binding.
		 *
		 * @param {MouseEvent} event The click event on the reactors trigger.
		 */
		* toggleReactors( event ) {
			const ctx      = getContext();
			const willOpen = ! ctx.reactorsOpen;
			ctx.reactorsOpen = willOpen;
			if ( ! willOpen || ctx.reactorsLoaded ) {
				return;
			}
			// Resolve the SSR list container scoped to THIS reactor wrap so a
			// feed of many cards never cross-fills. The trigger carries the
			// object id. The popover (and its list) is a SIBLING of the trigger
			// button inside .bn-post-card__reactors-wrap — getElement().ref is
			// the button itself, whose subtree does NOT contain the list, so
			// scope the lookup to the enclosing wrap instead.
			const trigger = event && event.target ? event.target.closest( '[data-bn-object-id]' ) : null;
			const ref     = getElement()?.ref || trigger || null;
			const wrap    = ref ? ref.closest( '.bn-post-card__reactors-wrap' ) : null;
			const listEl  = wrap ? wrap.querySelector( '.bn-reactors-popover__list' ) : null;
			if ( ! listEl ) {
				return;
			}
			const objectType = ( trigger && trigger.dataset.bnObjectType ) || 'post';
			const objectId   = ( trigger && trigger.dataset.bnObjectId ) || ctx.postId;
			ctx.reactorsLoaded = true;
			while ( listEl.firstChild ) { listEl.removeChild( listEl.firstChild ); }
			const loading = document.createElement( 'li' );
			loading.className = 'bn-reactors-popover__loading';
			loading.textContent = t( 'loading', 'Loading…' );
			listEl.appendChild( loading );
			try {
				const res = yield restFetch(
					'/reactions/list?object_type=' + encodeURIComponent( objectType ) + '&object_id=' + encodeURIComponent( objectId ) + '&limit=100',
					{ nonce: ctx.reactNonce, toastOnError: false }
				);
				while ( listEl.firstChild ) { listEl.removeChild( listEl.firstChild ); }
				if ( res.ok ) {
					const items = ( res.data && res.data.items ) || [];
					const total = ( res.data && res.data.total ) || items.length;
					ctx.reactionCount = total;
					const emojiBase = document.querySelector( '[data-emoji-base]' )?.dataset.emojiBase || '';
					items.forEach( ( r ) => listEl.appendChild( buildReactorRow( r, emojiBase ) ) );
				} else {
					const err = document.createElement( 'li' );
					err.className = 'bn-reactors-popover__error';
					err.textContent = t( 'reactionsLoadFailed', 'Could not load reactions. Try again.' );
					listEl.appendChild( err );
					ctx.reactorsLoaded = false;
				}
			} catch ( _e ) {
				while ( listEl.firstChild ) { listEl.removeChild( listEl.firstChild ); }
				const err = document.createElement( 'li' );
				err.className = 'bn-reactors-popover__error';
				err.textContent = t( 'reactionsLoadFailed', 'Could not load reactions. Try again.' );
				listEl.appendChild( err );
				ctx.reactorsLoaded = false;
			}
		},
		/**
		 * Dismiss this card's open popovers (reaction picker, options menu) when
		 * a click lands outside their trigger/popover. Bound to the document via
		 * data-wp-on-document--click on the card root, so it also closes a picker
		 * left open on another card when the viewer interacts elsewhere. Scoped to
		 * the current card through getElement().ref so each card only governs its
		 * own popovers.
		 *
		 * @param {MouseEvent} event The document click event.
		 */
		closePopups( event ) {
			const ctx = getContext();
			if ( ! ctx || ( ! ctx.reactionPickerOpen && ! ctx.optionsOpen && ! ctx.reactorsOpen ) ) {
				return;
			}
			const ref = getElement()?.ref || null;
			if ( ! ref ) {
				return;
			}
			if ( ctx.reactionPickerOpen ) {
				const reactWrap = ref.querySelector( '.bn-post-card__react-wrap' );
				if ( ! reactWrap || ! reactWrap.contains( event.target ) ) {
					ctx.reactionPickerOpen = false;
				}
			}
			if ( ctx.optionsOpen ) {
				const menuWrap = ref.querySelector( '.bn-post-card__menu-wrap' );
				if ( ! menuWrap || ! menuWrap.contains( event.target ) ) {
					ctx.optionsOpen = false;
				}
			}
			if ( ctx.reactorsOpen ) {
				const reactorsWrap = ref.querySelector( '.bn-post-card__reactors-wrap' );
				if ( ! reactorsWrap || ! reactorsWrap.contains( event.target ) ) {
					ctx.reactorsOpen = false;
				}
			}
		},
		/**
		 * Close this card's open popovers on the Escape key (keyboard a11y for the
		 * reaction picker toolbar and the options menu). Bound via
		 * data-wp-on-document--keydown on the card root.
		 *
		 * @param {KeyboardEvent} event The document keydown event.
		 */
		closePopupsOnEscape( event ) {
			if ( 'Escape' !== event.key ) {
				return;
			}
			const ctx = getContext();
			if ( ! ctx ) {
				return;
			}
			ctx.reactionPickerOpen = false;
			ctx.optionsOpen        = false;
			ctx.reactorsOpen       = false;
		},
		* deletePost() {
			const ctx = getContext();
			const ok = yield bnConfirm( {
				title: t( 'deletePostTitle', 'Delete this post?' ),
				body: t( 'cannotBeUndone', 'This cannot be undone.' ),
				confirmLabel: t( 'delete', 'Delete' ),
				tone: 'danger',
			} );
			if ( ! ok ) {
				return;
			}
			try {
				const res = yield restFetch( '/posts/' + ctx.postId, {
					method:  'DELETE',
					nonce:   ctx.reactNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					document.querySelector( '[data-post-id="' + ctx.postId + '"]' )?.remove();
				} else {
					// The card stays put on failure, which reads as "nothing
					// happened" — say why. Prefer the server's own reason (e.g. a
					// permission or moderation-lock message) over the generic one.
					const data = res.data || {};
					bnToast(
						data.message || t( 'postDeleteFailed', 'Could not delete this post. Try again.' ),
						{ tone: 'danger' }
					);
				}
			} catch ( _e ) {
				bnToast( t( 'postDeleteFailed', 'Could not delete this post. Try again.' ), { tone: 'danger' } );
			}
		},
		openShare( event ) {
			const ctx       = getContext();
			const btn       = event && event.target ? event.target.closest( '[data-post-id]' ) : null;
			const permalink = btn ? ( btn.getAttribute( 'data-post-permalink' ) || '' ) : '';

			// Pull a lightweight preview (author + excerpt) from the source card
			// so the repost modal shows what is being shared. The clicked button
			// lives inside its post-card article — read the byline + body text
			// straight from the DOM rather than threading more data through PHP.
			let author  = '';
			let excerpt = '';
			const card  = btn ? btn.closest( '[data-wp-interactive="buddynext/post-card"]' ) : null;
			if ( card ) {
				const nameEl = card.querySelector( '.bn-post-card__author-name' );
				author = nameEl ? ( nameEl.textContent || '' ).trim() : '';
				const contentEl = card.querySelector( '.bn-post-card__content' );
				if ( contentEl ) {
					excerpt = ( contentEl.textContent || '' ).trim().replace( /\s+/g, ' ' );
					if ( excerpt.length > 160 ) {
						excerpt = excerpt.slice( 0, 159 ).trimEnd() + '\u2026';
					}
				}
			}

			// Dispatch into the global share-modal store via a custom event.
			document.dispatchEvent(
				new CustomEvent( 'bn-open-share-modal', {
					detail: {
						postId:    ctx.postId,
						permalink,
						author,
						excerpt,
						nonce:     ctx.shareNonce,
						restUrl:   ctx.restUrl,
					},
				} )
			);
		},
		* repostFromCard() {
			// Optimistic-share fallback retained for keyboard / unit-test paths.
			const ctx = getContext();
			const prevCount = ctx.shareCount || 0;
			ctx.shareCount  = prevCount + 1;
			ctx.shareShared = true;
			try {
				const res = yield restFetch( '/posts/' + ctx.postId + '/share', {
					method:  'POST',
					nonce:   ctx.shareNonce,
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					ctx.shareCount  = prevCount;
					ctx.shareShared = false;
					// Surface the server's reason (e.g. already_shared) instead of
					// failing silently — the guard is correct, the UX was mute.
					const msg = ( res.data && res.data.message ) || t( 'shareFailed', 'Could not share this post. Try again.' );
					if ( window.bnToast ) { window.bnToast( msg, 'error' ); }
				}
			} catch ( _e ) {
				ctx.shareCount  = prevCount;
				ctx.shareShared = false;
				if ( window.bnToast ) { window.bnToast( t( 'shareFailed', 'Could not share this post. Try again.' ), 'error' ); }
			}
		},
		* reportPost() {
			const ctx    = getContext();
			const result = yield bnReportDialog( {
				title: t( 'reportPost', 'Report this post' ),
			} );
			if ( result === null ) {
				return;
			}
			try {
				const res = yield restFetch( '/reports', {
					method:  'POST',
					nonce:   ctx.reportNonce,
					toastOnError: false,
					body:    {
						object_type: 'post',
						object_id:   ctx.postId,
						reason:      result.reason,
						notes:       result.notes,
						// Tag the report with the post's space so space moderators
						// see it in their scoped queue (0 = global feed).
						space_id:    parseInt( ctx.spaceId, 10 ) || 0,
					},
				} );
				// A 409 (already reported) is an expected outcome, not a failure — the
				// server already holds this user's report, so the post is "reported"
				// either way. Treat success and already-reported identically.
				const alreadyReported = res.status === 409;
				if ( res.ok || res.status === 201 || alreadyReported ) {
					// Reflect the reported state immediately so the action menu
					// swaps Report for a disabled "Reported" item without a reload.
					ctx.hasReported = true;
					ctx.optionsOpen = false;

					// Drop the post out of the reporter's own feed straight away —
					// what a member reports, they expect to stop seeing (the norm on
					// Facebook, X, Instagram). Collapse with a short fade so the removal
					// reads as intentional rather than a glitch.
					const card = document.querySelector( '[data-post-id="' + ctx.postId + '"]' );
					if ( card ) {
						card.style.transition = 'opacity 0.2s ease, max-height 0.3s ease';
						card.style.overflow   = 'hidden';
						card.style.maxHeight  = card.offsetHeight + 'px';
						requestAnimationFrame( () => {
							card.style.opacity   = '0';
							card.style.maxHeight = '0';
						} );
						setTimeout( () => card.remove(), 350 );
					}

					bnToast(
						alreadyReported
							? ( ( res.data && res.data.message ) || t( 'reportAlready', 'You already reported this post.' ) )
							: t( 'reportSubmitted', 'Report submitted. Thanks for keeping the community safe.' ),
						{ tone: alreadyReported ? 'info' : 'success' }
					);
				} else {
					// Surface the server's reason instead of a generic failure.
					const data = res.data || {};
					bnToast(
						data.message || t( 'reportFailed', 'Could not submit report. Try again.' ),
						{ tone: 'danger' }
					);
				}
			} catch ( _e ) {
				bnToast( t( 'reportFailed', 'Could not submit report. Try again.' ), { tone: 'danger' } );
			}
		},
		* editPost() {
			const ctx     = getContext();
			// Close the kebab menu as soon as Edit is chosen — entering edit mode
			// is a committed action, so the dropdown should not linger open.
			ctx.optionsOpen = false;
			const element = getElement();
			const card    = element && element.ref ? element.ref.closest( '.bn-post-card' ) : null;
			if ( ! card ) {
				return;
			}

			// One editor at a time per card.
			if ( card.querySelector( '.bn-post-card__edit-form' ) ) {
				return;
			}

			const contentEl = card.querySelector( '.bn-post-card__content' );
			if ( ! contentEl ) {
				bnToast( t( 'postNotEditable', 'This post cannot be edited.' ), { tone: 'info' } );
				return;
			}

			// Pull the raw (unformatted) content so the editor shows exactly what the
			// author typed — the rendered node has nl2br/mention/hashtag markup baked in.
			let rawContent  = '';
			let isScheduled = false;
			let schedUtc    = '';
			try {
				const res = yield restFetch( '/posts/' + ctx.postId, {
					nonce: ctx.reactNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					const data = res.data;
					rawContent = ( data && typeof data.content === 'string' ) ? data.content : '';
					// The same GET already carries the row's status + slot, so the reschedule
					// control costs no extra request.
					isScheduled = !! data && 'scheduled' === data.status;
					schedUtc    = ( data && data.scheduled_at ) ? String( data.scheduled_at ) : '';
				}
			} catch ( _e ) {
				// Fall back to the visible text if the fetch fails.
			}
			if ( '' === rawContent ) {
				rawContent = ( contentEl.textContent || '' ).trim();
			}

			const form = document.createElement( 'div' );
			form.className = 'bn-post-card__edit-form';

			const ta = document.createElement( 'textarea' );
			ta.className = 'bn-post-card__edit-input';
			ta.rows = 3;
			ta.value = rawContent;
			ta.setAttribute( 'aria-label', t( 'editPostContent', 'Edit post content' ) );
			form.appendChild( ta );

			// Reschedule. A scheduled post's date was previously final: the author could
			// rewrite the words but never move the slot, with no way to reach it short of
			// deleting the post and writing it again.
			let schedInput = null;
			if ( isScheduled ) {
				const schedRow = document.createElement( 'div' );
				schedRow.className = 'bn-post-card__edit-schedule';

				const schedLabel = document.createElement( 'label' );
				schedLabel.className = 'bn-post-card__edit-schedule-label';
				const tzLabel = ( store( 'buddynext/feed' ).state && store( 'buddynext/feed' ).state.tz && store( 'buddynext/feed' ).state.tz.label ) || '';
				schedLabel.textContent = tzLabel
					? fmt( t( 'scheduledForTz', 'Scheduled for (%s)' ), tzLabel )
					: t( 'scheduledFor', 'Scheduled for' );
				schedLabel.htmlFor = 'bn-resched-' + ctx.postId;

				schedInput = document.createElement( 'input' );
				schedInput.type = 'datetime-local';
				schedInput.id = 'bn-resched-' + ctx.postId;
				schedInput.className = 'bn-post-card__edit-schedule-input';
				schedInput.value = toSiteInputValue( schedUtc );
				schedInput.min = siteNowInputValue();

				schedRow.appendChild( schedLabel );
				schedRow.appendChild( schedInput );
				form.appendChild( schedRow );
			}

			// Link preview — offer REMOVAL only.
			//
			// The edit form was a bare textarea, so a preview attached to the wrong
			// link (or one whose remote page had since changed) was permanent unless
			// the author deleted the whole post. This shows the existing card with a
			// dismiss control, matching Facebook's edit dialog.
			//
			// Removal only, on purpose: Facebook withdrew the ability to EDIT a
			// preview's headline and description because it let a post misrepresent
			// what it linked to. Taking the card off misrepresents nothing, so that
			// is the whole feature. To change a preview, edit the URL and post again.
			const previewEl = card.querySelector( '.bn-post-card__link-preview' );
			let removePreview = false;
			if ( previewEl ) {
				const previewRow = document.createElement( 'div' );
				previewRow.className = 'bn-post-card__edit-preview';

				const previewLabel = document.createElement( 'span' );
				previewLabel.className = 'bn-post-card__edit-preview-label';
				previewLabel.textContent = t( 'linkPreviewAttached', 'Link preview attached' );

				const removeBtn = document.createElement( 'button' );
				removeBtn.type = 'button';
				removeBtn.className = 'bn-btn';
				removeBtn.dataset.variant = 'ghost';
				removeBtn.dataset.size = 'sm';
				removeBtn.textContent = t( 'removeLinkPreview', 'Remove link preview' );

				removeBtn.addEventListener( 'click', () => {
					removePreview = true;
					// Show the consequence immediately; the card is only really gone
					// once Save succeeds, and Cancel restores it via teardown().
					previewEl.hidden = true;
					previewRow.hidden = true;
				} );

				previewRow.appendChild( previewLabel );
				previewRow.appendChild( removeBtn );
				form.appendChild( previewRow );
			}

			const bar = document.createElement( 'div' );
			bar.className = 'bn-post-card__edit-actions';

			const saveBtn = document.createElement( 'button' );
			saveBtn.type = 'button';
			saveBtn.className = 'bn-btn';
			saveBtn.dataset.variant = 'primary';
			saveBtn.dataset.size = 'sm';
			saveBtn.textContent = t( 'save', 'Save' );

			const cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'bn-btn';
			cancelBtn.dataset.variant = 'ghost';
			cancelBtn.dataset.size = 'sm';
			cancelBtn.textContent = t( 'cancel', 'Cancel' );

			bar.appendChild( saveBtn );
			bar.appendChild( cancelBtn );
			form.appendChild( bar );

			contentEl.hidden = true;
			contentEl.parentNode.insertBefore( form, contentEl.nextSibling );
			ta.focus();

			const teardown = () => {
				form.remove();
				contentEl.hidden = false;
			};

			cancelBtn.addEventListener( 'click', () => {
				// Hiding the preview is only a preview of the consequence — nothing
				// was saved, so cancelling has to put it back.
				if ( previewEl && removePreview ) {
					previewEl.hidden = false;
					removePreview    = false;
				}
				teardown();
			} );

			saveBtn.addEventListener( 'click', async () => {
				const next = ta.value.trim();
				if ( '' === next ) {
					bnToast( t( 'postContentEmpty', 'Post content cannot be empty.' ), { tone: 'info' } );
					return;
				}
				const payload = { content: next };
				if ( removePreview ) {
					payload.remove_link_preview = true;
				}
				if ( schedInput ) {
					const when = toUtcSqlDatetime( schedInput.value );
					if ( ! when ) {
						bnToast( t( 'scheduleInvalid', 'Pick a valid date and time.' ), { tone: 'info' } );
						return;
					}
					// `when` is already UTC, so compare instants — not the browser's wall clock.
					if ( new Date( when.replace( ' ', 'T' ) + 'Z' ).getTime() <= Date.now() ) {
						bnToast( t( 'schedulePast', 'Pick a time in the future.' ), { tone: 'info' } );
						return;
					}
					payload.scheduled_at = when;
				}
				saveBtn.disabled = true;
				try {
					const res = await restFetch( '/posts/' + ctx.postId, {
						method:  'PUT',
						nonce:   ctx.reactNonce,
						toastOnError: false,
						body:    payload,
					} );
					if ( ! res.ok ) {
						throw new Error( 'update failed' );
					}
					// Reflect the saved text immediately (line breaks preserved). Full
					// mention/hashtag formatting re-applies on the next page load.
					contentEl.textContent = next;
					// The server has cleared link_url/link_meta, so drop the card for
					// real rather than leaving it hidden until the next page load.
					if ( previewEl && removePreview ) {
						previewEl.remove();
					}
					if ( ! card.querySelector( '.bn-post-card__edited' ) ) {
						const mark = document.createElement( 'span' );
						mark.className = 'bn-post-card__edited';
						mark.textContent = t( 'editedSpaced', ' (edited)' );
						contentEl.appendChild( mark );
					}
					teardown();
					bnToast( t( 'postUpdated', 'Post updated' ), { tone: 'success' } );
				} catch ( _e ) {
					saveBtn.disabled = false;
					bnToast( t( 'postUpdateFailed', 'Could not update the post. Try again.' ), { tone: 'danger' } );
				}
			} );
		},
		* pinPost() {
			const ctx  = getContext();
			const prev = ! ! ctx.isPinned;
			// Optimistically flip; `prev` decides the verb (pin -> POST, unpin -> DELETE).
			ctx.isPinned = ! prev;
			try {
				const res = yield restFetch( '/posts/' + ctx.postId + '/pin', {
					method:  prev ? 'DELETE' : 'POST',
					nonce:   ctx.reactNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					bnToast( ctx.isPinned ? t( 'postPinned', 'Post pinned' ) : t( 'postUnpinned', 'Post unpinned' ), { tone: 'success' } );
				} else {
					ctx.isPinned = prev;
					let message = prev ? t( 'postUnpinFailed', 'Could not unpin this post. Try again.' ) : t( 'postPinFailed', 'Could not pin this post. Try again.' );
					try {
						const data = res.data;
						if ( data && data.message ) {
							message = data.message;
						}
					} catch ( _err ) {
						// Non-JSON body - keep the generic fallback message.
					}
					bnToast( message, { tone: 'danger' } );
				}
			} catch ( _e ) {
				ctx.isPinned = prev;
				bnToast( t( 'pinStatusFailed', 'Could not change pin status. Try again.' ), { tone: 'danger' } );
			}
		},
		* unpinPinnedFromStrip() {
			// Unpin from the compact pinned strip (space feed). The strip and the
			// feed are server-rendered and the post moves between them on unpin, so
			// reload on success to reflect the new placement.
			const ctx = getContext();
			try {
				const res = yield restFetch( '/posts/' + ctx.postId + '/pin', {
					method:  'DELETE',
					nonce:   ctx.reactNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					bnToast( t( 'postUnpinned', 'Post unpinned' ), { tone: 'success' } );
					window.location.reload();
				} else {
					let message = t( 'postUnpinFailed', 'Could not unpin this post. Try again.' );
					if ( res.data && res.data.message ) {
						message = res.data.message;
					}
					bnToast( message, { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'pinStatusFailed', 'Could not change pin status. Try again.' ), { tone: 'danger' } );
			}
		},
		* loadComments() {
			yield* bnLoadComments( getContext() );
		},
		* openComments() {
			const ctx        = getContext();
			ctx.commentsOpen = ! ctx.commentsOpen;
			if ( ! ctx.commentsOpen ) {
				return;
			}
			// Delegate to the shared module-level generator (see bnLoadComments)
			// — `this.loadComments()` / `actions.loadComments()` both fail in
			// the Interactivity API runtime (undefined `this`; wrapped action
			// not yield*-iterable), which left the thread stuck on skeletons.
			yield* bnLoadComments( ctx );
		},
		* submitComment() {
			const ctx     = getContext();
			const inputEl = document.querySelector( '[data-comment-input="' + ctx.postId + '"]' );
			const content = inputEl?.value.trim() || '';
			if ( ! content ) {
				return;
			}

			// Block re-entry while a submit is in flight. Without this, rapid clicks
			// on the send button each fire their own POST /comments before the first
			// resolves, posting the same comment several times. The flag is set
			// synchronously here — before the first yield — so the next click sees it
			// and returns; the send button also binds its disabled state to it.
			if ( ctx.commentSubmitting ) {
				return;
			}
			ctx.commentSubmitting = true;

			// Helper: render an inline alert above the comment textarea.
			const showInlineError = ( msg, code, appealUrl ) => {
				if ( ! inputEl ) {
					return;
				}
				const formEl  = inputEl.closest( '.bn-comment-form' );
				const parent  = formEl?.parentElement;
				if ( ! parent ) {
					return;
				}
				let alertEl = parent.querySelector( '.bn-comment-submit-error' );
				if ( ! alertEl ) {
					alertEl = document.createElement( 'div' );
					alertEl.className = 'bn-comment-submit-error';
					alertEl.setAttribute( 'role', 'alert' );
					parent.insertBefore( alertEl, formEl );
				}
				while ( alertEl.firstChild ) {
					alertEl.removeChild( alertEl.firstChild );
				}
				const msgSpan = document.createElement( 'span' );
				msgSpan.textContent = msg;
				const retry = document.createElement( 'button' );
				retry.type = 'button';
				retry.className = 'bn-comment-submit-error__retry';
				retry.textContent = t( 'retry', 'Retry' );
				retry.addEventListener( 'click', () => {
					alertEl.remove();
					// Re-fire submitComment by clicking the submit button.
					const submitBtn = formEl?.querySelector( '[data-wp-on--click="actions.submitComment"]' );
					submitBtn?.click();
				} );
				alertEl.appendChild( msgSpan );
				// A verification block (403 email_unverified) cannot succeed on
				// retry until the member verifies, so offer a Resend link instead
				// of a dead Retry - mirroring the composer's affordance.
				if ( 'email_unverified' === code ) {
					appendResendVerifyButton( alertEl, ctx.reactNonce );
				} else if ( appealUrl ) {
					// A suspension cannot be retried away. Offer the appeal page
					// instead of a control that is guaranteed to fail.
					appendAppealLink( alertEl, appealUrl );
				} else {
					alertEl.appendChild( retry );
				}
			};

			try {
				const res = yield restFetch( '/comments', {
					method:  'POST',
					nonce:   ctx.reactNonce,
					toastOnError: false,
					body:    { object_type: 'post', object_id: ctx.postId, content },
				} );
				if ( res.ok ) {
					// Clear any stale error alert from a previous failed attempt.
					inputEl?.closest( '.bn-post-card__comments' )?.querySelector( '.bn-comment-submit-error' )?.remove();
					const comment       = res.data;
					comment.author_name = comment.author_name || t( 'you', 'You' );
					const listEl        = document.querySelector( '[data-comment-list="' + ctx.postId + '"]' );
					if ( listEl ) {
						listEl.dataset.loaded = '1';
						listEl.appendChild( buildCommentNode( comment, ctx.currentUserId, ctx.postId, ctx.restUrl, ctx.reactNonce, 0 ) );
					}
					clearField( inputEl );
					adjustCommentCount( ctx.postId, 1 );
					if ( window.bnToast ) { window.bnToast( t( 'commentAdded', 'Comment added' ) ); }
				} else {
					// Surface the server's actual reason — create() now preserves
					// the real status/message (e.g. suspended 403, rate-limited 429)
					// instead of flattening to a generic 400. Fall back only when
					// the response carries no message.
					showInlineError(
						( res.data && res.data.message ) ? String( res.data.message ) : t( 'commentPostFailed', 'Could not post your comment. Try again.' ),
						res.data && res.data.code,
						res.data && res.data.data && res.data.data.appeal_url
					);
				}
			} catch ( _e ) {
				showInlineError( t( 'networkError', 'Network error. Try again.' ) );
			} finally {
				ctx.commentSubmitting = false;
			}
		},
		* votePoll( event ) {
			const ctx      = getContext();
			const optionId = parseInt( event.target.closest( '[data-option-id]' )?.dataset.optionId || '0', 10 );
			if ( ! optionId ) {
				return;
			}
			try {
				const res = yield restFetch( '/posts/' + ctx.postId + '/vote', {
					method:  'POST',
					nonce:   ctx.pollNonce,
					toastOnError: false,
					body:    { option_id: optionId },
				} );
				if ( res.ok ) {
					const data = res.data;
					if ( window.bnToast ) { window.bnToast( t( 'voteRecorded', 'Vote recorded' ) ); }
					if ( data.results ) {
						const total = data.results.reduce( ( s, r ) => s + r.vote_count, 0 );
						ctx.pollTotalVotes    = total;
						ctx.pollVotedOptionId = optionId;
						ctx.pollOptions       = data.results.map( ( r ) => ( {
							id:    r.id,
							text:  r.option_text,
							votes: r.vote_count,
							pct:   total > 0 ? Math.round( ( r.vote_count / total ) * 100 ) : 0,
						} ) );
					}
				} else {
					// A closed / rejected poll answers with a real reason ("This
					// poll has closed.", "You have already voted."). Dropping it
					// left the option looking simply unclickable — surface it.
					const data = res.data || {};
					bnToast(
						data.message || t( 'voteFailed', 'Could not record your vote. Try again.' ),
						{ tone: 'danger' }
					);
				}
			} catch ( _e ) {
				bnToast( t( 'voteFailed', 'Could not record your vote. Try again.' ), { tone: 'danger' } );
			}
		},
		* dismissAnnouncement() {
			const ctx = getContext();
			let res = null;
			try {
				res = yield restFetch( '/feed/announcements/' + ctx.postId + '/dismiss', {
					method:  'POST',
					nonce:   ctx.dismissNonce,
					toastOnError: false,
				} );
			} catch ( _e ) {
				res = null;
			}
			// Only hide the card once the server has RECORDED the dismissal.
			// Removing it optimistically (as before) made a failed write — a stale
			// nonce, a transient 5xx — look dismissed while bn_dismissed_announcements
			// got no row, so the announcement silently reappeared on the next reload
			// and no error was ever shown. Scope the removal to THIS announcement's
			// post id so a second announcement on the page is not collaterally hidden.
			if ( res && res.ok ) {
				document.querySelector(
					'.bn-post-card--announcement[data-post-id="' + ctx.postId + '"]'
				)?.remove();
			} else {
				bnToast(
					t( 'announcementDismissFailed', 'Could not dismiss this announcement. Please try again.' ),
					{ tone: 'danger' }
				);
			}
		},
		// Admin-only: end the announcement for everyone (expire its pin now).
		* endAnnouncement() {
			const ctx = getContext();
			try {
				const res = yield restFetch( '/feed/announcements/' + ctx.postId + '/end', {
					method:  'POST',
					nonce:   ctx.dismissNonce,
					toastOnError: false,
				} );
				// Only drop the banner once the server confirms the end —
				// removing it on a 403/404/500 gave a false sense of success.
				if ( res.ok ) {
					document.querySelector( '.bn-post-card--announcement' )?.remove();
					bnToast( t( 'announcementEnded', 'Announcement ended' ), { tone: 'success' } );
				} else {
					bnToast( t( 'announcementEndFailed', 'Could not end the announcement. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'announcementEndFailed', 'Could not end the announcement. Try again.' ), { tone: 'danger' } );
			}
		},
	},
} );
