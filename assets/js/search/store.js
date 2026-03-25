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
