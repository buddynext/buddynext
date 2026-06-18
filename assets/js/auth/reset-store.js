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
import { restFetch } from '../shell/rest-client.js';

function ctx() {
	try {
		return getContext();
	} catch ( _e ) {
		return {};
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
					body:   { user_login: c.login || '' },
				} );
				const data = r.data;
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
					body:   {
						key:      c.resetKey || '',
						login:    c.resetLogin || '',
						password: c.password || '',
					},
				} );
				const data = r.data;
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
