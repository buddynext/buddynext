/* BuddyNext — Search Results Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';

const { state, actions } = store( 'buddynext/search', {
	state: {
		/* Saved searches fetched from the Pro REST collection. */
		savedSearches: [],
		get hasSaved() {
			return Array.isArray( state.savedSearches ) && state.savedSearches.length > 0;
		},
	},
	actions: {
		* toggleFollow( event ) {
			const ctx = getContext();
			const btn = event.target.closest( '[data-user-id]' );
			if ( ! btn || ! ctx.restNonce ) { return; }
			const userId    = btn.dataset.userId;
			const following = btn.classList.contains( 'following' );
			btn.classList.toggle( 'following' );
			btn.textContent = following ? 'Follow' : 'Following';
			try {
				yield restFetch( '/users/' + userId + '/follow', {
					method: following ? 'DELETE' : 'POST',
					nonce: ctx.restNonce,
					toastOnError: false,
				} );
			} catch ( _e ) {
				btn.classList.toggle( 'following' );
				btn.textContent = following ? 'Following' : 'Follow';
			}
		},

		* toggleSpaceMembership( event ) {
			const ctx = getContext();
			const btn = event.target.closest( '[data-space-id]' );
			if ( ! btn || ! ctx.restNonce ) { return; }
			const spaceId = btn.dataset.spaceId;
			const joined  = btn.classList.contains( 'joined' );
			btn.classList.toggle( 'joined' );
			btn.textContent = joined ? 'Join' : 'Joined';
			try {
				yield restFetch( '/spaces/' + spaceId + '/members', {
					method: joined ? 'DELETE' : 'POST',
					nonce: ctx.restNonce,
					toastOnError: false,
				} );
			} catch ( _e ) {
				btn.classList.toggle( 'joined' );
				btn.textContent = joined ? 'Joined' : 'Join';
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
				ctx.savedMsg = ( window.wp && window.wp.i18n )
					? window.wp.i18n.__( 'Please name this search first.', 'buddynext' )
					: 'Please name this search first.';
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
				ctx.savedMsg  = ( window.wp && window.wp.i18n )
					? window.wp.i18n.__( 'Search saved.', 'buddynext' )
					: 'Search saved.';
				yield actions.loadSavedList();
			} catch ( _e ) {
				ctx.savedMsg = ( window.wp && window.wp.i18n )
					? window.wp.i18n.__( 'Could not save. Saved searches require BuddyNext Pro.', 'buddynext' )
					: 'Could not save. Saved searches require BuddyNext Pro.';
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
	if ( typeof document === 'undefined' ) { return; }
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

		var panel = document.createElement( 'div' );
		panel.className = 'bn-search-recent';
		panel.setAttribute( 'role', 'region' );
		panel.setAttribute( 'aria-label', 'Recent searches' );

		var title = document.createElement( 'span' );
		title.className = 'bn-search-recent__title';
		title.textContent = 'Recent:';
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
		clear.textContent = 'Clear';
		clear.addEventListener( 'click', function () {
			clearAll();
			panel.remove();
		} );
		panel.appendChild( clear );

		hero.insertAdjacentElement( 'afterend', panel );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
