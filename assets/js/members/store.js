/* BuddyNext — Member Directory Interactivity API store.
 *
 * Aligned with templates/directory/members.php v2 attribute API:
 *   - state.isListView / isGridPressed / isListPressed for view toggle
 *   - actions.setGridView / setListView for explicit pressed-state buttons
 *   - actions.toggleFollow / sendConnection for card-level CTAs
 */

import { store, getContext } from '@wordpress/interactivity';

const VIEW_STORAGE_KEY = 'bn_members_view';

function apiUrl( path ) {
	return ( window.wpApiSettings && window.wpApiSettings.root || '/wp-json/' ) + path;
}

function ctxNonce( ctx ) {
	return ( ctx && ctx.nonce ) || '';
}

function readView() {
	try {
		return window.localStorage.getItem( VIEW_STORAGE_KEY ) === 'list' ? 'list' : 'grid';
	} catch ( _e ) {
		return 'grid';
	}
}

function writeView( next ) {
	try {
		window.localStorage.setItem( VIEW_STORAGE_KEY, next );
	} catch ( _e ) { /* storage unavailable — soft-fail */ }
}

function applyViewClass( next ) {
	var grid = document.querySelector( '.bn-md-grid' );
	if ( grid ) {
		grid.classList.toggle( 'is-list', next === 'list' );
	}
	document.querySelectorAll( '.bn-md-filters__view .bn-btn' ).forEach( function ( btn ) {
		var pressed = btn.dataset.view === next;
		btn.setAttribute( 'aria-pressed', pressed ? 'true' : 'false' );
	} );
}

function nearestCard( el ) {
	return el ? el.closest( '[data-user-id]' ) : null;
}

const memberStore = store( 'buddynext/members', {
	state: {
		get isListView() {
			return readView() === 'list';
		},
		get isGridPressed() {
			return readView() === 'grid' ? 'true' : 'false';
		},
		get isListPressed() {
			return readView() === 'list' ? 'true' : 'false';
		},
	},
	actions: {
		setGridView: function () {
			writeView( 'grid' );
			applyViewClass( 'grid' );
		},
		setListView: function () {
			writeView( 'list' );
			applyViewClass( 'list' );
		},
		toggleFollow: async function ( event ) {
			var card   = nearestCard( event && event.target );
			var btn    = event && event.target && event.target.closest( '.bn-md-card__follow' );
			var userId = card ? parseInt( card.dataset.userId || '0', 10 ) : 0;
			if ( ! userId || ! btn ) {
				return;
			}
			var ctx      = getContext();
			var nonce    = btn.dataset.nonce || ctxNonce( ctx );
			var isFollow = btn.dataset.action !== 'unfollow';
			var method   = isFollow ? 'POST' : 'DELETE';
			btn.setAttribute( 'aria-disabled', 'true' );
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + userId + '/follow' ), {
					method: method,
					headers: { 'X-WP-Nonce': nonce },
				} );
				if ( res.ok ) {
					if ( isFollow ) {
						btn.textContent = btn.dataset.labelFollowing || 'Following';
						btn.dataset.action = 'unfollow';
						btn.setAttribute( 'data-variant', 'secondary' );
					} else {
						btn.textContent = btn.dataset.labelFollow || 'Follow';
						btn.dataset.action = 'follow';
						btn.setAttribute( 'data-variant', 'primary' );
					}
				}
			} catch ( _e ) { /* network/auth fail — leave label untouched */ }
			btn.removeAttribute( 'aria-disabled' );
		},
		sendConnection: async function ( event ) {
			var card   = nearestCard( event && event.target );
			var btn    = event && event.target && event.target.closest( '.bn-md-card__connect' );
			var userId = card ? parseInt( card.dataset.userId || '0', 10 ) : 0;
			if ( ! userId || ! btn ) {
				return;
			}
			var ctx   = getContext();
			var nonce = btn.dataset.nonce || ctxNonce( ctx );
			btn.setAttribute( 'aria-disabled', 'true' );
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + userId + '/connect' ), {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce },
				} );
				if ( res.ok ) {
					btn.textContent = btn.dataset.labelPending || 'Pending';
					btn.disabled = true;
				} else {
					btn.removeAttribute( 'aria-disabled' );
				}
			} catch ( _e ) {
				btn.removeAttribute( 'aria-disabled' );
			}
		},
	},
} );

// Sync initial view state on hydration so the button reflects the persisted choice.
if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { applyViewClass( readView() ); } );
	} else {
		applyViewClass( readView() );
	}
}

export default memberStore;
