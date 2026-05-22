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
				toast( 'Signed in.', 'success' );
				window.location.href = ( data && data.redirect_to ) || c.redirectTo || '/activity/';
			} catch ( _e ) {
				c.error = 'Something went wrong. Please try again.';
				c.submitting = false;
			}
		},
	},
} );
