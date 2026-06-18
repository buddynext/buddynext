/* BuddyNext — Feed Interactivity API store. */
import { store, getContext, getElement } from '@wordpress/interactivity';
import { bnConfirm, bnPrompt, bnReportDialog, bnToast } from '../shell/dialog.js';

/* ── Comment helpers (vanilla DOM — outside WP Interactivity API scope) ── */

function timeAgo( dateStr ) {
	const secs = Math.floor( ( Date.now() - new Date( dateStr ).getTime() ) / 1000 );
	if ( secs < 60 )    return 'just now';
	if ( secs < 3600 )  return Math.floor( secs / 60 ) + 'm ago';
	if ( secs < 86400 ) return Math.floor( secs / 3600 ) + 'h ago';
	return Math.floor( secs / 86400 ) + 'd ago';
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
/**
 * Apply a wp.hooks JS filter when available, otherwise return the value
 * unchanged. Lets extensions decorate comment rendering without forking this
 * module; degrades silently when @wordpress/hooks is not present.
 *
 * @param {string} hook  Filter name.
 * @param {*}      value Value to filter.
 * @param {...*}   args  Extra context arguments.
 * @return {*} Filtered value (or the original when hooks are unavailable).
 */
function bnApplyFilters( hook, value, ...args ) {
	if ( window.wp && window.wp.hooks && typeof window.wp.hooks.applyFilters === 'function' ) {
		return window.wp.hooks.applyFilters( hook, value, ...args );
	}
	return value;
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

	// Avatar initials.
	const avatar = document.createElement( 'div' );
	avatar.className = 'bn-comment__avatar';
	avatar.setAttribute( 'aria-hidden', 'true' );
	avatar.textContent = ( comment.author_name || 'U' ).split( ' ' ).map( ( w ) => w[ 0 ] || '' ).join( '' ).slice( 0, 2 ).toUpperCase();
	wrap.appendChild( avatar );

	const body = document.createElement( 'div' );
	body.className = 'bn-comment__body';

	// Header: author name + timestamp + pinned badge + edited marker.
	const header = document.createElement( 'div' );
	header.className = 'bn-comment__header';
	const authorSpan = document.createElement( 'span' );
	authorSpan.className = 'bn-comment__author';
	authorSpan.textContent = comment.author_name || 'User';
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
		pinBadge.textContent = 'Pinned';
		header.appendChild( pinBadge );
	}
	const timeEl = document.createElement( 'time' );
	timeEl.className = 'bn-comment__time';
	timeEl.textContent = timeAgo( comment.created_at );
	header.appendChild( timeEl );
	if ( comment.is_edited && ! comment.is_deleted ) {
		const editedMark = document.createElement( 'span' );
		editedMark.className = 'bn-comment__edited';
		editedMark.textContent = '(edited)';
		header.appendChild( editedMark );
	}
	body.appendChild( header );

	// Content paragraph (or placeholder for soft-deleted comments).
	const para = document.createElement( 'p' );
	para.className = 'bn-comment__content';
	if ( comment.is_deleted ) {
		para.textContent  = 'This comment was deleted.';
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
		// Resolve the colored Fluent Emoji vendor base via the comment-list
		// container keyed by postId. wrap.closest() can't be used here
		// because the wrap is not yet attached to the DOM at this point.
		const list      = document.querySelector( `.bn-comment-list[data-comment-list="${ postId }"]` );
		const emojiBase = list ? list.dataset.emojiBase : '';
		const REACTIONS = [ 'like', 'love', 'haha', 'wow', 'sad', 'angry' ];
		const REACTION_LABELS = {
			like: 'Like', love: 'Love', haha: 'Haha', wow: 'Wow', sad: 'Sad', angry: 'Angry',
		};

		const setReactionIcon = ( parent, type ) => {
			parent.replaceChildren();
			if ( type && emojiBase ) {
				const img = document.createElement( 'img' );
				img.src = emojiBase + type + '.svg';
				img.alt = REACTION_LABELS[ type ] || '';
				img.width = 16;
				img.height = 16;
				parent.appendChild( img );
			} else {
				parent.textContent = '♡';
			}
		};

		const wrapBtn = document.createElement( 'span' );
		wrapBtn.className = 'bn-comment__react-wrap';

		reactBtn = document.createElement( 'button' );
		reactBtn.type = 'button';
		reactBtn.className = 'bn-comment__like-btn';
		reactBtn.dataset.reaction = comment.viewer_liked ? ( comment.viewer_reaction || 'like' ) : '';
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
			? ( REACTION_LABELS[ reactBtn.dataset.reaction ] || 'React' )
			: 'React';

		const reactCount = document.createElement( 'span' );
		reactCount.className = 'bn-comment__like-count';
		reactCount.textContent = String( comment.like_count || 0 );

		reactBtn.appendChild( reactIcon );
		reactBtn.appendChild( document.createTextNode( ' ' ) );
		reactBtn.appendChild( reactLabel );
		reactBtn.appendChild( document.createTextNode( ' ' ) );
		reactBtn.appendChild( reactCount );

		// 6-emoji picker dropdown.
		const picker = document.createElement( 'div' );
		picker.className = 'bn-comment__react-picker';
		picker.hidden = true;
		picker.setAttribute( 'role', 'toolbar' );
		picker.setAttribute( 'aria-label', 'Choose reaction' );
		REACTIONS.forEach( ( type ) => {
			const opt = document.createElement( 'button' );
			opt.type = 'button';
			opt.className = 'bn-comment__react-option';
			opt.setAttribute( 'aria-label', REACTION_LABELS[ type ] );
			opt.title = REACTION_LABELS[ type ];
			opt.dataset.reaction = type;
			if ( emojiBase ) {
				const img = document.createElement( 'img' );
				img.src = emojiBase + type + '.svg';
				img.alt = REACTION_LABELS[ type ];
				img.width = 28;
				img.height = 28;
				opt.appendChild( img );
			} else {
				opt.textContent = REACTION_LABELS[ type ];
			}
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
		const openPicker  = () => { clearTimeout( hoverTimer ); picker.hidden = false; };
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
					sendReaction( 'like' ); // surfaces the sign-in toast.
					return;
				}
				openPicker();
				return;
			}
			sendReaction( reactBtn.dataset.reaction ? null : 'like' );
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
				bnToast( 'Sign in to react to comments.', { tone: 'info' } );
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
			reactLabel.textContent = next ? ( REACTION_LABELS[ next ] || 'React' ) : 'React';
			const cur = parseInt( reactCount.textContent || '0', 10 );
			let delta = 0;
			if ( ! prev && next ) { delta = 1; }
			else if ( prev && ! next ) { delta = -1; }
			reactCount.textContent = String( Math.max( 0, cur + delta ) );

			try {
				const res = await fetch( restUrl + '/reactions/toggle', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body:    JSON.stringify( {
						object_type: 'comment',
						object_id:   comment.id,
						emoji:       next || prev || 'like',
					} ),
				} );
				if ( ! res.ok ) { throw new Error( 'reaction failed' ); }
			} catch ( _e ) {
				// Rollback to prev.
				reactBtn.dataset.reaction = prev;
				reactBtn.dataset.liked = prev ? '1' : '0';
				reactBtn.setAttribute( 'aria-pressed', prev ? 'true' : 'false' );
				setReactionIcon( reactIcon, prev );
				reactLabel.textContent = prev ? ( REACTION_LABELS[ prev ] || 'React' ) : 'React';
				reactCount.textContent = String( cur );
				bnToast( 'Could not update your reaction. Try again.', { tone: 'danger' } );
			}
		}

		actions.appendChild( wrapBtn );
	}

	// Reply button.
	if ( canReply ) {
		const replyBtn = document.createElement( 'button' );
		replyBtn.type = 'button';
		replyBtn.className = 'bn-comment__reply-btn';
		replyBtn.textContent = 'Reply';
		actions.appendChild( replyBtn );
	}

	// Edit button — opens inline editor.
	if ( canEdit ) {
		const editBtn = document.createElement( 'button' );
		editBtn.type = 'button';
		editBtn.className = 'bn-comment__edit-btn';
		editBtn.textContent = 'Edit';
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
			saveBtn.textContent = 'Save';
			const cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'bn-comment__reply-cancel';
			cancelBtn.textContent = 'Cancel';
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
				emojiBtn.setAttribute( 'aria-label', 'Insert emoji' );
				emojiBtn.setAttribute( 'aria-haspopup', 'true' );
				emojiBtn.setAttribute( 'aria-expanded', 'false' );
				emojiBtn.title = 'Insert emoji';
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
					emojiBtn.textContent = 'Emoji';
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
					const res = await fetch( restUrl + '/comments/' + comment.id, {
						method:  'PUT',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						body:    JSON.stringify( { content: next } ),
					} );
					if ( res.ok ) {
						const updated = await res.json();
						para.textContent = updated.content;
						if ( ! body.querySelector( '.bn-comment__edited' ) ) {
							const editedMark = document.createElement( 'span' );
							editedMark.className = 'bn-comment__edited';
							editedMark.textContent = '(edited)';
							header.appendChild( editedMark );
						}
						editForm.remove();
						para.hidden = false;
						bnToast( 'Comment updated', { tone: 'success' } );
					} else {
						bnToast( 'Could not update comment. Try again.', { tone: 'danger' } );
					}
				} catch ( _e ) {
					bnToast( 'Could not update comment. Try again.', { tone: 'danger' } );
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
		delBtn.textContent = 'Delete';
		delBtn.addEventListener( 'click', async () => {
			const ok = await bnConfirm( {
				title: 'Delete this comment?',
				body: 'This cannot be undone.',
				confirmLabel: 'Delete',
				tone: 'danger',
			} );
			if ( ! ok ) {
				return;
			}
			const res = await fetch( restUrl + '/comments/' + comment.id, {
				method: 'DELETE', headers: { 'X-WP-Nonce': nonce },
			} );
			if ( res.ok ) {
				// Soft-delete: replace text + grey out, preserve thread.
				wrap.classList.add( 'bn-comment-card--deleted' );
				para.textContent = 'This comment was deleted.';
				para.dataset.placeholder = '1';
				para.hidden = false;
				actions.remove();
				adjustCommentCount( postId, -1 );
				bnToast( 'Comment deleted', { tone: 'success' } );
			} else {
				bnToast( 'Could not delete comment. Try again.', { tone: 'danger' } );
			}
		} );
		actions.appendChild( delBtn );
	}

	// Pin button (moderators only).
	if ( canPin ) {
		const pinBtn = document.createElement( 'button' );
		pinBtn.type = 'button';
		pinBtn.className = 'bn-comment__pin-btn';
		pinBtn.textContent = comment.pinned ? 'Unpin' : 'Pin';
		pinBtn.addEventListener( 'click', async () => {
			const wasPinned = wrap.classList.contains( 'bn-comment-card--pinned' );
			try {
				const res = await fetch( restUrl + '/comments/' + comment.id + '/pin', {
					method:  wasPinned ? 'DELETE' : 'POST',
					headers: { 'X-WP-Nonce': nonce },
				} );
				if ( res.ok ) {
					wrap.classList.toggle( 'bn-comment-card--pinned', ! wasPinned );
					pinBtn.textContent = wasPinned ? 'Pin' : 'Unpin';
					const existing = header.querySelector( '.bn-comment__pinned-badge' );
					if ( wasPinned && existing ) {
						existing.remove();
					} else if ( ! wasPinned && ! existing ) {
						const pinBadge = document.createElement( 'span' );
						pinBadge.className = 'bn-comment__pinned-badge';
						pinBadge.textContent = 'Pinned';
						header.insertBefore( pinBadge, timeEl );
					}
					bnToast( wasPinned ? 'Comment unpinned' : 'Comment pinned', { tone: 'success' } );
				} else {
					bnToast( 'Could not change pin status. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not change pin status. Try again.', { tone: 'danger' } );
			}
		} );
		actions.appendChild( pinBtn );
	}

	// Report button — visible for non-owner comments.
	if ( canReport ) {
		const reportBtn = document.createElement( 'button' );
		reportBtn.type = 'button';
		reportBtn.className = 'bn-comment__report-btn';
		reportBtn.setAttribute( 'aria-label', 'Report this comment' );
		reportBtn.textContent = 'Report';
		reportBtn.addEventListener( 'click', async () => {
			const result = await bnReportDialog( {
				title: 'Report this comment',
			} );
			if ( result === null ) {
				return;
			}
			try {
				const res = await fetch( restUrl + '/reports', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body:    JSON.stringify( {
						object_type: 'comment',
						object_id:   comment.id,
						reason:      result.reason,
						notes:       result.notes,
					} ),
				} );
				if ( res.ok || res.status === 201 ) {
					bnToast( 'Report submitted. Thanks for keeping the community safe.', { tone: 'success' } );
				} else {
					// Surface the server's reason (e.g. the 409 "already reported"
					// message) instead of a generic failure the user reads as "retry".
					const data = await res.json().catch( () => ( {} ) );
					bnToast( data.message || 'Could not submit report. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not submit report. Try again.', { tone: 'danger' } );
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
		replyTextarea.className = 'bn-comment-form__input';
		replyTextarea.placeholder = 'Write a reply...';
		replyTextarea.rows = 1;
		replyForm.appendChild( replyTextarea );

		const replySubmit = document.createElement( 'button' );
		replySubmit.type = 'button';
		replySubmit.className = 'bn-comment-form__submit';
		replySubmit.setAttribute( 'aria-label', 'Post reply' );
		replySubmit.textContent = 'Reply';
		replyForm.appendChild( replySubmit );

		const replyCancel = document.createElement( 'button' );
		replyCancel.type = 'button';
		replyCancel.className = 'bn-comment__reply-cancel';
		replyCancel.textContent = 'Cancel';
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
				const res = await fetch( restUrl + '/comments', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body:    JSON.stringify( { object_type: 'post', object_id: postId, content, parent_id: comment.id } ),
				} );
				if ( res.ok ) {
					const reply = await res.json();
					if ( ! repliesEl ) {
						repliesEl = document.createElement( 'div' );
						repliesEl.className = 'bn-comment__replies';
						body.appendChild( repliesEl );
					}
					repliesEl.appendChild( buildCommentNode( reply, currentUserId, postId, restUrl, nonce, cappedDepth + 1 ) );
					replyTextarea.value = '';
					replyForm.hidden    = true;
					adjustCommentCount( postId, 1 );
				} else {
					bnToast( 'Could not post reply. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not post reply. Try again.', { tone: 'danger' } );
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
	btn.setAttribute( 'aria-label', 1 === n ? '1 comment' : n + ' comments' );
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
		const res = yield fetch(
			ctx.restUrl + '/comments?object_type=post&object_id=' + ctx.postId + '&per_page=20',
			{ headers: { 'X-WP-Nonce': ctx.reactNonce } }
		);
		while ( listEl.firstChild ) {
			listEl.removeChild( listEl.firstChild );
		}
		if ( res.ok ) {
			const data = yield res.json();
			listEl.dataset.loaded = '1';
			( data.items || [] ).forEach( ( comment ) => {
				listEl.appendChild(
					buildCommentNode( comment, ctx.currentUserId, ctx.postId, ctx.restUrl, ctx.reactNonce, 0 )
				);
			} );
		} else {
			const err = document.createElement( 'div' );
			err.className = 'bn-comment-error';
			err.setAttribute( 'role', 'alert' );
			err.textContent = 'Could not load comments. ';
			const retry = document.createElement( 'button' );
			retry.type = 'button';
			retry.className = 'bn-comment-error__retry';
			retry.textContent = 'Retry';
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
		err.textContent = 'Network error. Comments could not be loaded.';
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
				return n === 1 ? '1 vote' : n + ' votes';
			} catch ( _e ) { return '0 votes'; }
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
					return count > 0 ? 'Shared \u00b7 ' + count : 'Shared';
				}
				return count > 0 ? 'Share \u00b7 ' + count : 'Share';
			} catch ( _e ) {
				return 'Share';
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
				} catch ( _e ) {
					ctx.reactionPickerBelow = false;
				}
			}

			ctx.reactionPickerOpen = willOpen;

			// Remember which picker is open and dismiss it on scroll so it never
			// floats over the sticky header once its card scrolls away.
			bnOpenReactionCtx = ctx.reactionPickerOpen ? ctx : null;
			if ( ! bnReactionScrollBound ) {
				bnReactionScrollBound = true;
				window.addEventListener(
					'scroll',
					() => {
						if ( bnOpenReactionCtx ) {
							bnOpenReactionCtx.reactionPickerOpen = false;
							bnOpenReactionCtx = null;
						}
					},
					{ passive: true }
				);
			}
		},
		* setReaction( event ) {
			const ctx  = getContext();
			const type = event.target.closest( '[data-reaction-type]' )?.dataset.reactionType || 'like';

			ctx.reactionPickerOpen = false;
			const newType = ctx.reactionType === type ? null : type;
			const prev    = ctx.reactionType;

			// Optimistic update — apply immediately, revert on failure.
			ctx.reactionType = newType;

			try {
				const res = yield fetch( ctx.restUrl + '/reactions/toggle', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.reactNonce },
					body:    JSON.stringify( { object_type: 'post', object_id: ctx.postId, emoji: newType } ),
				} );
				if ( ! res.ok ) {
					ctx.reactionType = prev; // Revert on failure.
				}
			} catch ( _e ) {
				ctx.reactionType = prev; // Revert on error.
			}
		},
		* toggleBookmark() {
			const ctx    = getContext();
			const method = ctx.bookmarked ? 'DELETE' : 'POST';
			const prev   = ctx.bookmarked;
			ctx.bookmarked = ! prev;
			try {
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/bookmark', {
					method,
					headers: { 'X-WP-Nonce': ctx.bookmarkNonce },
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( ctx.bookmarked ? 'Saved' : 'Removed from saved' ); }
				} else {
					ctx.bookmarked = prev;
				}
			} catch ( _e ) {
				ctx.bookmarked = prev;
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
			if ( ! ctx || ( ! ctx.reactionPickerOpen && ! ctx.optionsOpen ) ) {
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
		},
		* deletePost() {
			const ctx = getContext();
			const ok = yield bnConfirm( {
				title: 'Delete this post?',
				body: 'This cannot be undone.',
				confirmLabel: 'Delete',
				tone: 'danger',
			} );
			if ( ! ok ) {
				return;
			}
			try {
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId, {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctx.reactNonce },
				} );
				if ( res.ok ) {
					document.querySelector( '[data-post-id="' + ctx.postId + '"]' )?.remove();
				}
			} catch ( _e ) {}
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
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/share', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.shareNonce },
				} );
				if ( ! res.ok ) {
					ctx.shareCount  = prevCount;
					ctx.shareShared = false;
				}
			} catch ( _e ) {
				ctx.shareCount  = prevCount;
				ctx.shareShared = false;
			}
		},
		* reportPost() {
			const ctx    = getContext();
			const result = yield bnReportDialog( {
				title: 'Report this post',
			} );
			if ( result === null ) {
				return;
			}
			try {
				const res = yield fetch( ctx.restUrl + '/reports', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.reportNonce },
					body:    JSON.stringify( {
						object_type: 'post',
						object_id:   ctx.postId,
						reason:      result.reason,
						notes:       result.notes,
						// Tag the report with the post's space so space moderators
						// see it in their scoped queue (0 = global feed).
						space_id:    parseInt( ctx.spaceId, 10 ) || 0,
					} ),
				} );
				if ( res.ok || res.status === 201 ) {
					// Reflect the reported state immediately so the action menu
					// swaps Report for a disabled "Reported" item without a reload.
					ctx.hasReported = true;
					ctx.optionsOpen = false;
					bnToast( 'Report submitted. Thanks for keeping the community safe.', { tone: 'success' } );
				} else {
					// Surface the server's reason (e.g. the 409 "already reported"
					// message) instead of a generic failure. A 409 means the server
					// already has this user's report, so reflect that in the UI too.
					if ( res.status === 409 ) {
						ctx.hasReported = true;
						ctx.optionsOpen = false;
					}
					const data = yield res.json().catch( () => ( {} ) );
					bnToast( data.message || 'Could not submit report. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not submit report. Try again.', { tone: 'danger' } );
			}
		},
		* editPost() {
			const ctx     = getContext();
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
				bnToast( 'This post cannot be edited.', { tone: 'info' } );
				return;
			}

			// Pull the raw (unformatted) content so the editor shows exactly what the
			// author typed — the rendered node has nl2br/mention/hashtag markup baked in.
			let rawContent = '';
			try {
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId, {
					headers: { 'X-WP-Nonce': ctx.reactNonce },
				} );
				if ( res.ok ) {
					const data = yield res.json();
					rawContent = ( data && typeof data.content === 'string' ) ? data.content : '';
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
			ta.setAttribute( 'aria-label', 'Edit post content' );

			const bar = document.createElement( 'div' );
			bar.className = 'bn-post-card__edit-actions';

			const saveBtn = document.createElement( 'button' );
			saveBtn.type = 'button';
			saveBtn.className = 'bn-btn';
			saveBtn.dataset.variant = 'primary';
			saveBtn.dataset.size = 'sm';
			saveBtn.textContent = 'Save';

			const cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'bn-btn';
			cancelBtn.dataset.variant = 'ghost';
			cancelBtn.dataset.size = 'sm';
			cancelBtn.textContent = 'Cancel';

			bar.appendChild( saveBtn );
			bar.appendChild( cancelBtn );
			form.appendChild( ta );
			form.appendChild( bar );

			contentEl.hidden = true;
			contentEl.parentNode.insertBefore( form, contentEl.nextSibling );
			ta.focus();

			const teardown = () => {
				form.remove();
				contentEl.hidden = false;
			};
			cancelBtn.addEventListener( 'click', teardown );

			saveBtn.addEventListener( 'click', async () => {
				const next = ta.value.trim();
				if ( '' === next ) {
					bnToast( 'Post content cannot be empty.', { tone: 'info' } );
					return;
				}
				saveBtn.disabled = true;
				try {
					const res = await fetch( ctx.restUrl + '/posts/' + ctx.postId, {
						method:  'PUT',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.reactNonce },
						body:    JSON.stringify( { content: next } ),
					} );
					if ( ! res.ok ) {
						throw new Error( 'update failed' );
					}
					// Reflect the saved text immediately (line breaks preserved). Full
					// mention/hashtag formatting re-applies on the next page load.
					contentEl.textContent = next;
					if ( ! card.querySelector( '.bn-post-card__edited' ) ) {
						const mark = document.createElement( 'span' );
						mark.className = 'bn-post-card__edited';
						mark.textContent = ' (edited)';
						contentEl.appendChild( mark );
					}
					teardown();
					bnToast( 'Post updated', { tone: 'success' } );
				} catch ( _e ) {
					saveBtn.disabled = false;
					bnToast( 'Could not update the post. Try again.', { tone: 'danger' } );
				}
			} );
		},
		* pinPost() {
			const ctx  = getContext();
			const prev = ! ! ctx.isPinned;
			// Optimistically flip; `prev` decides the verb (pin -> POST, unpin -> DELETE).
			ctx.isPinned = ! prev;
			try {
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/pin', {
					method:  prev ? 'DELETE' : 'POST',
					headers: { 'X-WP-Nonce': ctx.reactNonce },
				} );
				if ( res.ok ) {
					bnToast( ctx.isPinned ? 'Post pinned' : 'Post unpinned', { tone: 'success' } );
				} else {
					ctx.isPinned = prev;
					let message = prev ? 'Could not unpin this post. Try again.' : 'Could not pin this post. Try again.';
					try {
						const data = yield res.json();
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
				bnToast( 'Could not change pin status. Try again.', { tone: 'danger' } );
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
			const showInlineError = ( msg ) => {
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
				retry.textContent = 'Retry';
				retry.addEventListener( 'click', () => {
					alertEl.remove();
					// Re-fire submitComment by clicking the submit button.
					const submitBtn = formEl?.querySelector( '[data-wp-on--click="actions.submitComment"]' );
					submitBtn?.click();
				} );
				alertEl.appendChild( msgSpan );
				alertEl.appendChild( retry );
			};

			try {
				const res = yield fetch( ctx.restUrl + '/comments', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.reactNonce },
					body:    JSON.stringify( { object_type: 'post', object_id: ctx.postId, content } ),
				} );
				if ( res.ok ) {
					// Clear any stale error alert from a previous failed attempt.
					inputEl?.closest( '.bn-post-card__comments' )?.querySelector( '.bn-comment-submit-error' )?.remove();
					const comment       = yield res.json();
					comment.author_name = comment.author_name || 'You';
					const listEl        = document.querySelector( '[data-comment-list="' + ctx.postId + '"]' );
					if ( listEl ) {
						listEl.dataset.loaded = '1';
						listEl.appendChild( buildCommentNode( comment, ctx.currentUserId, ctx.postId, ctx.restUrl, ctx.reactNonce, 0 ) );
					}
					if ( inputEl ) {
						inputEl.value = '';
					}
					adjustCommentCount( ctx.postId, 1 );
					if ( window.bnToast ) { window.bnToast( 'Comment added' ); }
				} else {
					showInlineError( 'Could not post your comment. Try again.' );
				}
			} catch ( _e ) {
				showInlineError( 'Network error. Try again.' );
			}
		},
		* votePoll( event ) {
			const ctx      = getContext();
			const optionId = parseInt( event.target.closest( '[data-option-id]' )?.dataset.optionId || '0', 10 );
			if ( ! optionId ) {
				return;
			}
			try {
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/vote', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.pollNonce },
					body:    JSON.stringify( { option_id: optionId } ),
				} );
				if ( res.ok ) {
					const data = yield res.json();
					if ( window.bnToast ) { window.bnToast( 'Vote recorded' ); }
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
				}
			} catch ( _e ) {}
		},
		* dismissAnnouncement() {
			const ctx = getContext();
			try {
				yield fetch( ctx.restUrl + '/feed/announcements/' + ctx.postId + '/dismiss', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.dismissNonce },
				} );
			} catch ( _e ) {}
			document.querySelector( '.bn-post-card--announcement' )?.remove();
		},
		// Admin-only: end the announcement for everyone (expire its pin now).
		* endAnnouncement() {
			const ctx = getContext();
			try {
				const res = yield fetch( ctx.restUrl + '/feed/announcements/' + ctx.postId + '/end', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.dismissNonce },
				} );
				// Only drop the banner once the server confirms the end —
				// removing it on a 403/404/500 gave a false sense of success.
				if ( res.ok ) {
					document.querySelector( '.bn-post-card--announcement' )?.remove();
					bnToast( 'Announcement ended', { tone: 'success' } );
				} else {
					bnToast( 'Could not end the announcement. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not end the announcement. Try again.', { tone: 'danger' } );
			}
		},
	},
} );

