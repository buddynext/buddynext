/* BuddyNext — Onboarding Interactivity API store (5-step wizard).
 *
 * Provides reactive state for the wizard, action handlers for every
 * affordance in templates/onboarding/index.php, and REST integration
 * for joining spaces, following users, saving interests, and completing
 * the wizard. Step positions are server-decided (the Interests step is
 * dropped when the owner has no categories), so `context.totalSteps` is
 * 4 or 5 and every getter works off the context, never a hardcoded map.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_onboarding) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/onboarding namespace below; each lookup keeps the English literal
 * as a fallback so the UI never breaks if the state is absent. fmt() fills
 * sprintf-style '%s'/'%d' placeholders. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( vals[ pos ? pos - 1 : i++ ] ?? '' ) ); }

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

const onboardingStore = store( 'buddynext/onboarding', {
	state: {
		get currentStep() {
			return ctx().step || 1;
		},
		get progressPercent() {
			const c = ctx();
			const total = c.totalSteps || 5;
			const step  = c.step || 1;
			return Math.round( ( step / total ) * 100 );
		},
		get progressWidth() {
			return ( this.progressPercent || 0 ) + '%';
		},
		get stepLabel() {
			const c = ctx();
			const total = c.totalSteps || 5;
			const step  = c.step || 1;
			return fmt( t( 'stepLabel', 'Step %1$s of %2$s' ), step, total );
		},
		get isStep1() { return ( ctx().step || 1 ) === 1; },
		get isStep2() { return ( ctx().step || 1 ) === 2; },
		get isStep3() { return ( ctx().step || 1 ) === 3; },
		get isStep4() { return ( ctx().step || 1 ) === 4; },
		get isStep5() { return ( ctx().step || 1 ) === 5; },
		get isStepActive1() { return ( ctx().step || 1 ) === 1; },
		get isStepActive2() { return ( ctx().step || 1 ) === 2; },
		get isStepActive3() { return ( ctx().step || 1 ) === 3; },
		get isStepActive4() { return ( ctx().step || 1 ) === 4; },
		get isStepActive5() { return ( ctx().step || 1 ) === 5; },
		get isStepDone1() { return ( ctx().step || 1 ) > 1; },
		get isStepDone2() { return ( ctx().step || 1 ) > 2; },
		get isStepDone3() { return ( ctx().step || 1 ) > 3; },
		get isStepDone4() { return ( ctx().step || 1 ) > 4; },
		get isStepDone5() { return false; },
		// Soft interests hint — visible until the member has picked at least
		// three topics (never blocking; Continue works with any count).
		get interestsHintVisible() {
			const picked = ctx().interestIds;
			return ( Array.isArray( picked ) ? picked.length : 0 ) < 3;
		},
		get displayNameError() {
			const c = ctx();
			if ( ! c.displayNameDirty ) { return ''; }
			const dn = String( c.displayName || '' ).trim();
			return dn.length < 2 ? t( 'displayNameError', 'Display name must be at least 2 characters.' ) : '';
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
			return String( ctx().displayName || '' ).trim() || t( 'previewName', 'Your name' );
		},
		get previewHandle() {
			const u = String( ctx().userLogin || '' ).trim();
			return u ? '@' + u : t( 'previewHandle', '@username' );
		},
		get previewBio() {
			return String( ctx().bio || '' ).trim() || t( 'previewBio', "Add a short bio so people know what you're into." );
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
			const total = c.totalSteps || 5;
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
			const total = c.totalSteps || 5;
			if ( ( c.step || 1 ) < total ) {
				c.step = ( c.step || 1 ) + 1;
				return;
			}
			// Last-step skip — finalize skip.
			rest( c, 'me/onboarding/skip', { method: 'POST' } )
				.then( () => {
					toast( t( 'toastCompleteLater', 'You can complete onboarding any time from settings.' ), 'info' );
					window.location.href = c.redirectUrl || '/activity/';
				} )
				.catch( () => {
					window.location.href = c.redirectUrl || '/activity/';
				} );
		},
		toggleInterest( event ) {
			const c = ctx();
			const btn = event && event.target ? event.target.closest( '[data-cat-id]' ) : null;
			if ( ! btn ) { return; }
			const catId = parseInt( btn.getAttribute( 'data-cat-id' ), 10 );
			if ( ! catId ) { return; }
			const picked = Array.isArray( c.interestIds ) ? c.interestIds.slice() : [];
			const idx = picked.indexOf( catId );
			if ( idx === -1 ) {
				picked.push( catId );
				btn.setAttribute( 'aria-pressed', 'true' );
				btn.classList.add( 'is-selected' );
			} else {
				picked.splice( idx, 1 );
				btn.setAttribute( 'aria-pressed', 'false' );
				btn.classList.remove( 'is-selected' );
			}
			c.interestIds = picked;
		},
		continueInterests() {
			// Persist the picks BEFORE advancing (cold-start contract: the
			// picks are stored by the time the Spaces / People steps show),
			// then walk forward. Zero picks is a valid save (clears any
			// stale picks from a redo run).
			const c = ctx();
			if ( c.saving ) { return; }
			c.saving = true;
			c.error  = '';
			rest( c, 'me/interests', {
				method: 'POST',
				body:   { interests: Array.isArray( c.interestIds ) ? c.interestIds : [] },
			} )
				.then( ( r ) => {
					if ( ! r.ok ) { throw new Error( 'Failed' ); }
					c.saving = false;
					// Advance on the captured context (getContext() is not
					// available inside promise callbacks) and persist the
					// step pointer best-effort, mirroring nextStep().
					const total = c.totalSteps || 5;
					if ( ( c.step || 1 ) < total ) {
						c.step = ( c.step || 1 ) + 1;
						rest( c, 'me/onboarding/step', {
							method: 'POST',
							body:   { step: c.step - 1, data: {} },
						} ).catch( () => {} );
					}
				} )
				.catch( () => {
					c.saving = false;
					c.error  = t( 'toastInterestsSaveFailed', 'Could not save your interests. Please try again.' );
					toast( t( 'toastInterestsSaveFailed', 'Could not save your interests. Please try again.' ), 'danger' );
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
			c.usernameStatusLabel = t( 'usernameChecking', 'Checking…' );

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
						c.usernameStatusLabel = ok ? t( 'usernameAvailable', 'Available' ) : t( 'usernameTaken', 'Taken' );
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
				btn.textContent = t( 'btnJoined', 'Joined' );
				btn.setAttribute( 'data-variant', 'secondary' );
				btn.setAttribute( 'aria-pressed', 'true' );
			} else {
				joined.splice( idx, 1 );
				btn.textContent = t( 'btnJoin', 'Join' );
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
					toast( isJoining ? t( 'toastJoinedSpace', 'Joined the space.' ) : t( 'toastLeftSpace', 'Left the space.' ), 'success' );
				} )
				.catch( () => {
					// Rollback.
					const rollback = Array.isArray( c.joinedSpaces ) ? c.joinedSpaces.slice() : [];
					const ridx = rollback.indexOf( spaceId );
					if ( isJoining && ridx !== -1 ) {
						rollback.splice( ridx, 1 );
						btn.textContent = t( 'btnJoin', 'Join' );
						btn.setAttribute( 'data-variant', 'primary' );
						btn.setAttribute( 'aria-pressed', 'false' );
					} else if ( ! isJoining ) {
						rollback.push( spaceId );
						btn.textContent = t( 'btnJoined', 'Joined' );
						btn.setAttribute( 'data-variant', 'secondary' );
						btn.setAttribute( 'aria-pressed', 'true' );
					}
					c.joinedSpaces = rollback;
					toast( t( 'toastSpaceUpdateFailed', 'Could not update space. Please try again.' ), 'danger' );
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
				btn.textContent = t( 'btnFollowing', 'Following' );
				btn.setAttribute( 'data-variant', 'secondary' );
				btn.setAttribute( 'aria-pressed', 'true' );
				btn.classList.add( 'is-following' );
			} else {
				list.splice( idx, 1 );
				btn.textContent = t( 'btnFollow', 'Follow' );
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
					toast( isFollowing ? t( 'toastFollowing', 'Following.' ) : t( 'toastUnfollowed', 'Unfollowed.' ), 'success' );
				} )
				.catch( () => {
					const rollback = Array.isArray( c.followingUsers ) ? c.followingUsers.slice() : [];
					const ridx = rollback.indexOf( userId );
					if ( isFollowing && ridx !== -1 ) {
						rollback.splice( ridx, 1 );
						btn.textContent = t( 'btnFollow', 'Follow' );
						btn.setAttribute( 'data-variant', 'primary' );
						btn.setAttribute( 'aria-pressed', 'false' );
						btn.classList.remove( 'is-following' );
					} else if ( ! isFollowing ) {
						rollback.push( userId );
						btn.textContent = t( 'btnFollowing', 'Following' );
						btn.setAttribute( 'data-variant', 'secondary' );
						btn.setAttribute( 'aria-pressed', 'true' );
						btn.classList.add( 'is-following' );
					}
					c.followingUsers = rollback;
					toast( t( 'toastFollowUpdateFailed', 'Could not update follow. Please try again.' ), 'danger' );
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
			// Size cap matches the server (ProfileController: 4MB).
			if ( file.size > 4 * 1024 * 1024 ) {
				toast( t( 'toastImageTooLarge', 'Image too large. Max 4MB.' ), 'danger' );
				return;
			}

			// Send the file once dimensions are known — runs only after the pixel
			// pre-check below passes.
			const doUpload = () => {
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
						if ( ! r.ok ) {
							// Surface the server's specific reason (avatar_too_large /
							// avatar_dimensions / avatar_invalid_type) instead of a
							// generic failure, so the user knows what to change.
							const msg = r.data && r.data.message ? r.data.message : t( 'toastPhotoUploadFailed', 'Could not upload photo. Please try again.' );
							throw new Error( msg );
						}
						return r.data;
					} )
					.then( ( data ) => {
						if ( data && data.avatar_url ) {
							const img = document.querySelector( '.bn-ob-avatar img' );
							if ( img ) { img.setAttribute( 'src', data.avatar_url ); }
							// Drive the live preview card avatar reactively (state.previewAvatar).
							c.avatarUrl = data.avatar_url;
						}
						toast( t( 'toastPhotoUpdated', 'Profile photo updated.' ), 'success' );
					} )
					.catch( ( err ) => {
						toast( err && err.message ? err.message : t( 'toastPhotoUploadFailed', 'Could not upload photo. Please try again.' ), 'danger' );
					} );
			};

			// Pre-check pixel dimensions against the server cap (1024×1024) so the
			// user gets an immediate, specific message rather than a 422 after a
			// wasted upload.
			const objectUrl = URL.createObjectURL( file );
			const probe = new Image();
			probe.onload = () => {
				const tooBig = probe.naturalWidth > 1024 || probe.naturalHeight > 1024;
				URL.revokeObjectURL( objectUrl );
				if ( tooBig ) {
					toast( t( 'toastImageDimensions', 'Image must be at most 1024×1024 pixels. Please choose a smaller photo.' ), 'danger' );
					return;
				}
				doUpload();
			};
			probe.onerror = () => {
				URL.revokeObjectURL( objectUrl );
				// Dimensions unreadable client-side; let the server decide.
				doUpload();
			};
			probe.src = objectUrl;
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
					toast( t( 'toastAllSet', 'You are all set. Welcome aboard!' ), 'success' );
					window.location.href = ( data && data.redirect_to ) || c.redirectUrl || '/activity/';
				} )
				.catch( () => {
					c.saving = false;
					c.error  = t( 'errorGeneric', 'Something went wrong. Please try again.' );
					toast( t( 'toastFinishFailed', 'Could not finish onboarding. Please try again.' ), 'danger' );
				} );
		},
	},
} );

I18N = ( onboardingStore.state && onboardingStore.state.i18n ) || {};
