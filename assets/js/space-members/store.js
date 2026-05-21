/* BuddyNext — Space Members Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm } from '../shell/dialog.js';

store( 'buddynext/space-members', {
	actions: {
		* removeMember( event ) {
			const ctx = getContext();
			const btn = event.target.closest( '[data-user-id]' );
			if ( ! btn || ! ctx.restNonce || ! ctx.spaceId ) { return; }
			const ok = yield bnConfirm( {
				title: 'Remove this member?',
				body: 'They will lose access to this space immediately.',
				confirmLabel: 'Remove',
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			try {
				yield fetch( ctx.restUrl + 'spaces/' + ctx.spaceId + '/members/' + btn.dataset.userId, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				window.location.reload();
			} catch ( _e ) {}
		},

		* changeRole( event ) {
			const ctx  = getContext();
			const btn  = event.target.closest( '[data-user-id]' );
			const role = event.target.dataset.role || 'member';
			if ( ! btn || ! ctx.restNonce || ! ctx.spaceId ) { return; }
			try {
				yield fetch( ctx.restUrl + 'spaces/' + ctx.spaceId + '/members/' + btn.dataset.userId, {
					method: 'PUT',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { role: role } ),
				} );
				window.location.reload();
			} catch ( _e ) {}
		},
	},
} );