/* ── Post composer ───────────────────────────────────────────────────────── */

// Module-level media state — shared between native event handler and store actions.
// WP Interactivity API getContext() doesn't work in native addEventListener callbacks.
const _mediaState = { ids: [], previews: [] };

/* ── Link preview detection ──────────────────────────────────────────────
 * As the user types, the first http(s) URL in the composer is detected and
 * its Open Graph card fetched (debounced) from buddynext/v1/link-preview.
 * The result is stored on the composer context so the preview card renders
 * and link_url/link_meta ride along in the submit payload. A dismissed URL
 * is remembered so it isn't re-fetched on the next keystroke.
 */
const _linkPreviewState = { url: '', dismissed: '', timer: null };
const LINK_PREVIEW_DEBOUNCE_MS = 700;
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
		return;
	}

	// Same URL already previewed or explicitly dismissed → nothing to do.
	if ( url === ctx.linkUrl || url === _linkPreviewState.dismissed || url === _linkPreviewState.url ) {
		return;
	}

	_linkPreviewState.url = url;
	clearTimeout( _linkPreviewState.timer );
	_linkPreviewState.timer = setTimeout( async () => {
		// Bail if the URL changed again during the debounce window.
		if ( detectFirstUrl( ctx.content ) !== url ) { return; }
		try {
			const base = ctx.restUrl || '';
			const res  = await fetch(
				base + '/link-preview?url=' + encodeURIComponent( url ),
				{ headers: { 'X-WP-Nonce': ctx.restNonce } }
			);
			if ( ! res.ok ) { return; }
			const data = await res.json();
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
		}
	}, LINK_PREVIEW_DEBOUNCE_MS );
}

