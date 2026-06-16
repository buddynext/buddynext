/* BuddyNext — Space Members Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm, bnToast } from '../shell/dialog.js';

store( 'buddynext/space-members', {
	state: {
		// Per-card kebab menu — reads the card-scoped `menuOpen` context so
		// each member card opens its own overflow menu independently.
		get menuOpen() { return !! getContext().menuOpen; },
		get menuExpanded() { return getContext().menuOpen ? 'true' : 'false'; },
	},
	actions: {
		// Toggle the per-card overflow menu (View / Message stay primary;
		// the management actions live behind this kebab).
		toggleMenu( event ) {
			if ( event && typeof event.stopPropagation === 'function' ) {
				event.stopPropagation();
			}
			const ctx = getContext();
			ctx.menuOpen = ! ctx.menuOpen;
		},

		// Close this card's menu when a click lands outside its wrapper.
		closeMenuOnOutside( event ) {
			const ctx = getContext();
			if ( ! ctx.menuOpen ) { return; }
			const wrap = event && event.target
				? event.target.closest( '.bn-space-members__menu-wrap' )
				: null;
			const current = event && event.currentTarget ? event.currentTarget : null;
			if ( wrap && wrap === current ) { return; }
			ctx.menuOpen = false;
		},

		* removeMember( event ) {
			const ctx = getContext();
			const btn = event.target.closest( '[data-user-id]' );
			if ( ! btn || ! ctx.restNonce || ! ctx.spaceId ) { return; }
			ctx.menuOpen = false;
			const ok = yield bnConfirm( {
				title: 'Remove this member?',
				body: 'They will lose access to this space immediately.',
				confirmLabel: 'Remove',
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			try {
				const res = yield fetch( ctx.restUrl + '/spaces/' + ctx.spaceId + '/members/' + btn.dataset.userId, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					window.location.reload();
				} else {
					bnToast( 'Could not remove member. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not remove member. Try again.', { tone: 'danger' } );
			}
		},

		* changeRole( event ) {
			const ctx  = getContext();
			const btn  = event.target.closest( '[data-user-id]' );
			const role = event.target.dataset.role || 'member';
			if ( ! btn || ! ctx.restNonce || ! ctx.spaceId ) { return; }
			ctx.menuOpen = false;
			try {
				const res = yield fetch( ctx.restUrl + '/spaces/' + ctx.spaceId + '/members/' + btn.dataset.userId + '/role', {
					method: 'PUT',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { role: role } ),
				} );
				if ( res.ok ) {
					window.location.reload();
				} else {
					bnToast( 'Could not update role. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not update role. Try again.', { tone: 'danger' } );
			}
		},
	},
} );
