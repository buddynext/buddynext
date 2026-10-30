/**
 * BuddyNext post-card store and comment controls.
 *
 * Split out of feed/store.js: registers buddynext/post-card - reactions, the
 * reaction-summary popover, comment threads (server-rendered by
 * templates/parts/comment.php; the controls are delegated below), bookmark, share trigger, delete, pin, report, and the
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
import { bnConfirm, bnReloadWithToast, bnReportDialog, bnToast } from '@buddynext/shell-dialog';
import { restFetch } from '@buddynext/rest-client';
import { uploadMedia, validateMedia } from '@buddynext/upload-core';
import { t, fmt, prependFeedCard, bnApplyFilters, escapeHtml, clearField, toUtcSqlDatetime, toSiteInputValue, siteNowInputValue } from '@buddynext/feed-shared';
import { bnClampPopoverToViewport } from '@buddynext/popover';

/**
 * Per-viewer values every post card shares (viewerId, restNonce, restUrl,
 * canUploadMedia, reactDefaultLabel), published once by AssetService as the
 * buddynext/post-card state instead of in each card's context.
 *
 * @return {Object} The post-card store state.
 */
const viewer = () => store( 'buddynext/post-card' ).state;

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

/**
 * Lucide glyphs for the comment action row, as inline SVG strings (the file has
 * no JS icon helper — icons are authored inline, same as REACTION_FALLBACK_GLYPH
 * above). Sized by CSS, not the markup, so one width rule covers every use.
 * Paths match the plugin's server-side Lucide set (buddynext_icon()).
 */
const BN_ICON = ( inner ) =>
	'<svg class="bn-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" ' +
	'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
	'stroke-linejoin="round" aria-hidden="true">' + inner + '</svg>';
const BN_ICONS = {
	send:   BN_ICON( '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>' ),
	check:  BN_ICON( '<path d="M20 6 9 17l-5-5"/>' ),
	x:      BN_ICON( '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>' ),
	smile:  BN_ICON( '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/>' ),
};

/**
 * Replace a reported post's own page with a short reported state.
 *
 * In a feed the reported card just leaves and the other posts fill the space; on the post's
 * own page nothing else is there, so the post (and its thread) is swapped for a message and a
 * link back, keeping the breadcrumb.
 *
 * @param {HTMLElement} page The .bn-single-post article.
 * @return {void}
 */
function showReportedState( page ) {
	const crumbs = page.querySelector( '.bn-single-post__breadcrumb' );
	const box    = document.createElement( 'div' );
	box.className = 'bn-feed-empty';
	box.setAttribute( 'role', 'status' );
	box.dataset.state = 'reported';

	const title = document.createElement( 'div' );
	title.className = 'bn-feed-empty__title';
	title.textContent = t( 'reportedPostTitle', 'You reported this post.' );

	const text = document.createElement( 'p' );
	text.className = 'bn-feed-empty__text';
	text.textContent = t( 'reportedPostText', 'Moderators will review it. Thanks for keeping the community safe.' );

	const back = document.createElement( 'a' );
	back.className = 'bn-btn bn-feed-empty__cta';
	back.dataset.variant = 'primary';
	back.href = page.dataset.bnFeedUrl || '/';
	back.textContent = t( 'backToFeed', 'Back to feed' );

	box.append( title, text, back );
	page.replaceChildren( ...( crumbs ? [ crumbs ] : [] ), box );
}

/**
 * An emoji trigger button wired to one textarea, or null when the site owner has
 * the emoji picker off (bnShellData.emojiPicker, from the owner option, so it
 * works on pages with no composer such as a post permalink). The shared
 * initEmojiPicker() handler does the click and insertion.
 *
 * @param {HTMLTextAreaElement} ta  The editor's textarea.
 * @param {string}              key Unique field key for this editor.
 * @return {HTMLButtonElement|null}
 */
function bnEmojiTrigger( ta, key ) {
	if ( ! ( window.bnShellData && window.bnShellData.emojiPicker ) ) {
		return null;
	}
	ta.dataset.bnEmojiField = key;
	const btn = document.createElement( 'button' );
	btn.type = 'button';
	btn.className = 'bn-emoji-trigger bn-comment__emoji-trigger';
	btn.dataset.bnEmojiTarget = '[data-bn-emoji-field="' + key + '"]';
	btn.setAttribute( 'aria-label', t( 'insertEmoji', 'Insert emoji' ) );
	btn.setAttribute( 'aria-haspopup', 'true' );
	btn.setAttribute( 'aria-expanded', 'false' );
	btn.title = t( 'insertEmoji', 'Insert emoji' );
	// The same line smile the comment form's server-rendered trigger uses.
	btn.innerHTML = BN_ICONS.smile;
	return btn;
}

/**
 * Fill a comment action button with an icon + a text label, so the label shows on
 * desktop and CSS swaps in the icon under 640px (the six-action row would
 * otherwise wrap onto a second line on a phone). Keeps an aria-label so the
 * icon-only mobile state stays accessible.
 *
 * @param {HTMLElement} btn     Button to populate.
 * @param {string}      iconKey Key into BN_ICONS.
 * @param {string}      label   Visible/aria label.
 */
function setCommentAction( btn, iconKey, label ) {
	btn.replaceChildren();
	const ico = document.createElement( 'span' );
	ico.className = 'bn-comment__act-ico';
	ico.innerHTML = BN_ICONS[ iconKey ] || '';
	const txt = document.createElement( 'span' );
	txt.className = 'bn-comment__act-txt';
	txt.textContent = label;
	btn.append( ico, txt );
	btn.setAttribute( 'aria-label', label );
}

/**
 * Update just the text label of a button built by setCommentAction(), leaving its
 * icon intact — for the Pin/Report controls that relabel after an action.
 *
 * @param {HTMLElement} btn   Button.
 * @param {string}      label New label.
 */
function setCommentActionLabel( btn, label ) {
	const txt = btn.querySelector( '.bn-comment__act-txt' );
	if ( txt ) {
		txt.textContent = label;
	}
	btn.setAttribute( 'aria-label', label );
}

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
 * Mounted post cards, so a reaction made somewhere else on the page (the photo
 * lightbox) can be shown on the card it belongs to.
 *
 * ponytail: entries are dropped only when a later event finds the card gone
 * from the page; a WeakRef registry if a very long session ever shows up in a
 * memory profile.
 *
 * @type {Array<{ctx: Object, el: Element|null}>}
 */
let bnMountedCards = [];