const PRIVACY_LABELS = {
	public:      'Public',
	followers:   'Followers',
	connections: 'Connections',
	private:     'Only me',
	space_members: 'Space members',
};

/**
 * Convert a <input type="datetime-local"> value (local wall-clock, no zone —
 * e.g. "2026-06-01T14:30") to a UTC "Y-m-d H:i:s" string for the post-create
 * payload. Returns '' for an empty/unparseable value so callers can skip the
 * scheduled_at field entirely.
 *
 * @param {string} localValue Raw datetime-local input value.
 * @return {string} UTC datetime ("Y-m-d H:i:s") or ''.
 */
function toUtcSqlDatetime( localValue ) {
	if ( ! localValue ) { return ''; }
	const d = new Date( localValue );
	if ( isNaN( d.getTime() ) ) { return ''; }
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return d.getUTCFullYear() + '-' + pad( d.getUTCMonth() + 1 ) + '-' + pad( d.getUTCDate() ) +
		' ' + pad( d.getUTCHours() ) + ':' + pad( d.getUTCMinutes() ) + ':' + pad( d.getUTCSeconds() );
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

function scheduleDraftSave( ctx ) {
	const userId = parseInt( ctx.userId, 10 );
	if ( userId <= 0 ) {
		return;
	}
	const key = String( userId );
	if ( _draftTimers.has( key ) ) {
		clearTimeout( _draftTimers.get( key ) );
	}
	setDraftStatus( ctx, 'Saving draft…', false );
	const t = setTimeout( () => {
		const payload = {
			content:      ctx.content || '',
			composerType: ctx.composerType || 'text',
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
			setDraftStatus( ctx, 'Draft saved', true );
		}
		_draftTimers.delete( key );

		// Cloud-sync seam (off by default). When the user opts in via
		// localStorage.bn_composer_cloud_sync = '1', the draft also POSTs
		// to /me/drafts so it survives across devices. The endpoint
		// existence and shape are documented in CommentDraftController;
		// the local path keeps working even if the endpoint isn't shipped.
		try {
			if ( window.localStorage.getItem( 'bn_composer_cloud_sync' ) === '1' ) {
				fetch( ctx.restUrl + '/me/drafts', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
					body:    JSON.stringify( { payload } ),
				} ).catch( () => {} );
			}
		} catch ( _e ) {}
	}, DRAFT_DEBOUNCE_MS );
	_draftTimers.set( key, t );
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
	const composers = document.querySelectorAll( '[data-wp-interactive="buddynext/post-composer"]' );
	composers.forEach( ( el ) => {
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
		ctxData.composerType = draft.composerType || ctxData.composerType;
		ctxData.privacy      = draft.privacy || ctxData.privacy;
		ctxData.hasDraft     = true;
		ctxData.draftStatus  = 'Draft restored';
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
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', restoreDraftsOnLoad );
} else {
	restoreDraftsOnLoad();
}

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
		get hasNoError() {
			try { return ! ( getContext().errorMessage || '' ); } catch ( _e ) { return true; }
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
				return PRIVACY_LABELS[ ctx.privacy ] || 'Public';
			} catch ( _e ) { return 'Public'; }
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
			try { return getContext().submitting ? 'Sharing…' : 'Share'; } catch ( _e ) { return 'Share'; }
		},
		get draftStatusHidden() {
			try { return ! ( getContext().draftStatus || '' ); } catch ( _e ) { return true; }
		},
		get draftDiscardHidden() {
			try { return ! getContext().hasDraft; } catch ( _e ) { return true; }
		},
		get voiceSubmitLabel() {
			try { return getContext().submitting ? 'Scheduling…' : 'Schedule room'; } catch ( _e ) { return 'Schedule room'; }
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
			const mvsBase = ctxData.mvsRestBase || ( ctxData.restUrl || '' ).replace( '/buddynext/v1', '/mvs/v1' );
			const nonce   = ctxData.restNonce || '';

			// Defensive guard: uploads route through WPMediaVerse. The Image button
			// is only rendered when the engine is active (server-side check in
			// composer.php), but if pickMedia is reached while disabled, degrade
			// gracefully instead of POSTing to a non-existent mvs/v1 route.
			if ( false === ctxData.mediaEnabled ) {
				bnToast( 'Image uploads are not available on this site.', { tone: 'info' } );
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
						bnToast( 'You can attach at most ' + MAX_MEDIA + ' images per post.', { tone: 'info' } );
						return;
					}
					if ( files.length > remaining ) {
						bnToast( 'Only ' + remaining + ' more image' + ( remaining === 1 ? '' : 's' ) + ' can be added.', { tone: 'info' } );
					}

					// Show preview area.
					const previewArea = document.querySelector( '.bn-composer__media-preview' );
					if ( previewArea ) {
						previewArea.hidden = false;
					}

					const uploadCount = Math.min( files.length, remaining );
					for ( let i = 0; i < uploadCount; i++ ) {
						const file     = files[ i ];
						const formData = new FormData();
						formData.append( 'file', file );

						try {
							const res = await fetch( mvsBase + '/media', {
								method:  'POST',
								headers: { 'X-WP-Nonce': nonce },
								body:    formData,
							} );

							if ( res.ok ) {
								const text = await res.text();
								// MVS may prepend DB error HTML before JSON — extract JSON.
								const jsonStart = text.indexOf( '{' );
								const data      = jsonStart >= 0 ? JSON.parse( text.substring( jsonStart ) ) : {};
								const mediaId   = data.id || data.media_id;
								// Engine-signed URLs only — never a WP-attachment source_url.
								const thumbUrl  = data.thumbnail_url || data.file_url || '';

								_mediaState.ids.push( mediaId );
								_mediaState.previews.push( { id: mediaId, url: thumbUrl, name: file.name } );

								// Append preview thumbnail to DOM.
								if ( previewArea && thumbUrl ) {
									const thumb = document.createElement( 'div' );
									thumb.className = 'bn-composer__media-thumb';
									thumb.dataset.mediaId = mediaId;
									// Build the preview via DOM rather than string-concatenated
									// innerHTML: setting .src assigns thumbUrl as data (never parsed
									// as markup), so a URL/id can't break out of the attribute.
									const thumbImg = document.createElement( 'img' );
									thumbImg.src = thumbUrl;
									thumbImg.alt = '';
									thumbImg.width = 80;
									thumbImg.height = 80;
									thumbImg.loading = 'lazy';
									const thumbRemove = document.createElement( 'button' );
									thumbRemove.className = 'bn-composer__media-remove';
									thumbRemove.type = 'button';
									thumbRemove.dataset.mediaId = mediaId;
									thumbRemove.textContent = '×';
									thumb.append( thumbImg, thumbRemove );
									thumbRemove.addEventListener( 'click', function () {
										_mediaState.ids = _mediaState.ids.filter( ( id ) => id !== mediaId );
										_mediaState.previews = _mediaState.previews.filter( ( p ) => p.id !== mediaId );
										thumb.remove();
										if ( ! _mediaState.ids.length && previewArea ) {
											previewArea.hidden = true;
										}
									} );
									previewArea.appendChild( thumb );
								}
							} else {
								// Non-2xx: surface the real status instead of silently
								// swallowing it. 404 = WPMediaVerse route missing (engine
								// inactive); other codes carry their own number.
								// eslint-disable-next-line no-console
								console.error( '[BuddyNext] Media upload failed:', res.status, mvsBase + '/media' );
								bnToast(
									404 === res.status
										? 'Image uploads are unavailable (media engine not active).'
										: 'Could not upload ' + ( file.name || 'image' ) + ' (error ' + res.status + ').',
									{ tone: 'danger' }
								);
							}
						} catch ( err ) {
							// eslint-disable-next-line no-console
							console.error( '[BuddyNext] Media upload error:', err );
							bnToast( 'Could not upload ' + ( file.name || 'image' ) + '. Try a smaller file.', { tone: 'danger' } );
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
				textarea.value = '';
			}
		},
		* submit() {
			const ctx     = getContext();
			const content = ( ctx.content || '' ).trim();
			if ( ! content || ctx.submitting ) {
				return;
			}
			ctx.errorMessage = '';
			ctx.submitting   = true;

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
					ctx.errorMessage = 'Add at least two poll options.';
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
			// preview. PostService also auto-fetches when link_url is set but
			// link_meta is empty, so a dismissed card still posts as plain text.
			if ( ctx.linkUrl ) {
				body.link_url = ctx.linkUrl;
				if ( ctx.linkMeta ) {
					body.link_meta = ctx.linkMeta;
				}
				if ( body.type === 'text' ) {
					body.type = 'link';
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
				const res = yield fetch( ctx.restUrl + '/posts', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
					body:    JSON.stringify( body ),
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
					document.querySelectorAll( '[data-wp-interactive="buddynext/post-composer"] .bn-composer__prompt' ).forEach( function ( ta ) { ta.value = ''; } );
					if ( window.bnToast ) {
						window.bnToast( body.scheduled_at ? 'Post scheduled' : 'Post published', 'success' );
					}
					setTimeout( function () { window.location.reload(); }, 500 );
					return;
				}
				let msg = 'Could not publish your post. Try again.';
				try {
					const data = yield res.json();
					if ( data && data.message ) { msg = data.message; }
				} catch ( _e2 ) {}
				ctx.errorMessage = msg;
				ctx.submitting   = false;
			} catch ( _e ) {
				ctx.errorMessage = 'Network error. Try again.';
				ctx.submitting   = false;
			}
		},
		togglePrivacy() {
			const ctx        = getContext();
			ctx.privacyOpen  = ! ctx.privacyOpen;
		},
		* submitVoice() {
			const ctx = getContext();
			if ( ctx.submitting ) { return; }
			const fields = {};
			document.querySelectorAll( '[data-bn-voice-field]' ).forEach( ( el ) => {
				fields[ el.dataset.bnVoiceField ] = el.value.trim();
			} );
			if ( ! fields.title || ! fields.scheduled_at ) {
				ctx.voiceError = 'Title and start time are required.';
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
				const res = yield fetch( ctx.restUrl + '/posts', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
					body:    JSON.stringify( body ),
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( 'Voice room scheduled', 'success' ); }
					setTimeout( () => window.location.reload(), 500 );
					return;
				}
				ctx.voiceError = 'Could not schedule the voice room. Try again.';
				ctx.submitting = false;
			} catch ( _e ) {
				ctx.voiceError = 'Network error. Try again.';
				ctx.submitting = false;
			}
		},
		cancel() {
			const ctx          = getContext();
			ctx.composerOpen   = false;
			ctx.composerType   = 'text';
			ctx.content        = '';
			ctx.submitting     = false;
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
		// on DOMContentLoaded, but if Interactivity hydrates first the live
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
			ctx.composerType = draft.composerType || ctx.composerType;
			ctx.privacy      = draft.privacy || ctx.privacy;
			ctx.hasDraft     = true;
			ctx.draftStatus  = 'Draft restored';
		},
	},
} );

