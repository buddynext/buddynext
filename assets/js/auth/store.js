/* BuddyNext — Auth Interactivity API store (login/register tabs + password strength). */
import { store, getContext, getElement } from '@wordpress/interactivity';

const STRENGTH_LABELS = [
	'',
	'Weak',
	'Fair',
	'Good',
	'Strong',
];

store( 'buddynext/auth', {
	state: {
		get isLogin() {
			try { return getContext().tab !== 'register'; } catch ( _e ) { return true; }
		},
		get isRegister() {
			try { return getContext().tab === 'register'; } catch ( _e ) { return false; }
		},
		get strengthWidth() {
			try {
				const s = Number( getContext().passwordStrength ) || 0;
				return ( s / 4 ) * 100 + '%';
			} catch ( _e ) {
				return '0%';
			}
		},
	},
	actions: {
		setTab( event ) {
			const ctx = getContext();
			const el  = getElement();
			const tab = ( el && el.attributes && el.attributes['data-tab'] )
				? el.attributes['data-tab']
				: ( ctx.tab === 'login' ? 'register' : 'login' );
			ctx.tab = tab;
			// Sync data-variant on the card and data-active on both panels.
			const card = el && el.ref ? el.ref.closest( '.bn-auth-card' ) : null;
			if ( card ) {
				card.setAttribute( 'data-variant', tab );
				const panels = card.querySelectorAll( '.bn-auth-panel' );
				panels.forEach( ( panel ) => {
					if ( panel.id === 'bn-auth-panel-' + tab ) {
						panel.setAttribute( 'data-active', '' );
					} else {
						panel.removeAttribute( 'data-active' );
					}
				} );
				const tabs = card.querySelectorAll( '.bn-tab' );
				tabs.forEach( ( t ) => {
					t.setAttribute(
						'aria-selected',
						t.getAttribute( 'data-tab' ) === tab ? 'true' : 'false'
					);
				} );
			}
			if ( event && typeof event.preventDefault === 'function' ) {
				event.preventDefault();
			}
		},
		checkPasswordStrength( event ) {
			const ctx  = getContext();
			const pass = ( event && event.target && event.target.value ) || '';
			let s = 0;
			if ( pass.length >= 8 ) { s++; }
			if ( /[A-Z]/.test( pass ) && /[a-z]/.test( pass ) ) { s++; }
			if ( /\d/.test( pass ) ) { s++; }
			if ( /[^A-Za-z0-9]/.test( pass ) ) { s++; }
			ctx.passwordStrength = s;
			ctx.strengthLabel    = pass.length === 0 ? '' : STRENGTH_LABELS[ s ] || '';
		},
	},
} );
