/* BuddyNext — Search Results Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';

store( 'buddynext/search', {
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
				yield fetch( ctx.restUrl + 'users/' + userId + '/follow', {
					method: following ? 'DELETE' : 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
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
				yield fetch( ctx.restUrl + 'spaces/' + spaceId + '/members', {
					method: joined ? 'DELETE' : 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
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
	},
} );

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
