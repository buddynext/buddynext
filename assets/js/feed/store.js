/* BuddyNext — Feed Interactivity API store. */
import { store, getContext, getElement } from '@wordpress/interactivity';
import { bnConfirm, bnPrompt, bnReportDialog, bnToast } from '@buddynext/shell-dialog';
import { restFetch } from '@buddynext/rest-client';
import { onNavReady } from '@buddynext/nav-init';
import { t, fmt, setI18N, setTz, bnApplyFilters, siteTzOffset, clearField, autoResizeTextarea, toUtcSqlDatetime, toSiteInputValue, siteNowInputValue } from './shared.js';

// Store concerns split into their own files by responsibility. Side-effect
// imports: each registers its namespace when this module loads, so they load
// exactly where @buddynext/feed is enqueued — the file moved, the loading did not.
import './tabs.js';
import './share-modal.js';
import './composer.js';

/* -- i18n -------------------------------------------------------------- */
/* t(), fmt() and the shared i18n table now live in ./shared.js so every split
 * store file (tabs.js, share-modal.js, …) reads one instance. The dictionary is
 * still read once from the buddynext/feed state below and handed to setI18N(). */

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
 * Escape a string for safe interpolation into innerHTML. Used where a string
 * (e.g. a user display name) has to go through innerHTML rather than
 * textContent — escaping the five HTML-significant characters prevents the
 * value from being parsed as markup.
 *
 * @param {string} str Raw value.
 * @return {string} HTML-escaped value.
 */
function escapeHtml( str ) {
	return String( str == null ? '' : str ).replace(
		/[&<>"']/g,
		( ch ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ ch ] )
	);
}

/**
 * Shift an absolutely-positioned popover back inside the viewport's end edge.
 *
 * CSS alone cannot do this: these pickers are absolutely positioned inside a
 * narrow wrapper and pinned to their trigger's start edge, so a viewport-relative
 * max-width still starts wherever the trigger happens to sit. Measure after paint
 * and shift back by exactly the overrun, never past the start edge.
 *
 * Extracted from the post-card reaction picker, which had this logic inline. The
 * comment/reply reaction picker is a separate implementation
 * (.bn-comment__react-picker, built in buildCommentNode) and never had it, so on
 * a narrow screen a picker on an indented reply ran straight off the right edge
 * with the later reactions unreachable and no scroll affordance. Two pickers, one
 * rule -- do not write a third copy.
 *
 * @param {Element|null} el Popover element, already visible. A hidden element has no measurable box.
 * @return {void}
 */
function bnClampPopoverToViewport( el ) {
	if ( ! el ) {
		return;
	}

	el.style.removeProperty( 'transform' );
	requestAnimationFrame( () => {
		const box    = el.getBoundingClientRect();
		const gutter = 12;
		const over   = box.right - ( window.innerWidth - gutter );
		if ( over > 0 ) {
			const shift = Math.min( over, Math.max( 0, box.left - gutter ) );
			el.style.transform = 'translateX(-' + Math.round( shift ) + 'px)';
		}
	} );
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
		const emoji = row.emoji
			? String( row.emoji )
			: '<span class="bn-post-card__reaction-fallback">' + escapeHtml( row.slug ) + '</span>';
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
				const ctx  = getContext();
				const type = ctx.reactionType;
				return type
					? 'bn-post-card__react-icon bn-post-card__react-icon--' + type
					: 'bn-post-card__react-icon';
			} catch ( _e ) {
				return 'bn-post-card__react-icon';
			}
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
		 * Fires on every post-card mount. Auto-loads the comment thread when
		 * the server seeded `commentsOpen` true (e.g. on the single-post page
		 * `/p/{id}/` where the thread should be expanded by default). On the
		 * home feed the seeded value is false so this becomes a no-op until
		 * the user clicks Comment.
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
				const tzLabel = ( feedStore.state && feedStore.state.tz && feedStore.state.tz.label ) || '';
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
			try {
				yield restFetch( '/feed/announcements/' + ctx.postId + '/dismiss', {
					method:  'POST',
					nonce:   ctx.dismissNonce,
					toastOnError: false,
				} );
			} catch ( _e ) {}
			document.querySelector( '.bn-post-card--announcement' )?.remove();
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

/* ── Infinite-scroll trigger for the home + explore feeds ──────────────────
   Watches every `[data-bn-infinite-feed]` sentinel. When it scrolls into the
   IntersectionObserver root margin, the next page is fetched as pre-rendered
   HTML from the matching `/feed/{scope}/page` endpoint and appended to the
   feed list — no full-page reload, no client-side card duplication.

   Required data attributes on the sentinel:
	 data-bn-infinite-feed   "home" | "explore"  (scope identifier)
	 data-bn-feed-target     CSS selector for the container to append into
	 data-next-cursor        Server-issued cursor for the next page
	 data-rest-url           Absolute URL of the page endpoint
	 data-rest-nonce         Valid wp_rest nonce
	 data-filter             (home only) active filter tab
	 data-per-page           Items per page

   The response HTML is generated server-side by render_items_html() in
   FeedController which delegates to the canonical partials/post-card.php
   template — the same escape-on-output pipeline that produces first-paint
   cards. The payload is parsed via DOMParser (an inert parser per HTML5
   spec — <script> elements are NOT executed) and each parsed node is then
   appended individually using createElement/appendChild semantics. This
   matches WPCS escape-on-output guarantees. */
