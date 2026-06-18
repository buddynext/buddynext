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
	actions: {
		/**
		 * Follow or unfollow a hashtag.
		 *
		 * Prefers the clicked button's data-hashtag attribute over the page
		 * context so the same action can drive multiple chips (header, sidebar
		 * card, related-tag list) without each needing its own context.
		 */
		toggleFollowHashtag: async function ( event ) {
			var ctx = getContext();
			if ( ! ctx || ! ctx.restNonce ) { return; }

			var clicked   = event && event.target ? event.target.closest( '[data-hashtag]' ) : null;
			var slug      = clicked ? clicked.getAttribute( 'data-hashtag' ) : ctx.hashtag;
			if ( ! slug ) { return; }

			var following = clicked
				? ( clicked.getAttribute( 'aria-pressed' ) === 'true' )
				: ctx.following;
			var url       = '/hashtags/' + encodeURIComponent( slug ) + '/follow';
			var method    = following ? 'DELETE' : 'POST';

			// Optimistic UI update. Only mirror to context if this click is
			// for the page-level hashtag (header button), not a sidebar chip.
			if ( slug === ctx.hashtag ) {
				ctx.following = ! following;
			}

			// Update button/switch state in the DOM (outside reactive context).
			var buttons = document.querySelectorAll(
				'[data-hashtag="' + slug + '"]'
			);
			buttons.forEach( function ( btn ) {
				var isSwitch = ( btn.getAttribute( 'role' ) === 'switch' );
				if ( isSwitch ) {
					btn.setAttribute( 'aria-checked', ! following ? 'true' : 'false' );
				} else {
					btn.classList.toggle( 'following', ! following );
					btn.setAttribute( 'aria-pressed', ! following ? 'true' : 'false' );
					// Drives the visible label (Follow vs Following) + icon via CSS.
					btn.setAttribute( 'data-current-state', ! following ? 'following' : 'follow' );
				}
			} );

			try {
				var res = await restFetch( url, {
					method:  method,
					nonce:   ctx.restNonce,
					toastOnError: false,
				} );

				if ( ! res.ok ) {
					// Roll back on failure.
					if ( slug === ctx.hashtag ) {
						ctx.following = following;
					}
					buttons.forEach( function ( btn ) {
						var isSwitch = ( btn.getAttribute( 'role' ) === 'switch' );
						if ( isSwitch ) {
							btn.setAttribute( 'aria-checked', following ? 'true' : 'false' );
						} else {
							btn.classList.toggle( 'following', following );
							btn.setAttribute( 'aria-pressed', following ? 'true' : 'false' );
							btn.setAttribute( 'data-current-state', following ? 'following' : 'follow' );
						}
					} );
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
				// Roll back silently on network error.
				if ( slug === ctx.hashtag ) {
					ctx.following = following;
				}
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