/**
 * Show a reaction change on one mounted card, when it is the card for that post.
 *
 * @param {{ctx: Object, el: Element|null}} card   Mounted card.
 * @param {number}                          postId Post whose reaction changed.
 * @param {Object}                          d      Event detail ({ type, body }).
 * @return {void}
 */
function mirrorReaction( card, postId, d ) {
	if ( Number( card.ctx.postId ) !== postId ) return;
	const type   = d.type || null;
	const option = type && card.el ? card.el.querySelector( '[data-reaction-type="' + CSS.escape( type ) + '"]' ) : null;
	card.ctx.reactionType  = type;
	card.ctx.reactionLabel = type
		? ( option?.getAttribute( 'title' ) || option?.getAttribute( 'aria-label' ) || type )
		: ( viewer().reactDefaultLabel || 'React' );
	updateReactionSummary( card.el, d.body || {} );
}

/**
 * A post's reaction changed outside its card: mirror it on the card.
 *
 * detail = { postId, type, body }, where type is the viewer's reaction slug (or
 * null when removed) and body is the /reactions/toggle response.
 */
document.addEventListener( 'bn:post-reaction', ( event ) => {
	const d      = event.detail || {};
	const postId = Number( d.postId ) || 0;
	if ( ! postId ) return;

	bnMountedCards = bnMountedCards.filter( ( card ) => ! card.el || card.el.isConnected );
	bnMountedCards.forEach( ( card ) => mirrorReaction( card, postId, d ) );
} );

/**
 * A post's totals changed somewhere else (another member, over realtime): show them.
 *
 * detail = { postId, reactions, reactionSummary, comments } - the post's stored
 * totals, not deltas, so a removal, an emoji swap or the actor's own tab all
 * land on the right number. Only counts change; the viewer's own reaction does
 * not. BuddyNext Pro's realtime module sends it; anything may.
 */
document.addEventListener( 'buddynext:post-counts', ( event ) => {
	const d      = event.detail || {};
	const postId = Number( d.postId ) || 0;
	if ( ! postId ) return;

	bnMountedCards = bnMountedCards.filter( ( card ) => ! card.el || card.el.isConnected );
	bnMountedCards.forEach( ( card ) => {
		if ( Number( card.ctx.postId ) !== postId ) return;
		if ( Number.isFinite( d.reactions ) ) {
			card.ctx.reactionCount = d.reactions;
			updateReactionSummary( card.el, { count: d.reactions, summary: d.reactionSummary || [] } );
		}
		if ( Number.isFinite( d.comments ) ) {
			setCommentCount( card.el, d.comments );
		}
	} );
} );

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
	// Keep the spoken name in step with the visible count (it was SSR-only, so a
	// screen reader kept hearing the count from page load).
	trigger.setAttribute(
		'aria-label',
		1 === total ? t( 'seeOneReaction', 'See 1 reaction' ) : fmt( t( 'seeAllReactions', 'See all %d reactions' ), total )
	);
}

// Thread levels (CommentService::MAX_REPLY_DEPTH). Reply on the deepest level
// posts to that comment's parent, so the reply lands beside it (fold-back
// rather than infinite nesting); create() refuses anything deeper.
const COMMENT_MAX_DEPTH = 5;

// Top-level comments fetched per page (initial load AND each "View more"
// click). One source so the request size and the `shown = page * perPage`
// arithmetic in bnRenderCommentPage can never drift. The REST endpoint's own
// default stays 20 (a distinct contract for the app/API); the web client
// deliberately asks for a shorter first fold so a long thread does not open
// far down the page.
const COMMENT_FIRST_PAGE = 6;

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
	// A suspension refusal already surfaced the appeal toast centrally in
	// rest-client (it sets res.suspended and toasts the appeal link even on
	// toastOnError:false). Building our own here stacks a second, identical toast
	// that the UI collapses to one stamped "x2" (card 10264293681 RFT round 4).
	// Let the central appeal toast stand — matches members/store.js follow/connect.
	if ( res && res.suspended ) {
		return;
	}
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

/* ── Comment threads: one server renderer, delegated controls ─────────────
 * Every comment is rendered on the server (templates/parts/comment.php via
 * \BuddyNext\Comments\CommentThread): the first page on a post's own page, and
 * each later page, new comment, reply and edit as HTML the API returns. The
 * controls carry no Interactivity directives, so HTML added after load works:
 * the listeners below sit on the document and read the comment's data-*
 * attributes. Before, the whole comment was built here with per-element
 * listeners closing over a JSON object, which meant a second renderer that had
 * drifted (pinned comments lost their badge, edited comments their links).
 */

/**
 * The thread container a control belongs to, and its post id.
 *
 * @param {Element} el Any element inside a comment list.
 * @return {{ listEl: Element|null, postId: number }}
 */
function commentScope( el ) {
	const listEl = el ? el.closest( '.bn-comment-list' ) : null;
	return { listEl, postId: listEl ? Number( listEl.dataset.commentList ) || 0 : 0 };
}

/**
 * The comment card's own (not a nested reply's) child element.
 *
 * @param {Element} card     .bn-comment-card.
 * @param {string}  selector Child selector inside the card's body.
 * @return {Element|null}
 */
function commentPart( card, selector ) {
	return card.querySelector( ':scope > .bn-comment__body > ' + selector );
}

/**
 * Add one comment the API returned (with its rendered `html`) to a container.
 *
 * The comment still passes the `buddynext.commentNode` filter with its data, as
 * the client renderer used to; `buddynext.comment` (data before rendering) is
 * now the PHP filter `buddynext_comment_render_data`.
 *
 * @param {Element} container Where to append.
 * @param {Object}  comment   API comment with `html`.
 * @param {Object}  extra     Filter context.
 * @return {Element|null} The inserted comment card.
 */
function bnInsertComment( container, comment, extra ) {
	const tpl = document.createElement( 'template' );
	// Server-rendered and escaped by parts/comment.php.
	tpl.innerHTML = ( comment && comment.html ) || '';
	const node = tpl.content.firstElementChild;
	if ( ! node ) {
		return null;
	}
	const out = bnApplyFilters( 'buddynext.commentNode', node, comment, extra ) || node;
	container.appendChild( out );
	return out;
}

/* Reaction control ------------------------------------------------------- */

function commentReactionMeta( listEl ) {
	const set = resolveReactionSet( listEl );
	return {
		meta: Object.fromEntries( set.map( ( r ) => [ r.slug, r ] ) ),
		fallback: set.length ? set[ 0 ].slug : 'like',
	};
}