( function () {
	function buildUrl( base, params ) {
		var separator = base.indexOf( '?' ) === -1 ? '?' : '&';
		var qs        = Object.keys( params )
			.filter( function ( k ) { return params[ k ] != null && params[ k ] !== ''; } )
			.map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
			} )
			.join( '&' );
		return qs ? base + separator + qs : base;
	}

	function showSpinner( trigger, show ) {
		var spinner = trigger.querySelector( '.bn-load-more__spinner' );
		if ( spinner ) {
			spinner.hidden = ! show;
		}
	}

	function appendParsedHtml( listEl, htmlString ) {
		// DOMParser produces a fully-inert document (HTML5 spec — scripts are
		// not executed). Each parsed body child node is then moved into the
		// live list via appendChild, which preserves element identity without
		// triggering any HTML-string parser on a live node.
		var doc   = new DOMParser().parseFromString( htmlString, 'text/html' );
		var nodes = Array.prototype.slice.call( doc.body.childNodes );
		for ( var i = 0; i < nodes.length; i++ ) {
			listEl.appendChild( nodes[ i ] );
		}
	}

	function replaceWithEndMarker( trigger ) {
		var endMarker = document.createElement( 'div' );
		endMarker.className = 'bn-feed-end';
		endMarker.setAttribute( 'role', 'status' );
		var text = document.createElement( 'span' );
		text.className   = 'bn-feed-end__text';
		text.textContent = ( window.bnI18n && window.bnI18n.feedEnd ) || t( 'feedEnd', "You've reached the end." );
		endMarker.appendChild( text );
		if ( trigger.parentNode ) {
			trigger.parentNode.replaceChild( endMarker, trigger );
		}
	}

	function showError( trigger, restartFn ) {
		// Inline retry control — lets the user recover without a page reload.
		while ( trigger.firstChild ) {
			trigger.removeChild( trigger.firstChild );
		}
		var btn = document.createElement( 'button' );
		btn.type            = 'button';
		btn.className       = 'bn-btn bn-load-more__btn';
		btn.dataset.variant = 'secondary';
		btn.textContent     = ( window.bnI18n && window.bnI18n.feedRetry ) || t( 'retry', 'Retry' );
		btn.addEventListener( 'click', function () {
			trigger.removeChild( btn );
			restartFn();
		} );
		trigger.appendChild( btn );
	}

	function fetchNextPage( trigger, observer ) {
		var cursor    = trigger.dataset.nextCursor || '';
		var restUrl   = trigger.dataset.restUrl || '';
		var restNonce = trigger.dataset.restNonce || '';
		var perPage   = trigger.dataset.perPage || '';
		var filter    = trigger.dataset.filter || '';
		var target    = trigger.dataset.bnFeedTarget || '';

		if ( ! cursor || ! restUrl ) {
			observer.disconnect();
			replaceWithEndMarker( trigger );
			return;
		}

		var listEl = target ? document.querySelector( target ) : null;
		if ( ! listEl ) {
			observer.disconnect();
			return;
		}

		showSpinner( trigger, true );

		var params = { cursor: cursor };
		if ( perPage ) { params.per_page = perPage; }
		if ( filter )  { params.filter = filter; }

		restFetch( buildUrl( restUrl, params ), { nonce: restNonce, toastOnError: false } )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
				var data = res.data;
				showSpinner( trigger, false );

				var html = ( data && typeof data.html === 'string' ) ? data.html : '';
				if ( html ) {
					appendParsedHtml( listEl, html );
				}

				if ( data && data.next_cursor ) {
					trigger.dataset.nextCursor = data.next_cursor;
				} else {
					observer.disconnect();
					replaceWithEndMarker( trigger );
				}
			} )
			.catch( function () {
				showSpinner( trigger, false );
				observer.disconnect();
				// Allow the manual retry to re-attach a fresh observer.
				delete trigger.dataset.bnInfiniteWired;
				showError( trigger, function () { startObserver( trigger ); } );
			} );
	}

	function startObserver( trigger ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			return;
		}
		// Guard so a trigger that survives a client-side navigation isn't
		// observed twice (a duplicate observer would double-fetch the next
		// page). The error-retry path clears the flag before re-calling.
		if ( trigger.dataset.bnInfiniteWired ) {
			return;
		}
		trigger.dataset.bnInfiniteWired = '1';

		var loading = false;

		var observer = new IntersectionObserver( function ( entries ) {
			if ( ! entries[ 0 ].isIntersecting || loading ) {
				return;
			}
			loading = true;
			fetchNextPage( trigger, observer );
			// Reset the in-flight flag after a short tick so subsequent
			// intersects can chain — the cursor will be refreshed by then.
			setTimeout( function () { loading = false; }, 250 );
		}, { rootMargin: '400px' } );

		observer.observe( trigger );
	}

	function init() {
		var triggers = document.querySelectorAll( '[data-bn-infinite-feed]' );
		if ( ! triggers.length ) {
			return;
		}
		Array.prototype.forEach.call( triggers, function ( trigger ) {
			startObserver( trigger );
		} );
	}

	onNavReady( init );
} )();

// The post-card `openShare` dispatches a `bn-open-share-modal` document event;
// the share-modal store receives it via its `data-wp-on-document--` directive
// (actions.receiveOpen), which runs inside the store so it can write the LIVE
// context. (A plain document listener here could only mutate the inert
// data-wp-context attribute, leaving the reactive store's postId at 0.)

/* ── Explore facet chips + search bar (buddynext/feed namespace) ──────────
 *
 * The explore template binds chips to actions.setFilter and the search input
 * to actions.onSearch under the buddynext/feed namespace. These wire facet
 * clicks to the search results page so chips actually filter, and the search
 * input routes to the search results page on submit.
 *
 * URLs come from the server-injected state (AssetService::i18n_feed) so
 * renamed hub slugs are honoured; the default-slug literal is only the
 * fallback for a stale cached state.
 */
const bnFeedUrl = ( key, fallbackPath ) => {
	const urls = feedStore.state && feedStore.state.urls;
	return ( urls && urls[ key ] ) || window.location.origin + fallbackPath;
};

