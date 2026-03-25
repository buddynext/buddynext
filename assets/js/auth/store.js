/* BuddyNext — Auth Interactivity API store (login/register tabs + password check). */
import { store, getContext } from '@wordpress/interactivity';

store( 'buddynext/auth', {
	state: {
		get isLogin() {
			try { return getContext().tab !== 'register'; } catch ( _e ) { return true; }
		},
		get isRegister() {
			try { return getContext().tab === 'register'; } catch ( _e ) { return false; }
		},
	},
	actions: {
		setTab() {
			const ctx = getContext();
			ctx.tab = ctx.switchTo || ( ctx.tab === 'login' ? 'register' : 'login' );
		},
		checkPasswordStrength( event ) {
			const ctx  = getContext();
			const pass = event.target.value || '';
			let s = 0;
			if ( pass.length >= 8 ) { s++; }
			if ( /[A-Z]/.test( pass ) && /[a-z]/.test( pass ) ) { s++; }
			if ( /\d/.test( pass ) ) { s++; }
			if ( /[^A-Za-z0-9]/.test( pass ) ) { s++; }
			ctx.passwordStrength = s;
		},
	},
} );
