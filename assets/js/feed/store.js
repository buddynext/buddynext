/* BuddyNext — Feed Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm, bnPrompt, bnToast } from '../shell/dialog.js';

/* ── Comment helpers (vanilla DOM — outside WP Interactivity API scope) ── */

function timeAgo( dateStr ) {
	const secs = Math.floor( ( Date.now() - new Date( dateStr ).getTime() ) / 1000 );
	if ( secs < 60 )    return 'just now';
	if ( secs < 3600 )  return Math.floor( secs / 60 ) + 'm ago';
	if ( secs < 86400 ) return Math.floor( secs / 3600 ) + 'h ago';
	return Math.floor( secs / 86400 ) + 'd ago';
}

/**
 * Maximum visual nesting depth. Replies deeper than this are flattened
 * to depth = MAX_DEPTH with an "@parent" mention prefix injected by the
 * server so the conversation stays readable on narrow screens.
 */
const COMMENT_MAX_DEPTH = 2;

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
function buildCommentNode( comment, currentUserId, postId, restUrl, nonce, depth ) {
	if ( typeof depth !== 'number' ) {
		// Back-compat: callers that still pass a boolean isReply.
		depth = depth ? 1 : 0;
	}
	const cappedDepth = Math.min( depth, COMMENT_MAX_DEPTH );

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
	const canReply    = currentUserId > 0 && ! comment.is_deleted && cappedDepth < COMMENT_MAX_DEPTH;
	const canReport   = currentUserId > 0 && ! isOwn && ! comment.is_deleted;

	// Like button — heart toggles on the comment object via /reactions/like.
	let likeBtn = null;
	if ( ! comment.is_deleted ) {
		likeBtn = document.createElement( 'button' );
		likeBtn.type = 'button';
		likeBtn.className = 'bn-comment__like-btn';
		likeBtn.dataset.liked = comment.viewer_liked ? '1' : '0';
		likeBtn.setAttribute( 'aria-pressed', comment.viewer_liked ? 'true' : 'false' );
		const heart = document.createElement( 'span' );
		heart.className = 'bn-comment__like-icon';
		heart.textContent = comment.viewer_liked ? '♥' : '♡';
		const likeCount = document.createElement( 'span' );
		likeCount.className = 'bn-comment__like-count';
		likeCount.textContent = String( comment.like_count || 0 );
		likeBtn.appendChild( heart );
		likeBtn.appendChild( document.createTextNode( ' ' ) );
		likeBtn.appendChild( likeCount );
		likeBtn.addEventListener( 'click', async () => {
			if ( currentUserId <= 0 ) {
				bnToast( 'Sign in to like comments.', { tone: 'info' } );
				return;
			}
			const wasLiked = likeBtn.dataset.liked === '1';
			// Optimistic flip + rollback on error.
			const nextLiked = ! wasLiked;
			likeBtn.dataset.liked = nextLiked ? '1' : '0';
			likeBtn.setAttribute( 'aria-pressed', nextLiked ? 'true' : 'false' );
			heart.textContent = nextLiked ? '♥' : '♡';
			const cur = parseInt( likeCount.textContent || '0', 10 );
			likeCount.textContent = String( Math.max( 0, cur + ( nextLiked ? 1 : -1 ) ) );
			try {
				const res = await fetch( restUrl + '/reactions/toggle', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body:    JSON.stringify( { object_type: 'comment', object_id: comment.id, emoji: 'like' } ),
				} );
				if ( ! res.ok ) {
					throw new Error( 'reaction failed' );
				}
			} catch ( _e ) {
				// Rollback.
				likeBtn.dataset.liked = wasLiked ? '1' : '0';
				likeBtn.setAttribute( 'aria-pressed', wasLiked ? 'true' : 'false' );
				heart.textContent = wasLiked ? '♥' : '♡';
				likeCount.textContent = String( cur );
				bnToast( 'Could not update your like. Try again.', { tone: 'danger' } );
			}
		} );
		actions.appendChild( likeBtn );
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
			editForm.appendChild( saveBtn );
			editForm.appendChild( cancelBtn );
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
			const reason = await bnPrompt( {
				title: 'Report this comment',
				body: 'Reports are reviewed by moderators. The person you report is not notified.',
				placeholder: 'Tell us why this comment is being reported (optional)',
				confirmLabel: 'Submit report',
				cancelLabel: 'Cancel',
			} );
			if ( reason === null ) {
				return;
			}
			try {
				const res = await fetch( restUrl + '/reports', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body:    JSON.stringify( { object_type: 'comment', object_id: comment.id, reason } ),
				} );
				if ( res.ok || res.status === 201 ) {
					bnToast( 'Report submitted. Thanks for keeping the community safe.', { tone: 'success' } );
				} else {
					bnToast( 'Could not submit report. Try again.', { tone: 'danger' } );
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
	return wrap;
}

function adjustCommentCount( postId, delta ) {
	const card = document.querySelector( 'article[data-post-id="' + postId + '"]' );
	const btn  = card?.querySelector( '[data-wp-on--click="actions.openComments"]' );
	const span = btn?.querySelector( '.bn-comment-count' );
	if ( span ) {
		const n = Math.max( 0, parseInt( span.textContent || '0', 10 ) + delta );
		span.textContent = String( n );
	}
}

/* ── Post card ───────────────────────────────────────────────────────────── */

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
		get bookmarked() {
			try { return !! getContext().bookmarked; } catch ( _e ) { return false; }
		},
		get showContent() {
			try { return !! getContext().showContent; } catch ( _e ) { return true; }
		},
		get optionsOpen() {
			try { return !! getContext().optionsOpen; } catch ( _e ) { return false; }
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
			// Defer one tick so the list element is mounted before fetch runs.
			yield new Promise( ( r ) => setTimeout( r, 0 ) );
			const listEl = document.querySelector( '[data-comment-list="' + ctx.postId + '"]' );
			if ( ! listEl || listEl.dataset.loaded ) {
				return;
			}
			// Mirror loadComments inline — calling cross-action generators from
			// callbacks is unstable in the Interactivity API runtime.
			while ( listEl.firstChild ) {
				listEl.removeChild( listEl.firstChild );
			}
			for ( let s = 0; s < 3; s++ ) {
				const sk       = document.createElement( 'div' );
				sk.className   = 'bn-comment-skeleton';
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
					const data           = yield res.json();
					listEl.dataset.loaded = '1';
					( data.items || [] ).forEach( ( comment ) => {
						listEl.appendChild(
							buildCommentNode( comment, ctx.currentUserId, ctx.postId, ctx.restUrl, ctx.reactNonce, 0 )
						);
					} );
				}
			} catch ( _e ) {
				// Silent — user can click Comment to retry.
			}
		},
	},
	actions: {
		toggleReactionPicker() {
			const ctx              = getContext();
			ctx.reactionPickerOpen = ! ctx.reactionPickerOpen;
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

			// Dispatch into the global share-modal store via a custom event.
			document.dispatchEvent(
				new CustomEvent( 'bn:open-share-modal', {
					detail: {
						postId:    ctx.postId,
						permalink,
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
			const reason = yield bnPrompt( {
				title: 'Report this post',
				body: 'Reports are reviewed by moderators. The person you report is not notified.',
				placeholder: 'Tell us why this post is being reported (optional)',
				confirmLabel: 'Submit report',
				cancelLabel: 'Cancel',
			} );
			if ( reason === null ) {
				return;
			}
			try {
				const res = yield fetch( ctx.restUrl + '/reports', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.reportNonce },
					body:    JSON.stringify( { object_type: 'post', object_id: ctx.postId, reason } ),
				} );
				if ( res.ok || res.status === 201 ) {
					bnToast( 'Report submitted. Thanks for keeping the community safe.', { tone: 'success' } );
				} else {
					bnToast( 'Could not submit report. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not submit report. Try again.', { tone: 'danger' } );
			}
		},
		editPost() {
			const ctx = getContext();
			window.location.href = ctx.restUrl.replace( '/wp-json/buddynext/v1', '' )
				+ '/activity/?edit=' + ctx.postId;
		},
		* pinPost() {
			const ctx  = getContext();
			const prev = ctx.isPinned;
			ctx.isPinned = ! prev;
			try {
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/pin', {
					method: prev ? 'DELETE' : 'POST',
					headers: { 'X-WP-Nonce': ctx.reactNonce },
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( ctx.isPinned ? 'Post pinned' : 'Post unpinned' ); }
				} else {
					ctx.isPinned = prev;
				}
			} catch ( _e ) {
				ctx.isPinned = prev;
			}
		},
		* loadComments() {
			const ctx    = getContext();
			const listEl = document.querySelector( '[data-comment-list="' + ctx.postId + '"]' );
			if ( ! listEl || listEl.dataset.loaded ) {
				return;
			}

			// Skeleton rows while we fetch — three placeholder bars so the
			// region does not collapse and the user knows the thread is
			// loading. Replaced on fetch success / replaced with error
			// alert on fetch failure.
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
		},
		* openComments() {
			const ctx        = getContext();
			ctx.commentsOpen = ! ctx.commentsOpen;
			if ( ! ctx.commentsOpen ) {
				return;
			}
			yield* this.loadComments();
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
					ctx.commentCount = ( ctx.commentCount || 0 ) + 1;
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
	},
} );