// One glyph: the bundled emoji, else the owner's character in its colour - the
// same output parts/comment.php gives a reaction on first render.
function paintReactionGlyph( parent, meta, size ) {
	parent.replaceChildren();
	if ( ! meta ) {
		parent.textContent = '♡';
		return;
	}
	if ( meta.emoji_url ) {
		const img  = document.createElement( 'img' );
		img.src    = meta.emoji_url;
		img.alt    = meta.label || '';
		img.width  = size;
		img.height = size;
		parent.appendChild( img );
		return;
	}
	const glyph = document.createElement( 'span' );
	glyph.className    = 'bn-reaction-glyph';
	glyph.textContent  = meta.char || ( meta.label || meta.slug ).charAt( 0 ).toUpperCase();
	glyph.style.width  = size + 'px';
	glyph.style.height = size + 'px';
	if ( /^#[0-9a-fA-F]{6}$/.test( meta.color ) ) {
		glyph.style.color = meta.color;
	}
	parent.appendChild( glyph );
}

function paintCommentReaction( btn, slug, listEl ) {
	const meta = slug ? commentReactionMeta( listEl ).meta[ slug ] || null : null;
	btn.dataset.reaction = slug;
	btn.dataset.liked    = slug ? '1' : '0';
	btn.setAttribute( 'aria-pressed', slug ? 'true' : 'false' );
	paintReactionGlyph( btn.querySelector( '.bn-comment__like-icon' ), meta, 16 );
	const hex = meta && /^#[0-9a-fA-F]{6}$/.test( meta.color ) ? meta.color : '';
	if ( hex ) {
		btn.style.setProperty( '--bn-comment-reaction-color', hex );
	} else {
		btn.style.removeProperty( '--bn-comment-reaction-color' );
	}
	btn.querySelector( '.bn-comment__like-label' ).textContent = meta ? meta.label : t( 'react', 'React' );
}

const bnPickerTimers = new WeakMap();

function openCommentPicker( wrap ) {
	clearTimeout( bnPickerTimers.get( wrap ) );
	const picker = wrap.querySelector( '.bn-comment__react-picker' );
	if ( picker ) {
		// Unhide, then clamp: a hidden element has no box to measure.
		picker.hidden = false;
		bnClampPopoverToViewport( picker );
	}
}

function closeCommentPickerSoon( wrap ) {
	bnPickerTimers.set( wrap, setTimeout( () => {
		const picker = wrap.querySelector( '.bn-comment__react-picker' );
		if ( picker ) {
			picker.hidden = true;
		}
	}, 200 ) );
}

async function sendCommentReaction( btn, type ) {
	if ( viewer().viewerId <= 0 ) {
		bnToast( t( 'signInToReact', 'Sign in to react to comments.' ), { tone: 'info' } );
		return;
	}
	const wrap   = btn.closest( '.bn-comment__react-wrap' );
	const picker = wrap && wrap.querySelector( '.bn-comment__react-picker' );
	if ( picker ) {
		picker.hidden = true;
	}
	const { listEl } = commentScope( btn );
	const card    = btn.closest( '.bn-comment-card' );
	const countEl = btn.querySelector( '.bn-comment__like-count' );
	const prev    = btn.dataset.reaction || '';
	const next    = ( null === type || prev === type ) ? '' : type;
	const cur     = parseInt( countEl.textContent || '0', 10 );
	const delta   = ! prev && next ? 1 : ( prev && ! next ? -1 : 0 );

	// Optimistic, rolled back on failure.
	paintCommentReaction( btn, next, listEl );
	countEl.textContent = String( Math.max( 0, cur + delta ) );
	try {
		const res = await restFetch( '/reactions/toggle', {
			method:  'POST',
			nonce:   viewer().restNonce,
			toastOnError: false,
			body:    { object_type: 'comment', object_id: Number( card.dataset.commentId ), emoji: next || prev || 'like' },
		} );
		if ( ! res.ok ) {
			const err = new Error( 'reaction failed' );
			err.bnResponse = res;
			throw err;
		}
	} catch ( e ) {
		paintCommentReaction( btn, prev, listEl );
		countEl.textContent = String( cur );
		reactionFailureToast( e && e.bnResponse, t( 'reactionUpdateFailed', 'Could not update your reaction. Try again.' ) );
	}
}

/* Reply ------------------------------------------------------------------ */

function toggleReplyForm( card ) {
	const body = card.querySelector( ':scope > .bn-comment__body' );
	let form   = commentPart( card, '.bn-comment__reply-form' );
	if ( ! form ) {
		form = document.createElement( 'div' );
		form.className = 'bn-comment__reply-form';
		form.hidden    = true;
		const ta = document.createElement( 'textarea' );
		ta.className   = 'bn-comment__reply-input';
		ta.placeholder = t( 'writeReply', 'Write a reply...' );
		ta.rows        = 1;
		const submit = document.createElement( 'button' );
		submit.type      = 'button';
		submit.className = 'bn-comment-form__submit';
		submit.setAttribute( 'aria-label', t( 'postReply', 'Post reply' ) );
		submit.innerHTML = BN_ICONS.send;
		const cancel = document.createElement( 'button' );
		cancel.type      = 'button';
		cancel.className = 'bn-comment__reply-cancel';
		setCommentAction( cancel, 'x', t( 'cancel', 'Cancel' ) );
		form.append( ta, submit, cancel );
		// Above the replies, where the client renderer put it.
		body.insertBefore( form, commentPart( card, '.bn-comment__replies' ) );
	}
	form.hidden = ! form.hidden;
	if ( ! form.hidden ) {
		form.querySelector( 'textarea' ).focus();
	}
}