const feedStore = store( 'buddynext/feed', {
	state: {
		get guestBannerDismissed() {
			try { return !! getContext().guestBannerDismissed; } catch ( _e ) { return false; }
		},
	},
	actions: {
		/**
		 * Load the next page of the feed WITHOUT a page load, and with every card alive.
		 *
		 * A post card is an Interactivity island, and the API only hydrates islands present
		 * at first paint — so the old infinite scroll, which injected the next page's cards,
		 * left every card past the first screen inert: React, Comment, Share and Save all
		 * did nothing, silently, for the rest of the session.
		 *
		 * The Interactivity Router is the one thing that CAN hydrate: it fetches a URL and
		 * swaps the matching data-wp-router-region, hydrating what it swapped. So this
		 * follows the link's own href (?shown=N — the cumulative server render) and lets the
		 * router replace the feed region. One PHP renderer, no injected HTML, no dead cards.
		 *
		 * Mirrors the shell's navigate action, including the modified-click bail-outs so a
		 * middle-click or cmd-click still opens a normal tab. Any failure falls through to
		 * the browser's own navigation, because the control is a real <a href> — the same
		 * progressive-enhancement contract the rest of the shell uses.
		 *
		 * Plan: free-internal/docs/plans/feed-hydrated-pagination-2026-07-24.md
		 *
		 * @param {MouseEvent} event Click on the Load-more link.
		 */
		*loadMore( event ) {
			const link = event && event.target ? event.target.closest( 'a[href]' ) : null;
			if ( ! link || ! link.href ) {
				return;
			}
			// Let the browser handle anything that is not a plain left-click, and anything
			// pointing off-site — same rules as the shell navigate action.
			if (
				event.ctrlKey ||
				event.metaKey ||
				event.shiftKey ||
				event.altKey ||
				event.button !== 0 ||
				link.target === '_blank' ||
				link.origin !== window.location.origin
			) {
				return;
			}

			event.preventDefault();

			// Drop the #bn-load-more fragment. It exists for the no-JS path, where a full
			// page load would otherwise drop the member at the top of the feed; on the
			// router path nothing moves, so honouring the anchor would yank them from where
			// they were reading down to the button. Keeping position is the whole point.
			const href = link.href.split( '#' )[ 0 ];

			// Where the member is reading. A region swap is not a navigation, so nothing
			// SHOULD move — but the swap replaces the focused Load-more button, and the
			// browser scrolls the re-created element into view, which lands them at the
			// bottom of the newly-loaded batch with 15 unseen cards above. Measured: 1200 ->
			// 4165. Restore the offset so new posts simply appear below them, which is what
			// continuous scrolling feels like everywhere else.
			const scrollY = window.scrollY;

			try {
				const router = yield import( '@wordpress/interactivity-router' );
				yield router.actions.navigate( href );

				// After the swap, and again on the next frame: the router settles focus and
				// the browser can adjust once more after layout.
				window.scrollTo( 0, scrollY );
				window.requestAnimationFrame( () => window.scrollTo( 0, scrollY ) );

				// Same signal a shell navigation emits, so anything that re-initialises on
				// navigation (nav chevrons, shell offsets) also runs after a feed swap.
				document.dispatchEvent(
					new CustomEvent( 'buddynext:navigated', { detail: { href } } )
				);
			} catch ( _e ) {
				// Router unavailable or the swap failed — do what the link would have done.
				window.location.href = href;
			}
		},

		/**
		 * Turn the Load-more control into continuous scroll.
		 *
		 * Bound with data-wp-init on the control, so it arrives with every region swap —
		 * including the swapped-in copy, which is what keeps the behaviour going page after
		 * page without re-registering anything by hand.
		 *
		 * Every mainstream network scrolls rather than asking you to click, so the click is
		 * the fallback, not the design. The link stays real and keyboard-reachable: a member
		 * on a keyboard, or with the observer unsupported, still has a focusable control.
		 *
		 * `rootMargin` starts the fetch a screen early so the next cards are usually there
		 * before the reader arrives. A guard flag stops a second fetch while one is in
		 * flight — the sentinel can stay intersecting across the swap.
		 */
		initLoadMore() {
			const { ref } = getElement();
			if ( ! ref || typeof window.IntersectionObserver !== 'function' ) {
				return; // No observer support: the link still works as a click.
			}
			// Honour reduced-motion by leaving auto-advance off — an unexpected stream of
			// new content is exactly the kind of motion that setting asks us not to start.
			if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
				return;
			}

			let firing = false;
			const observer = new window.IntersectionObserver(
				( entries ) => {
					if ( firing || ! entries.some( ( e ) => e.isIntersecting ) ) {
						return;
					}
					if ( ! ref.isConnected ) {
						observer.disconnect();
						return;
					}
					firing = true;
					observer.disconnect(); // The swap brings a fresh control with its own observer.
					ref.click();
				},
				{ rootMargin: '600px 0px' }
			);
			observer.observe( ref );
		},

		setFilter( event ) {
			if ( event && event.preventDefault ) { event.preventDefault(); }
			const ctx    = getContext();
			const target = event && event.target ? event.target.closest( '[data-filter]' ) : null;
			const filter = target ? target.getAttribute( 'data-filter' ) : '';
			if ( ! filter ) { return; }

			// Hashtag chip — go straight to that hashtag's feed page.
			if ( filter.indexOf( 'tag:' ) === 0 ) {
				const slug = filter.slice( 4 );
				if ( ! slug ) { return; }
				window.location.href = bnFeedUrl( 'hashtagBase', '/activity/hashtag/' ) + encodeURIComponent( slug ) + '/';
				return;
			}

			// People and Spaces have fully-featured directories (search, sort,
			// pagination). Send those chips there rather than routing to an empty
			// search facet. The URLs are resolved server-side into the context so
			// custom hub slugs are honoured.
			if ( 'people' === filter || 'members' === filter ) {
				if ( ctx && ctx.peopleUrl ) { window.location.href = ctx.peopleUrl; }
				return;
			}
			if ( 'spaces' === filter ) {
				if ( ctx && ctx.spacesUrl ) { window.location.href = ctx.spacesUrl; }
				return;
			}

			// Post-grid facets (all / posts / media) stay on the explore page and
			// reload it with ?filter= so the server-rendered grid stays the single
			// source of truth (see docs/specs/UI-CONTRACT.md). 'all' clears the
			// facet. Legacy ?type=/?q= params are dropped so no stale search state
			// leaks onto the explore URL.
			const url = new URL( window.location.href );
			url.searchParams.delete( 'cursor' );
			url.searchParams.delete( 'type' );
			url.searchParams.delete( 'q' );
			if ( 'all' === filter ) {
				url.searchParams.delete( 'filter' );
			} else {
				url.searchParams.set( 'filter', filter );
			}
			window.location.href = url.toString();
		},

		/**
		 * Submit the explore search input as a unified search query.
		 *
		 * Debounced; triggers only on Enter to avoid jumping away on every
		 * keystroke. Empty queries are ignored.
		 */
		onSearch( event ) {
			// Only act on Enter to keep typing fluid.
			const ev = event;
			const isInput = ev && ev.target && ev.target.tagName === 'INPUT';
			if ( ! isInput ) { return; }
			if ( ev.type === 'input' || ev.type === 'change' ) {
				// Stash on context for reactive use; do not navigate yet.
				try { getContext().query = ev.target.value || ''; } catch ( _e ) {}
				return;
			}
			if ( ev.type === 'keydown' && ev.key !== 'Enter' ) { return; }

			const q = ( ev.target.value || '' ).trim();
			if ( '' === q ) { return; }

			const target_url = new URL( bnFeedUrl( 'search', '/activity/search/' ) );
			target_url.searchParams.set( 'q', q );
			window.location.href = target_url.toString();
		},

		/**
		 * Dismiss the guest join banner for the rest of the session.
		 *
		 * Persisted in sessionStorage so the banner stays hidden across page
		 * loads until the browsing session ends (re-shows in a new session).
		 */
		dismissGuestBanner( event ) {
			if ( event && event.preventDefault ) { event.preventDefault(); }
			try {
				getContext().guestBannerDismissed = true;
				window.sessionStorage.setItem( 'bnGuestBannerDismissed', '1' );
			} catch ( _e ) {}
		},
	},
	callbacks: {
		/**
		 * Restore a prior dismissal on load so the banner does not flash back
		 * after a navigation within the same session.
		 */
		initGuestBanner() {
			try {
				if ( '1' === window.sessionStorage.getItem( 'bnGuestBannerDismissed' ) ) {
					getContext().guestBannerDismissed = true;
				}
			} catch ( _e ) {}
		},
	},
} );

// The server merges the injected dictionary into this namespace's state; read
// it once here so every store + vanilla-DOM builder in this file shares one
// translated table.
setI18N( ( feedStore && feedStore.state && feedStore.state.i18n ) || {} );
setTz( ( feedStore && feedStore.state && feedStore.state.tz ) || {} );

/* ── Wire Enter-to-search on the explore search input ─────────────────── */

function initExploreSearch() {
	const input = document.getElementById( 'bn-explore-search-input' );
	if ( ! input || input.dataset.bnSearchWired ) { return; }
	input.dataset.bnSearchWired = '1';
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key !== 'Enter' ) { return; }
		e.preventDefault();
		const q = ( input.value || '' ).trim();
		if ( '' === q ) { return; }
		const target_url = new URL( bnFeedUrl( 'search', '/activity/search/' ) );
		target_url.searchParams.set( 'q', q );
		window.location.href = target_url.toString();
	} );
}

onNavReady( initExploreSearch );

/*
   Composer enhancements — char counter, image drag-drop, and @ / # typeahead.
   Bound via onNavReady (Flavor A) so every page that ships the post-composer
   partial gets these features on initial load AND after a client-side
   navigation. Per-textarea dataset.bnEnhanced keeps re-runs idempotent.
   ---------------------------------------------------------------- */
const COMPOSER_CHAR_MAX = 5000;

function initComposerEnhancements() {
	const composers = document.querySelectorAll( '[data-wp-interactive="buddynext/post-composer"]' );
	composers.forEach( ( el ) => {
		const textarea = el.querySelector( '.bn-composer__prompt' );
		if ( ! textarea || textarea.dataset.bnEnhanced ) {
			return;
		}
		textarea.dataset.bnEnhanced = '1';

		attachCharCounter( textarea );
		attachImageDragDrop( textarea, el );
		attachMentionHashtagTypeahead( textarea );

		// Size the field to any content already present (a restored draft or a
		// share/@mention prefill lands without firing an input event).
		autoResizeTextarea( textarea );
	} );

	// Comment forms — pick up the @ / # typeahead and char counter
	// (cap at 1000 chars for replies; matches Twitter/LinkedIn norms).
	// New comment forms are appended by the JS itself when a thread
	// opens, so this runs at init *and* via a mutation observer.
	enhanceCommentForms();
}

