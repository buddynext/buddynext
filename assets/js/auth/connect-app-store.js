/* BuddyNext — Connect-app approve screen Interactivity API store.
 *
 * Powers templates/auth/connect-app.php, the last hop of the native-app
 * connect bridge. One action: approve — POST /buddynext/v1/auth/app-connect
 * over the member's cookie session + wp_rest nonce, then navigate to the
 * returned custom-scheme deep link. The template keeps a visible
 * tap-to-continue link bound to the same deep link, because some in-app
 * browsers suppress scripted custom-scheme navigation.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '@buddynext/rest-client';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_auth_connect_app) because Script Modules cannot use
 * wp_set_script_translations(). Each lookup keeps the English literal as a
 * fallback so the UI never breaks if the state is absent. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }

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

const connectStore = store( 'buddynext/auth-connect-app', {
	state: {
		get busy() { return !! ctx().busy; },
		get connected() { return !! ctx().connected; },
		get deepLink() { return ctx().deepLink || ''; },
		get error() { return ctx().error || ''; },
	},
	actions: {
		* approve() {
			const c = ctx();
			if ( c.busy || c.connected ) { return; }
			c.busy = true;
			c.error = '';
			try {
				const r = yield rest( c, 'auth/app-connect', {
					method: 'POST',
					body: {
						scheme: c.scheme,
						bridge_token: c.bridgeToken,
						app_name: c.appName,
						app_id: c.appId,
						state: c.state,
					},
				} );
				const data = r.data;
				if ( r.ok && data && data.deep_link ) {
					c.deepLink = data.deep_link;
					c.connected = true;
					// Hand the credential to the app. The template's visible
					// "Open the app" link stays as the fallback for browsers
					// that ignore scripted custom-scheme navigation.
					window.location.href = data.deep_link;
				} else if ( r.status === 410 ) {
					c.error = ( data && data.message ) || t( 'expired', 'This connection screen has expired. Go back to the app and try connecting again.' );
				} else if ( r.status === 429 ) {
					c.error = ( data && data.message ) || t( 'rateLimited', 'Too many connection attempts. Please wait a while and try again.' );
				} else {
					c.error = ( data && data.message ) || t( 'genericError', 'Something went wrong. Please try again.' );
				}
			} catch ( _e ) {
				c.error = t( 'genericError', 'Something went wrong. Please try again.' );
			}
			c.busy = false;
		},
	},
} );

I18N = ( connectStore.state && connectStore.state.i18n ) || {};
