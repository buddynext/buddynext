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
import { bnToast } from '../shell/dialog.js';

/* -- Helpers ----------------------------------------------------------- */

function apiUrl( ctx, path ) {
	if ( ctx && ctx.restUrl ) {
		return ctx.restUrl.replace( /\/$/, '' ) + path;
	}
	return ( ( window.wpApiSettings && window.wpApiSettings.root ) || '/wp-json/' ) + 'buddynext/v1' + path;
}

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

function followClass( ctx ) {
	if ( ctx.isPending ) {
		return 'bn-btn bn-btn--sm bn-btn--ghost bn-follow-btn';
	}
	if ( ctx.isFollowing ) {
		return 'bn-btn bn-btn--sm bn-btn--secondary bn-following bn-follow-btn';
	}
	return 'bn-btn bn-btn--sm bn-btn--primary bn-follow-btn';
}

/* -- Follow button store ----------------------------------------------- */

store( 'buddynext/follow-button', {
	state: {
		get label()        { return followLabel( getContext() ); },
		get btnState()     { return followState( getContext() ); },
		get ariaPressed()  { return followAriaPressed( getContext() ); },
		get ariaLabel()    { return followAriaLabel( getContext() ); },
		get buttonClass()  { return followClass( getContext() ); },
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
				const res = await fetch( apiUrl( ctx, '/users/' + userId + '/follow' ), {
					method:  ( wasFollowing || wasPending ) ? 'DELETE' : 'POST',
					headers: { 'X-WP-Nonce': ctxNonce( ctx ) },
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
			const prev = ctx.status;
			ctx.status = 'pending-sent';
			try {
				const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctxNonce( ctx ) },
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
				const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect' ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctxNonce( ctx ) },
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
				const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect/accept' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctxNonce( ctx ) },
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
				const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect/decline' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctxNonce( ctx ) },
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
				const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect' ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctxNonce( ctx ) },
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
