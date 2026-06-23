/* BuddyNext — Hashtag Feed Interactivity API store.
 *
 * Extends the buddynext/feed store with hashtag-specific actions:
 * toggleFollowHashtag, setSort, openComposerWithTag, voteJt.
 * The template uses data-wp-interactive="buddynext/feed" so we register
 * under the same namespace — WP Interactivity API merges the two calls.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_hashtags) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/hashtags namespace below; each lookup keeps the English literal as
 * a fallback so the UI never breaks if the state is absent. fmt() fills
 * sprintf-style '%s'/'%d' placeholders. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( vals[ pos ? pos - 1 : i++ ] ?? '' ) ); }

const hashtagsStore = store( 'buddynext/feed', {
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
						window.bnToast( t( 'followUpdateFailed', 'Could not update follow state. Try again.' ), { type: 'error' } );
					}
				} else if ( window.bnToast ) {
					window.bnToast(
						following
							? fmt( t( 'unfollowedHashtag', 'Unfollowed #%s' ), slug )
							: fmt( t( 'followingHashtag', 'Following #%s' ), slug ),
						{ type: 'success' }
					);
				}
			} catch ( _e ) {
				ctx.following = following; // Roll back silently.
				if ( window.bnToast ) {
					window.bnToast( t( 'networkError', 'Network error. Try again.' ), { type: 'error' } );
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
		 * Upvote a Jetonomy discussion from the hashtag bridge card, or remove
		 * the vote when already cast (the card shows a single up-vote control).
		 *
		 * Jetonomy's contract is POST { value: 1 } to cast and DELETE to clear,
		 * returning the new vote_score as `score`. The previous payload sent
		 * { direction } which the endpoint rejects with 400 — so the vote never
		 * registered and the tally never moved. No-op if Jetonomy is inactive.
		 */
		voteJt: async function ( event ) {
			var ctx  = getContext();
			if ( ! ctx || ! ctx.restNonce ) { return; }

			var btn  = event.target.closest( '[data-jt-id]' );
			var jtId = btn ? btn.dataset.jtId : null;
			if ( ! jtId ) { return; }

			var voted = btn.classList.contains( 'is-voted' );
			try {
				var res = await restFetch( '/posts/' + jtId + '/vote', {
					base:    '/wp-json/jetonomy/v1',
					method:  voted ? 'DELETE' : 'POST',
					nonce:   ctx.restNonce,
					body:    voted ? undefined : { value: 1 },
					toastOnError: false,
				} );
				// Reflect the result (previously a silent no-op — the request
				// 400'd on a bad payload and the tally never moved).
				if ( res && res.ok ) {
					var nowVoted = ! voted;
					btn.classList.toggle( 'is-voted', nowVoted );
					btn.setAttribute( 'aria-pressed', nowVoted ? 'true' : 'false' );
					var countEl = btn.querySelector( 'span' );
					if ( countEl ) {
						// Prefer the server's authoritative vote_score; fall back to
						// adjusting the displayed tally by the toggle direction.
						if ( res.data && null != res.data.score ) {
							countEl.textContent = String( parseInt( res.data.score, 10 ) || 0 );
						} else {
							countEl.textContent = String( ( parseInt( countEl.textContent, 10 ) || 0 ) + ( nowVoted ? 1 : -1 ) );
						}
					}
				}
			} catch ( _e ) {}
		},
	},
} );

// The hashtag dictionary is injected server-side under its own namespace
// (AssetService::i18n_hashtags → buddynext/hashtags). Read it once; fall back
// to the feed store's state so a shared key still resolves.
I18N =
	( store( 'buddynext/hashtags' ).state && store( 'buddynext/hashtags' ).state.i18n ) ||
	( hashtagsStore.state && hashtagsStore.state.i18n ) ||
	{};
