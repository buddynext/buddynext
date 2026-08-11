/**
 * BuddyNext share/repost modal.
 *
 * Split out of feed/store.js: the buddynext/share-modal store powers the Share
 * button wherever a post or profile is shareable — the activity feed, in-space
 * feeds, single posts, and member profiles. Its shared helpers (t, prependFeedCard)
 * come from ./shared.js, the same instances the feed store uses; getContext and
 * restFetch are the standard imports. Loaded as a relative import from
 * feed/store.js so it registers exactly where @buddynext/feed is enqueued.
 *
 * @package BuddyNext
 */

import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '@buddynext/rest-client';
import { t, prependFeedCard } from '@buddynext/feed-shared';


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
			try { return getContext().busy ? t( 'reposting', 'Reposting…' ) : t( 'repost', 'Repost' ); } catch ( _e ) { return t( 'repost', 'Repost' ); }
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
				const res = yield restFetch( '/posts/' + ctx.postId + '/share', {
					method:  'POST',
					nonce:   ctx.nonce,
					toastOnError: false,
					body:    { content: ( ctx.note || '' ).trim() },
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( t( 'reposted', 'Reposted' ), 'success' ); }
					ctx.open = false;
					ctx.busy = false;
					ctx.note = '';
					// Prepend the server-rendered repost card in place (no reload),
					// mirroring the composer. Fall back to a reload only when no card
					// html came back or there's no feed list on this page.
					if ( ! prependFeedCard( res.data && res.data.html ) ) {
						setTimeout( function () { window.location.reload(); }, 500 );
					}
					return;
				}
				// Show the server's specific reason (e.g. "You have already shared
				// this post.") inline AND as a toast, not a generic mute failure.
				ctx.error = ( res.data && res.data.message ) || t( 'repostFailed', 'Could not repost. Try again.' );
				if ( window.bnToast ) { window.bnToast( ctx.error, 'error' ); }
				ctx.busy  = false;
			} catch ( _e ) {
				ctx.error = t( 'networkError', 'Network error. Try again.' );
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
				if ( window.bnToast ) { window.bnToast( t( 'linkCopied', 'Link copied' ), 'success' ); }
				ctx.open  = false;
				ctx.busy  = false;
			} catch ( _e ) {
				ctx.error = t( 'linkCopyFailed', 'Could not copy link.' );
				ctx.busy  = false;
			}
		},
	},
} );
