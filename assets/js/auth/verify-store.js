/* BuddyNext — Email-verification Interactivity API store.
 *
 * Powers templates/auth/verify.php. Provides two actions:
 *   resendEmail     — POST /buddynext/v1/auth/verify/resend on the pending state.
 *   requestNewLink  — same endpoint, exposed as a primary CTA on the error state.
 *
 * Both update reactive state for the inline feedback chip + button label.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_auth_verify) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/auth-verify namespace below; each lookup keeps the English literal
 * as a fallback so the UI never breaks if the state is absent. fmt() fills
 * sprintf-style '%s'/'%d' placeholders. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( vals[ pos ? pos - 1 : i++ ] ?? '' ) ); }

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

function* sendResend( c ) {
	if ( c.sending ) { return; }
	c.sending = true;
	c.feedback = '';
	c.tone = '';
	try {
		const r = yield rest( c, 'auth/verify/resend', { method: 'POST' } );
		const data = r.data;
		if ( r.ok ) {
			c.feedback = ( data && data.message ) || t( 'verificationSent', 'Verification email sent. Check your inbox.' );
			c.tone = 'success';
			toast( c.feedback, 'success' );
		} else {
			c.feedback = ( data && data.message ) || t( 'genericError', 'Something went wrong. Please try again.' );
			c.tone = 'danger';
			toast( c.feedback, 'danger' );
		}
	} catch ( _e ) {
		c.feedback = t( 'genericError', 'Something went wrong. Please try again.' );
		c.tone = 'danger';
		toast( c.feedback, 'danger' );
	}
	c.sending = false;
}

const verifyStore = store( 'buddynext/auth-verify', {
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

I18N = ( verifyStore.state && verifyStore.state.i18n ) || {};
