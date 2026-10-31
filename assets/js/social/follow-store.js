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
import { bnToast, bnResolveConnectNote, bnConfirmDisconnect } from '@buddynext/shell-dialog';
import { restFetch, toastRestError } from '@buddynext/rest-client';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::inject_interactivity_i18n) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * follow-button namespace below and shared by all four social stores; each
 * lookup keeps the English literal as a fallback so the UI never breaks if the
 * state is absent. fmt() fills sprintf-style '%s' placeholders. */
let I18N = {};
function t( key, fallback ) {
	return ( I18N && I18N[ key ] ) || fallback;
}
function fmt( tpl, value ) {
	return String( null == tpl ? '' : tpl ).replace( '%s', String( value ) );
}

/* -- Helpers ----------------------------------------------------------- */

/*
 * The viewer's REST nonce and base are the same on every follow / connection
 * button, so AssetService publishes them once as buddynext/follow-button state
 * instead of in each button's context (a feed byline or member grid repeated
 * them per button). The ctx argument is kept so callers stay unchanged.
 */
function shared() {
	return store( 'buddynext/follow-button' ).state;
}

function ctxNonce() {
	return shared().restNonce || '';
}

function followLabel( ctx ) {
	if ( ctx.isPending )   { return t( 'requested', 'Requested' ); }
	if ( ctx.isFollowing ) { return t( 'following', 'Following' ); }
	return t( 'follow', 'Follow' );
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
	if ( ctx.isPending )   { return t( 'ariaCancelRequest', 'Cancel follow request' ); }
	if ( ctx.isFollowing ) { return t( 'ariaUnfollow', 'Unfollow this user' ); }
	return t( 'ariaFollow', 'Follow this user' );
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
	if ( ctx.isPending )   { return 'secondary'; }
	if ( ctx.isFollowing ) { return 'secondary'; }
	return 'primary';
}

/* -- Follow button store ----------------------------------------------- */

const followButtonStore = store( 'buddynext/follow-button', {
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
			// Ignore re-entrant clicks while a request is in flight (the button binds
			// context.busy to aria-busy + disabled). Prevents a rapid double-click
			// from firing two toggles that race.
			if ( ctx.busy ) { return; }
			const wasFollowing = !! ctx.isFollowing;
			const wasPending   = !! ctx.isPending;
			const userId       = ctx.userId;
			const target       = ctx.targetName || ( '#' + userId );
			const usePending   = !! ctx.privateFollow && ! wasFollowing && ! wasPending;

			ctx.busy = true;

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
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				// The server has the last word: the account may have gone private (or
				// public) since this page was drawn.
				const requested = ! wasFollowing && ! wasPending && !! ( res.data && res.data.pending );
				if ( ! wasFollowing && ! wasPending ) {
					ctx.isPending   = requested;
					ctx.isFollowing = ! requested;
				}
				let msg;
				if ( wasFollowing )      { msg = fmt( t( 'toastUnfollowed', 'Unfollowed @%s' ), target ); }
				else if ( wasPending )   { msg = fmt( t( 'toastRequestCancelled', 'Follow request to @%s cancelled' ), target ); }
				else if ( requested )    { msg = fmt( t( 'toastRequestSent', 'Follow request sent to @%s' ), target ); }
				else                     { msg = fmt( t( 'toastNowFollowing', 'Now following @%s' ), target ); }
				bnToast( msg, { tone: wasFollowing || wasPending ? 'info' : 'success' } );
			} catch ( e ) {
				// Roll back.
				ctx.isFollowing = wasFollowing;
				ctx.isPending   = wasPending;
				toastRestError( e, wasFollowing
						? fmt( t( 'toastCouldNotUnfollow', 'Could not unfollow @%s. Try again.' ), target )
						: fmt( t( 'toastCouldNotFollow', 'Could not follow @%s. Try again.' ), target ) );
			} finally {
				ctx.busy = false;
			}
		},
	},
} );

// The server merges the injected dictionary into this namespace's state; read
// it once here so all four social stores below share one translated table.
I18N = ( followButtonStore && followButtonStore.state && followButtonStore.state.i18n ) || {};

/* -- Request inbox shared helpers -------------------------------------- */
/* The inbox section (templates/parts/profile/people-panel.php) holds the
 * pending count in its own context; rows inherit it, so this write lands on
 * the section and the badge + sectionHidden follow. */
function settleRequest( ctx ) {
	ctx.hidden  = true;
	ctx.pending = Math.max( 0, ( ctx.pending || 1 ) - 1 );
}

/* Bump a server-rendered profile metric (parts/nav-metrics.php) after an
 * accept/approve. The label for count + 1 is pre-translated by PHP.
 * shortcut: later bumps keep that label, fine for languages whose plural form
 * is stable above 1; emit a label table if a locale needs more. */
