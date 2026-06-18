/* BuddyNext — Follow / Connection button Interactivity API stores.
 *
 * Drives the standalone Follow + Connect buttons used in:
 *   - templates/partials/follow-button.php           (member cards, sidebar)
 *   - templates/partials/connection-button.php       (member cards)
 *   - templates/blocks/follow-button.php             (Gutenberg block frontend)
 *   - templates/blocks/connection-button.php         (Gutenberg block frontend)
 *
 * Each button is an isolated Interactivity root with its own context
 * (userId, status, restUrl, nonce). Every action runs optimistically with a
 * rollback path and emits a toast on success / failure.
 *
 * Follow states surfaced via context.btnState:
 *   unfollowed | following | pending
 *   (self + blocked short-circuit before the partial renders)
 *
 * Connection states surfaced via context.btnState (and context.status):
 *   none | pending-sent | pending-received | accepted
 *   (blocked short-circuits before the partial renders)
 *
 * Sidebar widgets that watch follow counts (e.g. People to Follow) are
 * invalidated server-side via the WidgetListener on follow / unfollow, so
 * the next page render reflects the change without a client refetch.
 */

import { store, getContext } from '@wordpress/interactivity';
import { bnToast, bnResolveConnectNote } from '../shell/dialog.js';
import { restFetch } from '../shell/rest-client.js';

/* -- Helpers ----------------------------------------------------------- */

function ctxNonce( ctx ) {
	return ( ctx && ctx.nonce ) || '';
}

function followLabel( ctx ) {
	if ( ctx.isPending )   { return 'Requested'; }
	if ( ctx.isFollowing ) { return 'Following'; }
	return 'Follow';
}

function followState( ctx ) {
	if ( ctx.isPending )   { return 'pending'; }
	if ( ctx.isFollowing ) { return 'following'; }
	return 'unfollowed';
}

function followAriaPressed( ctx ) {
	return ( ctx.isFollowing || ctx.isPending ) ? 'true' : 'false';
}

function followAriaLabel( ctx ) {
	if ( ctx.isPending )   { return 'Cancel follow request'; }
	if ( ctx.isFollowing ) { return 'Unfollow this user'; }
	return 'Follow this user';
}

// v2 buttons are styled via the .bn-btn base class + data-variant / data-size
// attributes; the legacy bn-btn--sm/--primary/--secondary/--ghost modifier
// classes were removed in the token cleanup, so the look now rides on
// data-variant (see followVariant) and a static data-size. The class only
// carries the base + a state hook for any per-state custom styling.
function followClass( ctx ) {
	if ( ctx.isFollowing && ! ctx.isPending ) {
		return 'bn-btn bn-following bn-follow-btn';
	}
	return 'bn-btn bn-follow-btn';
}

function followVariant( ctx ) {
	if ( ctx.isPending )   { return 'ghost'; }
	if ( ctx.isFollowing ) { return 'secondary'; }
	return 'primary';
}

/* -- Follow button store ----------------------------------------------- */

store( 'buddynext/follow-button', {
	state: {
		get label()        { return followLabel( getContext() ); },
		get btnState()     { return followState( getContext() ); },
		get ariaPressed()  { return followAriaPressed( getContext() ); },
		get ariaLabel()    { return followAriaLabel( getContext() ); },
		get buttonClass()  { return followClass( getContext() ); },
		get followVariant() { return followVariant( getContext() ); },
	},
	actions: {
		async toggleFollow() {
			const ctx = getContext();
			const wasFollowing = !! ctx.isFollowing;
			const wasPending   = !! ctx.isPending;
			const userId       = ctx.userId;
			const target       = ctx.targetName || ( '#' + userId );
			const usePending   = !! ctx.privateFollow && ! wasFollowing && ! wasPending;

			// Optimistic toggle.
			if ( usePending ) {
				ctx.isPending   = true;
				ctx.isFollowing = false;
			} else if ( wasPending ) {
				ctx.isPending = false;
			} else {
				ctx.isFollowing = ! wasFollowing;
			}

			try {
				const res = await restFetch( '/users/' + userId + '/follow', {
					method:       ( wasFollowing || wasPending ) ? 'DELETE' : 'POST',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'follow_failed_' + res.status ); }
				let msg;
				if ( wasFollowing )      { msg = 'Unfollowed @' + target; }
				else if ( wasPending )   { msg = 'Follow request to @' + target + ' cancelled'; }
				else if ( usePending )   { msg = 'Follow request sent to @' + target; }
				else                     { msg = 'Now following @' + target; }
				bnToast( msg, { tone: wasFollowing || wasPending ? 'info' : 'success' } );
			} catch ( _e ) {
				// Roll back.
				ctx.isFollowing = wasFollowing;
				ctx.isPending   = wasPending;
				bnToast(
					wasFollowing
						? 'Could not unfollow @' + target + '. Try again.'
						: 'Could not follow @' + target + '. Try again.',
					{ tone: 'danger' }
				);
			}
		},
	},
} );

