/* BuddyNext — Onboarding Interactivity API store (4-step wizard). */
import { store, getContext } from '@wordpress/interactivity';

store( 'buddynext/onboarding', {
	state: {
		get currentStep() {
			try { return getContext().step || 1; } catch ( _e ) { return 1; }
		},
		get isFirstStep() {
			try { return getContext().step <= 1; } catch ( _e ) { return true; }
		},
		get isLastStep() {
			try { return getContext().step >= ( getContext().totalSteps || 4 ); } catch ( _e ) { return false; }
		},
	},
	actions: {
		nextStep() {
			const ctx = getContext();
			if ( ctx.step < ( ctx.totalSteps || 4 ) ) {
				ctx.step = ( ctx.step || 1 ) + 1;
			}
		},
		prevStep() {
			const ctx = getContext();
			if ( ctx.step > 1 ) {
				ctx.step = ctx.step - 1;
			}
		},
		skipStep() {
			const ctx = getContext();
			if ( ctx.step < ( ctx.totalSteps || 4 ) ) {
				ctx.step = ( ctx.step || 1 ) + 1;
			} else {
				// Last step skip = complete.
				window.location.href = ctx.redirectUrl || '/activity/';
			}
		},
		toggleInterest( event ) {
			const btn = event.target.closest( '[data-interest]' );
			if ( ! btn ) { return; }
			btn.classList.toggle( 'selected' );
			btn.setAttribute( 'aria-pressed', btn.classList.contains( 'selected' ) ? 'true' : 'false' );
		},
		toggleSpace( event ) {
			const btn = event.target.closest( '[data-space-id]' );
			if ( ! btn ) { return; }
			btn.classList.toggle( 'selected' );
			btn.setAttribute( 'aria-pressed', btn.classList.contains( 'selected' ) ? 'true' : 'false' );
		},
		toggleFollow( event ) {
			const ctx = getContext();
			const btn = event.target.closest( '[data-user-id]' );
			if ( ! btn || ! ctx.restNonce ) { return; }
			const userId = btn.dataset.userId;
			const following = btn.classList.contains( 'following' );
			btn.classList.toggle( 'following' );
			fetch( ctx.restUrl + 'users/' + userId + '/follow', {
				method: following ? 'DELETE' : 'POST',
				headers: { 'X-WP-Nonce': ctx.restNonce },
			} ).catch( function () { btn.classList.toggle( 'following' ); } );
		},
		triggerAvatarUpload() {
			const input = document.querySelector( '.bn-ob-avatar-input' );
			if ( input ) { input.click(); }
		},
		checkUsername( event ) {
			const ctx = getContext();
			ctx.username = event.target.value;
		},
		* completeOnboarding() {
			const ctx = getContext();
			if ( ! ctx.restNonce ) { return; }
			// Gather selected interests.
			const interests = [];
			document.querySelectorAll( '[data-interest].selected' ).forEach( function ( el ) {
				interests.push( el.dataset.interest );
			} );
			// Gather selected spaces.
			const spaces = [];
			document.querySelectorAll( '[data-space-id].selected' ).forEach( function ( el ) {
				spaces.push( parseInt( el.dataset.spaceId, 10 ) );
			} );
			try {
				yield fetch( ctx.restUrl + 'onboarding/complete', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { interests: interests, spaces: spaces } ),
				} );
				window.location.href = ctx.redirectUrl || '/activity/';
			} catch ( _e ) {
				window.location.href = ctx.redirectUrl || '/activity/';
			}
		},
	},
} );
