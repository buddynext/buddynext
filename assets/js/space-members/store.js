/* BuddyNext — Space Members Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm, bnToast } from '../shell/dialog.js';
import { restFetch } from '../shell/rest-client.js';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_space_members) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/space-members namespace below; each lookup keeps the English
 * literal as a fallback so the UI never breaks if the state is absent.
 * fmt() fills sprintf-style '%s'/'%d' placeholders. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( vals[ pos ? pos - 1 : i++ ] ?? '' ) ); }

const spaceMembersStore = store( 'buddynext/space-members', {
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
				title: t( 'removeMemberTitle', 'Remove this member?' ),
				body: t( 'removeMemberBody', 'They will lose access to this space immediately.' ),
				confirmLabel: t( 'remove', 'Remove' ),
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			try {
				const res = yield restFetch( '/spaces/' + ctx.spaceId + '/members/' + btn.dataset.userId, {
					method: 'DELETE',
					nonce: ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					window.location.reload();
				} else {
					bnToast( t( 'removeMemberFailed', 'Could not remove member. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'removeMemberFailed', 'Could not remove member. Try again.' ), { tone: 'danger' } );
			}
		},

		* changeRole( event ) {
			const ctx  = getContext();
			const btn  = event.target.closest( '[data-user-id]' );
			const role = event.target.dataset.role || 'member';
			if ( ! btn || ! ctx.restNonce || ! ctx.spaceId ) { return; }
			ctx.menuOpen = false;
			try {
				const res = yield restFetch( '/spaces/' + ctx.spaceId + '/members/' + btn.dataset.userId + '/role', {
					method: 'PUT',
					nonce: ctx.restNonce,
					body: { role: role },
					toastOnError: false,
				} );
				if ( res.ok ) {
					window.location.reload();
				} else {
					bnToast( t( 'updateRoleFailed', 'Could not update role. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'updateRoleFailed', 'Could not update role. Try again.' ), { tone: 'danger' } );
			}
		},
	},
} );

I18N = ( spaceMembersStore.state && spaceMembersStore.state.i18n ) || {};