/* -- Follow-request inbox store ---------------------------------------- */
/* Drives the per-row Approve / Reject buttons on the followers page
 * pending-requests section (templates/profile/followers.php). Each row
 * is its own Interactivity context with a followerId — the store hides
 * the row after a successful action and emits a toast either way. */

store( 'buddynext/follow-requests', {
	state: {
		get rowHidden() { return !! getContext().hidden; },
	},
	actions: {
		async approve( event ) {
			const ctx = getContext();
			if ( ctx.busy || ctx.hidden ) { return; }
			ctx.busy = true;
			try {
				const res = await restFetch( '/me/follow-requests/' + ctx.followerId + '/approve', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'approve_failed_' + res.status ); }
				ctx.hidden = true;
				bnToast( '@' + ( ctx.targetName || ctx.followerId ) + ' can now follow you', { tone: 'success' } );
			} catch ( _e ) {
				bnToast( 'Could not approve request. Try again.', { tone: 'danger' } );
			} finally {
				ctx.busy = false;
			}
		},
		async reject( event ) {
			const ctx = getContext();
			if ( ctx.busy || ctx.hidden ) { return; }
			ctx.busy = true;
			try {
				const res = await restFetch( '/me/follow-requests/' + ctx.followerId + '/reject', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'reject_failed_' + res.status ); }
				ctx.hidden = true;
				bnToast( 'Request from @' + ( ctx.targetName || ctx.followerId ) + ' declined', { tone: 'info' } );
			} catch ( _e ) {
				bnToast( 'Could not decline request. Try again.', { tone: 'danger' } );
			} finally {
				ctx.busy = false;
			}
		},
	},
} );

/* -- Connection-request inbox store ------------------------------------ */
/* Drives the per-row Accept / Decline buttons on the Connections tab
 * pending connection-requests section (templates/parts/profile-tab-panel.php).
 * Each row is its own context with a requesterId — the store hides the row
 * after a successful action and emits a toast either way. Mirrors the
 * follow-requests inbox, hitting the connection accept/decline endpoints. */

store( 'buddynext/connection-requests', {
	state: {
		get rowHidden() { return !! getContext().hidden; },
	},
	actions: {
		async accept() {
			const ctx = getContext();
			if ( ctx.busy || ctx.hidden ) { return; }
			ctx.busy = true;
			try {
				const res = await restFetch( '/users/' + ctx.requesterId + '/connect/accept', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'accept_failed_' + res.status ); }
				ctx.hidden = true;
				bnToast( 'Connected with @' + ( ctx.targetName || ctx.requesterId ), { tone: 'success' } );
			} catch ( _e ) {
				bnToast( 'Could not accept request. Try again.', { tone: 'danger' } );
			} finally {
				ctx.busy = false;
			}
		},
		async decline() {
			const ctx = getContext();
			if ( ctx.busy || ctx.hidden ) { return; }
			ctx.busy = true;
			try {
				const res = await restFetch( '/users/' + ctx.requesterId + '/connect/decline', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'decline_failed_' + res.status ); }
				ctx.hidden = true;
				bnToast( 'Request from @' + ( ctx.targetName || ctx.requesterId ) + ' declined', { tone: 'info' } );
			} catch ( _e ) {
				bnToast( 'Could not decline request. Try again.', { tone: 'danger' } );
			} finally {
				ctx.busy = false;
			}
		},
	},
} );

