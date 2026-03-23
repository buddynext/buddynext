/* BuddyNext — Member Directory Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';

function apiUrl( path ) {
	return ( window.wpApiSettings && window.wpApiSettings.root || '/wp-json/' ) + path;
}

function ctxNonce( ctx ) {
	return ( ctx && ctx.nonce ) || '';
}

function debounce( fn, ms ) {
	var timer = null;
	return function () {
		var args = arguments; var self = this;
		clearTimeout( timer );
		timer = setTimeout( function () { fn.apply( self, args ); }, ms );
	};
}

function nearestCard( el ) {
	return el ? el.closest( '[data-user-id]' ) : null;
}

store( 'buddynext/members', {
	state: {
		get isListView() {
			try { return localStorage.getItem( 'bn_members_view' ) === 'list'; } catch ( _ ) { return false; }
		},
	},
	actions: {
		toggleView: function () {
			var isList = false;
			try { isList = localStorage.getItem( 'bn_members_view' ) === 'list'; } catch ( _ ) {}
			var next = ! isList;
			try { localStorage.setItem( 'bn_members_view', next ? 'list' : 'grid' ); } catch ( _ ) {}
			var grid = document.querySelector( '.bn-members-grid' );
			if ( grid ) { grid.classList.toggle( 'bn-list-view', next ); }
		},
		search: debounce( function ( event ) {
			var ctx = getContext();
			ctx.search = ( event && event.target && event.target.value ) || '';
		}, 350 ),
		follow: async function ( event ) {
			var card   = nearestCard( event && event.target );
			var userId = card ? parseInt( card.dataset.userId || card.dataset.memberId, 10 ) : 0;
			if ( ! userId ) { return; }
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + userId + '/follow' ), {
					method: 'POST', headers: { 'X-WP-Nonce': ctxNonce( ctx ) },
				} );
				if ( res.ok && card ) {
					var btn = card.querySelector( '.bn-follow-btn' );
					if ( btn ) {
						btn.textContent = btn.dataset.labelFollowing || 'Following';
						btn.dataset.action = 'unfollow';
						btn.classList.add( 'bn-is-following' );
					}
				}
			} catch ( _e ) {}
		},
		unfollow: async function ( event ) {
			var card   = nearestCard( event && event.target );
			var userId = card ? parseInt( card.dataset.userId || card.dataset.memberId, 10 ) : 0;
			if ( ! userId ) { return; }
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + userId + '/follow' ), {
					method: 'DELETE', headers: { 'X-WP-Nonce': ctxNonce( ctx ) },
				} );
				if ( res.ok && card ) {
					var btn = card.querySelector( '.bn-follow-btn' );
					if ( btn ) {
						btn.textContent = btn.dataset.labelFollow || '+ Follow';
						btn.dataset.action = 'follow';
						btn.classList.remove( 'bn-is-following' );
					}
				}
			} catch ( _e ) {}
		},
		toggleFollow: async function ( event ) {
			var btn = event && event.target && event.target.closest( '.bn-follow-btn' );
			if ( ! btn ) { return; }
			var actions = store( 'buddynext/members' ).actions;
			if ( btn.dataset.action === 'unfollow' ) {
				await actions.unfollow( event );
			} else {
				await actions.follow( event );
			}
		},
		connect: async function ( event ) {
			var card   = nearestCard( event && event.target );
			var userId = card ? parseInt( card.dataset.userId || card.dataset.memberId, 10 ) : 0;
			if ( ! userId ) { return; }
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + userId + '/connect' ), {
					method: 'POST', headers: { 'X-WP-Nonce': ctxNonce( ctx ) },
				} );
				if ( res.ok && card ) {
					var btn = card.querySelector( '.bn-connect-btn' );
					if ( btn ) { btn.textContent = btn.dataset.labelPending || 'Pending'; btn.disabled = true; }
				}
			} catch ( _e ) {}
		},
	},
} );
