/* BuddyNext — Signup Interactivity API store.
 *
 * Powers templates/auth/signup.php. Submits POST /buddynext/v1/auth/register
 * with email + user_login + password + terms_agreed. On success, redirects
 * to the verify-email page (when verification is enabled) or onboarding.
 * On 422, surfaces per-field errors inline.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';

const STRENGTH_LABELS = [ '', 'Weak', 'Fair', 'Good', 'Strong' ];

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

store( 'buddynext/auth-signup', {
	state: {
		get error() { return ctx().error || ''; },
		get submitting() { return !! ctx().submitting; },
		get strengthWidth() {
			const s = Number( ctx().passwordStrength ) || 0;
			return ( ( s / 4 ) * 100 ) + '%';
		},
		get strengthLabelText() {
			const c = ctx();
			if ( ! c.password ) { return ''; }
			const s = Number( c.passwordStrength ) || 0;
			return STRENGTH_LABELS[ s ] || '';
		},
		get emailError() {
			const c = ctx();
			return ( c.fieldErrors && c.fieldErrors.email ) || '';
		},
		get usernameError() {
			const c = ctx();
			return ( c.fieldErrors && c.fieldErrors.user_login ) || '';
		},
		get passwordError() {
			const c = ctx();
			return ( c.fieldErrors && c.fieldErrors.password ) || '';
		},
		get termsError() {
			const c = ctx();
			return ( c.fieldErrors && c.fieldErrors.terms_agreed ) || '';
		},
		get challengeError() {
			const c = ctx();
			return ( c.fieldErrors && c.fieldErrors.challenge ) || '';
		},
		get emailInvalid() { return !! this.emailError; },
		get usernameInvalid() { return !! this.usernameError; },
		get passwordInvalid() { return !! this.passwordError; },
		get challengeInvalid() { return !! this.challengeError; },
		get submitDisabled() {
			// Only disabled while submitting — the button stays full strength at
			// rest and required fields/terms are validated on click in
			// submitSignup() with inline messages (matches the login surface).
			return !! ctx().submitting;
		},
	},
	actions: {
		setEmail( event ) {
			const c = ctx();
			c.email = event && event.target ? String( event.target.value || '' ) : '';
			if ( c.fieldErrors && c.fieldErrors.email ) {
				const next = Object.assign( {}, c.fieldErrors );
				delete next.email;
				c.fieldErrors = next;
			}
		},
		setUserLogin( event ) {
			const c = ctx();
			c.userLogin = event && event.target ? String( event.target.value || '' ) : '';
			if ( c.fieldErrors && c.fieldErrors.user_login ) {
				const next = Object.assign( {}, c.fieldErrors );
				delete next.user_login;
				c.fieldErrors = next;
			}
		},
		setPassword( event ) {
			const c = ctx();
			const v = event && event.target ? String( event.target.value || '' ) : '';
			c.password = v;
			let s = 0;
			if ( v.length >= 8 ) { s++; }
			if ( /[A-Z]/.test( v ) && /[a-z]/.test( v ) ) { s++; }
			if ( /\d/.test( v ) ) { s++; }
			if ( /[^A-Za-z0-9]/.test( v ) ) { s++; }
			c.passwordStrength = s;
			c.strengthLabel = v.length === 0 ? '' : ( STRENGTH_LABELS[ s ] || '' );
			if ( c.fieldErrors && c.fieldErrors.password ) {
				const next = Object.assign( {}, c.fieldErrors );
				delete next.password;
				c.fieldErrors = next;
			}
		},
		toggleTerms( event ) {
			const c = ctx();
			c.termsAgreed = !! ( event && event.target && event.target.checked );
			if ( c.termsAgreed && c.fieldErrors && c.fieldErrors.terms_agreed ) {
				const next = Object.assign( {}, c.fieldErrors );
				delete next.terms_agreed;
				c.fieldErrors = next;
			}
		},
		setChallengeAnswer( event ) {
			const c = ctx();
			c.challengeAnswer = event && event.target ? String( event.target.value || '' ) : '';
			if ( c.fieldErrors && c.fieldErrors.challenge ) {
				const next = Object.assign( {}, c.fieldErrors );
				delete next.challenge;
				c.fieldErrors = next;
			}
		},
		setHoneypot( event ) {
			const c = ctx();
			c.honeypot = event && event.target ? String( event.target.value || '' ) : '';
		},
		* submitSignup( event ) {
			if ( event && typeof event.preventDefault === 'function' ) {
				event.preventDefault();
			}
			// The submit event fires on the <form>, so event.target is the form —
			// use it to collect any custom registration fields below.
			const regForm = event && event.target ? event.target : null;
			const c = ctx();
			if ( c.submitting ) { return; }
			// Validate on click rather than disabling the button up front.
			if ( ! ( c.email || '' ).trim() || ! ( c.userLogin || '' ).trim() || ! ( c.password || '' ) ) {
				c.error = 'Please fill in your email, username, and password.';
				return;
			}
			if ( ! c.termsAgreed ) {
				c.error = 'Please agree to the Terms of Service and Privacy Policy to continue.';
				return;
			}
			if ( c.challengeEnabled && ! ( c.challengeAnswer || '' ).trim() ) {
				c.error = 'Please answer the verification question.';
				return;
			}
			c.submitting = true;
			c.error = '';
			c.fieldErrors = {};
			try {
				const body = {
					email:           c.email || '',
					user_login:      c.userLogin || '',
					password:        c.password || '',
					terms_agreed:    !! c.termsAgreed,
					reg_token:       c.regToken || '',
					challenge_token: c.challengeToken || '',
					challenge_answer: c.challengeAnswer || '',
				};
				// Honeypot under its server-issued (rotatable) field name.
				if ( c.honeypotName ) {
					body[ c.honeypotName ] = c.honeypot || '';
				}
				// Forward any custom registration profile fields (rendered server-
				// side, tagged data-bn-reg-field). Keeps the store generic: it does
				// not need to know each field up front.
				if ( regForm && regForm.querySelectorAll ) {
					const regEls = regForm.querySelectorAll( '[data-bn-reg-field]' );
					regEls.forEach( function ( el ) {
						const name = el.getAttribute( 'name' );
						if ( ! name ) { return; }
						if ( 'checkbox' === el.type ) {
							body[ name ] = el.checked ? ( el.value || '1' ) : '';
						} else if ( el.multiple ) {
							body[ name ] = Array.prototype.map.call(
								el.selectedOptions || [],
								function ( o ) { return o.value; }
							);
						} else {
							body[ name ] = el.value || '';
						}
					} );
				}
				const r = yield rest( c, 'auth/register', {
					method: 'POST',
					body:   body,
				} );
				const data = r.data;
				if ( ! r.ok || ! ( data && data.success ) ) {
					if ( data && data.data && data.data.fields ) {
						c.fieldErrors = data.data.fields;
					}
					c.error = ( data && data.message ) || 'Could not create your account.';
					c.submitting = false;
					return;
				}
				toast( 'Account created. Welcome aboard!', 'success' );
				window.location.href = ( data && data.redirect_to ) || '/onboarding/';
			} catch ( _e ) {
				c.error = 'Something went wrong. Please try again.';
				c.submitting = false;
			}
		},
	},
} );
