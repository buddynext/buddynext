/* BuddyNext — Login Interactivity API store.
 *
 * Powers templates/auth/login.php. Submits credentials to
 * POST /buddynext/v1/auth/login and redirects on success. Surfaces
 * inline errors without a page reload.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_auth_login) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/auth-login namespace below; each lookup keeps the English literal
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

const loginStore = store( 'buddynext/auth-login', {
	state: {
		get error() { return ctx().error || ''; },
		get submitting() { return !! ctx().submitting; },
		get submitDisabled() {
			// Only disabled while a request is in flight. The button stays full
			// strength at rest (premium pattern) and empty fields are caught on
			// click in submitLogin() with an inline message — never grey it out.
			return !! ctx().submitting;
		},
		get twofaStep() { return !! ctx().twofaStep; },
		get twofaError() { return ctx().twofaError || ''; },
		get emailSent() { return !! ctx().emailSent; },
		get emailHintText() {
			const c = ctx();
			return c.emailHint
				? fmt( t( 'codeSentTo', 'Code sent to %s' ), c.emailHint )
				: t( 'codeSentCheckEmail', 'Code sent — check your email' );
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
			// Validate on click instead of disabling the button up front.
			if ( String( c.user || '' ).trim().length === 0 || String( c.password || '' ).length === 0 ) {
				c.error = t( 'enterEmailPassword', 'Enter your email and password to sign in.' );
				return;
			}
			c.submitting = true;
			c.error = '';
			try {
				const r = yield rest( c, 'auth/login', {
					method: 'POST',
					body:   {
						user:        c.user || '',
						password:    c.password || '',
						remember:    !! c.remember,
						redirect_to: c.redirectTo || '',
					},
				} );
				const data = r.data;
				if ( ! r.ok || ! ( data && data.success ) ) {
					const msg = ( data && data.message ) || t( 'invalidCredentials', 'Invalid email or password.' );
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
				toast( t( 'signedIn', 'Signed in.' ), 'success' );
				window.location.href = ( data && data.redirect_to ) || c.redirectTo || '/activity/';
			} catch ( _e ) {
				c.error = t( 'genericError', 'Something went wrong. Please try again.' );
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
					body:   {
						twofa_token: c.twofaToken || '',
						code:        c.twofaCode || '',
						redirect_to: c.redirectTo || '',
					},
				} );
				const data = r.data;
				if ( ! r.ok || ! ( data && data.success ) ) {
					c.twofaError = ( data && data.message ) || t( 'twofaIncorrect', 'That code was not correct.' );
					c.submitting = false;
					return;
				}
				toast( t( 'signedIn', 'Signed in.' ), 'success' );
				window.location.href = ( data && data.redirect_to ) || c.redirectTo || '/activity/';
			} catch ( _e ) {
				c.twofaError = t( 'genericError', 'Something went wrong. Please try again.' );
				c.submitting = false;
			}
		},
		* sendEmailCode() {
			const c = ctx();
			if ( c.emailSent ) { return; }
			try {
				yield rest( c, 'auth/2fa/email-code', {
					method: 'POST',
					body:   { twofa_token: c.twofaToken || '' },
				} );
				c.emailSent = true;
				toast( t( 'emailCodeSent', 'If your session is still valid, a code is on its way.' ), 'info' );
			} catch ( _e ) {
				toast( t( 'emailCodeFailed', 'Could not send the code. Try your authenticator app.' ), 'error' );
			}
		},
	},
} );

I18N = ( loginStore.state && loginStore.state.i18n ) || {};