/* ── Announcement ────────────────────────────────────────────────────────── */

store( 'buddynext/announcement', {
	actions: {
		* dismiss() {
			const ctx    = getContext();
			const banner = document.querySelector( '[data-bn-rest-nonce]' );
			const nonce  = banner?.dataset.bnRestNonce || '';
			const restUrl = banner?.dataset.bnRestUrl
				|| ( ( window.wpApiSettings?.root || '' ) + 'buddynext/v1' );
			try {
				yield fetch( restUrl + '/feed/announcements/' + ctx.announcementId + '/dismiss', {
					method:  'POST',
					headers: { 'X-WP-Nonce': nonce },
				} );
			} catch ( _e ) {}
			document.querySelector( '.bn-announcement' )?.remove();
		},
	},
} );

/* ── Spaces sidebar join ─────────────────────────────────────────────────── */

store( 'buddynext/spaces', {
	actions: {
		* join( event ) {
			const ctx    = getContext();
			const banner = document.querySelector( '[data-bn-rest-nonce]' );
			const nonce  = banner?.dataset.bnRestNonce || '';
			const restUrl = banner?.dataset.bnRestUrl
				|| ( ( window.wpApiSettings?.root || '' ) + 'buddynext/v1' );
			try {
				const res = yield fetch( restUrl + '/spaces/' + ctx.spaceId + '/join', {
					method:  'POST',
					headers: { 'X-WP-Nonce': nonce },
				} );
				if ( res.ok ) {
					event.target.textContent = 'Joined';
					event.target.disabled    = true;
				}
			} catch ( _e ) {}
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
		text.textContent = ( window.bnI18n && window.bnI18n.feedEnd ) || "You've reached the end.";
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
		btn.textContent     = ( window.bnI18n && window.bnI18n.feedRetry ) || 'Retry';
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

		var headers = { Accept: 'application/json' };
		if ( restNonce ) { headers[ 'X-WP-Nonce' ] = restNonce; }

		fetch( buildUrl( restUrl, params ), { headers: headers, credentials: 'same-origin' } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'http_' + r.status );
				}
				return r.json();
			} )
			.then( function ( data ) {
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
				showError( trigger, function () { startObserver( trigger ); } );
			} );
	}

	function startObserver( trigger ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

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

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

/* ── Share modal ─────────────────────────────────────────────────────────── */

store( 'buddynext/share-modal', {
	state: {
		get open() {
			try { return !! getContext().open; } catch ( _e ) { return false; }
		},
		get busy() {
			try { return !! getContext().busy; } catch ( _e ) { return false; }
		},
		get error() {
			try { return getContext().error || ''; } catch ( _e ) { return ''; }
		},
		get hasNoError() {
			try { return ! ( getContext().error || '' ); } catch ( _e ) { return true; }
		},
		get author() {
			try { return getContext().author || ''; } catch ( _e ) { return ''; }
		},
		get excerpt() {
			try { return getContext().excerpt || ''; } catch ( _e ) { return ''; }
		},
		get hasNoPreview() {
			try {
				const ctx = getContext();
				return ! ( ctx.author || ctx.excerpt );
			} catch ( _e ) { return true; }
		},
		get repostLabel() {
			try { return getContext().busy ? 'Reposting…' : 'Repost'; } catch ( _e ) { return 'Repost'; }
		},
	},
	actions: {
		// Opens the modal in response to the post card's `bn-open-share-modal`
		// document event. Bound via `data-wp-on-document--bn-open-share-modal`
		// so it runs INSIDE the store — getContext() here is the live, writable
		// context, unlike a plain document listener which can only mutate the
		// inert data-wp-context attribute (that left postId stuck at 0, so
		// repost silently aborted on its `! ctx.postId` guard).
		receiveOpen( event ) {
			const detail  = ( event && event.detail ) || {};
			const ctx     = getContext();
			ctx.postId    = detail.postId || 0;
			ctx.permalink = detail.permalink || '';
			ctx.author    = detail.author || '';
			ctx.excerpt   = detail.excerpt || '';
			ctx.nonce     = detail.nonce || ctx.nonce;
			ctx.restUrl   = detail.restUrl || ctx.restUrl;
			ctx.note      = '';
			ctx.error     = '';
			ctx.busy      = false;
			ctx.open      = true;
			// Clear any leftover text from a previous open (the textarea is
			// input-only, so resetting ctx.note alone leaves the old value on
			// screen).
			document.querySelectorAll( '.bn-share-modal .bn-share-modal__note' ).forEach( function ( ta ) { ta.value = ''; } );
		},
		close() {
			const ctx = getContext();
			ctx.open  = false;
			ctx.busy  = false;
			ctx.error = '';
		},
		onNoteInput( event ) {
			const ctx = getContext();
			ctx.note  = event && event.target ? event.target.value : '';
		},
		* repost() {
			const ctx = getContext();
			if ( ctx.busy || ! ctx.postId ) { return; }
			ctx.busy  = true;
			ctx.error = '';
			try {
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/share', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.nonce },
					body:    JSON.stringify( { content: ( ctx.note || '' ).trim() } ),
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( 'Reposted', 'success' ); }
					ctx.open = false;
					ctx.busy = false;
					ctx.note = '';
					// Reload so the new repost card appears in the feed — the same
					// server-rendered-source-of-truth pattern the composer uses after
					// publishing (see docs/specs/UI-CONTRACT.md).
					setTimeout( function () { window.location.reload(); }, 500 );
					return;
				}
				ctx.error = 'Could not repost. Try again.';
				ctx.busy  = false;
			} catch ( _e ) {
				ctx.error = 'Network error. Try again.';
				ctx.busy  = false;
			}
		},
		* copyLink() {
			const ctx = getContext();
			if ( ! ctx.permalink ) { return; }
			ctx.busy = true;
			try {
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					yield navigator.clipboard.writeText( ctx.permalink );
				} else {
					const tmp = document.createElement( 'textarea' );
					tmp.value = ctx.permalink;
					document.body.appendChild( tmp );
					tmp.select();
					document.execCommand( 'copy' );
					document.body.removeChild( tmp );
				}
				if ( window.bnToast ) { window.bnToast( 'Link copied', 'success' ); }
				ctx.open  = false;
				ctx.busy  = false;
			} catch ( _e ) {
				ctx.error = 'Could not copy link.';
				ctx.busy  = false;
			}
		},
	},
} );