function enhanceCommentForms( root ) {
	const scope = root || document;
	const inputs = scope.querySelectorAll( '.bn-comment-form__input' );
	inputs.forEach( ( textarea ) => {
		if ( textarea.dataset.bnEnhanced ) { return; }
		textarea.dataset.bnEnhanced = '1';
		// 1000-char cap is a separate constant from the post composer's 5000;
		// override the global by passing the desired max via a data attr.
		textarea.dataset.bnCharMax = '1000';
		attachCharCounter( textarea );
		attachMentionHashtagTypeahead( textarea );
	} );
}

// Comment forms appear after the initial DOM (thread opens, reply
// composer injected). Watch the body and enhance any new
// .bn-comment-form__input that lands.
if ( typeof MutationObserver !== 'undefined' && typeof document !== 'undefined' ) {
	const cmtObserver = new MutationObserver( ( mutations ) => {
		mutations.forEach( ( m ) => {
			m.addedNodes.forEach( ( n ) => {
				if ( n.nodeType !== 1 ) { return; }
				if ( n.classList && n.classList.contains( 'bn-comment-form__input' ) ) {
					enhanceCommentForms( n.parentElement );
				} else if ( n.querySelector ) {
					enhanceCommentForms( n );
				}
			} );
		} );
	} );
	if ( document.body ) {
		cmtObserver.observe( document.body, { childList: true, subtree: true } );
	} else {
		document.addEventListener( 'DOMContentLoaded', () => cmtObserver.observe( document.body, { childList: true, subtree: true } ) );
	}
}

function attachCharCounter( textarea ) {
	const max = parseInt( textarea.dataset.bnCharMax, 10 ) || COMPOSER_CHAR_MAX;
	// Prefer a slot the template owns (composer toolbar) so the counter
	// renders inline next to Share instead of stealing its own row.
	const root = textarea.closest( '.bn-composer, .bn-comment-form' ) || textarea.parentElement;
	let counter = root ? root.querySelector( '.bn-composer__char-counter-slot, .bn-comment-form__char-counter-slot' ) : null;
	if ( ! counter ) {
		counter = document.createElement( 'span' );
		counter.className = 'bn-composer__char-counter';
		counter.setAttribute( 'aria-live', 'polite' );
		textarea.insertAdjacentElement( 'afterend', counter );
	}

	const update = () => {
		const len = ( textarea.value || '' ).length;
		// Nothing typed yet -> render nothing. update() runs once on attach, so the
		// counter used to read "0 / 1000" before the member had touched the field:
		// noise in the composer toolbar, and on the comment form a permanently
		// non-empty element that the :empty rule could never hide, stealing width
		// from the input it sits beside.
		counter.textContent = len ? `${ len } / ${ max }` : '';
		counter.dataset.state = len > max
			? 'over'
			: ( len > max * 0.9 ? 'near' : 'ok' );
	};
	textarea.addEventListener( 'input', update );
	update();
}

