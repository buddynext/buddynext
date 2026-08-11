/* BuddyNext — Feed Interactivity API store. */
import { store, getContext, getElement } from '@wordpress/interactivity';
import { restFetch } from '@buddynext/rest-client';
import { onNavReady } from '@buddynext/nav-init';
import { setI18N, setTz, t, fmt, bnApplyFilters, escapeHtml, siteTzOffset, clearField, autoResizeTextarea, toUtcSqlDatetime, toSiteInputValue, siteNowInputValue, bnEmojiAssetBase } from '@buddynext/feed-shared';

// Store concerns split into their own files by responsibility. Side-effect
// imports: each registers its namespace when this module loads, so they load
// exactly where @buddynext/feed is enqueued — the file moved, the loading did not.
import '@buddynext/feed-tabs';
import '@buddynext/feed-share-modal';
import '@buddynext/feed-composer';
import '@buddynext/feed-post-card';

/* -- i18n -------------------------------------------------------------- */
/* t(), fmt() and the shared i18n table now live in ./shared.js so every split
 * store file (tabs.js, share-modal.js, …) reads one instance. The dictionary is
 * still read once from the buddynext/feed state below and handed to setI18N(). */

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