// The post-card `openShare` dispatches a `bn-open-share-modal` document event;
// the share-modal store receives it via its `data-wp-on-document--` directive
// (actions.receiveOpen), which runs inside the store so it can write the LIVE
// context. (A plain document listener here could only mutate the inert
// data-wp-context attribute, leaving the reactive store's postId at 0.)

/* ── Feed filter tabs ────────────────────────────────────────────────────── */

store( 'buddynext/feed-tabs', {
	actions: {
		setFilter( event ) {
			if ( event && event.preventDefault ) { event.preventDefault(); }
			const ctx    = getContext();
			const target = event && event.target ? event.target.closest( '[data-filter]' ) : null;
			const filter = target ? target.getAttribute( 'data-filter' ) : '';
			if ( ! filter || filter === ctx.filter ) { return; }
			ctx.filter = filter;
			// Reactive page transitions reload the surface so server-rendered post
			// cards stay the single source of truth — see docs/specs/UI-CONTRACT.md.
			const url = new URL( window.location.href );
			url.searchParams.set( 'filter', filter );
			url.searchParams.delete( 'cursor' );
			window.location.href = url.toString();
		},
	},
} );

/* ── Explore facet chips + search bar (buddynext/feed namespace) ──────────
 *
 * The explore template binds chips to actions.setFilter and the search input
 * to actions.onSearch under the buddynext/feed namespace. These wire facet
 * clicks to the search results page so chips actually filter, and the search
 * input routes to /activity/search/?q=… on submit.
 */