function attachImageDragDrop( textarea, composerEl ) {
	let depth = 0;
	const dropZone = textarea.closest( '.bn-composer, .bn-composer__inner' ) || textarea;
	const setActive = ( on ) => dropZone.classList.toggle( 'bn-composer--dragover', on );

	dropZone.addEventListener( 'dragenter', ( e ) => {
		if ( ! e.dataTransfer || ! Array.from( e.dataTransfer.items || [] ).some( i => i.kind === 'file' ) ) {
			return;
		}
		depth++;
		setActive( true );
	} );
	dropZone.addEventListener( 'dragleave', () => {
		depth = Math.max( 0, depth - 1 );
		if ( depth === 0 ) { setActive( false ); }
	} );
	dropZone.addEventListener( 'dragover', ( e ) => {
		if ( e.dataTransfer && Array.from( e.dataTransfer.items || [] ).some( i => i.kind === 'file' ) ) {
			e.preventDefault();
		}
	} );
	dropZone.addEventListener( 'drop', ( e ) => {
		depth = 0;
		setActive( false );
		if ( ! e.dataTransfer ) { return; }
		const files = Array.from( e.dataTransfer.files || [] ).filter( f => f.type.startsWith( 'image/' ) );
		if ( files.length === 0 ) { return; }
		e.preventDefault();
		// Find the existing file input the composer uses for the Image button
		// and inject the dropped files so the existing upload pipeline picks
		// them up. Composer JS listens to the input's change event.
		const fileInput = composerEl.querySelector( 'input[type="file"]' );
		if ( ! fileInput ) { return; }
		const dt = new DataTransfer();
		files.forEach( f => dt.items.add( f ) );
		fileInput.files = dt.files;
		fileInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );

	// Image paste — same target, paste from clipboard.
	textarea.addEventListener( 'paste', ( e ) => {
		const items = e.clipboardData?.items;
		if ( ! items ) { return; }
		const imageItems = Array.from( items ).filter( i => i.kind === 'file' && i.type.startsWith( 'image/' ) );
		if ( imageItems.length === 0 ) { return; }
		const fileInput = composerEl.querySelector( 'input[type="file"]' );
		if ( ! fileInput ) { return; }
		const dt = new DataTransfer();
		imageItems.forEach( i => {
			const f = i.getAsFile();
			if ( f ) { dt.items.add( f ); }
		} );
		if ( dt.files.length === 0 ) { return; }
		e.preventDefault();
		fileInput.files = dt.files;
		fileInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
}

/* @ + # typeahead — minimal autocomplete dropdown. Fires when the cursor
   sits inside an unterminated @foo or #bar token at least 2 chars long.
   Uses the existing /search?type=members and /hashtags/autocomplete REST
   endpoints, both already permission_callback=__return_true. */
function attachMentionHashtagTypeahead( textarea ) {
	let dropdown = null;
	let activeIndex = 0;
	let suggestions = [];
	let activeKind = null; // '@' or '#'
	let activeStart = -1;
	let activeToken = '';
	let fetchAbort = null;
	let suggestTimer = null;
	const SUGGEST_DEBOUNCE_MS = 200;

	const closeDropdown = () => {
		if ( dropdown ) {
			dropdown.remove();
			dropdown = null;
		}
		suggestions = [];
		activeKind = null;
		activeStart = -1;
		activeToken = '';
	};

	/**
	 * Put the suggestion list under the CARET, the way X and LinkedIn do.
	 *
	 * The list is absolutely positioned inside .bn-composer__input and carried no
	 * `top` at all, so it landed at the container's origin - which is the
	 * textarea's first line. Typing "Testing hashtag autocomplete #des" dropped
	 * the "#design" suggestion straight over the words being written, leaving
	 * "lete #des" visible beside it. Nothing was wrong with the text; it was
	 * covered. That is what got reported as garbled output.
	 *
	 * A textarea exposes no caret geometry, so the position is measured with the
	 * standard mirror technique: a hidden div that copies the textarea's box and
	 * type metrics, filled with the text up to the trigger character, with a
	 * marker span at the end to read off. Measured rather than declared in CSS
	 * because the composer auto-grows and wraps - any fixed offset is right at
	 * one height and one line count only.
	 *
	 * @return {void}
	 */
	const positionAtCaret = () => {
		if ( ! dropdown ) {
			return;
		}

		const cs     = window.getComputedStyle( textarea );
		const mirror = document.createElement( 'div' );

		// Copy every property that affects where a glyph lands.
		[
			'boxSizing', 'width', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
			'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
			'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing',
			'lineHeight', 'textTransform', 'wordSpacing', 'textIndent', 'whiteSpace', 'wordWrap',
		].forEach( ( prop ) => {
			mirror.style[ prop ] = cs[ prop ];
		} );

		mirror.style.position   = 'absolute';
		mirror.style.visibility = 'hidden';
		mirror.style.whiteSpace = 'pre-wrap';
		mirror.style.wordWrap   = 'break-word';
		mirror.style.top        = '0';
		mirror.style.left       = '0';

		// Text up to the trigger character, then a zero-width marker to measure.
		const upto   = textarea.value.slice( 0, activeStart >= 0 ? activeStart : textarea.selectionStart );
		const marker = document.createElement( 'span' );

		mirror.textContent = upto;
		marker.textContent = '\u200b';
		mirror.appendChild( marker );
		textarea.parentElement.appendChild( mirror );

		const lineHeight = parseFloat( cs.lineHeight ) || ( parseFloat( cs.fontSize ) * 1.4 );

		// Measured as a DIFFERENCE of bounding rects, not with offsetTop: the
		// composer's input wrapper is position:static, so offsetTop on the marker
		// resolves against a further-up ancestor and silently double-counts the
		// mirror's own origin - which pinned the list near the top of the box
		// however far down the caret actually was.
		const mirrorRect = mirror.getBoundingClientRect();
		const markerRect = marker.getBoundingClientRect();
		const caretTop   = markerRect.top - mirrorRect.top;
		const caretLeft  = markerRect.left - mirrorRect.left;

		mirror.remove();

		// One line below the caret, and never left of the textarea.
		let top  = textarea.offsetTop + caretTop - textarea.scrollTop + lineHeight;
		let left = textarea.offsetLeft + caretLeft;

		// Keep the whole list inside the composer rather than letting it hang off
		// the right edge on a caret near the end of a long line.
		const containerWidth = textarea.parentElement.clientWidth;
		const listWidth      = dropdown.offsetWidth || 220;
		if ( left + listWidth > containerWidth ) {
			left = Math.max( 0, containerWidth - listWidth );
		}

		// If the caret sits low enough that the list would overflow the composer,
		// flip it above the caret line instead of pushing the page taller.
		const listHeight = dropdown.offsetHeight || 0;
		const spaceBelow = ( textarea.offsetTop + textarea.offsetHeight ) - ( top - textarea.offsetTop );
		if ( listHeight > 0 && spaceBelow < listHeight && top - lineHeight - listHeight > 0 ) {
			top = top - lineHeight - listHeight;
		}

		dropdown.style.top  = Math.round( top ) + 'px';
		dropdown.style.left = Math.round( left ) + 'px';
	};

	const renderDropdown = () => {
		if ( ! dropdown ) {
			dropdown = document.createElement( 'div' );
			dropdown.className = 'bn-composer__typeahead';
			dropdown.setAttribute( 'role', 'listbox' );
			textarea.parentElement.appendChild( dropdown );
		}
		// s.label / s.handle are user-controlled (member display name + login,
		// or hashtag) so they MUST be HTML-escaped before going into innerHTML —
		// otherwise a name like `<img src=x onerror=...>` would execute in the
		// typeahead of anyone who @-mentions that member (stored XSS). avatar is a
		// WP avatar URL but is escaped for the attribute context too.
		const isMember = '@' === activeKind;
		dropdown.innerHTML = suggestions.map( ( s, i ) => {
			const avatarHtml = isMember && s.avatar
				? `<img class="bn-composer__typeahead-avatar" src="${ escapeHtml( s.avatar ) }" alt="" width="28" height="28" loading="lazy">`
				: '';
			const handleHtml = isMember && s.handle
				? `<span class="bn-composer__typeahead-handle">@${ escapeHtml( s.handle ) }</span>`
				: '';
			// Hashtags keep the "#" prefix on the name; members lead with the
			// display name (the handle shows on its own line below).
			const namePrefix = isMember ? '' : escapeHtml( activeKind );

			// Show what has been TYPED in normal weight and the remainder in bold,
			// the way X does it: the eye lands on the part it is about to gain
			// rather than re-reading the part it just wrote. Falls back to the
			// plain label when the match is not a prefix (a fuzzy or mid-word hit),
			// so nothing is emphasised misleadingly.
			const typed  = activeToken || '';
			const label  = String( s.label );
			const isPre  = typed.length > 0 && label.toLowerCase().startsWith( typed.toLowerCase() );
			const nameHtml = isPre
				? escapeHtml( label.slice( 0, typed.length ) ) +
					'<b class="bn-composer__typeahead-match">' + escapeHtml( label.slice( typed.length ) ) + '</b>'
				: escapeHtml( label );

			return `
			<button type="button" role="option" class="bn-composer__typeahead-item" data-i="${ i }"
					aria-selected="${ i === activeIndex ? 'true' : 'false' }">
				${ avatarHtml }
				<span class="bn-composer__typeahead-text">
					<span class="bn-composer__typeahead-name">${ namePrefix }${ nameHtml }</span>
					${ handleHtml }
				</span>
			</button>
		`;
		} ).join( '' );
		positionAtCaret();

		dropdown.querySelectorAll( '.bn-composer__typeahead-item' ).forEach( ( btn ) => {
			btn.addEventListener( 'mousedown', ( e ) => {
				e.preventDefault();
				selectSuggestion( parseInt( btn.dataset.i, 10 ) );
			} );
		} );
	};

	const selectSuggestion = ( idx ) => {
		const s = suggestions[ idx ];
		if ( ! s ) { return; }
		const value = textarea.value;
		const cursorPos = textarea.selectionStart;
		const before = value.slice( 0, activeStart );
		const after = value.slice( cursorPos );
		const insertion = activeKind + s.token + ' ';
		textarea.value = before + insertion + after;
		const newPos = ( before + insertion ).length;
		textarea.setSelectionRange( newPos, newPos );
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		closeDropdown();
	};

	const fetchSuggestions = async ( kind, query ) => {
		if ( fetchAbort ) { fetchAbort.abort(); }
		fetchAbort = new AbortController();
		const url = kind === '@'
			? `/members?search=${ encodeURIComponent( query ) }&per_page=5`
			: `/hashtags/autocomplete?q=${ encodeURIComponent( query ) }&limit=5`;
		try {
			const r = await restFetch( url, { signal: fetchAbort.signal, toastOnError: false } );
			const data = r.data;
			if ( kind === '@' ) {
				const items = Array.isArray( data.items ) ? data.items : [];
				return items.slice( 0, 5 ).map( ( m ) => {
					const handle = m.handle || m.user_login || m.username || '';
					return {
						token:  handle,
						label:  m.display_name || handle,
						handle,
						avatar: m.avatar_url || '',
					};
				} ).filter( s => s.token );
			}
			const items = Array.isArray( data ) ? data : ( data.items || [] );
			return items.slice( 0, 5 ).map( ( h ) => ( {
				token:  h.slug || h.name || '',
				label:  h.slug || h.name || '',
				handle: '',
				avatar: '',
			} ) ).filter( s => s.token );
		} catch ( _e ) {
			return [];
		}
	};

	// Where a handle ends. Injected from \BuddyNext\Profile\Handle::CHARSET so this
	// walk and the PHP mention parsers share ONE definition — a local copy that
	// drifted would let the composer offer a mention the server cannot resolve.
	// The literal is only a fallback for a page that rendered without state.
	const handleChars = new RegExp(
		'[' + ( ( feedStore.state && feedStore.state.handleCharset ) || 'a-zA-Z0-9_-' ) + ']'
	);

	textarea.addEventListener( 'input', () => {
		const value = textarea.value;
		const cursorPos = textarea.selectionStart;
		// Walk back from the cursor to find an unterminated @ or # token.
		let i = cursorPos - 1;
		while ( i >= 0 && handleChars.test( value[ i ] ) ) { i--; }
		// Token-detection runs synchronously so the dropdown closes instantly when
		// there is no active token; only the network search is debounced.
		const bail = () => { clearTimeout( suggestTimer ); closeDropdown(); };
		if ( i < 0 ) { bail(); return; }
		const trigger = value[ i ];
		if ( trigger !== '@' && trigger !== '#' ) { bail(); return; }
		// Boundary: the char before the trigger must not be word-like.
		if ( i > 0 && /[a-zA-Z0-9_]/.test( value[ i - 1 ] ) ) { bail(); return; }
		const token = value.slice( i + 1, cursorPos );
		if ( token.length < 2 ) { bail(); return; }
		activeKind = trigger;
		activeStart = i;
		activeToken = token;
		// Debounce the suggestion fetch so a fast typist fires one request after a
		// short pause instead of one per keystroke (the in-flight request is also
		// aborted in fetchSuggestions). ~200ms is below the perceptible-lag bar.
		clearTimeout( suggestTimer );
		suggestTimer = setTimeout( async () => {
			const results = await fetchSuggestions( trigger, token );
			if ( results.length === 0 ) { closeDropdown(); return; }
			suggestions = results;
			activeIndex = 0;
			renderDropdown();
		}, SUGGEST_DEBOUNCE_MS );
	} );

	textarea.addEventListener( 'keydown', ( e ) => {
		if ( ! dropdown || suggestions.length === 0 ) { return; }
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			activeIndex = ( activeIndex + 1 ) % suggestions.length;
			renderDropdown();
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			activeIndex = ( activeIndex - 1 + suggestions.length ) % suggestions.length;
			renderDropdown();
		} else if ( e.key === 'Enter' || e.key === 'Tab' ) {
			e.preventDefault();
			selectSuggestion( activeIndex );
		} else if ( e.key === 'Escape' ) {
			closeDropdown();
		}
	} );

	textarea.addEventListener( 'blur', () => setTimeout( closeDropdown, 150 ) );
}

onNavReady( initComposerEnhancements );

/*
   Realtime "new posts" pill — listens for Pro's bn:realtime:post-new
   custom events (fired by buddynext-pro/assets/js/realtime/store.js
   when Soketi delivers a post.new message on the subscribed feed
   channel). Accumulates the count, shows a sticky pill at the top
   of the feed list, and reloads the feed when clicked.

   No-op when no realtime layer is active (the event never fires).
   ---------------------------------------------------------------- */
const POLL_INTERVAL = 60000;

// Ceiling for the pill label. Mirrors FeedService::NEW_COUNT_CAP, which bounds the
// server-side count to CAP + 1 rows — so a value above this means "at least this
// many", and printing the raw number would be both meaningless and a lie about
// precision we deliberately stopped paying for.
const BN_PILL_CAP = 99;

// Per-page state for the pill. Re-seeded on every (re-)init so the once-bound
// document listeners always read the freshly-swapped feed / watermark / nonce.
const bnPill = {
	feed:      null,
	pill:      null,
	pendingIds: new Set(),
	watermark: 0,
	filter:    'for-you',
	restUrl:   '',
	restNonce: '',
	pollTimer: null,
	enabled:   true,
	pollMs:    POLL_INTERVAL,
	realtimeActive: false,
};

function bnPillRender() {
	if ( bnPill.pendingIds.size === 0 ) {
		if ( bnPill.pill ) { bnPill.pill.remove(); bnPill.pill = null; }
		return;
	}
	if ( ! bnPill.feed ) { return; }
	if ( ! bnPill.pill ) {
		const pill = document.createElement( 'button' );
		pill.type = 'button';
		pill.className = 'bn-feed-new-pill';
		pill.setAttribute( 'role', 'status' );
		pill.setAttribute( 'aria-live', 'polite' );
		pill.addEventListener( 'click', () => {
			window.location.reload();
		} );
		bnPill.feed.parentElement.insertBefore( pill, bnPill.feed );
		bnPill.pill = pill;
	}
	// Cap the label. Nobody acts on "3,412 new posts" differently than on "99+" —
	// a raw four-digit count is noise, and it makes a healthy community read as
	// overwhelming rather than alive. Every mainstream platform caps this (X:
	// "Show N posts"; Facebook / LinkedIn: just "New posts"). The server counts a
	// bounded window (FeedService::NEW_COUNT_CAP + 1) so anything at or above the
	// ceiling means "at least this many", not "exactly this many".
	const n      = bnPill.pendingIds.size;
	const capped = n > BN_PILL_CAP;

	if ( capped ) {
		bnPill.pill.textContent = fmt( t( 'manyNewPostsCapped', '%d+ new posts — refresh to view' ), BN_PILL_CAP );
	} else if ( n === 1 ) {
		bnPill.pill.textContent = t( 'oneNewPost', '1 new post — refresh to view' );
	} else {
		bnPill.pill.textContent = fmt( t( 'manyNewPosts', '%d new posts — refresh to view' ), n );
	}
}

async function bnPillPoll() {
	if ( ! bnPill.enabled || document.hidden || ! bnPill.restUrl ) { return; }
	try {
		const url = bnPill.restUrl + '/feed/new-count?after_id=' + encodeURIComponent( bnPill.watermark ) +
			'&filter=' + encodeURIComponent( bnPill.filter );
		const res = await restFetch( url, { nonce: bnPill.restNonce || '', toastOnError: false } );
		if ( ! res.ok ) { return; }
		const json = res.data;
		if ( ! json || typeof json.count === 'undefined' ) { return; }
		const fresh = Number( json.count ) || 0;
		const newestId = Number( json.newest_id ) || bnPill.watermark;
		if ( fresh > 0 && newestId > bnPill.watermark ) {
			// Synthesize ids above the watermark so the pill counts the delta
			// without needing the individual ids. The renderer keys off a Set,
			// so distinct synthetic ids are sufficient for an accurate count.
			for ( let i = 1; i <= fresh; i++ ) {
				document.dispatchEvent( new CustomEvent( 'bn:realtime:post-new', {
					detail: { post_id: bnPill.watermark + i, user_id: 0 },
				} ) );
			}
			bnPill.watermark = newestId;
		}
	} catch ( _e ) {
		// Network failure — retry next tick.
	}
}

function bnPillSchedule() {
	if ( bnPill.pollTimer ) { window.clearTimeout( bnPill.pollTimer ); bnPill.pollTimer = null; }
	// No background poll when the indicator is off, the interval is 0 (disabled
	// via the buddynext_feed_new_count_interval filter), or a realtime layer is
	// already pushing post.new events (Pro websockets) — the poll is redundant then.
	if ( ! bnPill.enabled || bnPill.pollMs <= 0 || bnPill.realtimeActive ) { return; }
	bnPill.pollTimer = window.setTimeout( function () {
		bnPillPoll().finally( bnPillSchedule );
	}, bnPill.pollMs );
}

/*
   Realtime "new posts" pill — listens for Pro's bn:realtime:post-new
   custom events (fired by buddynext-pro/assets/js/realtime/store.js
   when Soketi delivers a post.new message on the subscribed feed
   channel). Accumulates the count, shows a sticky pill at the top
   of the feed list, and reloads the feed when clicked.

   No-op when no realtime layer is active (the event never fires).

   Flavor B hybrid singleton: the document `bn:realtime:post-new` and
   `visibilitychange` listeners install ONCE behind window.__bnPillInited
   (they read the module-level bnPill state, so a swapped feed is covered
   without re-binding). On every (re-)init the per-page state is re-seeded
   and the existing poll timer is cleared before a fresh one is scheduled —
   so a client navigation never stacks a second poll chain or listener.
   ---------------------------------------------------------------- */
function initRealtimeNewPostsPill() {
	const feed = document.querySelector( '.bn-feed-list, .bn-explore-grid' );
	// Skip explore — it ranks by engagement, not chrono; a "new post"
	// at the top makes no sense there.
	if ( ! feed || feed.classList.contains( 'bn-explore-grid' ) ) {
		// Tear down any pill carried over from a previous (feed) page.
		if ( bnPill.pollTimer ) { window.clearTimeout( bnPill.pollTimer ); bnPill.pollTimer = null; }
		if ( bnPill.pill ) { bnPill.pill.remove(); bnPill.pill = null; }
		bnPill.feed = null;
		bnPill.pendingIds = new Set();
		return;
	}

	// Re-seed per-page state for the freshly-rendered feed.
	bnPill.feed = feed;
	bnPill.pendingIds = new Set();
	if ( bnPill.pill ) { bnPill.pill.remove(); bnPill.pill = null; }

	// REST root + nonce come from the always-present composer context; the feed
	// page on /activity renders the composer for every logged-in member.
	const composerEl = document.querySelector( '[data-wp-interactive="buddynext/post-composer"]' );
	bnPill.restUrl   = '';
	bnPill.restNonce = '';
	if ( composerEl ) {
		try {
			const cfg = JSON.parse( composerEl.dataset.wpContext || '{}' );
			bnPill.restUrl   = cfg.restUrl || '';
			bnPill.restNonce = cfg.restNonce || '';
		} catch ( _e ) {}
	}

	// Active home-feed filter (defaults to for-you). The new-count query must
	// scope to the same source blend the user is actually viewing.
	const activeTab = document.querySelector( '.bn-feed-filter-tab[aria-current="true"]' );
	bnPill.filter = ( activeTab && activeTab.dataset.filter ) || 'for-you';

	// Seed the watermark from the newest post-card already rendered. The pill
	// only ever cares about posts above this id.
	bnPill.watermark = 0;
	feed.querySelectorAll( '[data-post-id]' ).forEach( ( card ) => {
		const cardId = parseInt( card.dataset.postId, 10 );
		if ( cardId > bnPill.watermark ) { bnPill.watermark = cardId; }
	} );

	// Resolve the new-posts indicator config from the feed shell. The server
	// encodes the poll cadence in ms: -1 = indicator off, 0 = no background poll
	// (realtime pills only), > 0 = poll interval. A missing attr keeps the default.
	const bnStack = document.querySelector( '.bn-feed-stack' );
	const bnRawMs = bnStack ? parseInt( bnStack.dataset.bnNewPollMs || '', 10 ) : NaN;
	bnPill.enabled = Number.isNaN( bnRawMs ) ? true : bnRawMs >= 0;
	bnPill.pollMs  = Number.isNaN( bnRawMs ) ? POLL_INTERVAL : Math.max( 0, bnRawMs );

	// Install the document-delegated listeners exactly once.
	if ( ! window.__bnPillInited ) {
		window.__bnPillInited = true;

		document.addEventListener( 'bn:realtime:post-new', ( e ) => {
			if ( ! bnPill.feed || ! bnPill.enabled ) { return; }
			// A realtime layer is live (Pro websockets): stop the redundant
			// background poll and let pushed events drive the pill from here on.
			bnPill.realtimeActive = true;
			if ( bnPill.pollTimer ) { window.clearTimeout( bnPill.pollTimer ); bnPill.pollTimer = null; }
			const id = parseInt( e.detail?.post_id, 10 );
			const author = parseInt( e.detail?.user_id, 10 );
			if ( ! id ) { return; }
			// Skip the viewer's own posts — they're shown immediately
			// by the composer's local insertion logic.
			const composer = document.querySelector( '[data-wp-interactive="buddynext/post-composer"]' );
			let viewerId = 0;
			if ( composer ) {
				try { viewerId = parseInt( JSON.parse( composer.dataset.wpContext || '{}' ).userId, 10 ); } catch ( _e ) {}
			}
			if ( author === viewerId ) { return; }
			bnPill.pendingIds.add( id );
			bnPillRender();
		} );

		// Re-poll immediately when the tab regains focus after being hidden.
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) { bnPillPoll(); }
		} );
	}

	// Clear any in-flight poll chain before reseeding so navigations never
	// stack a second timer feeding the same listener.
	bnPillSchedule();
}

