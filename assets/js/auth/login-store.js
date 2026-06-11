/* BuddyNext — Login Interactivity API store.
 *
 * Powers templates/auth/login.php. Submits credentials to
 * POST /buddynext/v1/auth/login and redirects on success. Surfaces
 * inline errors without a page reload.
 */
import { store, getContext } from '@wordpress/interactivity';

function ctx() {
	try {
		return getContext();
	} catch ( _e ) {
		return {};
	}
}

function rest( c, path, opts ) {
	const url     = ( c.restUrl || '/wp-json/buddynext/v1/' ) + String( path ).replace( /^\//, '' );
	const headers = Object.assign(
		{ 'X-WP-Nonce': c.restNonce || '', 'Content-Type': 'application/json' },
		( opts && opts.headers ) || {}
	);
	return fetch( url, Object.assign( {}, opts || {}, { headers, credentials: 'same-origin' } ) );
}

function toast( message, tone ) {
	if ( typeof window.bnToast === 'function' ) {
		window.bnToast( message, tone || 'info' );
	}
}

store( 'buddynext/auth-login', {
	state: {
		get error() { return ctx().error || ''; },
		get submitting() { return !! ctx().submitting; },
		get submitDisabled() {
			const c = ctx();
			if ( c.submitting ) { return true; }
			const u = String( c.user || '' ).trim();
			const p = String( c.password || '' );
			return u.length === 0 || p.length === 0;
		},
		get twofaStep() { return !! ctx().twofaStep; },
		get twofaError() { return ctx().twofaError || ''; },
		get emailSent() { return !! ctx().emailSent; },
		get emailHintText() {
			const c = ctx();
			return c.emailHint
				? 'Code sent to ' + c.emailHint
				: 'Code sent — check your email';
		},
		get twofaDisabled() {
			const c = ctx();
			return !! c.submitting || String( c.twofaCode || '' ).trim().length === 0;
		},
	},
	actions: {
		setUser( event ) {
			const c = ctx();
			c.user = event && event.target ? String( event.target.value || '' ) : '';
		},
		setPassword( event ) {
			const c = ctx();
			c.password = event && event.target ? String( event.target.value || '' ) : '';
		},
		toggleRemember( event ) {
			const c = ctx();
			c.remember = !! ( event && event.target && event.target.checked );
		},
		* submitLogin( event ) {
			if ( event && typeof event.preventDefault === 'function' ) {
				event.preventDefault();
			}
			const c = ctx();
			if ( c.submitting ) { return; }
			c.submitting = true;
			c.error = '';
			try {
				const r = yield rest( c, 'auth/login', {
					method: 'POST',
					body:   JSON.stringify( {
						user:        c.user || '',
						password:    c.password || '',
						remember:    !! c.remember,
						redirect_to: c.redirectTo || '',
					} ),
				} );
				const data = yield r.json();
				if ( ! r.ok || ! ( data && data.success ) ) {
					const msg = ( data && data.message ) || 'Invalid email or password.';
					c.error = msg;
					c.submitting = false;
					return;
				}
				// 2FA-enabled accounts: no session yet — switch to the code step.
				if ( data.twofa_required ) {
					c.twofaStep = true;
					c.twofaToken = data.twofa_token || '';
					c.emailHint = data.email_hint || '';
					c.error = '';
					c.submitting = false;
					return;
				}
				toast( 'Signed in.', 'success' );
				window.location.href = ( data && data.redirect_to ) || c.redirectTo || '/activity/';
			} catch ( _e ) {
				c.error = 'Something went wrong. Please try again.';
				c.submitting = false;
			}
		},
		setTwofaCode( event ) {
			const c = ctx();
			c.twofaCode = event && event.target ? String( event.target.value || '' ) : '';
			if ( c.twofaError ) { c.twofaError = ''; }
		},
		* submitTwoFactor( event ) {
			if ( event && typeof event.preventDefault === 'function' ) {
				event.preventDefault();
			}
			const c = ctx();
			if ( c.submitting ) { return; }
			c.submitting = true;
			c.twofaError = '';
			try {
				const r = yield rest( c, 'auth/2fa', {
					method: 'POST',
					body:   JSON.stringify( {
						twofa_token: c.twofaToken || '',
						code:        c.twofaCode || '',
						redirect_to: c.redirectTo || '',
					} ),
				} );
				const data = yield r.json();
				if ( ! r.ok || ! ( data && data.success ) ) {
					c.twofaError = ( data && data.message ) || 'That code was not correct.';
					c.submitting = false;
					return;
				}
				toast( 'Signed in.', 'success' );
				window.location.href = ( data && data.redirect_to ) || c.redirectTo || '/activity/';
			} catch ( _e ) {
				c.twofaError = 'Something went wrong. Please try again.';
				c.submitting = false;
			}
		},
		* sendEmailCode() {
			const c = ctx();
			if ( c.emailSent ) { return; }
			try {
				yield rest( c, 'auth/2fa/email-code', {
					method: 'POST',
					body:   JSON.stringify( { twofa_token: c.twofaToken || '' } ),
				} );
				c.emailSent = true;
				toast( 'If your session is still valid, a code is on its way.', 'info' );
			} catch ( _e ) {
				toast( 'Could not send the code. Try your authenticator app.', 'error' );
			}
		},
	},
} );