const BN_SEARCH_PATH = '/activity/search/';

store( 'buddynext/feed', {
	state: {
		get guestBannerDismissed() {
			try { return !! getContext().guestBannerDismissed; } catch ( _e ) { return false; }
		},
	},
	actions: {
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
				window.location.href = '/activity/hashtag/' + encodeURIComponent( slug ) + '/';
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

			const target_url = new URL( window.location.origin + BN_SEARCH_PATH );
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

/* ── Wire Enter-to-search on the explore search input ─────────────────── */

document.addEventListener( 'DOMContentLoaded', () => {
	const input = document.getElementById( 'bn-explore-search-input' );
	if ( ! input ) { return; }
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key !== 'Enter' ) { return; }
		e.preventDefault();
		const q = ( input.value || '' ).trim();
		if ( '' === q ) { return; }
		const target_url = new URL( window.location.origin + BN_SEARCH_PATH );
		target_url.searchParams.set( 'q', q );
		window.location.href = target_url.toString();
	} );
} );

/*
   Composer enhancements — char counter, image drag-drop, and @ / # typeahead.
   Hooked on DOMContentLoaded so every page that ships the post-composer
   partial gets these features without per-page wiring.
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
		counter.textContent = `${ len } / ${ max }`;
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
	let fetchAbort = null;
	let suggestTimer = null;
	const SUGGEST_DEBOUNCE_MS = 200;
	const REST_BASE = ( window.wpApiSettings?.root || '/wp-json/' ) + 'buddynext/v1';

	const closeDropdown = () => {
		if ( dropdown ) {
			dropdown.remove();
			dropdown = null;
		}
		suggestions = [];
		activeKind = null;
		activeStart = -1;
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
			return `
			<button type="button" role="option" class="bn-composer__typeahead-item" data-i="${ i }"
					aria-selected="${ i === activeIndex ? 'true' : 'false' }">
				${ avatarHtml }
				<span class="bn-composer__typeahead-text">
					<span class="bn-composer__typeahead-name">${ namePrefix }${ escapeHtml( s.label ) }</span>
					${ handleHtml }
				</span>
			</button>
		`;
		} ).join( '' );
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
			? `${ REST_BASE }/members?search=${ encodeURIComponent( query ) }&per_page=5`
			: `${ REST_BASE }/hashtags/autocomplete?q=${ encodeURIComponent( query ) }&limit=5`;
		try {
			const r = await fetch( url, { signal: fetchAbort.signal, credentials: 'include' } );
			const data = await r.json();
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

	textarea.addEventListener( 'input', () => {
		const value = textarea.value;
		const cursorPos = textarea.selectionStart;
		// Walk back from the cursor to find an unterminated @ or # token.
		let i = cursorPos - 1;
		while ( i >= 0 && /[a-zA-Z0-9_-]/.test( value[ i ] ) ) { i--; }
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

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initComposerEnhancements );
} else {
	initComposerEnhancements();
}

/*
   Realtime "new posts" pill — listens for Pro's bn:realtime:post-new
   custom events (fired by buddynext-pro/assets/js/realtime/store.js
   when Soketi delivers a post.new message on the subscribed feed
   channel). Accumulates the count, shows a sticky pill at the top
   of the feed list, and reloads the feed when clicked.

   No-op when no realtime layer is active (the event never fires).
   ---------------------------------------------------------------- */
