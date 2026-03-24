/* BuddyNext — Feed Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';

/* ── Comment helpers (vanilla DOM — outside WP Interactivity API scope) ── */

function timeAgo( dateStr ) {
	const secs = Math.floor( ( Date.now() - new Date( dateStr ).getTime() ) / 1000 );
	if ( secs < 60 )    return 'just now';
	if ( secs < 3600 )  return Math.floor( secs / 60 ) + 'm ago';
	if ( secs < 86400 ) return Math.floor( secs / 3600 ) + 'h ago';
	return Math.floor( secs / 86400 ) + 'd ago';
}

/**
 * Build a comment DOM node using safe DOM methods (no innerHTML for user content).
 *
 * @param {Object}  comment       Comment data from the REST API.
 * @param {number}  currentUserId Current user's WordPress ID.
 * @param {number}  postId        Post ID the comment belongs to.
 * @param {string}  restUrl       buddynext/v1 REST root.
 * @param {string}  nonce         WP REST nonce.
 * @param {boolean} isReply       Whether this node is a threaded reply.
 * @return {HTMLElement}
 */
function buildCommentNode( comment, currentUserId, postId, restUrl, nonce, isReply ) {
	const wrap = document.createElement( 'div' );
	wrap.className = isReply ? 'bn-comment bn-comment--reply' : 'bn-comment';
	wrap.dataset.commentId = comment.id;

	// Avatar initials.
	const avatar = document.createElement( 'div' );
	avatar.className = 'bn-comment__avatar';
	avatar.setAttribute( 'aria-hidden', 'true' );
	avatar.textContent = ( comment.author_name || 'U' ).split( ' ' ).map( ( w ) => w[ 0 ] || '' ).join( '' ).slice( 0, 2 ).toUpperCase();
	wrap.appendChild( avatar );

	const body = document.createElement( 'div' );
	body.className = 'bn-comment__body';

	// Header: author name + timestamp.
	const header = document.createElement( 'div' );
	header.className = 'bn-comment__header';
	const authorSpan = document.createElement( 'span' );
	authorSpan.className = 'bn-comment__author';
	authorSpan.textContent = comment.author_name || 'User';
	const timeEl = document.createElement( 'time' );
	timeEl.className = 'bn-comment__time';
	timeEl.textContent = timeAgo( comment.created_at );
	header.appendChild( authorSpan );
	header.appendChild( timeEl );
	body.appendChild( header );

	// Content paragraph.
	const para = document.createElement( 'p' );
	para.className = 'bn-comment__content';
	para.textContent = comment.content;
	body.appendChild( para );

	// Action buttons.
	const actions = document.createElement( 'div' );
	actions.className = 'bn-comment__actions';
	body.appendChild( actions );

	const canDelete = parseInt( comment.user_id, 10 ) === currentUserId;

	if ( ! isReply ) {
		const replyBtn = document.createElement( 'button' );
		replyBtn.type = 'button';
		replyBtn.className = 'bn-comment__reply-btn';
		replyBtn.textContent = 'Reply';
		actions.appendChild( replyBtn );
	}

	if ( canDelete ) {
		const delBtn = document.createElement( 'button' );
		delBtn.type = 'button';
		delBtn.className = 'bn-comment__delete-btn';
		delBtn.textContent = 'Delete';
		delBtn.addEventListener( 'click', async () => {
			if ( ! window.confirm( 'Delete this comment?' ) ) {
				return;
			}
			const res = await fetch( restUrl + '/comments/' + comment.id, {
				method: 'DELETE', headers: { 'X-WP-Nonce': nonce },
			} );
			if ( res.ok ) {
				wrap.remove();
				adjustCommentCount( postId, -1 );
			}
		} );
		actions.appendChild( delBtn );
	}

	if ( ! isReply ) {
		// Reply form (hidden by default).
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

		// Wire reply-form toggle.
		actions.querySelector( '.bn-comment__reply-btn' )?.addEventListener( 'click', () => {
			replyForm.hidden = ! replyForm.hidden;
		} );
		replyCancel.addEventListener( 'click', () => { replyForm.hidden = true; } );

		replySubmit.addEventListener( 'click', async () => {
			const content = replyTextarea.value.trim();
			if ( ! content ) {
				return;
			}
			const res = await fetch( restUrl + '/comments', {
				method:  'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body:    JSON.stringify( { object_type: 'post', object_id: postId, content, parent_id: comment.id } ),
			} );
			if ( res.ok ) {
				const reply       = await res.json();
				reply.author_name = reply.author_name || 'You';
				repliesEl.appendChild( buildCommentNode( reply, currentUserId, postId, restUrl, nonce, true ) );
				replyTextarea.value = '';
				replyForm.hidden    = true;
			}
		} );

		// Replies container.
		const repliesEl = document.createElement( 'div' );
		repliesEl.className = 'bn-comment__replies';
		( comment.replies || [] ).forEach( ( r ) => {
			repliesEl.appendChild( buildCommentNode( r, currentUserId, postId, restUrl, nonce, true ) );
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
	actions: {
		toggleReactionPicker() {
			const ctx              = getContext();
			ctx.reactionPickerOpen = ! ctx.reactionPickerOpen;
		},
		* setReaction( event ) {
			const ctx  = getContext();
			const type = event.target.closest( '[data-reaction-type]' )?.dataset.reactionType || 'like';

			ctx.reactionPickerOpen = false;
			const newType          = ctx.reactionType === type ? null : type;

			try {
				const res = yield fetch( ctx.restUrl + '/reactions/toggle', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.reactNonce },
					body:    JSON.stringify( { object_type: 'post', object_id: ctx.postId, emoji: newType } ),
				} );
				if ( res.ok ) {
					ctx.reactionType = newType;
				}
			} catch ( _e ) {}
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
				if ( ! res.ok ) {
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
			if ( ! window.confirm( 'Delete this post?' ) ) {
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
		* sharePost() {
			const ctx = getContext();
			try {
				const res = yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/share', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.shareNonce },
				} );
				if ( res.ok ) {
					ctx.shareCount  = ( ctx.shareCount || 0 ) + 1;
					ctx.shareShared = true;
				}
			} catch ( _e ) {}
		},
		* reportPost() {
			const ctx    = getContext();
			const reason = window.prompt( 'Reason for report (optional):' ) || '';
			try {
				yield fetch( ctx.restUrl + '/reports', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.reportNonce },
					body:    JSON.stringify( { object_type: 'post', object_id: ctx.postId, reason } ),
				} );
			} catch ( _e ) {}
		},
		editPost() {
			const ctx = getContext();
			window.location.href = ctx.restUrl.replace( '/wp-json/buddynext/v1', '' )
				+ '/activity/?edit=' + ctx.postId;
		},
		pinPost() {},
		* openComments() {
			const ctx = getContext();
			ctx.commentsOpen = ! ctx.commentsOpen;

			if ( ! ctx.commentsOpen ) {
				return;
			}

			const listEl = document.querySelector( '[data-comment-list="' + ctx.postId + '"]' );
			if ( ! listEl || listEl.dataset.loaded ) {
				return;
			}

			try {
				const res = yield fetch(
					ctx.restUrl + '/comments?object_type=post&object_id=' + ctx.postId + '&per_page=20',
					{ headers: { 'X-WP-Nonce': ctx.reactNonce } }
				);
				if ( res.ok ) {
					const data = yield res.json();
					listEl.dataset.loaded = '1';
					( data.items || [] ).forEach( ( comment ) => {
						listEl.appendChild(
							buildCommentNode( comment, ctx.currentUserId, ctx.postId, ctx.restUrl, ctx.reactNonce, false )
						);
					} );
				}
			} catch ( _e ) {}
		},
		* submitComment() {
			const ctx     = getContext();
			const inputEl = document.querySelector( '[data-comment-input="' + ctx.postId + '"]' );
			const content = inputEl?.value.trim() || '';
			if ( ! content ) {
				return;
			}

			try {
				const res = yield fetch( ctx.restUrl + '/comments', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.reactNonce },
					body:    JSON.stringify( { object_type: 'post', object_id: ctx.postId, content } ),
				} );
				if ( res.ok ) {
					const comment       = yield res.json();
					comment.author_name = comment.author_name || 'You';
					const listEl        = document.querySelector( '[data-comment-list="' + ctx.postId + '"]' );
					if ( listEl ) {
						listEl.dataset.loaded = '1';
						listEl.appendChild( buildCommentNode( comment, ctx.currentUserId, ctx.postId, ctx.restUrl, ctx.reactNonce, false ) );
					}
					if ( inputEl ) {
						inputEl.value = '';
					}
					adjustCommentCount( ctx.postId, 1 );
					ctx.commentCount = ( ctx.commentCount || 0 ) + 1;
				}
			} catch ( _e ) {}
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

			// Trigger hidden file input for media upload.
			const fileInput = document.querySelector( '.bn-composer__file-input' );
			if ( fileInput ) {
				fileInput.click();
			}
		},

		/**
		 * Handle file selection — upload to WPMediaVerse REST API.
		 *
		 * Reads the selected file from the hidden input, uploads via
		 * POST /mvs/v1/media (FormData), stores the returned media_id
		 * in ctx.mediaIds, and shows a thumbnail preview.
		 */
		* handleMediaUpload( event ) {
			const ctx   = getContext();
			const files = event.target.files;
			if ( ! files || ! files.length ) {
				return;
			}

			if ( ! ctx.mediaIds ) {
				ctx.mediaIds = [];
			}
			if ( ! ctx.mediaPreviews ) {
				ctx.mediaPreviews = [];
			}

			ctx.mediaUploading = true;

			for ( let i = 0; i < files.length; i++ ) {
				const file     = files[ i ];
				const formData = new FormData();
				formData.append( 'file', file );

				try {
					// Upload to WPMediaVerse REST endpoint.
					const mvsBase = ctx.mvsRestBase || ctx.restUrl.replace( '/buddynext/v1', '/mvs/v1' );
					const res     = yield fetch( mvsBase + '/media', {
						method:  'POST',
						headers: { 'X-WP-Nonce': ctx.restNonce },
						body:    formData,
					} );

					if ( res.ok ) {
						const data = yield res.json();
						const mediaId  = data.id || data.media_id;
						const thumbUrl = data.thumbnail_url || data.source_url || data._mvs_file_url || '';

						ctx.mediaIds.push( mediaId );
						ctx.mediaPreviews.push( { id: mediaId, url: thumbUrl, name: file.name } );
					}
				} catch ( _e ) {
					// Upload failed — skip this file silently.
				}
			}

			ctx.mediaUploading = false;
			// Reset file input so the same file can be re-selected.
			event.target.value = '';
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
			getContext().content = event.target.value;
		},
		* submit() {
			const ctx     = getContext();
			const content = ( ctx.content || '' ).trim();
			if ( ! content || ctx.submitting ) {
				return;
			}
			ctx.submitting = true;

			// Collect poll options and media attachments.
			const body = {
				content,
				privacy: ctx.privacy || 'public',
				type:    ctx.composerType || 'text',
			};

			// Attach media IDs from WPMediaVerse uploads.
			if ( ctx.mediaIds && ctx.mediaIds.length ) {
				body.media_ids = ctx.mediaIds;
				// If user selected Photo mode, set type to 'photo'.
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
					ctx.submitting = false;
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
					window.location.reload();
				}
			} catch ( _e ) {
				ctx.submitting = false;
			}
		},
		setPrivacy( event ) {
			getContext().privacy = event.target.value;
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
