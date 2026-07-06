/* BuddyNext — Search Results Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';
import { onNavReady } from '../shell/nav-init.js';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_search) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/search namespace below; each lookup keeps the English literal as a
 * fallback so the UI never breaks if the state is absent. fmt() fills
 * sprintf-style '%s'/'%d' placeholders. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( vals[ pos ? pos - 1 : i++ ] ?? '' ) ); }

const searchStore = store( 'buddynext/search', {
	state: {
		/* Saved searches fetched from the Pro REST collection. */
		savedSearches: [],
		get hasSaved() {
			return Array.isArray( state.savedSearches ) && state.savedSearches.length > 0;
		},
	},
	actions: {
		/* Follow / unfollow a member. State is single-source on the row's
		   context (ctx.following); the button label + variant + aria-pressed
		   all bind to it in the template, so the handler only flips the flag
		   and reverts it if the REST call fails — no DOM paint loop. */
		* toggleFollow() {
			const ctx = getContext();
			if ( ! ctx.restNonce || ! ctx.userId ) { return; }
			const wasFollowing = !! ctx.following;
			ctx.following = ! wasFollowing;
			try {
				yield restFetch( '/users/' + ctx.userId + '/follow', {
					method: wasFollowing ? 'DELETE' : 'POST',
					nonce: ctx.restNonce,
					toastOnError: false,
				} );
			} catch ( _e ) {
				ctx.following = wasFollowing;
			}
		},

		/* Join / leave a space. Same single-source pattern as toggleFollow:
		   ctx.joined drives the label / variant / aria-pressed bindings. */
		* toggleSpaceMembership() {
			const ctx = getContext();
			if ( ! ctx.restNonce || ! ctx.spaceId ) { return; }
			const wasJoined = !! ctx.joined;
			ctx.joined = ! wasJoined;
			try {
				// Join/leave have dedicated POST endpoints; /spaces/{id}/members is
				// GET-only (listing), so the previous POST/DELETE there 404'd and the
				// search-result join button never worked.
				yield restFetch( '/spaces/' + ctx.spaceId + ( wasJoined ? '/leave' : '/join' ), {
					method: 'POST',
					nonce: ctx.restNonce,
					toastOnError: false,
				} );
			} catch ( _e ) {
				ctx.joined = wasJoined;
			}
		},

		applyDateFilter( event ) {
			const val = event.target.value;
			if ( val ) {
				const url = new URL( window.location.href );
				url.searchParams.set( 'date', val );
				window.location.href = url.toString();
			}
		},

		applySortFilter( event ) {
			const val = event.target.value;
			if ( val ) {
				const url = new URL( window.location.href );
				url.searchParams.set( 'sort', val );
				window.location.href = url.toString();
			}
		},

		/* ── Saved searches (Pro) ──────────────────────────────────────
		   All four talk to buddynext-pro/v1/me/saved-searches. When Pro is
		   inactive the collection 404s; we surface a single notice instead
		   of failing. */

		setSavedName( event ) {
			const ctx = getContext();
			ctx.savedName = event.target.value;
		},

		*saveCurrent() {
			const ctx = getContext();
			if ( ! ctx.savedSearchUrl || ! ctx.restNonce ) {
				return;
			}
			const name = ( ctx.savedName || '' ).trim();
			if ( ! name ) {
				ctx.savedMsg = t( 'nameSearchFirst', 'Please name this search first.' );
				return;
			}
			try {
				const res = yield restFetch( ctx.savedSearchUrl, {
					method: 'POST',
					nonce: ctx.restNonce,
					body: {
						name,
						query_args: ctx.currentArgs || {},
					},
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					throw new Error( 'save_failed' );
				}
				ctx.savedName = '';
				ctx.savedMsg  = t( 'searchSaved', 'Search saved.' );
				yield actions.loadSavedList();
			} catch ( _e ) {
				ctx.savedMsg = t( 'searchSaveProRequired', 'Could not save. Saved searches require BuddyNext Pro.' );
			}
		},

		*deleteSaved( event ) {
			const ctx = getContext();
			const btn = event.target.closest( '[data-saved-id]' );
			const id  = btn ? parseInt( btn.dataset.savedId, 10 ) : 0;
			if ( ! id || ! ctx.savedSearchUrl || ! ctx.restNonce ) {
				return;
			}
			try {
				yield restFetch( ctx.savedSearchUrl + '/' + id, {
					method: 'DELETE',
					nonce: ctx.restNonce,
					toastOnError: false,
				} );
				state.savedSearches = state.savedSearches.filter( function ( s ) {
					return s.id !== id;
				} );
			} catch ( _e ) {
				/* leave list intact on failure */
			}
		},

		/* Internal: (re)fetch the list. Used by the init callback + after save. */
		*loadSavedList() {
			const ctx = getContext();
			if ( ! ctx.savedSearchUrl || ! ctx.restNonce || ! ctx.isLoggedIn ) {
				return;
			}
			try {
				const res = yield restFetch( ctx.savedSearchUrl, {
					nonce: ctx.restNonce,
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					return;
				}
				const rows = res.data;
				if ( ! Array.isArray( rows ) ) {
					return;
				}
				state.savedSearches = rows.map( function ( row ) {
					return {
						id: row.id,
						name: row.name,
						url: buildRunUrl( ctx, row.query_args || {} ),
					};
				} );
			} catch ( _e ) {
				/* silent — Pro may be inactive */
			}
		},
	},

	callbacks: {
		*loadSaved() {
			yield actions.loadSavedList();
		},
	},
} );