async function submitReply( form ) {
	const card    = form.closest( '.bn-comment-card' );
	const ta      = form.querySelector( 'textarea' );
	const content = ta.value.trim();
	const { postId } = commentScope( form );
	if ( ! content || ! postId ) {
		return;
	}
	// The server refuses a reply below the deepest level, so a reply there
	// joins that level: it goes to the same parent, as a sibling.
	const atCap = Number( card.dataset.depth || 0 ) >= COMMENT_MAX_DEPTH - 1;
	const host  = ( atCap && card.parentElement.closest( '.bn-comment-card' ) ) || card;
	try {
		const res = await restFetch( '/comments', {
			method:  'POST',
			nonce:   viewer().restNonce,
			toastOnError: false,
			body:    { object_type: 'post', object_id: postId, content, parent_id: Number( host.dataset.commentId ), render: 'html' },
		} );
		if ( res.ok ) {
			let replies = commentPart( host, '.bn-comment__replies' );
			if ( ! replies ) {
				replies = document.createElement( 'div' );
				replies.className = 'bn-comment__replies';
				host.querySelector( ':scope > .bn-comment__body' ).appendChild( replies );
			}
			const depth = Number( host.dataset.depth || 0 ) + 1;
			const node  = bnInsertComment( replies, res.data, { postId, depth } );
			if ( node ) {
				node.dataset.depth = String( depth );
			}
			clearField( ta );
			form.hidden = true;
			adjustCommentCount( postId, 1 );
			return;
		}
		const data = res.data || {};
		if ( 'email_unverified' === data.code ) {
			// The reason inline, with a Resend link, so they can fix it here.
			let errEl = form.querySelector( '.bn-comment-submit-error' );
			if ( ! errEl ) {
				errEl = document.createElement( 'div' );
				errEl.className = 'bn-comment-submit-error';
				errEl.setAttribute( 'role', 'alert' );
				form.insertBefore( errEl, form.firstChild );
			}
			errEl.replaceChildren();
			const span = document.createElement( 'span' );
			span.textContent = data.message || t( 'replyFailed', 'Could not post reply. Try again.' );
			errEl.appendChild( span );
			appendResendVerifyButton( errEl, viewer().restNonce );
		} else {
			bnToast( data.message || t( 'replyFailed', 'Could not post reply. Try again.' ), { tone: 'danger' } );
		}
	} catch ( _e ) {
		bnToast( t( 'replyFailed', 'Could not post reply. Try again.' ), { tone: 'danger' } );
	}
}

/* Edit ------------------------------------------------------------------- */

function openCommentEditor( card ) {
	if ( commentPart( card, '.bn-comment__edit-form' ) ) {
		return;
	}
	const para = commentPart( card, '.bn-comment__content' );
	const form = document.createElement( 'div' );
	form.className = 'bn-comment__edit-form';
	const ta = document.createElement( 'textarea' );
	ta.className = 'bn-comment-form__input';
	ta.value     = para ? para.textContent || '' : '';
	ta.rows      = 2;
	const save = document.createElement( 'button' );
	save.type      = 'button';
	save.className = 'bn-comment-form__submit';
	save.innerHTML = BN_ICONS.check;
	save.setAttribute( 'aria-label', t( 'save', 'Save' ) );
	const cancel = document.createElement( 'button' );
	cancel.type      = 'button';
	cancel.className = 'bn-comment__reply-cancel';
	setCommentAction( cancel, 'x', t( 'cancel', 'Cancel' ) );
	const row = document.createElement( 'div' );
	row.className = 'bn-comment__edit-actions';
	// Emoji insert, when the owner turned the emoji picker on.
	const emoji = bnEmojiTrigger( ta, 'bn-comment-edit-' + card.dataset.commentId );
	if ( emoji ) {
		row.appendChild( emoji );
	}
	row.append( save, cancel );
	form.append( ta, row );
	if ( para ) {
		para.hidden = true;
	}
	card.querySelector( ':scope > .bn-comment__body' ).insertBefore( form, commentPart( card, '.bn-comment__actions' ) );
	ta.focus();
}

function closeCommentEditor( form ) {
	const card = form.closest( '.bn-comment-card' );
	const para = commentPart( card, '.bn-comment__content' );
	form.remove();
	if ( para ) {
		para.hidden = false;
	}
}

async function saveCommentEdit( form ) {
	const card = form.closest( '.bn-comment-card' );
	const next = form.querySelector( 'textarea' ).value.trim();
	if ( ! next ) {
		return;
	}
	try {
		const res = await restFetch( '/comments/' + card.dataset.commentId, {
			method:  'PUT',
			nonce:   viewer().restNonce,
			toastOnError: false,
			body:    { content: next, render: 'html' },
		} );
		if ( ! res.ok ) {
			bnToast( t( 'commentUpdateFailed', 'Could not update comment. Try again.' ), { tone: 'danger' } );
			return;
		}
		// The server's formatted text, so @mentions and #hashtags stay links.
		const para = commentPart( card, '.bn-comment__content' );
		if ( para ) {
			para.innerHTML = res.data.content_html || '';
		}
		const header = commentPart( card, '.bn-comment__header' );
		// The server decides: an unchanged save is not an edit.
		if ( res.data.is_edited && header && ! header.querySelector( '.bn-comment__edited' ) ) {
			const mark = document.createElement( 'span' );
			mark.className   = 'bn-comment__edited';
			mark.textContent = t( 'edited', '(edited)' );
			header.appendChild( mark );
		}
		closeCommentEditor( form );
		bnToast( t( 'commentUpdated', 'Comment updated' ), { tone: 'success' } );
	} catch ( _e ) {
		bnToast( t( 'commentUpdateFailed', 'Could not update comment. Try again.' ), { tone: 'danger' } );
	}
}

/* Delete, pin, report ------------------------------------------------------ */

async function deleteComment( card ) {
	const ok = await bnConfirm( {
		title: t( 'deleteCommentTitle', 'Delete this comment?' ),
		body: t( 'cannotBeUndone', 'This cannot be undone.' ),
		confirmLabel: t( 'delete', 'Delete' ),
		tone: 'danger',
	} );
	if ( ! ok ) {
		return;
	}
	const { postId } = commentScope( card );
	const res = await restFetch( '/comments/' + card.dataset.commentId, { method: 'DELETE', nonce: viewer().restNonce, toastOnError: false } );
	if ( ! res.ok ) {
		bnToast( t( 'commentDeleteFailed', 'Could not delete comment. Try again.' ), { tone: 'danger' } );
		return;
	}
	// Soft delete: the thread keeps its shape; the text becomes a placeholder.
	card.classList.add( 'bn-comment-card--deleted' );
	const para = commentPart( card, '.bn-comment__content' );
	if ( para ) {
		para.textContent         = t( 'commentDeleted', 'This comment was deleted.' );
		para.dataset.placeholder = '1';
		para.hidden              = false;
	}
	commentPart( card, '.bn-comment__actions' )?.remove();
	adjustCommentCount( postId, -1 );
	bnToast( t( 'commentDeletedToast', 'Comment deleted' ), { tone: 'success' } );
}