/* ── Post composer ───────────────────────────────────────────────────────── */

// Module-level media state — shared between native event handler and store actions.
// WP Interactivity API getContext() doesn't work in native addEventListener callbacks.
const _mediaState = { ids: [], previews: [] };

const PRIVACY_LABELS = {
	public:    'Everyone',
	followers: 'Followers',
	private:   'Only me',
	space_members: 'Space members',
};

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
		const draft = readDraft( userId );
		if ( ! draft || ! draft.content ) {
			return;
		}
		// Pre-fill the textarea so the user sees their draft immediately,
		// even before WP Interactivity hydrates the store.
		const textarea = el.querySelector( '.bn-composer__prompt' );
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
		get hasMedia() {
			try { return ( getContext().mediaIds || [] ).length > 0; } catch ( _e ) { return false; }
		},
		get mediaPreviews() {
			try { return getContext().mediaPreviews || []; } catch ( _e ) { return []; }
		},
		get mediaUploading() {
			try { return !! getContext().mediaUploading; } catch ( _e ) { return false; }
		},
		get errorMessage() {
			try { return getContext().errorMessage || ''; } catch ( _e ) { return ''; }
		},
		get hasNoError() {
			try { return ! ( getContext().errorMessage || '' ); } catch ( _e ) { return true; }
		},
		get hasNoEventError() {
			try { return ! ( getContext().eventError || '' ); } catch ( _e ) { return true; }
		},
		get hasNoVoiceError() {
			try { return ! ( getContext().voiceError || '' ); } catch ( _e ) { return true; }
		},
		get eventError() {
			try { return getContext().eventError || ''; } catch ( _e ) { return ''; }
		},
		get voiceError() {
			try { return getContext().voiceError || ''; } catch ( _e ) { return ''; }
		},
		get privacyLabel() {
			try {
				const ctx = getContext();
				return PRIVACY_LABELS[ ctx.privacy ] || 'Everyone';
			} catch ( _e ) { return 'Everyone'; }
		},
		get isPrivacyPublic() {
			try { return getContext().privacy === 'public'; } catch ( _e ) { return false; }
		},
		get isPrivacyFollowers() {
			try { return getContext().privacy === 'followers'; } catch ( _e ) { return false; }
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
		get eventSubmitLabel() {
			try { return getContext().submitting ? 'Scheduling…' : 'Schedule event'; } catch ( _e ) { return 'Schedule event'; }
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
								const thumbUrl  = data.thumbnail_url || data.source_url || data._mvs_file_url || '';

								_mediaState.ids.push( mediaId );
								_mediaState.previews.push( { id: mediaId, url: thumbUrl, name: file.name } );

								// Append preview thumbnail to DOM.
								if ( previewArea && thumbUrl ) {
									const thumb = document.createElement( 'div' );
									thumb.className = 'bn-composer__media-thumb';
									thumb.dataset.mediaId = mediaId;
									thumb.innerHTML = '<img src="' + thumbUrl + '" alt="" width="80" height="80" loading="lazy">'
										+ '<button class="bn-composer__media-remove" type="button" data-media-id="' + mediaId + '">&times;</button>';
									thumb.querySelector( '.bn-composer__media-remove' ).addEventListener( 'click', function () {
										_mediaState.ids = _mediaState.ids.filter( ( id ) => id !== mediaId );
										_mediaState.previews = _mediaState.previews.filter( ( p ) => p.id !== mediaId );
										thumb.remove();
										if ( ! _mediaState.ids.length && previewArea ) {
											previewArea.hidden = true;
										}
									} );
									previewArea.appendChild( thumb );
								}
							}
						} catch ( _e ) {
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
		openPoll() {
			const ctx        = getContext();
			ctx.composerOpen = true;
			ctx.composerType = 'poll';
		},
		openLink() {
			const ctx        = getContext();
			ctx.composerOpen = true;
			ctx.composerType = 'link';
		},
		onInput( event ) {
			const ctx     = getContext();
			ctx.content   = event.target.value;
			scheduleDraftSave( ctx );
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
						clearDraft( userId );
					}
					ctx.hasDraft    = false;
					setDraftStatus( ctx, '', false );
					if ( window.bnToast ) { window.bnToast( 'Post published', 'success' ); }
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
		openEvent() {
			const ctx       = getContext();
			ctx.eventOpen   = true;
			ctx.eventError  = '';
		},
		closeEvent() {
			getContext().eventOpen = false;
		},
		openVoice() {
			const ctx       = getContext();
			ctx.voiceOpen   = true;
			ctx.voiceError  = '';
		},
		closeVoice() {
			getContext().voiceOpen = false;
		},
		openAiHelper() {
			const ctx  = getContext();
			ctx.aiOpen = true;

			// When Pro is active, hand off to Pro's AI store so it can populate the body.
			if ( ctx.hasPro ) {
				document.dispatchEvent(
					new CustomEvent( 'bn:open-composer-ai', {
						detail: { restUrl: ctx.restUrl, nonce: ctx.restNonce },
					} )
				);
			}
		},
		closeAiHelper() {
			getContext().aiOpen = false;
		},
		togglePrivacy() {
			const ctx        = getContext();
			ctx.privacyOpen  = ! ctx.privacyOpen;
		},
		* submitEvent() {
			const ctx = getContext();
			if ( ctx.submitting ) { return; }
			const fields = {};
			document.querySelectorAll( '[data-bn-event-field]' ).forEach( ( el ) => {
				fields[ el.dataset.bnEventField ] = el.value.trim();
			} );
			if ( ! fields.title || ! fields.date ) {
				ctx.eventError = 'Title and date are required.';
				return;
			}
			ctx.eventError = '';
			ctx.submitting = true;
			const scheduledAt = fields.date + ( fields.time ? ' ' + fields.time + ':00' : ' 00:00:00' );
			const body = {
				type:         'event',
				content:      ( fields.title + ( fields.description ? '\n\n' + fields.description : '' ) ).trim(),
				privacy:      ctx.privacy || 'public',
				link_meta:    {
					title:    fields.title,
					location: fields.location,
					event_at: scheduledAt,
				},
			};
			try {
				const res = yield fetch( ctx.restUrl + '/posts', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
					body:    JSON.stringify( body ),
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( 'Event scheduled', 'success' ); }
					setTimeout( () => window.location.reload(), 500 );
					return;
				}
				ctx.eventError = 'Could not schedule the event. Try again.';
				ctx.submitting = false;
			} catch ( _e ) {
				ctx.eventError = 'Network error. Try again.';
				ctx.submitting = false;
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
	},
	actions: {
		close() {
			const ctx = getContext();
			ctx.open  = false;
			ctx.busy  = false;
			ctx.error = '';
		},
		* repost() {
			const ctx = getContext();
			if ( ctx.busy || ! ctx.postId ) { return; }
			ctx.busy  = true;
			ctx.error = '';
			try {
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/share', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( 'Reposted', 'success' ); }
					// Bump the source card's share count via DOM lookup.
					const card = document.querySelector( '[data-post-id="' + ctx.postId + '"]' );
					if ( card ) {
						const labelEl = card.querySelector( '[data-wp-text="state.shareLabel"]' );
						if ( labelEl ) {
							const match = ( labelEl.textContent || '' ).match( /(\d+)/ );
							const count = match ? parseInt( match[ 1 ], 10 ) + 1 : 1;
							labelEl.textContent = 'Shared · ' + count;
						}
					}
					ctx.open  = false;
					ctx.busy  = false;
					return;
				}
				ctx.error = 'Could not repost. Try again.';
				ctx.busy  = false;
			} catch ( _e ) {
				ctx.error = 'Network error. Try again.';
				ctx.busy  = false;
			}
		},
		quote() {
			const ctx = getContext();
			if ( ! ctx.postId ) { return; }
			// Pre-fill the composer with a quote of the source post and focus it.
			const composer = document.querySelector( '[data-wp-interactive="buddynext/post-composer"]' );
			if ( composer ) {
				const ta = composer.querySelector( '.bn-composer__prompt' );
				if ( ta ) {
					ta.value = ( ta.value ? ta.value + '\n\n' : '' ) + ctx.permalink + '\n';
					ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					ta.focus();
				}
				composer.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
			ctx.open = false;
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

// Bridge: post-card openShare dispatches a CustomEvent; populate share-modal context.
document.addEventListener( 'bn:open-share-modal', function ( e ) {
	const detail = e.detail || {};
	const modal  = document.querySelector( '[data-wp-interactive="buddynext/share-modal"]' );
	if ( ! modal ) { return; }
	try {
		const ctx = JSON.parse( modal.getAttribute( 'data-wp-context' ) || '{}' );
		ctx.open      = true;
		ctx.busy      = false;
		ctx.error     = '';
		ctx.postId    = detail.postId || 0;
		ctx.permalink = detail.permalink || '';
		ctx.nonce     = detail.nonce || ctx.nonce;
		ctx.restUrl   = detail.restUrl || ctx.restUrl;
		modal.setAttribute( 'data-wp-context', JSON.stringify( ctx ) );
		modal.hidden = false;
	} catch ( _e ) {}
} );

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
 * input routes to /search/?q=… on submit.
 */
const BN_SEARCH_PATH = '/search/';

store( 'buddynext/feed', {
	actions: {
		setFilter( event ) {
			if ( event && event.preventDefault ) { event.preventDefault(); }
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

			// "All" stays on explore with no facet narrowing.
			if ( 'all' === filter ) {
				const url = new URL( window.location.href );
				url.searchParams.delete( 'type' );
				url.searchParams.delete( 'q' );
				url.searchParams.delete( 'cursor' );
				window.location.href = url.toString();
				return;
			}

			// Route the rest (people/posts/spaces/media/members/hashtags) to
			// the unified search results page with the chosen facet.
			const typeAlias = {
				people: 'members',
				posts:  'posts',
				spaces: 'spaces',
				media:  'media',
			};
			const tab = typeAlias[ filter ] || filter;

			const target_url = new URL( window.location.origin + BN_SEARCH_PATH );
			target_url.searchParams.set( 'type', tab );
			window.location.href = target_url.toString();
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