const { state, actions } = searchStore;
I18N = ( searchStore.state && searchStore.state.i18n ) || {};

/*
   Build a /search URL that reproduces a saved search's query_args. Running a
   saved search this way re-applies the advanced filters through the same web
   seam used everywhere else (no separate code path), and mirrors what the Pro
   REST .../run endpoint does server-side for app clients.
   ---------------------------------------------------------------- */
function buildRunUrl( ctx, args ) {
	const url = new URL( window.location.origin + window.location.pathname );
	const set = function ( key, val ) {
		if ( val !== undefined && val !== null && val !== '' ) {
			url.searchParams.set( key, String( val ) );
		}
	};
	set( 'q', args.query );
	// Stored type 'user' maps back to the 'members' tab on the web surface.
	set( 'type', args.type === 'user' ? 'members' : args.type );
	set( 'date', args.date );
	set( 'sort', args.sort );
	set( 'tier_slug', args.tier_slug );
	set( 'space_id', args.space_id );
	set( 'member_label', args.member_label );
	set( 'joined_after', args.joined_after );
	set( 'active_within_days', args.active_within_days );
	return url.toString();
}

/*
   `/` keyboard shortcut — focus the search input from anywhere on
   the search page. Skips when the user is typing in another input,
   textarea, or contenteditable region. Standard convention used by
   Twitter, GitHub, Slack, Discord.
   ---------------------------------------------------------------- */
( function () {
	if ( typeof document === 'undefined' || window.__bnSearchKeydownBound ) { return; }
	window.__bnSearchKeydownBound = true;
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey ) { return; }
		var t = e.target;
		if ( ! t ) { return; }
		var tag = ( t.tagName || '' ).toLowerCase();
		if ( tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable ) { return; }
		var input = document.getElementById( 'bn-search-q' );
		if ( ! input ) { return; }
		e.preventDefault();
		input.focus();
		input.select();
	} );
} )();

/*
   Recent searches — small localStorage list (last 5 unique queries).
   Renders below the search hero on the search page when present.
   Each entry is a button that fills the search input and submits.
   ---------------------------------------------------------------- */
( function () {
	if ( typeof document === 'undefined' || ! window.localStorage ) { return; }
	var STORAGE_KEY = 'bn:recent-searches';
	var MAX_RECENT  = 5;

	function read() {
		try {
			var raw = localStorage.getItem( STORAGE_KEY );
			if ( ! raw ) { return []; }
			var arr = JSON.parse( raw );
			return Array.isArray( arr ) ? arr.filter( function ( q ) { return typeof q === 'string' && q.length > 0; } ) : [];
		} catch ( _e ) {
			return [];
		}
	}

	function write( arr ) {
		try {
			localStorage.setItem( STORAGE_KEY, JSON.stringify( arr.slice( 0, MAX_RECENT ) ) );
		} catch ( _e ) {}
	}

	function pushQuery( q ) {
		q = ( q || '' ).trim();
		if ( ! q ) { return; }
		var arr = read().filter( function ( prev ) { return prev !== q; } );
		arr.unshift( q );
		write( arr );
	}

	function clearAll() {
		try { localStorage.removeItem( STORAGE_KEY ); } catch ( _e ) {}
	}

	function init() {
		// Capture the current query on the results page (the search form
		// committed it via the natural GET so it's already in the URL).
		var params = new URLSearchParams( window.location.search );
		var q = params.get( 'q' );
		if ( q ) { pushQuery( q ); }

		var list = read();
		if ( list.length === 0 ) { return; }

		var hero = document.querySelector( '.bn-search-hero' );
		if ( ! hero ) { return; }

		// Idempotent: skip if the panel is already built (re-run after a
		// client-side navigation would otherwise stack duplicate panels).
		if ( document.querySelector( '.bn-search-recent' ) ) { return; }

		var panel = document.createElement( 'div' );
		panel.className = 'bn-search-recent';
		panel.setAttribute( 'role', 'region' );
		panel.setAttribute( 'aria-label', t( 'recentSearchesLabel', 'Recent searches' ) );

		var title = document.createElement( 'span' );
		title.className = 'bn-search-recent__title';
		title.textContent = t( 'recentSearchesTitle', 'Recent:' );
		panel.appendChild( title );

		list.forEach( function ( prevQ ) {
			var chip = document.createElement( 'a' );
			chip.className = 'bn-search-recent__chip';
			var url = new URL( window.location.origin + window.location.pathname );
			url.searchParams.set( 'q', prevQ );
			chip.href = url.toString();
			chip.textContent = prevQ;
			panel.appendChild( chip );
		} );

		var clear = document.createElement( 'button' );
		clear.type = 'button';
		clear.className = 'bn-search-recent__clear';
		clear.textContent = t( 'clear', 'Clear' );
		clear.addEventListener( 'click', function () {
			clearAll();
			panel.remove();
		} );
		panel.appendChild( clear );

		hero.insertAdjacentElement( 'afterend', panel );
	}

	onNavReady( init );
} )();

