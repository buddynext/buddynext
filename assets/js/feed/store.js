/* BuddyNext — Feed Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';

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
				yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/share', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.shareNonce },
				} );
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
		openComments() {},
		* votePoll( event ) {
			const ctx      = getContext();
			const optionId = event.target.closest( '[data-option-id]' )?.dataset.optionId;
			if ( ! optionId ) {
				return;
			}
			try {
				yield fetch( ctx.restUrl + '/posts/' + ctx.postId + '/vote', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.pollNonce },
					body:    JSON.stringify( { option_id: parseInt( optionId, 10 ) } ),
				} );
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
			try {
				const res = yield fetch( ctx.restUrl + '/posts', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
					body:    JSON.stringify( {
						content,
						privacy: ctx.privacy || 'public',
						type:    ctx.composerType || 'text',
					} ),
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
