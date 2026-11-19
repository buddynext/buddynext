/* BuddyNext - one-click install of the free GitHub site tools.
 *
 * Drives every [data-bn-gh-install] control (the Home demo card and the Add-ons
 * rows, rendered by CompanionGithubUi) against POST companions/install and
 * GET companions/status. Endpoints, nonce and every string come from data-*
 * attributes, so this file holds no server values and no copy of its own.
 *
 * Failure reveals the manual fallback (Download, Upload, Come back) with the
 * server's reason in its role=alert line; "Check again" re-reads the state:
 * active finishes in place, installed-but-off reloads so the server renders the
 * right Activate control for this host and user.
 */
( function () {
	'use strict';

	function init( box ) {
		var go       = box.querySelector( '[data-bn-gh-go]' );
		var label    = box.querySelector( '[data-bn-gh-label]' );
		var status   = box.querySelector( '[data-bn-gh-status]' );
		var progress = box.querySelector( '[data-bn-gh-progress]' );
		var fallback = box.querySelector( '[data-bn-gh-fallback]' );
		var reason   = box.querySelector( '[data-bn-gh-reason]' );
		var zip      = box.querySelector( '[data-bn-gh-zip]' );
		var check    = box.querySelector( '[data-bn-gh-check]' );
		var retry    = box.querySelector( '[data-bn-gh-retry]' );
		var t        = function ( key ) { return box.getAttribute( 'data-i18n-' + key ) || ''; };
		var nonce    = box.getAttribute( 'data-nonce' );
		var slug     = box.getAttribute( 'data-slug' );

		function say( text ) {
			if ( status ) {
				status.textContent = text;
			}
		}

		function busy( on ) {
			if ( ! go ) {
				return;
			}
			go.disabled = on;
			go.classList.toggle( 'is-busy', on );
			go.setAttribute( 'aria-busy', on ? 'true' : 'false' );
			if ( progress ) {
				progress.hidden = ! on;
			}
		}

		function fail( message, manual ) {
			busy( false );
			if ( go ) {
				go.hidden = true;
			}
			say( '' );
			if ( zip && manual && manual.zip_url ) {
				zip.href = manual.zip_url;
			}
			if ( fallback ) {
				fallback.hidden = false;
			}
			if ( reason ) {
				reason.textContent = message || t( 'failed' );
			}
		}

		function done() {
			busy( false );
			if ( fallback ) {
				fallback.hidden = true;
			}
			box.setAttribute( 'data-state', 'active' );
			say( t( 'done' ) );

			var badge = box.querySelector( '[data-bn-gh-badge]' );
			if ( badge && box.getAttribute( 'data-done-badge' ) ) {
				badge.textContent = box.getAttribute( 'data-done-badge' );
				badge.className   = 'bn-companion-badge bn-companion-badge--success';
			}
			var sub = box.querySelector( '[data-bn-gh-sub]' );
			if ( sub && box.getAttribute( 'data-done-sub' ) ) {
				sub.textContent = box.getAttribute( 'data-done-sub' );
			}
			var trust = box.querySelector( '.bn-demo-installer__trust' );
			if ( trust ) {
				trust.hidden = true;
			}

			// The control becomes its next step (Set up a demo), or goes when there is none.
			var url  = box.getAttribute( 'data-done-url' );
			var slot = box.querySelector( '[data-bn-gh-action]' );
			if ( url && ( go || slot ) ) {
				var link         = document.createElement( 'a' );
				link.className   = 'bn-btn';
				link.href        = url;
				link.textContent = box.getAttribute( 'data-done-label' ) || '';
				link.setAttribute( 'data-variant', 'primary' );
				if ( go ) {
					go.replaceWith( link );
				} else {
					slot.appendChild( link );
				}
			} else if ( go ) {
				go.remove();
			}
		}

		function install() {
			var activating = 'inactive' === box.getAttribute( 'data-state' );
			var original   = label ? label.textContent : '';
			busy( true );
			if ( label ) {
				label.textContent = activating ? t( 'activating' ) : t( 'installing' );
			}
			say( activating ? t( 'switching' ) : t( 'downloading' ) );

			fetch( box.getAttribute( 'data-rest-install' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( { slug: slug } )
			} ).then( function ( r ) {
				return r.json().then( function ( d ) { return { ok: r.ok, d: d }; }, function () { return { ok: false, d: null }; } );
			} ).then( function ( res ) {
				if ( res.ok ) {
					done();
					return;
				}
				if ( label ) {
					label.textContent = original;
				}
				var data = res.d && res.d.data ? res.d.data : {};
				fail( res.d && res.d.message ? res.d.message : t( 'failed' ), data.manual );
			} ).catch( function () {
				if ( label ) {
					label.textContent = original;
				}
				fail( t( 'network' ), null );
			} );
		}

		function recheck() {
			if ( ! check ) {
				return;
			}
			var original      = check.textContent;
			check.disabled    = true;
			check.textContent = t( 'checking' );
			var url = box.getAttribute( 'data-rest-status' );
			url    += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'slug=' + encodeURIComponent( slug );

			fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					check.disabled    = false;
					check.textContent = original;
					if ( d && 'active' === d.status ) {
						done();
					} else if ( d && 'inactive' === d.status ) {
						window.location.reload();
					} else {
						say( t( 'missing' ) );
					}
				} )
				.catch( function () {
					check.disabled    = false;
					check.textContent = original;
					say( t( 'network' ) );
				} );
		}

		if ( go ) {
			go.addEventListener( 'click', install );
		}
		if ( retry && go ) {
			retry.addEventListener( 'click', function () {
				go.hidden = false;
				if ( fallback ) {
					fallback.hidden = true;
				}
				install();
			} );
		}
		if ( check ) {
			check.addEventListener( 'click', recheck );
		}
	}

	function boot() {
		document.querySelectorAll( '[data-bn-gh-install]' ).forEach( init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
