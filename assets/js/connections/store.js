/* BuddyNext — Connections Interactivity API store (profile connections tab). */
import { store, getContext } from '@wordpress/interactivity';

store( 'buddynext/connections', {
	actions: {
		* removeConnection( event ) {
			const ctx = getContext();
			const btn = event.target.closest( '[data-user-id]' );
			if ( ! btn || ! ctx.restNonce ) { return; }
			if ( ! window.confirm( 'Remove this connection?' ) ) { return; }
			const userId = btn.dataset.userId;
			try {
				const res = yield fetch( ctx.restUrl + 'users/' + userId + '/connect', {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					const card = btn.closest( '.bn-connection-card' );
					if ( card ) { card.remove(); }
				}
			} catch ( _e ) {}
		},

		* sendMessage( event ) {
			const btn = event.target.closest( '[data-user-id]' );
			if ( ! btn ) { return; }
			window.location.href = '/messages/?compose=' + btn.dataset.userId;
		},
	},
} );