function bumpMetric( id ) {
	const el      = document.querySelector( '.bn-nav-metric[data-bn-metric="' + id + '"]' );
	const valueEl = el && el.querySelector( '.bn-nav-metric__value' );
	if ( ! valueEl ) { return; }
	const next    = ( parseInt( valueEl.textContent, 10 ) || 0 ) + 1;
	const labelEl = el.querySelector( '.bn-nav-metric__label' );
	valueEl.textContent = String( next );
	if ( labelEl && el.dataset.bnNextLabel ) { labelEl.textContent = el.dataset.bnNextLabel; }
	if ( labelEl && el.hasAttribute( 'aria-label' ) ) { el.setAttribute( 'aria-label', next + ' ' + labelEl.textContent ); }

	// The Network sub-tab the request was accepted on (Connections or Followers)
	// carries the same count.
	const tab   = document.querySelector( '.bn-subnav__item[aria-current="page"]' );
	const count = tab && tab.querySelector( '.bn-subnav__count' );
	if ( count ) {
		const was = parseInt( count.textContent, 10 ) || 0;
		count.textContent = String( was + 1 );
		tab.setAttribute( 'aria-label', ( tab.getAttribute( 'aria-label' ) || '' ).replace( '(' + was + ')', '(' + ( was + 1 ) + ')' ) );
	}
}

/* Re-render the owner's people list (the buddynext/profile-people router region in
 * people-panel.php) after an accept/approve, so the new person appears with
 * hydrated buttons. The reading position is kept: a region swap is not a page change. */
async function refreshPeopleList() {
	if ( ! document.querySelector( '[data-wp-router-region="buddynext/profile-people"]' ) ) { return; }
	const scrollY = window.scrollY;
	try {
		const router = await import( '@wordpress/interactivity-router' );
		await router.actions.navigate( window.location.href, { force: true, replace: true } );
		window.scrollTo( 0, scrollY );
	} catch ( _e ) {
		// The request row and the header count are already updated; the list
		// catches up on the next load.
	}
}

/* -- Follow-request inbox store ---------------------------------------- */
/* Drives the per-row Approve / Reject buttons on the followers page
 * pending-requests section (templates/parts/profile/people-panel.php). Each row
 * is its own Interactivity context with a followerId — the store hides
 * the row after a successful action and emits a toast either way. */