async function toggleCommentPin( card, btn ) {
	const wasPinned = card.classList.contains( 'bn-comment-card--pinned' );
	try {
		const res = await restFetch( '/comments/' + card.dataset.commentId + '/pin', {
			method: wasPinned ? 'DELETE' : 'POST', nonce: viewer().restNonce, toastOnError: false,
		} );
		if ( ! res.ok ) {
			bnToast( t( 'pinStatusFailed', 'Could not change pin status. Try again.' ), { tone: 'danger' } );
			return;
		}
		card.classList.toggle( 'bn-comment-card--pinned', ! wasPinned );
		setCommentActionLabel( btn, wasPinned ? t( 'pin', 'Pin' ) : t( 'unpin', 'Unpin' ) );
		const header = commentPart( card, '.bn-comment__header' );
		const badge  = header && header.querySelector( '.bn-comment__pinned-badge' );
		if ( wasPinned && badge ) {
			badge.remove();
		} else if ( ! wasPinned && header && ! badge ) {
			const pin = document.createElement( 'span' );
			pin.className   = 'bn-comment__pinned-badge';
			pin.textContent = t( 'pinned', 'Pinned' );
			header.insertBefore( pin, header.querySelector( '.bn-comment__time' ) );
		}
		bnToast( wasPinned ? t( 'commentUnpinned', 'Comment unpinned' ) : t( 'commentPinned', 'Comment pinned' ), { tone: 'success' } );
	} catch ( _e ) {
		bnToast( t( 'pinStatusFailed', 'Could not change pin status. Try again.' ), { tone: 'danger' } );
	}
}

async function reportComment( card, btn ) {
	const result = await bnReportDialog( { title: t( 'reportComment', 'Report this comment' ) } );
	if ( null === result ) {
		return;
	}
	// The control shows the report landed, like the post-level Report.
	const markReported = () => {
		btn.disabled = true;
		btn.classList.add( 'is-reported' );
		setCommentActionLabel( btn, t( 'reported', 'Reported' ) );
	};
	try {
		const res = await restFetch( '/reports', {
			method:  'POST',
			nonce:   viewer().restNonce,
			toastOnError: false,
			body:    { object_type: 'comment', object_id: Number( card.dataset.commentId ), reason: result.reason, notes: result.notes },
		} );
		const data = res.data || {};
		if ( res.ok || 201 === res.status ) {
			markReported();
			bnToast( t( 'reportSubmitted', 'Report submitted. Thanks for keeping the community safe.' ), { tone: 'success' } );
		} else if ( 409 === res.status ) {
			markReported();
			bnToast( data.message || t( 'reportAlready', 'You already reported this.' ), { tone: 'info' } );
		} else {
			bnToast( data.message || t( 'reportFailed', 'Could not submit report. Try again.' ), { tone: 'danger' } );
		}
	} catch ( _e ) {
		bnToast( t( 'reportFailed', 'Could not submit report. Try again.' ), { tone: 'danger' } );
	}
}

/* Delegated listeners (installed once) ------------------------------------ */

document.addEventListener( 'click', ( e ) => {
	const target = e.target instanceof Element ? e.target : null;
	if ( ! target ) {
		return;
	}
	// A picker opened by tap closes when the tap lands elsewhere.
	document.querySelectorAll( '.bn-comment__react-picker:not([hidden])' ).forEach( ( picker ) => {
		if ( ! picker.parentElement.contains( target ) ) {
			picker.hidden = true;
		}
	} );
	const { listEl } = commentScope( target );
	if ( ! listEl ) {
		return;
	}
	let el;
	if ( ( el = target.closest( '.bn-comment__like-btn' ) ) ) {
		e.preventDefault();
		const picker   = el.closest( '.bn-comment__react-wrap' ).querySelector( '.bn-comment__react-picker' );
		const fallback = commentReactionMeta( listEl ).fallback;
		// Closed picker (touch, keyboard): open it; a guest gets the sign-in note.
		// Open picker: quick-toggle the first enabled reaction or clear mine.
		if ( picker && picker.hidden ) {
			if ( viewer().viewerId <= 0 ) {
				sendCommentReaction( el, fallback );
			} else {
				openCommentPicker( el.closest( '.bn-comment__react-wrap' ) );
			}
			return;
		}
		sendCommentReaction( el, el.dataset.reaction ? null : fallback );
		return;
	}
	if ( ( el = target.closest( '.bn-comment__react-option' ) ) ) {
		// Keyboard activation only; pointer picks are handled on pointerdown.
		if ( 0 === e.detail ) {
			sendCommentReaction( el.closest( '.bn-comment__react-wrap' ).querySelector( '.bn-comment__like-btn' ), el.dataset.reaction );
		}
		return;
	}
	if ( ( el = target.closest( '.bn-comment-loadmore' ) ) ) {
		bnLoadMoreComments( listEl, el );
		return;
	}
	const card = target.closest( '.bn-comment-card' );
	if ( ! card ) {
		return;
	}
	if ( ( el = target.closest( '.bn-comment__reply-form' ) ) ) {
		if ( target.closest( '.bn-comment__reply-cancel' ) ) {
			el.hidden = true;
		} else if ( target.closest( '.bn-comment-form__submit' ) ) {
			submitReply( el );
		}
		return;
	}
	if ( ( el = target.closest( '.bn-comment__edit-form' ) ) ) {
		if ( target.closest( '.bn-comment__reply-cancel' ) ) {
			closeCommentEditor( el );
		} else if ( target.closest( '.bn-comment-form__submit' ) ) {
			saveCommentEdit( el );
		}
		return;
	}
	if ( target.closest( '.bn-comment__reply-btn' ) ) {
		toggleReplyForm( card );
	} else if ( target.closest( '.bn-comment__edit-btn' ) ) {
		openCommentEditor( card );
	} else if ( target.closest( '.bn-comment__delete-btn' ) ) {
		deleteComment( card );
	} else if ( ( el = target.closest( '.bn-comment__pin-btn' ) ) ) {
		toggleCommentPin( card, el );
	} else if ( ( el = target.closest( '.bn-comment__report-btn' ) ) ) {
		reportComment( card, el );
	}
} );

// Picking a reaction must land before a blur/leave closes the picker.
document.addEventListener( 'pointerdown', ( e ) => {
	const opt = e.target instanceof Element ? e.target.closest( '.bn-comment-list .bn-comment__react-option' ) : null;
	if ( ! opt ) {
		return;
	}
	e.preventDefault();
	const wrap = opt.closest( '.bn-comment__react-wrap' );
	clearTimeout( bnPickerTimers.get( wrap ) );
	sendCommentReaction( wrap.querySelector( '.bn-comment__like-btn' ), opt.dataset.reaction );
} );

