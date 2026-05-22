/* BuddyNext — Email-verification Interactivity API store.
 *
 * Powers templates/auth/verify.php. Provides two actions:
 *   resendEmail     — POST /buddynext/v1/auth/verify/resend on the pending state.
 *   requestNewLink  — same endpoint, exposed as a primary CTA on the error state.
 *
 * Both update reactive state for the inline feedback chip + button label.
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

function* sendResend( c ) {
	if ( c.sending ) { return; }
	c.sending = true;
	c.feedback = '';
	c.tone = '';
	try {
		const r = yield rest( c, 'auth/verify/resend', { method: 'POST' } );
		const data = yield r.json();
		if ( r.ok ) {
			c.feedback = ( data && data.message ) || 'Verification email sent. Check your inbox.';
			c.tone = 'success';
			toast( c.feedback, 'success' );
		} else {
			c.feedback = ( data && data.message ) || 'Something went wrong. Please try again.';
			c.tone = 'danger';
			toast( c.feedback, 'danger' );
		}
	} catch ( _e ) {
		c.feedback = 'Something went wrong. Please try again.';
		c.tone = 'danger';
		toast( c.feedback, 'danger' );
	}
	c.sending = false;
}

store( 'buddynext/auth-verify', {
	state: {
		get sending() { return !! ctx().sending; },
		get feedback() { return ctx().feedback || ''; },
		get tone() { return ctx().tone || ''; },
	},
	actions: {
		* resendEmail() {
			yield* sendResend( ctx() );
		},
		* requestNewLink() {
			yield* sendResend( ctx() );
		},
	},
} );