onNavReady( initRealtimeNewPostsPill );

/*
   Realtime comment indicator — listens for `bn:realtime:comment-added`
   events dispatched by Pro's RealtimeDispatcher when Soketi delivers
   a `comment.added` message. If the affected post's comment thread
   is currently open in this tab, increments a discreet "N new" badge
   above the comment list; click fetches and prepends.

   No-op when no realtime layer is active or the thread isn't open.
   ---------------------------------------------------------------- */
function initRealtimeCommentIndicator() {
	// Flavor B singleton — the delegated document listener queries the DOM
	// fresh on each event, so it already covers content swapped in by a
	// client-side navigation. Install it exactly once; any re-run is a no-op.
	if ( window.__bnCommentIndicatorInited ) { return; }
	window.__bnCommentIndicatorInited = true;

	document.addEventListener( 'bn:realtime:comment-added', ( e ) => {
		const postId      = parseInt( e.detail?.post_id, 10 );
		const commenterId = parseInt( e.detail?.user_id, 10 );
		if ( ! postId ) { return; }
		const list = document.querySelector( `.bn-comment-list[data-comment-list="${ postId }"]` );
		if ( ! list || list.children.length === 0 ) {
			// Thread not open yet — when the user opens it, the freshest
			// list will be fetched from REST and the new comment will be
			// included naturally. No need to do anything here.
			return;
		}
		// Skip the viewer's own comment (already inserted optimistically
		// by submitComment). Resolve current user via the closest
		// post-card context.
		const card = list.closest( '[data-wp-interactive="buddynext/post-card"]' );
		if ( card ) {
			try {
				const ctx = JSON.parse( card.dataset.wpContext || '{}' );
				if ( parseInt( ctx.currentUserId, 10 ) === commenterId ) { return; }
			} catch ( _e ) {}
		}

		let pill = list.previousElementSibling;
		if ( ! pill || ! pill.classList.contains( 'bn-comment-new-pill' ) ) {
			pill = document.createElement( 'button' );
			pill.type = 'button';
			pill.className = 'bn-comment-new-pill';
			pill.setAttribute( 'role', 'status' );
			pill.setAttribute( 'aria-live', 'polite' );
			pill.dataset.count = '0';
			pill.addEventListener( 'click', () => {
				// Trigger a refetch of the thread by clicking the comment
				// toggle twice (close + reopen). Cheap and reliable.
				const cardEl    = list.closest( '[data-wp-interactive="buddynext/post-card"]' );
				const commentTriggers = cardEl?.querySelectorAll( '[aria-label*="comment" i]' );
				if ( commentTriggers && commentTriggers.length > 0 ) {
					commentTriggers[ 0 ].click();
					setTimeout( () => commentTriggers[ 0 ].click(), 50 );
				} else {
					window.location.reload();
				}
				pill.remove();
			} );
			list.parentElement.insertBefore( pill, list );
		}
		const n = parseInt( pill.dataset.count, 10 ) + 1;
		pill.dataset.count = String( n );
		pill.textContent = n === 1 ? t( 'oneNewComment', '1 new comment — show' ) : fmt( t( 'manyNewComments', '%d new comments — show' ), n );
	} );
}

