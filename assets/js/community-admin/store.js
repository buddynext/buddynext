/* BuddyNext — Community Admin Interactivity API store.
 *
 * Powers the role controls in the Community Admin > Members view. Each member
 * row carries { userId, role } context; changing the role <select> POSTs to
 * community-admin/members/{id}/role and the badge/select stay in sync without a
 * reload. Role changes are admin-only server-side (RoleController::require_role_manager),
 * so a moderator viewing the panel never sees these controls.
 */
import { store, getContext } from '@wordpress/interactivity';
import { bnToast } from '@buddynext/shell-dialog';
import { restFetch } from '@buddynext/rest-client';

/* Translated strings are injected server-side into this namespace's state when
 * available; each lookup keeps an English literal fallback so the control never
 * breaks if the state is absent. */
const caStore = store( 'buddynext/community-admin', {
	actions: {
		/**
		 * Change a member's community role from the row <select>.
		 *
		 * @param {Event} event The change event from the role <select>.
		 */
		*setRole( event ) {
			const ctx = getContext();
			const select = event.target;
			const nextRole = select.value;
			const prevRole = ctx.role;

			if ( nextRole === prevRole ) {
				return;
			}

			select.disabled = true;

			const res = yield restFetch( 'community-admin/members/' + ctx.userId + '/role', {
				method: 'POST',
				body: { role: nextRole },
				toastOnError: false,
			} );

			select.disabled = false;

			const i18n = ( caStore.state && caStore.state.i18n ) || {};
			if ( res && res.ok && res.data && res.data.role ) {
				ctx.role = res.data.role;
				select.value = res.data.role;
				bnToast( i18n.roleUpdated || 'Member role updated.', { tone: 'success' } );
			} else {
				// Revert the control to the last known-good role on failure.
				select.value = prevRole;
				const msg = ( res && res.error && res.error.message ) || i18n.roleFailed || 'Could not update the role. Try again.';
				bnToast( msg, { tone: 'danger' } );
			}
		},
	},
} );