/* --------------------------------------------------------------------------
   As-you-type suggestions overlay.

   Debounced typeahead on the search input: hits GET /search/suggest (grouped,
   capped per type) and renders a grouped overlay under the field. Clicking a
   suggestion navigates straight to it. Web + native app previously had no
   suggestion endpoint or typeahead at all. Keyboard: Escape closes; the input's
   own submit still runs a full search.
   -------------------------------------------------------------------------- */
( function () {
	if ( typeof document === 'undefined' ) { return; }

	function initTypeahead() {
		var input = document.getElementById( 'bn-search-q' );
		if ( ! input || input.getAttribute( 'data-bn-typeahead' ) === '1' ) { return; }
		input.setAttribute( 'data-bn-typeahead', '1' );
		input.setAttribute( 'autocomplete', 'off' );

		var overlay = document.createElement( 'div' );
		overlay.className = 'bn-search-suggest';
		overlay.setAttribute( 'role', 'listbox' );
		overlay.setAttribute( 'aria-label', t( 'suggestionsLabel', 'Search suggestions' ) );
		overlay.hidden = true;
		var host = input.closest( '.bn-search-hero__field' ) || input.parentNode;
		host.appendChild( overlay );

		var timer = null;
		var lastQ = '';

		function close() {
			overlay.hidden = true;
			overlay.textContent = '';
		}

		function render( groups ) {
			overlay.textContent = '';
			// grouped_search returns { types: [ { type, results, total }, … ] }.
			var types = ( groups && groups.types ) || [];
			if ( ! types.length ) { close(); return; }
			types.forEach( function ( group ) {
				var head = document.createElement( 'div' );
				head.className = 'bn-search-suggest__group';
				head.textContent = group.type || '';
				overlay.appendChild( head );
				( group.results || [] ).forEach( function ( item ) {
					if ( ! item || ! item.url ) { return; }
					var a = document.createElement( 'a' );
					a.className = 'bn-search-suggest__item';
					a.setAttribute( 'role', 'option' );
					a.href = item.url;
					a.textContent = item.title || item.url;
					overlay.appendChild( a );
				} );
			} );
			overlay.hidden = false;
		}

		function fetchSuggest( q ) {
			restFetch( '/search/suggest?q=' + encodeURIComponent( q ), { method: 'GET' } )
				.then( function ( res ) {
					// Ignore a stale response if the query moved on.
					if ( q !== lastQ ) { return; }
					var data = res && res.data ? res.data : {};
					render( data.groups || [] );
				} )
				.catch( function () { close(); } );
		}

		input.addEventListener( 'input', function () {
			var q = input.value.trim();
			lastQ = q;
			if ( timer ) { clearTimeout( timer ); }
			// Too short to be useful — clear (server also short-circuits blanks).
			if ( q.length < 2 ) { close(); return; }
			timer = setTimeout( function () { fetchSuggest( q ); }, 250 );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) { close(); }
		} );

		// Close when focus leaves the field + overlay (deferred so a click on a
		// suggestion registers before the overlay is torn down).
		document.addEventListener( 'click', function ( e ) {
			if ( ! host.contains( e.target ) ) { close(); }
		} );
	}

	onNavReady( initTypeahead );
} )();
