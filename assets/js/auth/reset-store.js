/* BuddyNext — Password-reset Interactivity API store.
 *
 * Powers templates/auth/reset.php, which has two modes:
 *   1. Request:  no key in the URL -> POST /auth/lost-password (sends the email).
 *   2. Set new:  ?key=...&login=... -> POST /auth/reset-password (commits it).
 *
 * Security lives in WordPress core (retrieve_password / check_password_reset_key
 * / reset_password); this store only drives the branded screen. Mirrors the
 * signup store: same rest() helper, inline field errors, generic messaging.
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

store( 'buddynext/auth-reset', {
	state: {
		get error() { return ctx().error || ''; },
		get notice() { return ctx().notice || ''; },
		get submitting() { return !! ctx().submitting; },
		get done() { return !! ctx().done; },
		get loginError() {
			const c = ctx();
			return ( c.fieldErrors && c.fieldErrors.user_login ) || '';
		},
		get passwordError() {
			const c = ctx();
			return ( c.fieldErrors && c.fieldErrors.password ) || '';
		},
	},
	actions: {
		setLogin( event ) {
			const c = ctx();
			c.login = event && event.target ? String( event.target.value || '' ) : '';
		},
		setPassword( event ) {
			const c = ctx();
			c.password = event && event.target ? String( event.target.value || '' ) : '';
		},

		// Mode 1 — request a reset link.
		* requestReset( event ) {
			if ( event && typeof event.preventDefault === 'function' ) {
				event.preventDefault();
			}
			const c = ctx();
			if ( c.submitting ) { return; }
			if ( ! ( c.login || '' ).trim() ) {
				c.error = 'Please enter your email or username.';
				return;
			}
			c.submitting = true;
			c.error = '';
			try {
				const r = yield rest( c, 'auth/lost-password', {
					method: 'POST',
					body:   JSON.stringify( { user_login: c.login || '' } ),
				} );
				const data = yield r.json();
				c.submitting = false;
				// Always a generic success (no account enumeration).
				c.done = true;
				c.notice = ( data && data.message ) || 'If an account matches, a reset link is on its way.';
			} catch ( _e ) {
				c.submitting = false;
				c.error = 'Something went wrong. Please try again.';
			}
		},

		// Mode 2 — set the new password from the emailed key.
		* setNewPassword( event ) {
			if ( event && typeof event.preventDefault === 'function' ) {
				event.preventDefault();
			}
			const c = ctx();
			if ( c.submitting ) { return; }
			if ( ! ( c.password || '' ) ) {
				c.error = 'Please choose a new password.';
				return;
			}
			c.submitting = true;
			c.error = '';
			c.fieldErrors = {};
			try {
				const r = yield rest( c, 'auth/reset-password', {
					method: 'POST',
					body:   JSON.stringify( {
						key:      c.resetKey || '',
						login:    c.resetLogin || '',
						password: c.password || '',
					} ),
				} );
				const data = yield r.json();
				if ( ! r.ok || ! ( data && data.success ) ) {
					if ( data && data.data && data.data.fields ) {
						c.fieldErrors = data.data.fields;
					}
					c.error = ( data && data.message ) || 'Could not reset your password.';
					c.submitting = false;
					return;
				}
				toast( 'Password updated. Please sign in.', 'success' );
				window.location.href = ( data && data.redirect_to ) || '/login/';
			} catch ( _e ) {
				c.submitting = false;
				c.error = 'Something went wrong. Please try again.';
			}
		},
	},
} );