/* -- Connection button store ------------------------------------------ */

function connectionLabel( ctx ) {
	const s = ctx.status || '';
	if ( s === 'accepted' )         { return 'Connected'; }
	if ( s === 'pending-sent' )     { return 'Requested'; }
	if ( s === 'pending-received' ) { return 'Respond'; }
	return 'Connect';
}

function connectionState( ctx ) {
	return ctx.status || 'none';
}

store( 'buddynext/connection-button', {
	state: {
		get btnState()          { return connectionState( getContext() ); },
		get label()             { return connectionLabel( getContext() ); },
		get showConnect()       { return ( getContext().status || '' ) === ''; },
		get showPending()       { return getContext().status === 'pending-sent'; },
		get showAcceptDecline() { return getContext().status === 'pending-received'; },
		get showConnected()     { return getContext().status === 'accepted'; },
	},
	actions: {
		async sendRequest() {
			const ctx  = getContext();
			const name = ctx.targetName || ( '#' + ctx.userId );

			// LinkedIn-style "Add a note" step. The note is optional — confirming
			// with an empty textarea sends a note-less request; cancelling aborts
			// without touching state. The server caps the note at 280 chars.
			const note = await bnResolveConnectNote( {
				body: 'Add a personal message to your request to @' + name + ', or send it without one.',
			} );
			if ( note === null ) {
				return; // User cancelled — leave the Connect button untouched.
			}

			const prev = ctx.status;
			ctx.status = 'pending-sent';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					body:         { note: note },
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'connect_failed_' + res.status ); }
				bnToast( 'Connection request sent to @' + name, { tone: 'success' } );
			} catch ( _e ) {
				ctx.status = prev || '';
				bnToast( 'Could not send connection request. Try again.', { tone: 'danger' } );
			}
		},

		async withdrawRequest() {
			const ctx  = getContext();
			const name = ctx.targetName || ( '#' + ctx.userId );
			const prev = ctx.status;
			ctx.status = '';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect', {
					method:       'DELETE',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'withdraw_failed_' + res.status ); }
				bnToast( 'Request to @' + name + ' withdrawn', { tone: 'info' } );
			} catch ( _e ) {
				ctx.status = prev;
				bnToast( 'Could not withdraw request. Try again.', { tone: 'danger' } );
			}
		},

		async acceptRequest() {
			const ctx  = getContext();
			const name = ctx.targetName || ( '#' + ctx.userId );
			const prev = ctx.status;
			ctx.status = 'accepted';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect/accept', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'accept_failed_' + res.status ); }
				bnToast( 'Connected with @' + name, { tone: 'success' } );
			} catch ( _e ) {
				ctx.status = prev;
				bnToast( 'Could not accept request. Try again.', { tone: 'danger' } );
			}
		},

		async declineRequest() {
			const ctx  = getContext();
			const name = ctx.targetName || ( '#' + ctx.userId );
			const prev = ctx.status;
			ctx.status = '';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect/decline', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'decline_failed_' + res.status ); }
				bnToast( 'Request from @' + name + ' declined', { tone: 'info' } );
			} catch ( _e ) {
				ctx.status = prev;
				bnToast( 'Could not decline request. Try again.', { tone: 'danger' } );
			}
		},

		async disconnect() {
			const ctx  = getContext();
			const name = ctx.targetName || ( '#' + ctx.userId );
			const prev = ctx.status;
			ctx.status = '';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect', {
					method:       'DELETE',
					base:         ctx.restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'disconnect_failed_' + res.status ); }
				bnToast( 'Disconnected from @' + name, { tone: 'info' } );
			} catch ( _e ) {
				ctx.status = prev;
				bnToast( 'Could not disconnect. Try again.', { tone: 'danger' } );
			}
		},
	},
} );