function initRealtimeNewPostsPill() {
	const feed = document.querySelector( '.bn-feed-list, .bn-explore-grid' );
	if ( ! feed ) {
		return;
	}
	// Skip explore — it ranks by engagement, not chrono; a "new post"
	// at the top makes no sense there.
	const grid = feed.classList.contains( 'bn-explore-grid' );
	if ( grid ) {
		return;
	}

	let pendingIds = new Set();
	let pill = null;

	const renderPill = () => {
		if ( pendingIds.size === 0 ) {
			if ( pill ) { pill.remove(); pill = null; }
			return;
		}
		if ( ! pill ) {
			pill = document.createElement( 'button' );
			pill.type = 'button';
			pill.className = 'bn-feed-new-pill';
			pill.setAttribute( 'role', 'status' );
			pill.setAttribute( 'aria-live', 'polite' );
			pill.addEventListener( 'click', () => {
				window.location.reload();
			} );
			feed.parentElement.insertBefore( pill, feed );
		}
		const n = pendingIds.size;
		pill.textContent = n === 1
			? '1 new post — refresh to view'
			: `${ n } new posts — refresh to view`;
	};

	document.addEventListener( 'bn:realtime:post-new', ( e ) => {
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
		pendingIds.add( id );
		renderPill();
	} );

	// ---- Free producer: visibility-aware 60s poll of /feed/new-count ----
	// Mirrors the notifications-store poll (assets/js/notifications/store.js):
	// a document.hidden guard, a re-poll on tab focus, and the wp_rest nonce.
	// When the server reports posts newer than our watermark, we dispatch
	// `bn:realtime:post-new` for the delta so the existing pill renderer above
	// fires unchanged. Pro's Soketi path pre-empts this — both feed the same
	// listener, so the pill behaves identically with or without Pro.
	const POLL_INTERVAL = 60000;

	// REST root + nonce come from the always-present composer context; the feed
	// page on /activity renders the composer for every logged-in member.
	const composerEl = document.querySelector( '[data-wp-interactive="buddynext/post-composer"]' );
	if ( ! composerEl ) { return; }
	let restUrl = '';
	let restNonce = '';
	try {
		const cfg = JSON.parse( composerEl.dataset.wpContext || '{}' );
		restUrl   = cfg.restUrl || '';
		restNonce = cfg.restNonce || '';
	} catch ( _e ) {}
	if ( ! restUrl ) { return; }

	// Active home-feed filter (defaults to for-you). The new-count query must
	// scope to the same source blend the user is actually viewing.
	const activeTab = document.querySelector( '.bn-feed-filter-tab[aria-current="true"]' );
	const filter = ( activeTab && activeTab.dataset.filter ) || 'for-you';

	// Seed the watermark from the newest post-card already rendered. The pill
	// only ever cares about posts above this id.
	let watermark = 0;
	feed.querySelectorAll( '[data-post-id]' ).forEach( ( card ) => {
		const cardId = parseInt( card.dataset.postId, 10 );
		if ( cardId > watermark ) { watermark = cardId; }
	} );

	let pollTimer = null;

	async function pollNewCount() {
		if ( document.hidden ) { return; }
		try {
			const url = restUrl + '/feed/new-count?after_id=' + encodeURIComponent( watermark ) +
				'&filter=' + encodeURIComponent( filter );
			const res = await fetch( url, {
				method:      'GET',
				credentials: 'same-origin',
				headers:     { 'X-WP-Nonce': restNonce || '' },
			} );
			if ( ! res.ok ) { return; }
			const json = await res.json();
			if ( ! json || typeof json.count === 'undefined' ) { return; }
			const fresh = Number( json.count ) || 0;
			const newestId = Number( json.newest_id ) || watermark;
			if ( fresh > 0 && newestId > watermark ) {
				// Synthesize ids above the watermark so the pill counts the delta
				// without needing the individual ids. The renderer keys off a Set,
				// so distinct synthetic ids are sufficient for an accurate count.
				for ( let i = 1; i <= fresh; i++ ) {
					document.dispatchEvent( new CustomEvent( 'bn:realtime:post-new', {
						detail: { post_id: watermark + i, user_id: 0 },
					} ) );
				}
				watermark = newestId;
			}
		} catch ( _e ) {
			// Network failure — retry next tick.
		}
	}

	function scheduleNewCount() {
		if ( pollTimer ) { window.clearTimeout( pollTimer ); }
		pollTimer = window.setTimeout( function () {
			pollNewCount().finally( scheduleNewCount );
		}, POLL_INTERVAL );
	}

	// Re-poll immediately when the tab regains focus after being hidden.
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) { pollNewCount(); }
	} );

	scheduleNewCount();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initRealtimeNewPostsPill );
} else {
	initRealtimeNewPostsPill();
}