store( 'buddynext/follow-requests', {
	state: {
		get rowHidden() { return !! getContext().hidden; },
		get sectionHidden() { return ( getContext().pending || 0 ) <= 0; },
	},
	actions: {
		async approve( event ) {
			const ctx = getContext();
			if ( ctx.busy || ctx.hidden ) { return; }
			ctx.busy = true;
			try {
				const res = await restFetch( '/me/follow-requests/' + ctx.followerId + '/approve', {
					method:       'POST',
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				settleRequest( ctx );
				bumpMetric( 'followers' );
				bnToast( fmt( t( 'toastCanFollowYou', '@%s can now follow you' ), ( ctx.targetName || ctx.followerId ) ), { tone: 'success' } );
				refreshPeopleList();
			} catch ( e ) {
				if ( 404 === e.status ) { settleRequest( ctx ); }
				toastRestError( e, t( 'toastApproveFailed', 'Could not approve request. Try again.' ) );
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
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				settleRequest( ctx );
				bnToast( fmt( t( 'toastRequestDeclined', 'Request from @%s declined' ), ( ctx.targetName || ctx.followerId ) ), { tone: 'info' } );
			} catch ( e ) {
				if ( 404 === e.status ) { settleRequest( ctx ); }
				toastRestError( e, t( 'toastDeclineFailed', 'Could not decline request. Try again.' ) );
			} finally {
				ctx.busy = false;
			}
		},
	},
} );

/* -- Connection-request inbox store ------------------------------------ */
/* Drives the per-row Accept / Decline buttons on the Connections tab
 * pending connection-requests section (templates/parts/profile/people-panel.php).
 * Each row is its own context with a requesterId — the store hides the row
 * after a successful action and emits a toast either way. Mirrors the
 * follow-requests inbox, hitting the connection accept/decline endpoints. */

store( 'buddynext/connection-requests', {
	state: {
		get rowHidden() { return !! getContext().hidden; },
		get sectionHidden() { return ( getContext().pending || 0 ) <= 0; },
	},
	actions: {
		async accept() {
			const ctx = getContext();
			if ( ctx.busy || ctx.hidden ) { return; }
			ctx.busy = true;
			try {
				const res = await restFetch( '/users/' + ctx.requesterId + '/connect/accept', {
					method:       'POST',
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				settleRequest( ctx );
				bumpMetric( 'connections' );
				bnToast( fmt( t( 'toastConnectedWith', 'Connected with @%s' ), ( ctx.targetName || ctx.requesterId ) ), { tone: 'success' } );
				refreshPeopleList();
			} catch ( e ) {
				if ( 404 === e.status ) { settleRequest( ctx ); }
				toastRestError( e, t( 'toastCouldNotAccept', 'Could not accept request. Try again.' ) );
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
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				settleRequest( ctx );
				bnToast( fmt( t( 'toastRequestDeclined', 'Request from @%s declined' ), ( ctx.targetName || ctx.requesterId ) ), { tone: 'info' } );
			} catch ( e ) {
				if ( 404 === e.status ) { settleRequest( ctx ); }
				toastRestError( e, t( 'toastDeclineFailed', 'Could not decline request. Try again.' ) );
			} finally {
				ctx.busy = false;
			}
		},
	},
} );

/* -- Connection button store ------------------------------------------ */

function connectionLabel( ctx ) {
	const s = ctx.status || '';
	if ( s === 'accepted' )         { return t( 'connected', 'Connected' ); }
	if ( s === 'pending-sent' )     { return t( 'requested', 'Requested' ); }
	if ( s === 'pending-received' ) { return t( 'respond', 'Respond' ); }
	return t( 'connect', 'Connect' );
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
			if ( ctx.busy ) { return; }
			const name = ctx.targetName || ( '#' + ctx.userId );

			// LinkedIn-style "Add a note" step. The note is optional — confirming
			// with an empty textarea sends a note-less request; cancelling aborts
			// without touching state. The server caps the note at 280 chars.
			const note = await bnResolveConnectNote( {
				body: fmt( t( 'noteBody', 'Add a personal message to your request to @%s, or send it without one.' ), name ),
			} );
			if ( note === null ) {
				return; // User cancelled — leave the Connect button untouched.
			}

			const prev = ctx.status;
			ctx.busy   = true;
			ctx.status = 'pending-sent';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect', {
					method:       'POST',
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					body:         { note: note },
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				bnToast( fmt( t( 'toastConnectionSent', 'Connection request sent to @%s' ), name ), { tone: 'success' } );
			} catch ( e ) {
				ctx.status = prev || '';
				toastRestError( e, t( 'toastCouldNotConnect', 'Could not send connection request. Try again.' ) );
			} finally {
				ctx.busy = false;
			}
		},

		async withdrawRequest() {
			const ctx  = getContext();
			if ( ctx.busy ) { return; }
			const name = ctx.targetName || ( '#' + ctx.userId );
			const prev = ctx.status;
			ctx.busy   = true;
			ctx.status = '';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect', {
					method:       'DELETE',
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				bnToast( fmt( t( 'toastRequestWithdrawn', 'Request to @%s withdrawn' ), name ), { tone: 'info' } );
			} catch ( e ) {
				ctx.status = 404 === e.status ? '' : prev;
				toastRestError( e, t( 'toastCouldNotWithdraw', 'Could not withdraw request. Try again.' ) );
			} finally {
				ctx.busy = false;
			}
		},

		async acceptRequest() {
			const ctx  = getContext();
			if ( ctx.busy ) { return; }
			const name = ctx.targetName || ( '#' + ctx.userId );
			const prev = ctx.status;
			ctx.busy   = true;
			ctx.status = 'accepted';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect/accept', {
					method:       'POST',
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				bnToast( fmt( t( 'toastConnectedWith', 'Connected with @%s' ), name ), { tone: 'success' } );
			} catch ( e ) {
				ctx.status = prev;
				toastRestError( e, t( 'toastCouldNotAccept', 'Could not accept request. Try again.' ) );
			} finally {
				ctx.busy = false;
			}
		},

		async declineRequest() {
			const ctx  = getContext();
			if ( ctx.busy ) { return; }
			const name = ctx.targetName || ( '#' + ctx.userId );
			const prev = ctx.status;
			ctx.busy   = true;
			ctx.status = '';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect/decline', {
					method:       'POST',
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				bnToast( fmt( t( 'toastRequestDeclined', 'Request from @%s declined' ), name ), { tone: 'info' } );
			} catch ( e ) {
				ctx.status = prev;
				toastRestError( e, t( 'toastCouldNotDecline', 'Could not decline request. Try again.' ) );
			} finally {
				ctx.busy = false;
			}
		},

		async disconnect() {
			const ctx  = getContext();
			if ( ctx.busy ) { return; }
			const name = ctx.targetName || ( '#' + ctx.userId );
			if ( ! await bnConfirmDisconnect( ctx.targetName ? '@' + ctx.targetName : '' ) ) { return; }
			const prev = ctx.status;
			ctx.busy   = true;
			ctx.status = '';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect', {
					method:       'DELETE',
					base:         shared().restUrl || undefined,
					nonce:        ctxNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				bnToast( fmt( t( 'toastDisconnected', 'Disconnected from @%s' ), name ), { tone: 'info' } );
			} catch ( e ) {
				ctx.status = prev;
				toastRestError( e, t( 'toastCouldNotDisconnect', 'Could not disconnect. Try again.' ) );
			} finally {
				ctx.busy = false;
			}
		},
	},
} );