onNavReady( initRealtimeCommentIndicator, { once: true } );

/* ── Emoji insert picker (composer + comment editor) ─────────────────────
 * Inserts a Unicode emoji at the caret of a target textarea/input. The
 * trigger is any `.bn-emoji-trigger[data-bn-emoji-target]` (a CSS selector
 * resolved relative to the trigger's nearest composer/comment container, or
 * the document). Gated server-side by buddynext_enable_emoji_picker — when
 * the option is off the trigger button is never rendered.
 *
 * The slug→character map lives here in the data layer (NOT in PHP markup) so
 * no emoji characters are hardcoded in templates. Glyphs render from the
 * bundled SVGs for cross-platform consistency, mirroring the reaction picker.
 */
const BN_EMOJI_MAP = {
	grin: '😀', haha: '😂', rofl: '🤣', wink: '😉', hearteyes: '😍',
	starstruck: '🤩', cool: '😎', thinking: '🤔', mindblown: '🤯',
	partyface: '🥳', pleading: '🥺', cry: '😢', sad: '😞', angry: '😠',
	like: '👍', thumbsdown: '👎', love: '❤️', fire: '🔥', hundred: '💯',
	clap: '👏', raisedhands: '🙌', pray: '🙏', muscle: '💪', peace: '✌️',
	eyes: '👀', wow: '😮', celebrate: '🎉', sparkles: '✨', star: '⭐',
	rocket: '🚀', trophy: '🏆', gift: '🎁', check: '✅',
};