// Hover and focus reveal the picker on pointer and keyboard devices.
const bnPickerWrapFrom = ( e ) => ( e.target instanceof Element ? e.target.closest( '.bn-comment-list .bn-comment__react-wrap' ) : null );
const bnLeftWrap       = ( wrap, related ) => ! ( related instanceof Node && wrap.contains( related ) );
document.addEventListener( 'mouseover', ( e ) => {
	const wrap = bnPickerWrapFrom( e );
	if ( wrap && bnLeftWrap( wrap, e.relatedTarget ) ) {
		openCommentPicker( wrap );
	}
} );
document.addEventListener( 'mouseout', ( e ) => {
	const wrap = bnPickerWrapFrom( e );
	if ( wrap && bnLeftWrap( wrap, e.relatedTarget ) ) {
		closeCommentPickerSoon( wrap );
	}
} );
document.addEventListener( 'focusin', ( e ) => {
	const wrap = bnPickerWrapFrom( e );
	if ( wrap ) {
		openCommentPicker( wrap );
	}
} );
document.addEventListener( 'focusout', ( e ) => {
	const wrap = bnPickerWrapFrom( e );
	if ( wrap && bnLeftWrap( wrap, e.relatedTarget ) ) {
		closeCommentPickerSoon( wrap );
	}
} );

// An avatar that fails to load falls back to the initials the server put on it.
document.addEventListener( 'error', ( e ) => {
	const img = e.target;
	if ( ! ( img instanceof HTMLImageElement ) || ! img.parentElement || ! img.parentElement.classList.contains( 'bn-comment__avatar' ) ) {
		return;
	}
	const avatar = img.parentElement;
	const letters = img.dataset.bnInitials || '';
	img.remove();
	avatar.textContent = letters;
}, true );

function adjustCommentCount( postId, delta ) {
	const card = document.querySelector( 'article[data-post-id="' + postId + '"]' );
	const span = card?.querySelector( '[data-wp-on--click="actions.openComments"] .bn-post-card__action-count' );
	if ( span ) {
		setCommentCount( card, parseInt( span.textContent || '0', 10 ) + delta );
	}
}

/**
 * Show a post card's comment count.
 *
 * Single source of truth for the comment count: every add (top-level + reply),
 * delete and realtime update routes through here, so it owns the visible chip,
 * its hidden state, and the button's accessible name in lock-step. (Delete is a
 * vanilla-JS handler with no Interactivity context, so a reactive binding could
 * never stay in sync with it.)
 *
 * @param {Element|null} card The post card article.
 * @param {number}       n    Comment count.
 * @return {void}
 */
