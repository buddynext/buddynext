/* BuddyNext — Hashtag Feed Interactivity API store.
 *
 * Extends the buddynext/feed store with hashtag-specific actions:
 * toggleFollowHashtag, setSort, openComposerWithTag, voteJt.
 * The template uses data-wp-interactive="buddynext/feed" so we register
 * under the same namespace — WP Interactivity API merges the two calls.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';

store( 'buddynext/feed', {
	state: {
		/**
		 * aria-pressed string for the hashtag follow control, derived from the
		 * row/page context. Interactivity directives can't eval ternaries, so the
		 * boolean → 'true'/'false' mapping lives here.
		 */
		get hashtagFollowPressed() {
			return getContext().following ? 'true' : 'false';
		},
		/**
		 * data-current-state string ('following' | 'follow') driving the visible
		 * label + icon via CSS on the hero follow button.
		 */
		get hashtagFollowState() {
			return getContext().following ? 'following' : 'follow';
		},
	},
	actions: {
		/**
		 * Follow or unfollow a hashtag.
		 *
		 * Reactive single-source: each follow control lives inside its own
		 * data-wp-context carrying { hashtag, following }. The action flips
		 * ctx.following and the template re-renders the class / aria-pressed /
		 * label off that one value (data-wp-class / data-wp-bind--aria-pressed /
		 * data-wp-text), so there is no querySelectorAll + classList paint loop.
		 */
		toggleFollowHashtag: async function () {
			var ctx = getContext();
			if ( ! ctx || ! ctx.restNonce ) { return; }

			var slug = ctx.hashtag;
			if ( ! slug ) { return; }

			var following = !! ctx.following;
			var url       = '/hashtags/' + encodeURIComponent( slug ) + '/follow';
			var method    = following ? 'DELETE' : 'POST';

			// Optimistic, reactive update — bindings follow ctx.following.
			ctx.following = ! following;

			try {
				var res = await restFetch( url, {
					method:  method,
					nonce:   ctx.restNonce,
					toastOnError: false,
				} );

				if ( ! res.ok ) {
					ctx.following = following; // Roll back.
					if ( window.bnToast ) {
						window.bnToast( 'Could not update follow state. Try again.', { type: 'error' } );
					}
				} else if ( window.bnToast ) {
					window.bnToast(
						following
							? 'Unfollowed #' + slug
							: 'Following #' + slug,
						{ type: 'success' }
					);
				}
			} catch ( _e ) {
				ctx.following = following; // Roll back silently.
				if ( window.bnToast ) {
					window.bnToast( 'Network error. Try again.', { type: 'error' } );
				}
			}
		},

		/**
		 * Change the sort order for hashtag posts.
		 * Reads data-sort from the clicked button and navigates.
		 */
		setSort: function ( event ) {
			var btn  = event.target.closest( '[data-sort]' );
			var sort = btn ? btn.dataset.sort : null;
			if ( ! sort ) { return; }

			var url    = new URL( window.location.href );
			url.searchParams.set( 'sort', sort );
			window.location.href = url.toString();
		},

		/**
		 * Open the post composer with the hashtag pre-filled.
		 * Navigates to the home feed with a ?compose= param that the feed
		 * store picks up to pre-populate the composer text area.
		 */
		openComposerWithTag: function () {
			var ctx = getContext();
			if ( ! ctx || ! ctx.hashtag ) { return; }

			var tag = ctx.hashtag;
			// Try to open inline if a composer is present on this page.
			var composer = document.querySelector( '[data-wp-on--click="actions.openComposer"]' );
			if ( composer ) {
				composer.click();
				// Pre-fill the textarea once it appears.
				var tick = 0;
				var poll = setInterval( function () {
					var ta = document.getElementById( 'bn-composer-content' );
					if ( ta ) {
						ta.value = '#' + tag + ' ';
						ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						clearInterval( poll );
					}
					if ( ++tick > 20 ) { clearInterval( poll ); }
				}, 50 );
			} else {
				window.location.href = '/activity/?compose=' + encodeURIComponent( '#' + tag + ' ' );
			}
		},

		/**
		 * Vote on a Jetonomy post (up or down).
		 * No-op if Jetonomy REST routes are unavailable.
		 */
		voteJt: async function ( event ) {
			var ctx  = getContext();
			if ( ! ctx || ! ctx.restNonce ) { return; }

			var btn       = event.target.closest( '[data-jt-id]' );
			var jtId      = btn ? btn.dataset.jtId : null;
			var direction = btn ? btn.dataset.direction : 'up';
			if ( ! jtId ) { return; }

			try {
				await restFetch( '/posts/' + jtId + '/vote', {
					base:    '/wp-json/jetonomy/v1',
					method:  'POST',
					nonce:   ctx.restNonce,
					body:    { direction: direction },
					toastOnError: false,
				} );
			} catch ( _e ) {}
		},

		/* ── Post actions (Like / Comment / Share / Save) ───────────────── */

		react: async function ( event ) {
			var ctx = getContext();
			if ( ! ctx || ! ctx.restNonce ) { return; }

			var btn    = event.target.closest( '[data-post-id]' );
			var postId = btn ? btn.dataset.postId : null;
			if ( ! postId ) { return; }

			try {
				var res = await restFetch( '/reactions/toggle', {
					method:  'POST',
					nonce:   ctx.restNonce,
					body:    { object_type: 'post', object_id: parseInt( postId, 10 ), emoji: 'like' },
					toastOnError: false,
				} );
				if ( res.ok ) {
					btn.classList.toggle( 'active' );
					// Also toggle the reaction pill in the summary row.
					var card = btn.closest( 'article' );
					if ( card ) {
						var pill = card.querySelector( '.bn-reaction-pill, .bn-react-summary' );
						if ( pill ) { pill.classList.toggle( 'active' ); }
					}
				}
			} catch ( _e ) {}
		},

		openComments: function ( event ) {
			var btn    = event.target.closest( '[data-post-id]' );
			var postId = btn ? btn.dataset.postId : null;
			if ( ! postId ) { return; }
			window.location.href = '/activity/?post=' + postId + '#comments';
		},

		share: async function ( event ) {
			var ctx = getContext();
			if ( ! ctx || ! ctx.restNonce ) { return; }

			var btn    = event.target.closest( '[data-post-id]' );
			var postId = btn ? btn.dataset.postId : null;
			if ( ! postId ) { return; }

			try {
				var res = await restFetch( '/posts/' + postId + '/share', {
					method:  'POST',
					nonce:   ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					btn.classList.add( 'active' );
				}
			} catch ( _e ) {}
		},

		bookmark: async function ( event ) {
			var ctx = getContext();
			if ( ! ctx || ! ctx.restNonce ) { return; }

			var btn    = event.target.closest( '[data-post-id]' );
			var postId = btn ? btn.dataset.postId : null;
			if ( ! postId ) { return; }

			try {
				var res = await restFetch( '/posts/' + postId + '/bookmark', {
					method:  'POST',
					nonce:   ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					btn.classList.toggle( 'active' );
				}
			} catch ( _e ) {}
		},
	},
} );
