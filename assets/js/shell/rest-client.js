/* BuddyNext — unified front-end REST client.
 *
 * The single entry point for every front-end mutation/read. Centralising
 * fetch removes the divergent per-store variants and gives one place for
 * nonce handling, automatic stale-nonce recovery, and error UX.
 *
 * Behaviour:
 *   - Resolves the base URL from window.buddynextRestData.restBase, else the
 *     buddynext/v1 default. A per-call `opts.base` overrides it — used for
 *     cross-namespace calls (e.g. the WPMediaVerse mvs/v1 routes the messages
 *     store talks to).
 *   - Resolves the nonce from window.buddynextRestData.restNonce. A per-call
 *     `opts.nonce` overrides it — honours the context-supplied nonce that
 *     templates pass via data-wp-context during/after migration.
 *   - Always sends `credentials: 'same-origin'` so the auth cookie travels,
 *     and `X-WP-Nonce` for cookie-auth REST.
 *   - JSON-encodes plain-object bodies (FormData/Blob pass through untouched
 *     so the browser sets the multipart boundary for uploads).
 *   - Parses JSON responses when content-type matches; otherwise data is null.
 *   - On `403 rest_cookie_invalid_nonce`, GETs /auth/nonce once to refresh and
 *     retries. A 404 there (route not shipped) abandons the retry and returns
 *     the original 403 verbatim.
 *   - Never throws on HTTP/network errors — always resolves to
 *     `{ ok, status, data, error? }`.
 *   - On a failed result, shows one generic error toast unless the caller
 *     opts out with `{ toastOnError: false }` (sites doing optimistic rollback
 *     plus their own toast must opt out to avoid double-toasting).
 */

import { bnToast } from './dialog.js';

const DEFAULT_BASE = '/wp-json/buddynext/v1';

function resolveBase() {
	if ( window.buddynextRestData && window.buddynextRestData.restBase ) {
		return String( window.buddynextRestData.restBase ).replace( /\/+$/, '' );
	}
	return DEFAULT_BASE;
}

function resolveNonce() {
	if ( window.buddynextRestData && window.buddynextRestData.restNonce ) {
		return window.buddynextRestData.restNonce;
	}
	return '';
}

// In-memory nonce; updated when an /auth/nonce refresh succeeds. Falls back to
// the freshly-localized value on every call so a hard page reload always wins
// over a stale in-memory copy.
let currentNonce = null;

function activeNonce( opts, isRetry ) {
	// On the post-refresh retry the freshly minted nonce must win — the
	// per-call opts.nonce is the value baked into the page HTML, which is
	// exactly what a full-page cache serves stale ("Cookie check failed").
	// Re-sending it would make the recovery a guaranteed no-op.
	if ( isRetry && currentNonce ) {
		return currentNonce;
	}
	return opts.nonce || currentNonce || resolveNonce();
}

function buildUrl( path, opts ) {
	// Absolute URL passed straight through (rare; e.g. a full REST URL).
	if ( path && /^https?:\/\//i.test( path ) ) {
		return path;
	}
	const base = opts.base
		? String( opts.base ).replace( /\/+$/, '' )
		: resolveBase();
	if ( ! path ) {
		return base;
	}
	return base + ( path.charAt( 0 ) === '/' ? path : '/' + path );
}

function isPlainObject( val ) {
	return (
		val &&
		typeof val === 'object' &&
		Object.prototype.toString.call( val ) === '[object Object]'
	);
}

function parseBody( response ) {
	const ct =
		response.headers && response.headers.get
			? response.headers.get( 'content-type' ) || ''
			: '';
	if ( ct.indexOf( 'application/json' ) !== -1 ) {
		return response.json().catch( () => null );
	}
	return Promise.resolve( null );
}

function doFetch( url, init ) {
	// jsdom and some older engines reject `signal: undefined`; drop when absent.
	if ( typeof init.signal === 'undefined' ) {
		delete init.signal;
	}
	return fetch( url, init );
}

function refreshNonce() {
	// Always refresh against the buddynext/v1 base — the wp_rest cookie nonce is
	// namespace-agnostic, so one refresh also covers cross-namespace calls.
	const url = resolveBase() + '/auth/nonce';
	return doFetch( url, {
		method: 'GET',
		credentials: 'same-origin',
		headers: { Accept: 'application/json' },
	} )
		.then( ( response ) => {
			if ( response.status === 404 ) {
				return { refreshed: false, missing: true };
			}
			if ( ! response.ok ) {
				return { refreshed: false, missing: false };
			}
			return parseBody( response ).then( ( data ) => {
				if ( data && typeof data.nonce === 'string' && data.nonce ) {
					currentNonce = data.nonce;
					if ( window.buddynextRestData ) {
						window.buddynextRestData.restNonce = data.nonce;
					}
					return { refreshed: true, missing: false };
				}
				return { refreshed: false, missing: false };
			} );
		} )
		.catch( () => ( { refreshed: false, missing: false } ) );
}

function performRequest( path, opts, isRetry ) {
	opts = opts || {};
	const method = ( opts.method || 'GET' ).toUpperCase();
	const headers = {};
	if ( opts.headers ) {
		Object.keys( opts.headers ).forEach( ( k ) => {
			headers[ k ] = opts.headers[ k ];
		} );
	}

	const nonce = activeNonce( opts, isRetry );
	if ( nonce && ! headers[ 'X-WP-Nonce' ] ) {
		headers[ 'X-WP-Nonce' ] = nonce;
	}

	let body = opts.body;
	if ( isPlainObject( body ) ) {
		if ( ! headers[ 'Content-Type' ] ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		body = JSON.stringify( body );
	}

	const init = {
		method,
		credentials: 'same-origin',
		headers,
		signal: opts.signal,
	};
	if ( typeof body !== 'undefined' && method !== 'GET' && method !== 'HEAD' ) {
		init.body = body;
	}

	return doFetch( buildUrl( path, opts ), init )
		.then( ( response ) =>
			parseBody( response ).then( ( data ) => {
				const result = {
					ok: response.ok,
					status: response.status,
					data,
				};

				// 403 + stale nonce → refresh once, then retry.
				if (
					! isRetry &&
					response.status === 403 &&
					data &&
					data.code === 'rest_cookie_invalid_nonce'
				) {
					return refreshNonce().then( ( state ) => {
						if ( state.refreshed ) {
							return performRequest( path, opts, true );
						}
						return result;
					} );
				}

				return result;
			} )
		)
		.catch( ( err ) => ( {
			ok: false,
			status: 0,
			data: null,
			error: err && err.message ? err.message : 'network_error',
		} ) );
}

/**
 * Perform a REST request.
 *
 * @param {string} path Path relative to the namespace base (or absolute URL).
 * @param {Object} [opts] fetch-like options plus { base, nonce, toastOnError }.
 * @return {Promise<{ok:boolean,status:number,data:*,error?:string}>} Result.
 */
export async function restFetch( path, opts ) {
	opts = opts || {};
	const result = await performRequest( path, opts, false );
	if ( ! result.ok && opts.toastOnError !== false ) {
		bnToast(
			( result.data && result.data.message ) ||
				'Something went wrong. Please try again.',
			{ tone: 'danger' }
		);
	}
	return result;
}

// Named global for parity with the adoption plan and for the block bundle
// (blocks.js), which is not part of the feature-store module graph.
window.buddynextRest = { restFetch };