function setCommentCount( card, n ) {
	const btn  = card?.querySelector( '[data-wp-on--click="actions.openComments"]' );
	const span = btn?.querySelector( '.bn-post-card__action-count' );
	if ( ! span || ! btn ) {
		return;
	}
	n = Math.max( 0, n );
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
function bnRenderCommentPage( listEl, data ) {
	const items   = data.items || [];
	const total   = Number( data.total ) || items.length;
	const page    = Number( data.page ) || Number( listEl.dataset.page ) || 1;
	const perPage = Number( data.per_page ) || bnCommentPageSize( listEl );
	const postId  = Number( listEl.dataset.commentList ) || 0;

	// Drop any prior load-more button and truncation notice before appending
	// this page's items (both are re-derived from the response below).
	listEl.querySelector( ':scope > .bn-comment-loadmore' )?.remove();
	listEl.querySelector( ':scope > .bn-comment-truncated' )?.remove();

	items.forEach( ( comment ) => bnInsertComment( listEl, comment, { postId, depth: 0 } ) );

	const shown = page * perPage;
	if ( shown < total ) {
		const btn = document.createElement( 'button' );
		btn.type        = 'button';
		btn.className   = 'bn-comment-loadmore';
		btn.textContent = fmt( t( 'viewMoreCommentsCount', 'View more comments (%d)' ), total - shown );
		listEl.appendChild( btn );
	}

	// The server caps how many deep replies one page loads and flags it; say so,
	// so a capped thread is not presented as the whole conversation.
	if ( data.replies_truncated ) {
		const note = document.createElement( 'p' );
		note.className = 'bn-comment-truncated';
		note.setAttribute( 'role', 'note' );
		note.textContent = t( 'repliesTruncated', "Some replies aren't shown." );
		listEl.appendChild( note );
	}
}

/**
 * Top-level comments per page, as the server published it on the list.
 *
 * @param {Element} listEl The [data-comment-list] container.
 * @return {number}
 */
function bnCommentPageSize( listEl ) {
	return Number( listEl.dataset.perPage ) || COMMENT_FIRST_PAGE;
}

/**
 * Fetch and append the next page of comments (the load-more click handler).
 *
 * @param {Element} listEl The [data-comment-list] container.
 * @param {Object}  ctx    Post-card context.
 * @param {Element} btn    The load-more button (disabled while fetching).
 * @return {Promise<void>}
 */
async function bnLoadMoreComments( listEl, btn ) {
	const nextPage = ( Number( listEl.dataset.page ) || 1 ) + 1;
	btn.disabled    = true;
	btn.textContent = t( 'loadingComments', 'Loading…' );

	try {
		const res = await restFetch(
			'/comments?object_type=post&object_id=' + listEl.dataset.commentList + '&per_page=' + bnCommentPageSize( listEl ) + '&page=' + nextPage + '&render=html',
			{ nonce: viewer().restNonce, toastOnError: false }
		);
		if ( res.ok ) {
			listEl.dataset.page = String( nextPage );
			bnRenderCommentPage( listEl, res.data );
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
			'/comments?object_type=post&object_id=' + ctx.postId + '&per_page=' + bnCommentPageSize( listEl ) + '&page=1&render=html',
			{ nonce: viewer().restNonce, toastOnError: false }
		);
		while ( listEl.firstChild ) {
			listEl.removeChild( listEl.firstChild );
		}
		if ( res.ok ) {
			listEl.dataset.loaded = '1';
			listEl.dataset.page   = '1';
			bnRenderCommentPage( listEl, res.data );
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
				return ctx.reactionLabel || viewer().reactDefaultLabel || t( 'react', 'React' );
			} catch ( _e ) { return t( 'react', 'React' ); }
		},
		get pollOptionPctText() {
			try {
				const ctx = getContext();
				const opt = ( ctx.pollOptions || [] ).find( ( o ) => o.id === ctx.optionId );
				return opt ? opt.pct + '%' : '0%';
			} catch ( _e ) { return '0%'; }
		},
		// "Option text: 42%" - the label a screen reader hears, redrawn with the live
		// percentage. The member's own choice adds "your vote", so it is never told
		// by colour alone.
		get pollOptionLabel() {
			try {
				const ctx = getContext();
				const opt = ( ctx.pollOptions || [] ).find( ( o ) => o.id === ctx.optionId );
				const pct = opt ? opt.pct : 0;
				if ( ctx.pollVotedOptionId && ctx.pollVotedOptionId === ctx.optionId ) {
					return fmt( t( 'pollOptionYourVote', '%1$s: %2$d%%, your vote' ), ctx.optionText || '', pct );
				}
				return ( ctx.optionText || '' ) + ': ' + pct + '%';
			} catch ( _e ) { return ''; }
		},
		get pollOptionPressed() {
			try {
				const ctx = getContext();
				return ctx.pollVotedOptionId && ctx.pollVotedOptionId === ctx.optionId ? 'true' : 'false';
			} catch ( _e ) { return 'false'; }
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
			if ( ctx && ctx.postId ) {
				bnMountedCards.push( { ctx, el: getElement()?.ref || null } );
			}
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
		/**
		 * See more: show the rest of a long post in place.
		 *
		 * The control is a link to the permalink. A modified or non-primary click
		 * keeps that job (new tab / window); a plain click expands the card instead
		 * and hands focus to the revealed text, since the link itself disappears.
		 *
		 * @param {MouseEvent} event Click event.
		 */
		expandBody( event ) {
			if ( event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}
			event.preventDefault();
			const ctx = getContext();
			ctx.bodyExpanded = true;

			const link = getElement()?.ref || null;
			const body = link ? document.getElementById( link.getAttribute( 'aria-controls' ) || '' ) : null;
			if ( body ) {
				body.focus( { preventScroll: true } );
			}
		},

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
			ctx.reactionLabel = newType ? label : ( viewer().reactDefaultLabel || 'React' );

			try {
				const res = yield restFetch( '/reactions/toggle', {
					method:  'POST',
					nonce:   viewer().restNonce,
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
					nonce: viewer().restNonce,
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
					{ nonce: viewer().restNonce, toastOnError: false }
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
			// Hand focus back to the button that opened the popup (WAI-ARIA menu
			// pattern). On phones the menu is a sheet that takes focus, so closing it
			// without this left keyboard and screen-reader users nowhere.
			const ref    = getElement()?.ref || null;
			const opener = ! ref ? null
				: ctx.optionsOpen ? ref.querySelector( 'button.bn-post-card__menu' )
				: ctx.reactionPickerOpen ? ref.querySelector( '[data-wp-on--click="actions.toggleReactionPicker"]' )
				: null;
			ctx.reactionPickerOpen = false;
			ctx.optionsOpen        = false;
			ctx.reactorsOpen       = false;
			if ( opener ) {
				opener.focus();
			}
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
					nonce:   viewer().restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					const card   = document.querySelector( '[data-post-id="' + ctx.postId + '"]' );
					const single = card ? card.closest( '.bn-single-post' ) : null;
					const back   = single ? single.querySelector( '.bn-single-post__breadcrumb a[href]' ) : null;
					// On the post's own page there is nothing left to show: go back to
					// the feed the breadcrumb names, instead of leaving an empty page.
					if ( back ) {
						window.location.href = back.href;
						return;
					}
					card?.remove();
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
						nonce:     viewer().restNonce,
						restUrl:   viewer().restUrl,
						canRepost: !! btn && '1' === btn.getAttribute( 'data-can-repost' ),
						shareable: !! btn && '1' === btn.getAttribute( 'data-shareable' ),
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
					nonce:   viewer().restNonce,
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
					nonce:   viewer().restNonce,
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
					if ( card && card.classList.contains( 'bn-single-post' ) ) {
						// On the post's own page the card IS the page: removing it leaves a blank
						// screen. Swap the post for a short reported state and a way back.
						showReportedState( card );
					} else if ( card ) {
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
			let postType    = '';
			let postSpaceId = 0;
			let mediaItems  = [];
			try {
				const res = yield restFetch( '/posts/' + ctx.postId, {
					nonce: viewer().restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					const data = res.data;
					rawContent = ( data && typeof data.content === 'string' ) ? data.content : '';
					// The same GET already carries the row's status + slot, so the reschedule
					// control costs no extra request.
					isScheduled = !! data && 'scheduled' === data.status;
					schedUtc    = ( data && data.scheduled_at ) ? String( data.scheduled_at ) : '';
					postType    = ( data && data.type ) ? String( data.type ) : '';
					postSpaceId = ( data && data.space_id ) ? parseInt( data.space_id, 10 ) || 0 : 0;
					mediaItems  = ( data && Array.isArray( data.media ) ) ? data.media : [];
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

			// Photos and videos: add and remove on a text or photo post, the same
			// tiles as the composer. The list sent on Save is the post's whole new
			// media set; the server checks every id is the author's and detaches the
			// removed ones (they stay in the author's media library).
			let mediaIds     = mediaItems.map( ( m ) => parseInt( m.id, 10 ) ).filter( Boolean );
			const mediaStart = mediaIds.join( ',' );
			let uploading    = 0;
			if ( 'text' === postType || 'photo' === postType ) {
				const mediaRow = document.createElement( 'div' );
				mediaRow.className = 'bn-post-card__edit-media';

				const tiles = document.createElement( 'div' );
				tiles.className = 'bn-composer__media-preview';

				const addTile = ( id, src, kind ) => {
					const tile = document.createElement( 'div' );
					tile.className = 'bn-composer__media-thumb bn-composer__media-thumb--' + ( kind || 'image' );
					if ( id ) {
						tile.dataset.mediaId = String( id );
					} else {
						tile.classList.add( 'is-uploading' );
					}
					if ( src ) {
						const img = document.createElement( 'img' );
						img.src = src;
						img.alt = '';
						img.width = 80;
						img.height = 80;
						img.loading = 'lazy';
						tile.appendChild( img );
					}
					const remove = document.createElement( 'button' );
					remove.type = 'button';
					remove.className = 'bn-composer__media-remove';
					remove.textContent = '×';
					remove.setAttribute( 'aria-label', t( 'removeMedia', 'Remove from post' ) );
					remove.hidden = ! id;
					remove.addEventListener( 'click', () => {
						const mid = parseInt( tile.dataset.mediaId, 10 );
						mediaIds = mediaIds.filter( ( x ) => x !== mid );
						tile.remove();
						addBtn.focus();
					} );
					tile.appendChild( remove );
					tiles.appendChild( tile );
					return tile;
				};
				mediaItems.forEach( ( m ) => addTile( m.id, m.thumb_url || m.url || '', 'video' === m.type ? 'video' : 'image' ) );

				const picker = document.createElement( 'input' );
				picker.type = 'file';
				picker.accept = 'image/*,video/*';
				picker.multiple = true;
				picker.hidden = true;

				const addBtn = document.createElement( 'button' );
				addBtn.type = 'button';
				addBtn.className = 'bn-btn';
				addBtn.dataset.variant = 'secondary';
				addBtn.dataset.size = 'sm';
				addBtn.textContent = t( 'addPhotoOrVideo', 'Add photo or video' );
				addBtn.addEventListener( 'click', () => picker.click() );

				picker.addEventListener( 'change', async () => {
					const files = Array.from( picker.files || [] );
					picker.value = '';
					// Two passes, as in the composer: every picked file gets its tile and
					// a "Waiting" pill at once, then each upload shows its percentage.
					const jobs = [];
					for ( const file of files ) {
						const invalid = validateMedia( file, { badTypeMsg: t( 'mediaBadType', 'Only images, video and audio can be attached.' ) } );
						if ( invalid ) {
							bnToast( invalid, { tone: 'danger' } );
							continue;
						}
						const kind = /^video\//.test( file.type || '' ) ? 'video' : 'image';
						const tile = addTile( 0, 'image' === kind ? URL.createObjectURL( file ) : '', kind );
						const progress = document.createElement( 'span' );
						progress.className = 'bn-composer__media-progress';
						progress.setAttribute( 'role', 'progressbar' );
						progress.setAttribute( 'aria-label', fmt( t( 'uploadingFile', 'Uploading %s' ), file.name || '' ) );
						progress.setAttribute( 'aria-valuemin', '0' );
						progress.setAttribute( 'aria-valuemax', '100' );
						progress.setAttribute( 'aria-valuenow', '0' );
						progress.textContent = t( 'uploadWaiting', 'Waiting' );
						tile.appendChild( progress );
						uploading++;
						jobs.push( { file, tile, progress } );
					}
					saveBtn.disabled = uploading > 0;
					for ( const { file, tile, progress } of jobs ) {
						progress.textContent = '0%';
						// Staged private like the composer's uploads; the post's own
						// privacy is applied to it when the edit is saved.
						const out = await uploadMedia( file, {
							nonce: viewer().restNonce,
							privacy: 'private',
							spaceId: postSpaceId,
							onProgress: ( percent ) => {
								progress.textContent = percent + '%';
								progress.setAttribute( 'aria-valuenow', String( percent ) );
							},
						} );
						progress.remove();
						uploading--;
						saveBtn.disabled = uploading > 0;
						if ( out.ok && out.mediaId ) {
							mediaIds.push( parseInt( out.mediaId, 10 ) );
							tile.dataset.mediaId = String( out.mediaId );
							tile.classList.remove( 'is-uploading' );
							tile.querySelector( '.bn-composer__media-remove' ).hidden = false;
						} else {
							tile.remove();
							bnToast( out.message || t( 'mediaUploadFailed', 'That file could not be uploaded.' ), { tone: 'danger' } );
						}
					}
				} );

				mediaRow.appendChild( tiles );
				// Removing is always allowed; adding only where the site lets this member upload.
				if ( viewer().canUploadMedia ) {
					mediaRow.appendChild( addBtn );
					mediaRow.appendChild( picker );
				}
				form.appendChild( mediaRow );
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

			const emojiBtn = bnEmojiTrigger( ta, 'bn-post-edit-' + ( ctx.postId || '' ) );
			if ( emojiBtn ) {
				bar.appendChild( emojiBtn );
			}
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
				const next         = ta.value.trim();
				const mediaChanged = mediaIds.join( ',' ) !== mediaStart;
				if ( '' === next && ! mediaIds.length ) {
					bnToast( t( 'postContentEmpty', 'Post content cannot be empty.' ), { tone: 'info' } );
					return;
				}
				const payload = { content: next };
				if ( mediaChanged ) {
					payload.media_ids = mediaIds;
				}
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
						nonce:   viewer().restNonce,
						toastOnError: false,
						body:    payload,
					} );
					if ( ! res.ok ) {
						const msg = res.data && res.data.message ? String( res.data.message ) : '';
						throw new Error( msg || 'update failed' );
					}
					// New or removed photos change the card's gallery, which is server
					// rendered; reload it, the same as the composer does after posting.
					if ( mediaChanged ) {
						bnReloadWithToast( t( 'postUpdated', 'Post updated' ), 'success' );
						return;
					}
					// Reflect the saved text immediately (line breaks preserved). Full
					// mention/hashtag formatting re-applies on the next page load.
					contentEl.textContent = next;
					// The server has cleared link_url/link_meta, so drop the card for
					// real rather than leaving it hidden until the next page load.
					if ( previewEl && removePreview ) {
						previewEl.remove();
					}
					// The server decides: edited_at stays empty for an unchanged
					// save or an audience-only change.
					if ( res.data && res.data.edited_at && ! card.querySelector( '.bn-post-card__edited' ) ) {
						const mark = document.createElement( 'span' );
						mark.className = 'bn-post-card__edited';
						mark.textContent = t( 'editedSpaced', ' (edited)' );
						contentEl.appendChild( mark );
					}
					teardown();
					bnToast( t( 'postUpdated', 'Post updated' ), { tone: 'success' } );
				} catch ( err ) {
					saveBtn.disabled = false;
					const reason = err && err.message && 'update failed' !== err.message ? err.message : '';
					bnToast( reason || t( 'postUpdateFailed', 'Could not update the post. Try again.' ), { tone: 'danger' } );
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
					nonce:   viewer().restNonce,
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
					nonce:   viewer().restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					bnReloadWithToast( t( 'postUnpinned', 'Post unpinned' ), { tone: 'success' } );
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
					appendResendVerifyButton( alertEl, viewer().restNonce );
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
					nonce:   viewer().restNonce,
					toastOnError: false,
					body:    { object_type: 'post', object_id: ctx.postId, content, render: 'html' },
				} );
				if ( res.ok ) {
					// Clear any stale error alert from a previous failed attempt.
					inputEl?.closest( '.bn-post-card__comments' )?.querySelector( '.bn-comment-submit-error' )?.remove();
					const listEl = document.querySelector( '[data-comment-list="' + ctx.postId + '"]' );
					if ( listEl ) {
						listEl.dataset.loaded = '1';
						bnInsertComment( listEl, res.data, { postId: ctx.postId, depth: 0 } );
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
					nonce:   viewer().restNonce,
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
				} else if ( ! ( res && res.suspended ) ) {
					// A closed / rejected poll answers with a real reason ("This
					// poll has closed.", "You have already voted."). Dropping it
					// left the option looking simply unclickable — surface it. A
					// suspension refusal is skipped here because rest-client already
					// toasted the appeal link centrally, else the two stack to "x2"
					// (card 10264293681 RFT round 4).
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
					nonce:   viewer().restNonce,
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
					t( 'announcementDismissFailed', 'Could not dismiss this announcement. Try again.' ),
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
					nonce:   viewer().restNonce,
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
