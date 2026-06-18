/* BuddyNext — Onboarding Interactivity API store (4-step wizard).
 *
 * Provides reactive state for the wizard, action handlers for every
 * affordance in templates/onboarding/index.php, and REST integration
 * for joining spaces, following users, saving interests, and completing
 * the wizard.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';

// Holds the pending username-availability check timer so a fresh
// keystroke can cancel the in-flight check before it fires.
let usernameCheckTimer = null;

function ctx() {
	try {
		return getContext();
	} catch ( _e ) {
		return {};
	}
}

function toast( message, tone ) {
	if ( typeof window.bnToast === 'function' ) {
		window.bnToast( message, tone || 'info' );
		return;
	}
	if ( typeof window.buddynext_toast === 'function' ) {
		window.buddynext_toast( message, tone || 'info' );
	}
}

function rest( c, path, opts ) {
	opts = opts || {};
	const init = {
		base: c.restUrl || '/wp-json/buddynext/v1/',
		nonce: c.restNonce || '',
		method: opts.method,
		toastOnError: false,
	};
	if ( typeof opts.body !== 'undefined' ) {
		init.body = opts.body;
	}
	return restFetch( '/' + String( path ).replace( /^\//, '' ), init );
}

store( 'buddynext/onboarding', {
	state: {
		get currentStep() {
			return ctx().step || 1;
		},
		get progressPercent() {
			const c = ctx();
			const total = c.totalSteps || 4;
			const step  = c.step || 1;
			return Math.round( ( step / total ) * 100 );
		},
		get progressWidth() {
			return ( this.progressPercent || 0 ) + '%';
		},
		get stepLabel() {
			const c = ctx();
			const total = c.totalSteps || 4;
			const step  = c.step || 1;
			return 'Step ' + step + ' of ' + total;
		},
		get isStep1() { return ( ctx().step || 1 ) === 1; },
		get isStep2() { return ( ctx().step || 1 ) === 2; },
		get isStep3() { return ( ctx().step || 1 ) === 3; },
		get isStep4() { return ( ctx().step || 1 ) === 4; },
		get isStepActive1() { return ( ctx().step || 1 ) === 1; },
		get isStepActive2() { return ( ctx().step || 1 ) === 2; },
		get isStepActive3() { return ( ctx().step || 1 ) === 3; },
		get isStepActive4() { return ( ctx().step || 1 ) === 4; },
		get isStepDone1() { return ( ctx().step || 1 ) > 1; },
		get isStepDone2() { return ( ctx().step || 1 ) > 2; },
		get isStepDone3() { return ( ctx().step || 1 ) > 3; },
		get isStepDone4() { return false; },
		get displayNameError() {
			const c = ctx();
			if ( ! c.displayNameDirty ) { return ''; }
			const dn = String( c.displayName || '' ).trim();
			return dn.length < 2 ? 'Display name must be at least 2 characters.' : '';
		},
		get continueDisabledStep1() {
			const c = ctx();
			const dn = String( c.displayName || '' ).trim();
			return dn.length < 2;
		},
		get saving() { return !! ctx().saving; },
		get error() { return ctx().error || ''; },
		// Live profile-preview helpers consumed by the onboarding canvas
		// (right column). They reflect whatever the user has typed on the
		// left so the preview card updates as they fill the form.
		get previewName() {
			return String( ctx().displayName || '' ).trim() || 'Your name';
		},
		get previewHandle() {
			const u = String( ctx().userLogin || '' ).trim();
			return u ? '@' + u : '@username';
		},
		get previewBio() {
			return String( ctx().bio || '' ).trim() || "Add a short bio so people know what you're into.";
		},
		get previewInitial() {
			const dn = String( ctx().displayName || '' ).trim();
			return dn ? dn.charAt( 0 ).toUpperCase() : '?';
		},
		get previewAvatar() {
			return String( ctx().avatarUrl || '' );
		},
	},
	actions: {
		nextStep() {
			const c = ctx();
			const total = c.totalSteps || 4;
			if ( ( c.step || 1 ) < total ) {
				c.step = ( c.step || 1 ) + 1;
				// Persist step server-side (best-effort).
				rest( c, 'me/onboarding/step', {
					method: 'POST',
					body:   { step: c.step - 1, data: {
						display_name: c.displayName || '',
						description:  c.bio || '',
					} },
				} ).catch( () => {} );
			}
		},
		prevStep() {
			const c = ctx();
			if ( ( c.step || 1 ) > 1 ) {
				c.step = c.step - 1;
			}
		},
		skipStep() {
			const c = ctx();
			const total = c.totalSteps || 4;
			if ( ( c.step || 1 ) < total ) {
				c.step = ( c.step || 1 ) + 1;
				return;
			}
			// Last-step skip — finalize skip.
			rest( c, 'me/onboarding/skip', { method: 'POST' } )
				.then( () => {
					toast( 'You can complete onboarding any time from settings.', 'info' );
					window.location.href = c.redirectUrl || '/activity/';
				} )
				.catch( () => {
					window.location.href = c.redirectUrl || '/activity/';
				} );
		},
		setDisplayName( event ) {
			const c = ctx();
			c.displayName = event && event.target ? String( event.target.value || '' ) : '';
			c.displayNameDirty = true;
		},
		setBio( event ) {
			const c = ctx();
			c.bio = event && event.target ? String( event.target.value || '' ) : '';
		},
		checkUsername( event ) {
			const c = ctx();
			c.userLogin = event && event.target ? String( event.target.value || '' ) : '';
			const captured = c.userLogin;

			// Too short — clear feedback, no network call.
			if ( captured.length < 3 ) {
				c.usernameChecking = false;
				c.usernameStatusLabel = '';
				return;
			}

			// Show "Checking…" immediately while we wait for the debounce
			// window to close and the REST call to return.
			c.usernameChecking = true;
			c.usernameStatusLabel = 'Checking…';

			if ( usernameCheckTimer ) { clearTimeout( usernameCheckTimer ); }
			usernameCheckTimer = setTimeout( () => {
				rest( c, 'profile-slug/check?slug=' + encodeURIComponent( captured ), {
					method: 'GET',
				} )
					.then( ( r ) => r.data )
					.then( ( data ) => {
						// Discard stale responses if the user kept typing.
						if ( c.userLogin !== captured ) { return; }
						const ok = !! ( data && data.available );
						c.usernameAvailable  = ok;
						c.usernameChecking   = false;
						c.usernameStatusLabel = ok ? 'Available' : 'Taken';
					} )
					.catch( () => {
						c.usernameChecking    = false;
						c.usernameStatusLabel = '';
					} );
			}, 350 );
		},
		toggleChannel( event ) {
			const c = ctx();
			const input = event && event.target ? event.target : null;
			if ( ! input ) { return; }
			const channel = input.getAttribute( 'data-channel' );
			const value   = !! input.checked;
			if ( 'email'  === channel ) { c.channelEmail = value; }
			if ( 'in_app' === channel ) { c.channelInApp = value; }
			if ( 'push'   === channel ) { c.channelPush  = value; }
			if ( 'sound'  === channel ) { c.channelSound = value; }
		},
		joinSuggestedSpace( event ) {
			const c = ctx();
			const btn = event && event.target ? event.target.closest( '[data-space-id]' ) : null;
			if ( ! btn ) { return; }
			const spaceId = parseInt( btn.getAttribute( 'data-space-id' ), 10 );
			if ( ! spaceId ) { return; }
			const joined  = Array.isArray( c.joinedSpaces ) ? c.joinedSpaces.slice() : [];
			const idx     = joined.indexOf( spaceId );
			const isJoining = idx === -1;
			// Optimistic UI.
			if ( isJoining ) {
				joined.push( spaceId );
				btn.textContent = 'Joined';
				btn.setAttribute( 'data-variant', 'secondary' );
				btn.setAttribute( 'aria-pressed', 'true' );
			} else {
				joined.splice( idx, 1 );
				btn.textContent = 'Join';
				btn.setAttribute( 'data-variant', 'primary' );
				btn.setAttribute( 'aria-pressed', 'false' );
			}
			c.joinedSpaces = joined;
			// Membership lives on /spaces/{id}/join: POST joins, DELETE leaves
			// (both wired on that route in SpaceController). The /members route
			// is GET-only and cannot accept the join/leave write.
			rest( c, 'spaces/' + spaceId + '/join', {
				method: isJoining ? 'POST' : 'DELETE',
			} )
				.then( ( r ) => {
					if ( ! r.ok ) { throw new Error( 'Failed' ); }
					toast( isJoining ? 'Joined the space.' : 'Left the space.', 'success' );
				} )
				.catch( () => {
					// Rollback.
					const rollback = Array.isArray( c.joinedSpaces ) ? c.joinedSpaces.slice() : [];
					const ridx = rollback.indexOf( spaceId );
					if ( isJoining && ridx !== -1 ) {
						rollback.splice( ridx, 1 );
						btn.textContent = 'Join';
						btn.setAttribute( 'data-variant', 'primary' );
						btn.setAttribute( 'aria-pressed', 'false' );
					} else if ( ! isJoining ) {
						rollback.push( spaceId );
						btn.textContent = 'Joined';
						btn.setAttribute( 'data-variant', 'secondary' );
						btn.setAttribute( 'aria-pressed', 'true' );
					}
					c.joinedSpaces = rollback;
					toast( 'Could not update space. Please try again.', 'danger' );
				} );
		},
		followSuggestedUser( event ) {
			const c = ctx();
			const btn = event && event.target ? event.target.closest( '[data-user-id]' ) : null;
			if ( ! btn ) { return; }
			const userId = parseInt( btn.getAttribute( 'data-user-id' ), 10 );
			if ( ! userId ) { return; }
			const list = Array.isArray( c.followingUsers ) ? c.followingUsers.slice() : [];
			const idx = list.indexOf( userId );
			const isFollowing = idx === -1;
			if ( isFollowing ) {
				list.push( userId );
				btn.textContent = 'Following';
				btn.setAttribute( 'data-variant', 'secondary' );
				btn.setAttribute( 'aria-pressed', 'true' );
				btn.classList.add( 'is-following' );
			} else {
				list.splice( idx, 1 );
				btn.textContent = 'Follow';
				btn.setAttribute( 'data-variant', 'primary' );
				btn.setAttribute( 'aria-pressed', 'false' );
				btn.classList.remove( 'is-following' );
			}
			c.followingUsers = list;
			rest( c, 'users/' + userId + '/follow', {
				method: isFollowing ? 'POST' : 'DELETE',
			} )
				.then( ( r ) => {
					if ( ! r.ok ) { throw new Error( 'Failed' ); }
					toast( isFollowing ? 'Following.' : 'Unfollowed.', 'success' );
				} )
				.catch( () => {
					const rollback = Array.isArray( c.followingUsers ) ? c.followingUsers.slice() : [];
					const ridx = rollback.indexOf( userId );
					if ( isFollowing && ridx !== -1 ) {
						rollback.splice( ridx, 1 );
						btn.textContent = 'Follow';
						btn.setAttribute( 'data-variant', 'primary' );
						btn.setAttribute( 'aria-pressed', 'false' );
						btn.classList.remove( 'is-following' );
					} else if ( ! isFollowing ) {
						rollback.push( userId );
						btn.textContent = 'Following';
						btn.setAttribute( 'data-variant', 'secondary' );
						btn.setAttribute( 'aria-pressed', 'true' );
						btn.classList.add( 'is-following' );
					}
					c.followingUsers = rollback;
					toast( 'Could not update follow. Please try again.', 'danger' );
				} );
		},
		triggerAvatarUpload() {
			const input = document.querySelector( '.bn-ob-avatar-input' );
			if ( input ) { input.click(); }
		},
		handleAvatarUpload( event ) {
			const c = ctx();
			const file = event && event.target && event.target.files ? event.target.files[ 0 ] : null;
			if ( ! file ) { return; }
			if ( file.size > 4 * 1024 * 1024 ) {
				toast( 'Image too large. Max 4MB.', 'danger' );
				return;
			}
			const form = new FormData();
			form.append( 'avatar', file );
			restFetch( '/me/avatar', {
				base:  c.restUrl || '/wp-json/buddynext/v1/',
				nonce: c.restNonce || '',
				method: 'POST',
				body:  form,
				toastOnError: false,
			} )
				.then( ( r ) => {
					if ( ! r.ok ) { throw new Error( 'Upload failed' ); }
					return r.data;
				} )
				.then( ( data ) => {
					if ( data && data.avatar_url ) {
						const img = document.querySelector( '.bn-ob-avatar img' );
						if ( img ) { img.setAttribute( 'src', data.avatar_url ); }
						// Drive the live preview card avatar reactively (state.previewAvatar).
						c.avatarUrl = data.avatar_url;
					}
					toast( 'Profile photo updated.', 'success' );
				} )
				.catch( () => {
					toast( 'Could not upload photo. Please try again.', 'danger' );
				} );
		},
		finish() {
			const c = ctx();
			if ( c.saving ) { return; }
			c.saving = true;
			c.error  = '';
			// Persist step 1 fields first (display_name + bio) via service.
			rest( c, 'me/profile', {
				method: 'PUT',
				body:   {
					display_name: c.displayName || '',
					bio:          c.bio || '',
				},
			} ).catch( () => {} );

			// Persist the chosen handle if the user changed it. Server-
			// side check_slug_availability rejects collisions; we fire and
			// forget — the live badge already showed any conflict before
			// the user could reach the Continue button.
			const slug = String( c.userLogin || '' ).trim();
			if ( slug.length >= 3 ) {
				rest( c, 'me/profile-slug', {
					method: 'PUT',
					body:   { slug },
				} ).catch( () => {} );
			}

			// Persist channel preferences (email / in-app / push). The
			// per-event toggles inside each channel stay at their server
			// defaults — the user can refine those from /notifications/
			// preferences/.
			rest( c, 'me/notification-channels', {
				method: 'PUT',
				body:   {
					email:  !! c.channelEmail,
					in_app: !! c.channelInApp,
					push:   !! c.channelPush,
					sound:  !! c.channelSound,
				},
			} ).catch( () => {} );

			rest( c, 'me/onboarding/complete', {
				method: 'POST',
				body:   {
					spaces:   c.joinedSpaces || [],
					user_ids: c.followingUsers || [],
				},
			} )
				.then( ( r ) => {
					if ( ! r.ok ) { throw new Error( 'Failed' ); }
					return r.data;
				} )
				.then( ( data ) => {
					c.saving = false;
					toast( 'You are all set. Welcome aboard!', 'success' );
					window.location.href = ( data && data.redirect_to ) || c.redirectUrl || '/activity/';
				} )
				.catch( () => {
					c.saving = false;
					c.error  = 'Something went wrong. Please try again.';
					toast( 'Could not finish onboarding. Please try again.', 'danger' );
				} );
		},
	},
} );