function bnEmojiAssetBase() {
	const link = document.querySelector( '[data-emoji-base]' );
	if ( link && link.dataset.emojiBase ) { return link.dataset.emojiBase; }
	// Derive from a known plugin script src as a fallback.
	const s = document.querySelector( 'script[src*="/buddynext/assets/"]' );
	if ( s ) { return s.src.replace( /assets\/.*$/, 'assets/emoji/' ); }
	return '';
}

function bnInsertAtCaret( field, text ) {
	if ( ! field ) { return; }
	field.focus();
	const start = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
	const end   = typeof field.selectionEnd === 'number' ? field.selectionEnd : field.value.length;
	const before = field.value.slice( 0, start );
	const after  = field.value.slice( end );
	field.value = before + text + after;
	const caret = start + text.length;
	field.setSelectionRange( caret, caret );
	// Notify any listeners (draft autosave, link detection, char counter).
	field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

function initEmojiPicker() {
	// Flavor B singleton — the delegated document click/keydown + window
	// resize/scroll listeners and the lazily body-appended panel cover content
	// swapped in by a client-side navigation. Install once behind the window
	// flag so a re-run adds no duplicate listeners and no duplicate body panel.
	if ( window.__bnEmojiPickerInited ) { return; }
	window.__bnEmojiPickerInited = true;

	let panel = null;
	let activeTrigger = null;

	const closePanel = () => {
		if ( panel ) { panel.hidden = true; }
		if ( activeTrigger ) { activeTrigger.setAttribute( 'aria-expanded', 'false' ); }
		activeTrigger = null;
	};

	const buildPanel = () => {
		const base = bnEmojiAssetBase();
		const p = document.createElement( 'div' );
		p.className = 'bn-emoji-popover';
		p.setAttribute( 'role', 'menu' );
		p.setAttribute( 'aria-label', t( 'insertEmoji', 'Insert emoji' ) );
		p.hidden = true;
		Object.keys( BN_EMOJI_MAP ).forEach( ( slug ) => {
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'bn-emoji-popover__option';
			btn.dataset.emojiChar = BN_EMOJI_MAP[ slug ];
			btn.setAttribute( 'aria-label', slug );
			btn.title = slug;
			if ( base ) {
				const img = document.createElement( 'img' );
				img.src = base + slug + '.svg';
				img.alt = '';
				img.width = 22;
				img.height = 22;
				btn.appendChild( img );
			} else {
				btn.textContent = BN_EMOJI_MAP[ slug ];
			}
			p.appendChild( btn );
		} );
		document.body.appendChild( p );
		return p;
	};

	const resolveTarget = ( trigger ) => {
		const sel = trigger.dataset.bnEmojiTarget;
		if ( ! sel ) { return null; }
		// Prefer a match within the nearest composer / comment container so
		// multiple composers on a page each target their own field.
		const scope = trigger.closest( '.bn-composer, .bn-comment__edit-form, .bn-comment-form, form, .bn-post-card' );
		return ( scope && scope.querySelector( sel ) ) || document.querySelector( sel );
	};

	document.addEventListener( 'click', ( e ) => {
		const option = e.target.closest( '.bn-emoji-popover__option' );
		if ( option && panel && ! panel.hidden && activeTrigger ) {
			e.preventDefault();
			bnInsertAtCaret( resolveTarget( activeTrigger ), option.dataset.emojiChar );
			closePanel();
			return;
		}

		const trigger = e.target.closest( '.bn-emoji-trigger' );
		if ( ! trigger ) {
			if ( panel && ! panel.hidden && ! e.target.closest( '.bn-emoji-popover' ) ) {
				closePanel();
			}
			return;
		}
		e.preventDefault();
		if ( ! panel ) { panel = buildPanel(); }
		if ( activeTrigger === trigger && ! panel.hidden ) {
			closePanel();
			return;
		}
		activeTrigger = trigger;
		trigger.setAttribute( 'aria-expanded', 'true' );
		// Position the panel under the trigger, CLAMPED to the viewport.
		//
		// This used to set top/left straight from the trigger rect with no
		// measurement of the panel and no flip. Opening the picker on a comment box
		// near the bottom-right of the window put it 240px past the right edge and
		// 184px past the bottom — measured at 1440x900. Near the bottom of a feed
		// that is every comment box on screen.
		//
		// Unhide first: the panel is display:none while hidden, so it has no
		// measurable size and any clamp computed before this is meaningless.
		panel.hidden = false;
		panel.style.position = 'absolute';

		const r      = trigger.getBoundingClientRect();
		const pr     = panel.getBoundingClientRect();
		const margin = 8;

		// Horizontal: prefer trigger-aligned, clamp into [margin, right edge].
		const maxLeft = Math.max( margin, window.innerWidth - pr.width - margin );
		const left    = Math.min( Math.max( r.left, margin ), maxLeft );

		// Vertical: flip above the trigger when it does not fit below. A popover is
		// SUPPOSED to paint over the content behind it — the card's "overlaps the
		// post below" is a consequence of never flipping, not of a missing backdrop.
		const fitsBelow = r.bottom + 6 + pr.height + margin <= window.innerHeight;
		const top       = fitsBelow
			? r.bottom + 6
			: Math.max( margin, r.top - 6 - pr.height );

		panel.style.top  = ( window.scrollY + top ) + 'px';
		panel.style.left = ( window.scrollX + left ) + 'px';
	} );

	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' ) { closePanel(); }
	} );
	window.addEventListener( 'resize', closePanel );
	window.addEventListener( 'scroll', closePanel, true );
}

onNavReady( initEmojiPicker, { once: true } );