/*
   Realtime comment indicator — listens for `bn:realtime:comment-added`
   events dispatched by Pro's RealtimeDispatcher when Soketi delivers
   a `comment.added` message. If the affected post's comment thread
   is currently open in this tab, increments a discreet "N new" badge
   above the comment list; click fetches and prepends.

   No-op when no realtime layer is active or the thread isn't open.
   ---------------------------------------------------------------- */
function initRealtimeCommentIndicator() {
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
		pill.textContent = n === 1 ? '1 new comment — show' : `${ n } new comments — show`;
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initRealtimeCommentIndicator );
} else {
	initRealtimeCommentIndicator();
}

/*
   Reactors popover — opens an FB-style "people who reacted" panel
   when the user clicks the per-emoji summary chip on a post card.
   Fetches /reactions/list with limit=100. Lazy-builds the popover
   once and re-uses the same DOM node, repositioning it for each
   trigger. Click-outside or Escape closes.
   ---------------------------------------------------------------- */
function initReactorsPopover() {
	const REST_BASE = ( window.wpApiSettings?.root || '/wp-json/' ) + 'buddynext/v1';
	let panel = null;
	let activeTrigger = null;

	const ensurePanel = () => {
		if ( panel ) { return panel; }
		panel = document.createElement( 'div' );
		panel.className = 'bn-reactors-popover';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', 'People who reacted' );
		panel.hidden = true;
		document.body.appendChild( panel );
		return panel;
	};

	const closePanel = () => {
		if ( panel ) { panel.hidden = true; }
		activeTrigger = null;
	};

	const positionPanel = ( trigger ) => {
		const r = trigger.getBoundingClientRect();
		panel.style.position = 'absolute';
		panel.style.top      = ( window.scrollY + r.bottom + 6 ) + 'px';
		panel.style.left     = ( window.scrollX + r.left ) + 'px';
	};

	const renderList = ( items, total ) => {
		const header = document.createElement( 'div' );
		header.className = 'bn-reactors-popover__head';
		header.textContent = total === 1 ? '1 reaction' : `${ total } reactions`;

		const list = document.createElement( 'ul' );
		list.className = 'bn-reactors-popover__list';
		items.forEach( ( r ) => {
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
			name.textContent = r.display_name || `User #${ r.user_id }`;
			li.appendChild( name );

			// Emoji badge — pull from the same vendor base as the comment
			// builder. We look up the closest comment-list ancestor of any
			// open thread; if none, the emoji is omitted (graceful fallback).
			const emojiBase = document.querySelector( '[data-emoji-base]' )?.dataset.emojiBase;
			if ( r.emoji && emojiBase ) {
				const img = document.createElement( 'img' );
				img.src = emojiBase + r.emoji + '.svg';
				img.alt = r.emoji;
				img.width = 18;
				img.height = 18;
				img.className = 'bn-reactors-popover__emoji';
				li.appendChild( img );
			}

			list.appendChild( li );
		} );

		panel.replaceChildren();
		panel.appendChild( header );
		panel.appendChild( list );
	};

	document.addEventListener( 'click', async ( e ) => {
		const trigger = e.target.closest( '[data-bn-reactors]' );
		if ( ! trigger ) {
			// Outside-click on an open panel closes it.
			if ( panel && ! panel.hidden && ! e.target.closest( '.bn-reactors-popover' ) ) {
				closePanel();
			}
			return;
		}
		e.preventDefault();
		if ( activeTrigger === trigger && panel && ! panel.hidden ) {
			closePanel();
			return;
		}
		activeTrigger = trigger;
		ensurePanel();
		const objectType = trigger.dataset.bnObjectType || 'post';
		const objectId   = trigger.dataset.bnObjectId   || '0';
		panel.replaceChildren();
		const loading = document.createElement( 'div' );
		loading.className = 'bn-reactors-popover__loading';
		loading.textContent = 'Loading…';
		panel.appendChild( loading );
		panel.hidden = false;
		positionPanel( trigger );
		try {
			const url = `${ REST_BASE }/reactions/list?object_type=${ encodeURIComponent( objectType ) }&object_id=${ encodeURIComponent( objectId ) }&limit=100`;
			const r = await fetch( url, { credentials: 'include' } );
			const data = await r.json();
			renderList( data.items || [], data.total || ( data.items || [] ).length );
			positionPanel( trigger );
		} catch ( _e ) {
			panel.replaceChildren();
			const err = document.createElement( 'div' );
			err.className = 'bn-reactors-popover__error';
			err.textContent = 'Could not load reactions. Try again.';
			panel.appendChild( err );
		}
	} );

	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' ) { closePanel(); }
	} );

	window.addEventListener( 'resize', closePanel );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initReactorsPopover );
} else {
	initReactorsPopover();
}

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
		p.setAttribute( 'aria-label', 'Insert emoji' );
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
		// Position the panel under the trigger.
		const r = trigger.getBoundingClientRect();
		panel.style.position = 'absolute';
		panel.style.top  = ( window.scrollY + r.bottom + 6 ) + 'px';
		panel.style.left = ( window.scrollX + r.left ) + 'px';
		panel.hidden = false;
	} );

	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' ) { closePanel(); }
	} );
	window.addEventListener( 'resize', closePanel );
	window.addEventListener( 'scroll', closePanel, true );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initEmojiPicker );
} else {
	initEmojiPicker();
}
