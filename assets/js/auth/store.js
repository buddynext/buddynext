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

/*
 * Password show/hide toggle.
 *
 * Store-independent and delegated so the one handler serves both the login and
 * signup screens (both load this module). The button finds its input via the
 * enclosing .bn-auth-pw wrapper, falling back to aria-controls. Labels/aria are
 * read from data-* attributes so they stay translatable. Bound once.
 */
if ( typeof document !== 'undefined' && ! document.__bnPwToggleBound ) {
	document.addEventListener( 'click', function ( ev ) {
		var btn = ( ev.target && ev.target.closest ) ? ev.target.closest( '[data-bn-pw-toggle]' ) : null;
		if ( ! btn ) { return; }
		ev.preventDefault();

		var wrap  = btn.closest( '.bn-auth-pw' );
		var input = wrap ? wrap.querySelector( 'input' ) : null;
		if ( ! input ) {
			var id = btn.getAttribute( 'aria-controls' );
			input  = id ? document.getElementById( id ) : null;
		}
		if ( ! input ) { return; }

		var show   = input.type === 'password';
		input.type = show ? 'text' : 'password';
		btn.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
		btn.textContent = show
			? ( btn.getAttribute( 'data-hide-label' ) || 'Hide' )
			: ( btn.getAttribute( 'data-show-label' ) || 'Show' );
		var aria = show ? btn.getAttribute( 'data-hide-aria' ) : btn.getAttribute( 'data-show-aria' );
		if ( aria ) { btn.setAttribute( 'aria-label', aria ); }
	} );
	document.__bnPwToggleBound = true;
}
