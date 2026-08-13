/**
 * BuddyNext — Shell extras (search overlay, hover card, toasts, shortcuts).
 *
 * Bundled in the same bn-shell layer as font-scale.js but split into a
 * separate file because these features depend on REST endpoints that
 * are only available on hub pages where wpApiSettings has been set up.
 *
 * Reads window.bnShellData (localized by PageRouter::enqueue_hub_assets):
 *   restNonce          string
 *   restSearchUrl      string
 *   restNotifsUrl      string
 *   restNotifsReadUrl  string
 *   restUserUrl        string
 *   feedUrl            string
 *   navUrls            object
 *
 * @package BuddyNext
 */

( function () {
	'use strict';

	// Translation: classic script reading from the global wp.i18n with safe
	// identity fallbacks (the bn-shell-extras handle declares wp-i18n + uses
	// wp_set_script_translations).
	var bnI18n   = ( window.wp && window.wp.i18n ) || {};
	var __       = bnI18n.__ || function ( s ) { return s; };
	var sprintf  = bnI18n.sprintf || function ( s ) { return s; };

	var data = window.bnShellData || {};
	var nonce = data.restNonce || '';

	// ── Toast helper ───────────────────────────────────────────────────────
	window.bnToast = function ( msg, type ) {
		// Accept a tone string — bnToast(msg, 'success') — or an options object —
		// bnToast(msg, { tone, timeout }). Map to one of the four real toast classes
		// (error/success/info/warning); 'danger'/'warn' are aliases that would
		// otherwise emit undefined classes and render neutral. Kept behaviourally
		// identical to the module bnToast in dialog.js.
		var opts    = ( type && 'object' === typeof type ) ? type : {};
		var tone    = ( 'string' === typeof type ) ? type : ( opts.tone || '' );
		// Errors/warnings dwell longer than transient success/info; explicit wins.
		var isAlert = ( 'danger' === tone || 'error' === tone || 'warn' === tone || 'warning' === tone );
		var timeout = ( 'number' === typeof opts.timeout ) ? opts.timeout : ( isAlert ? 7000 : 3000 );
		var cls     = '';
		if ( 'success' === tone ) {
			cls = 'bn-toast--success';
		} else if ( 'danger' === tone || 'error' === tone ) {
			cls = 'bn-toast--error';
		} else if ( 'warn' === tone || 'warning' === tone ) {
			cls = 'bn-toast--warning';
		} else if ( 'info' === tone ) {
			cls = 'bn-toast--info';
		}
		var c = document.querySelector( '.bn-toast-container' );
		if ( ! c ) {
			c = document.createElement( 'div' );
			c.className = 'bn-toast-container';
			document.body.appendChild( c );
		}
		var t = document.createElement( 'div' );
		t.className = 'bn-toast' + ( cls ? ' ' + cls : '' );
		t.setAttribute( 'role', isAlert ? 'alert' : 'status' );
		t.setAttribute( 'aria-live', isAlert ? 'assertive' : 'polite' );
		t.textContent = msg;
		c.appendChild( t );
		// JS-owned lifetime: fade via --leaving, then remove; click dismisses early.
		var removeTimer;
		var dismiss = function () {
			window.clearTimeout( removeTimer );
			t.classList.add( 'bn-toast--leaving' );
			setTimeout( function () {
				t.remove();
				if ( c && ! c.children.length ) { c.remove(); }
			}, 250 );
		};
		t.addEventListener( 'click', dismiss );
		removeTimer = setTimeout( dismiss, timeout );
	};

	// ── Notification dropdown ──────────────────────────────────────────────
	function openNotifDropdown( btn ) {
		var wrap = btn.closest( '.bn-nav-notif-wrap' );
		var dd = wrap ? wrap.querySelector( '.bn-notif-dropdown' ) : null;
		if ( ! dd ) return;
		var isOpen = ! dd.hidden;
		dd.hidden = isOpen;
		btn.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );

		if ( ! isOpen && ! dd.dataset.loaded && data.restNotifsUrl ) {
			dd.dataset.loaded = '1';
			window.buddynextRest.restFetch( data.restNotifsUrl, {
				nonce: nonce,
				toastOnError: false
			} ).then( function ( res ) { if ( ! res.ok ) { throw new Error( 'http' ); } return res.data; } ).then( function ( payload ) {
				var list = document.getElementById( 'bn-notif-dropdown-list' );
				if ( ! list ) return;
				var items = payload.items || payload;
				if ( ! items || ! items.length ) {
					list.textContent = '';
					var empty = document.createElement( 'div' );
					empty.className = 'bn-notif-dropdown__loading';
					empty.textContent = __( 'No notifications yet', 'buddynext' );
					list.appendChild( empty );
					return;
				}
				list.textContent = '';
				items.forEach( function ( n ) {
					var div = document.createElement( 'div' );
					div.className = 'bn-notif-dropdown__item' + ( n.is_read ? '' : ' bn-notif-dropdown__item--unread' );
					var avatar = document.createElement( 'div' );
					avatar.className = 'bn-notif-dropdown__avatar';
					avatar.textContent = ( n.sender_name || 'U' ).split( ' ' ).map( function ( w ) { return w[ 0 ]; } ).join( '' ).toUpperCase().slice( 0, 2 );
					var body = document.createElement( 'div' );
					body.className = 'bn-notif-dropdown__body';
					var text = document.createElement( 'div' );
					text.className = 'bn-notif-dropdown__text';
					var strong = document.createElement( 'strong' );
					strong.textContent = n.sender_name || '';
					text.appendChild( strong );
					text.appendChild( document.createTextNode( ' ' + ( n.message || n.type || '' ) ) );
					var time = document.createElement( 'div' );
					time.className = 'bn-notif-dropdown__time';
					time.textContent = n.time_ago || '';
					body.appendChild( text );
					body.appendChild( time );
					div.appendChild( avatar );
					div.appendChild( body );
					list.appendChild( div );
				} );
			} ).catch( function () {
				var list = document.getElementById( 'bn-notif-dropdown-list' );
				if ( list ) {
					list.textContent = '';
					var err = document.createElement( 'div' );
					err.className = 'bn-notif-dropdown__loading';
					err.textContent = __( 'Could not load', 'buddynext' );
					list.appendChild( err );
				}
			} );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest && e.target.closest( '[data-bn-action="toggle-notif-dropdown"]' );
		if ( t ) { openNotifDropdown( t ); return; }

		var m = e.target.closest && e.target.closest( '[data-bn-action="mark-all-read"]' );
		if ( m && data.restNotifsReadUrl ) {
			window.buddynextRest.restFetch( data.restNotifsReadUrl, {
				method: 'PUT',
				nonce: nonce,
				toastOnError: false
			} ).then( function ( res ) {
				if ( ! res.ok ) { return; }
				var pill = document.querySelector( '.bn-nav-notif-wrap .bn-nav-pill' );
				if ( pill ) pill.remove();
				document.querySelectorAll( '.bn-notif-dropdown__item--unread' ).forEach( function ( el ) {
					el.classList.remove( 'bn-notif-dropdown__item--unread' );
				} );
				if ( window.bnToast ) window.bnToast( __( 'All notifications marked read', 'buddynext' ) );
			} );
			return;
		}

		var wrap = document.querySelector( '.bn-nav-notif-wrap' );
		if ( wrap && ! wrap.contains( e.target ) ) {
			var dd = wrap.querySelector( '.bn-notif-dropdown' );
			if ( dd ) dd.hidden = true;
			var btn = wrap.querySelector( '[aria-expanded]' );
			if ( btn ) btn.setAttribute( 'aria-expanded', 'false' );
		}
	} );

	// ── Search overlay (cmd+K) ─────────────────────────────────────────────
	window.bnSearchOverlay = {
		el: null,
		init: function () {
			if ( this.el ) return;
			var ov = document.createElement( 'div' );
			ov.className = 'bn-search-overlay';
			ov.hidden = true;

			var inner = document.createElement( 'div' );
			inner.className = 'bn-search-overlay__inner';

			var inputWrap = document.createElement( 'div' );
			inputWrap.className = 'bn-search-overlay__input-wrap';

			var icon = document.createElement( 'span' );
			icon.className = 'bn-search-overlay__icon';
			icon.textContent = '⌕';

			var input = document.createElement( 'input' );
			input.type = 'search';
			input.className = 'bn-search-overlay__input';
			input.placeholder = __( 'Search posts, people, spaces, discussions...', 'buddynext' );
			input.setAttribute( 'autocomplete', 'off' );

			var kbd = document.createElement( 'kbd' );
			kbd.className = 'bn-search-overlay__kbd';
			kbd.textContent = 'Esc';

			inputWrap.appendChild( icon );
			inputWrap.appendChild( input );
			inputWrap.appendChild( kbd );
			inner.appendChild( inputWrap );

			var results = document.createElement( 'div' );
			results.className = 'bn-search-overlay__results';
			results.id = 'bn-search-results';
			inner.appendChild( results );

			ov.appendChild( inner );
			document.body.appendChild( ov );
			this.el = ov;

			var self = this;
			ov.addEventListener( 'click', function ( e ) { if ( e.target === ov ) self.close(); } );
			input.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) self.close(); } );

			var timer = null;
			input.addEventListener( 'input', function () {
				clearTimeout( timer );
				var q = input.value.trim();
				if ( q.length < 2 ) { results.textContent = ''; return; }
				timer = setTimeout( function () { self.search( q, results ); }, 300 );
			} );
		},
		open: function () {
			this.init();
			this.el.hidden = false;
			this.el.querySelector( 'input' ).value = '';
			this.el.querySelector( 'input' ).focus();
			document.getElementById( 'bn-search-results' ).textContent = '';
		},
		close: function () { if ( this.el ) this.el.hidden = true; },
		// Normalise the search REST response into a flat, capped list of
		// { type, title, url } items. The default (no-type) request returns the
		// grouped shape { grouped:true, results:{ types:[ { type, results[] } ] } };
		// a typed request returns { items:[] }. Both collapse to one list here.
		flatten: function ( payload ) {
			var out = [];
			if ( ! payload ) { return out; }

			var groups = payload.results && payload.results.types;
			if ( Array.isArray( groups ) ) {
				groups.forEach( function ( group ) {
					( group.results || [] ).forEach( function ( item ) {
						out.push( {
							type: group.type,
							title: item.title || item.content || '',
							url: item.url || item.permalink || '#'
						} );
					} );
				} );
				return out.slice( 0, 8 );
			}

			// Typed-search fallback shape: a flat items[] array.
			var items = payload.items || payload.results || payload;
			return Array.isArray( items ) ? items.slice( 0, 8 ) : out;
		},
		search: function ( q, resultsEl ) {
			if ( ! data.restSearchUrl ) return;
			resultsEl.textContent = '';
			var loading = document.createElement( 'div' );
			loading.className = 'bn-search-overlay__loading';
			loading.textContent = __( 'Searching...', 'buddynext' );
			resultsEl.appendChild( loading );

			var url = data.restSearchUrl + '?q=' + encodeURIComponent( q ) + '&per_page=8';
			window.buddynextRest.restFetch( url, { nonce: nonce, toastOnError: false } )
				.then( function ( res ) { if ( ! res.ok ) { throw new Error( 'http' ); } return res.data; } )
				.then( function ( payload ) {
					resultsEl.textContent = '';
					var items = self.flatten( payload );
					if ( ! items.length ) {
						var empty = document.createElement( 'div' );
						empty.className = 'bn-search-overlay__empty';
						empty.textContent = sprintf( __( 'No results for "%s"', 'buddynext' ), q );
						resultsEl.appendChild( empty );
						return;
					}
					items.forEach( function ( item ) {
						var a = document.createElement( 'a' );
						a.className = 'bn-search-overlay__result';
						a.href = item.url || item.permalink || '#';
						var title = document.createElement( 'div' );
						title.className = 'bn-search-overlay__result-title';
						title.textContent = item.title || item.content || '';
						var meta = document.createElement( 'div' );
						meta.className = 'bn-search-overlay__result-meta';
						meta.textContent = ( item.type || 'post' ) + ( item.author_name ? ' by ' + item.author_name : '' );
						a.appendChild( title );
						a.appendChild( meta );
						resultsEl.appendChild( a );
					} );
				} )
				.catch( function () {
					resultsEl.textContent = '';
					var err = document.createElement( 'div' );
					err.className = 'bn-search-overlay__empty';
					err.textContent = __( 'Search failed', 'buddynext' );
					resultsEl.appendChild( err );
				} );
		}
	};

	// ── Keyboard shortcuts ─────────────────────────────────────────────────
	// Owner-disablable via the buddynext_keyboard_shortcuts_enabled filter. The
	// handler is not bound at all when it is off, rather than bound and made to
	// return early: a listener that exists but declines to act still competes for
	// the keystroke and still has to be reasoned about by anyone debugging.
	// '0' is the only off value; anything else (including the key being absent on
	// a cached payload from before this shipped) means on. See the PHP side for
	// why this is a string and not a boolean.
	if ( data.shortcuts !== '0' ) {
	/**
	 * The general feed composer on this page, if there is one.
	 *
	 * Space composers use the same interactive namespace, so they are excluded
	 * the same way the ?compose= deep-link excludes them: by having no spaceId
	 * in their context. Returns the textarea, because focusing it IS opening it.
	 *
	 * @return {HTMLTextAreaElement|null} The composer input, or null.
	 */
	function bnFeedComposerInput() {
		var nodes = document.querySelectorAll( '[data-wp-interactive="buddynext/post-composer"]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var ctx;
			try { ctx = JSON.parse( nodes[ i ].getAttribute( 'data-wp-context' ) || '{}' ); }
			catch ( _e ) { continue; }
			if ( ctx.spaceId === null || ctx.spaceId === undefined ) {
				return nodes[ i ].querySelector( 'textarea' );
			}
		}
		return null;
	}

	document.addEventListener( 'keydown', function ( e ) {
		var inInput = e.target.closest && e.target.closest( 'input,textarea,[contenteditable]' );

		if ( ( e.metaKey || e.ctrlKey ) && e.key === 'k' ) {
			e.preventDefault();
			window.bnSearchOverlay.open();
			return;
		}
		if ( inInput ) return;

		if ( e.key === '/' ) { e.preventDefault(); window.bnSearchOverlay.open(); return; }

		if ( e.key === 'n' && data.feedUrl ) {
			// Already looking at a page that HAS the composer: focus it. This used
			// to navigate unconditionally, so pressing n on the feed reloaded the
			// whole page to reach a composer that was already in the DOM, throwing
			// away scroll position and any unsaved state on the way. ?compose=1
			// does nothing more than focus this same textarea.
			var bnInput = bnFeedComposerInput();
			if ( bnInput ) {
				e.preventDefault();
				bnInput.focus();
				var bnEnd = bnInput.value.length;
				try { bnInput.setSelectionRange( bnEnd, bnEnd ); } catch ( _e ) {}
				bnInput.scrollIntoView( { block: 'center' } );
				return;
			}
			e.preventDefault();
			window.location = data.feedUrl + '?compose=1';
			return;
		}

		if ( e.key === 'g' ) {
			var _bnGo = function ( ev ) {
				document.removeEventListener( 'keydown', _bnGo );
				var map = { f: 'feed', m: 'members', s: 'spaces', n: 'notifications', d: 'messages' };
				var urls = data.navUrls || {};
				if ( map[ ev.key ] && urls[ map[ ev.key ] ] ) {
					ev.preventDefault();
					window.location = urls[ map[ ev.key ] ];
				}
			};
			document.addEventListener( 'keydown', _bnGo, { once: true } );
			setTimeout( function () { document.removeEventListener( 'keydown', _bnGo ); }, 1000 );
			return;
		}

		if ( e.key === '?' && window.bnToast ) {
			window.bnToast( __( '/ search  |  n new post  |  g+f feed  |  g+s spaces  |  g+m members', 'buddynext' ) );
		}
	} );
	} // end buddynext_keyboard_shortcuts_enabled

	// ── Mobile "More" overflow sheet ───────────────────────────────────────
	// The bottom bar folds the Profile shortcut + admin-created custom tabs into
	// a bottom sheet (nav.php) when overflow items exist. Toggle/close it here.
	( function () {
		function sheet() { return document.getElementById( 'bn-mobile-more' ); }
		function backdrop() { return document.querySelector( '.bn-mobile-more-backdrop' ); }

		function setOpen( open ) {
			var s = sheet();
			var b = backdrop();
			if ( ! s ) return;
			s.hidden = ! open;
			if ( b ) b.hidden = ! open;
			var toggle = document.querySelector( '[data-bn-more-toggle]' );
			if ( toggle ) toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target.closest ) return;
			if ( e.target.closest( '[data-bn-more-toggle]' ) ) {
				e.preventDefault();
				setOpen( sheet() && sheet().hidden );
				return;
			}
			if ( e.target.closest( '[data-bn-more-close]' ) ) {
				e.preventDefault();
				setOpen( false );
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				var s = sheet();
				if ( s && ! s.hidden ) setOpen( false );
			}
		} );
	}() );

	// ── Horizontal tab strips: wheel-to-scroll ─────────────────────────────
	// .bn-tabs strips scroll horizontally when they overflow (CSS overflow-x:
	// auto) and a scroll-driven edge fade hints there is more. But a mouse wheel
	// only scrolls vertically, so desktop mouse users could not reach tabs past
	// the fade (keyboard users already get auto-scroll on focus, trackpad users
	// get native horizontal scroll). Translate vertical wheel intent into
	// horizontal scroll while the strip can still move; release to the page at
	// the ends so the surrounding scroll never feels stuck.
	document.addEventListener( 'wheel', function ( e ) {
		var strip = e.target.closest && e.target.closest( '.bn-tabs' );
		if ( ! strip ) return;
		if ( strip.scrollWidth <= strip.clientWidth ) return;
		// Defer to genuine horizontal intent (trackpad) — only remap vertical.
		if ( Math.abs( e.deltaY ) <= Math.abs( e.deltaX ) ) return;
		var atStart = strip.scrollLeft <= 0;
		var atEnd   = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 1;
		if ( ( e.deltaY < 0 && atStart ) || ( e.deltaY > 0 && atEnd ) ) return;
		strip.scrollLeft += e.deltaY;
		e.preventDefault();
	}, { passive: false } );

	// ── User hover card ────────────────────────────────────────────────────
	( function () {
		var card = null;
		var hoverTimer = null;
		var leaveTimer = null;

		function hideCard() {
			clearTimeout( hoverTimer );
			if ( card ) card.hidden = true;
		}

		document.addEventListener( 'mouseenter', function ( e ) {
			if ( ! e.target || ! e.target.closest ) return;
			var el = e.target.closest( '.bn-hover-user' );
			if ( ! el ) return;
			clearTimeout( leaveTimer );

			hoverTimer = setTimeout( function () {
				var userId = el.dataset.bnUserId;
				var name = el.dataset.bnUserName || '';
				var handle = el.dataset.bnUserHandle || '';
				// The trigger is the byline anchor, so its href is the canonical
				// profile link — reused for every actionable element on the card.
				var profileUrl = el.getAttribute( 'href' ) || '#';
				if ( ! card ) {
					card = document.createElement( 'div' );
					card.className = 'bn-hover-card';
					document.body.appendChild( card );
					card.addEventListener( 'mouseenter', function () { clearTimeout( leaveTimer ); } );
					card.addEventListener( 'mouseleave', function () { card.hidden = true; } );
				}
				var initials = name.split( ' ' ).map( function ( w ) { return w[ 0 ]; } ).join( '' ).toUpperCase().slice( 0, 2 );
				card.textContent = '';
				var header = document.createElement( 'div' );
				header.className = 'bn-hover-card__header';
				// Avatar links to the profile so the whole card reads as navigable.
				var av = document.createElement( 'a' );
				av.className = 'bn-hover-card__avatar';
				av.href = profileUrl;
				av.textContent = initials;
				// Keep a handle so the profile fetch below can swap the initials
				// for the real avatar image once it resolves.
				var avatarBox = av;
				var info = document.createElement( 'div' );
				info.className = 'bn-hover-card__info';
				var nm = document.createElement( 'a' );
				nm.className = 'bn-hover-card__name';
				nm.href = profileUrl;
				nm.textContent = name;
				var hd = document.createElement( 'a' );
				hd.className = 'bn-hover-card__handle';
				hd.href = profileUrl;
				hd.textContent = handle ? '@' + handle : '';
				info.appendChild( nm );
				info.appendChild( hd );
				header.appendChild( av );
				header.appendChild( info );
				card.appendChild( header );

				// Stats link to the profile too — clicking any counter opens the
				// user's profile (the card has no dedicated followers/following view).
				var stats = document.createElement( 'a' );
				stats.className = 'bn-hover-card__stats';
				stats.href = profileUrl;
				card.appendChild( stats );

				var followBtn = document.createElement( 'button' );
				followBtn.className = 'bn-hover-card__follow';
				followBtn.type = 'button';
				followBtn.hidden = true; // Revealed once the relationship resolves.
				card.appendChild( followBtn );

				// Bind the Follow/Following toggle to the current relationship so the
				// card mirrors the post-card state instead of always reading "Follow".
				function applyFollowState( following ) {
					followBtn.hidden = false;
					followBtn.textContent = following ? 'Following' : 'Follow';
					followBtn.classList.toggle( 'is-following', !! following );
					followBtn.onclick = function () {
						if ( ! data.restUserUrl ) return;
						var method = following ? 'DELETE' : 'POST';
						window.buddynextRest.restFetch( data.restUserUrl + userId + '/follow', {
							method: method,
							nonce: nonce,
							toastOnError: false
						} ).then( function ( res ) {
							if ( ! res.ok ) { return; }
							following = ! following;
							applyFollowState( following );
							if ( window.bnToast ) {
								window.bnToast( ( following ? 'Followed ' : 'Unfollowed ' ) + name );
							}
						} );
					};
				}

				var rect = el.getBoundingClientRect();
				card.style.top = ( rect.bottom + 8 ) + 'px';
				card.style.left = Math.max( 8, Math.min( rect.left, window.innerWidth - 296 ) ) + 'px';
				card.hidden = false;

				if ( data.restUserUrl ) {
					// Canonical profile route is /users/{id}/profile — the bare
					// /users/{id} returns 404, so avatar/bio/stats never loaded.
					window.buddynextRest.restFetch( data.restUserUrl + userId + '/profile', {
						nonce: nonce,
						toastOnError: false
					} ).then( function ( res ) { if ( ! res.ok ) { throw new Error( 'http' ); } return res.data; } ).then( function ( u ) {
						// Swap the initials placeholder for the real avatar image.
						if ( u && u.avatar_url ) {
							var img = document.createElement( 'img' );
							img.src = u.avatar_url;
							img.alt = '';
							img.loading = 'lazy';
							avatarBox.textContent = '';
							avatarBox.appendChild( img );
						}
						stats.textContent = '';
						var pairs = [
							[ 'Posts', u.post_count || 0 ],
							[ 'Followers', u.follower_count || 0 ],
							[ 'Following', u.following_count || 0 ]
						];
						pairs.forEach( function ( p ) {
							var s = document.createElement( 'span' );
							var n = document.createElement( 'span' );
							n.className = 'bn-hover-card__stat-num';
							n.textContent = p[ 1 ];
							s.appendChild( n );
							s.appendChild( document.createTextNode( ' ' + p[ 0 ] ) );
							stats.appendChild( s );
						} );
						if ( u.bio ) {
							var bio = document.createElement( 'div' );
							bio.className = 'bn-hover-card__bio';
							bio.textContent = u.bio.substring( 0, 100 );
							card.insertBefore( bio, stats );
						}
						// Hide the Follow button on the viewer's own card; otherwise
						// reflect the real relationship.
						if ( ! u.is_self ) {
							applyFollowState( !! u.is_following );
						}
					} ).catch( function () { /* ignore */ } );
				}
			}, 400 );
		}, true );

		document.addEventListener( 'mouseleave', function ( e ) {
			if ( ! e.target || ! e.target.closest || ! e.target.closest( '.bn-hover-user' ) ) return;
			clearTimeout( hoverTimer );
			leaveTimer = setTimeout( function () { if ( card ) card.hidden = true; }, 200 );
		}, true );

		// Dismiss on click — the trigger byline and every link inside the card
		// navigate to the profile, so a still-pending or open card would otherwise
		// flash over the loading page. Cancel the timer and hide it immediately.
		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target || ! e.target.closest ) return;
			if ( e.target.closest( '.bn-hover-user' ) || e.target.closest( '.bn-hover-card' ) ) {
				hideCard();
			}
		}, true );

		// Dismiss on scroll — once the page moves the card is anchored to a stale
		// position and the trigger may be off-screen, so close it immediately.
		window.addEventListener( 'scroll', function () {
			if ( card && ! card.hidden ) hideCard();
		}, true );
	}() );

	// ── Primary nav-bar scroll controls ────────────────────────────────────
	// The primary tab bar ( .bn-navgroup > .bn-tabs ) hides its scrollbar, so a
	// strip with more tabs than fit has no scrollbar to drag and (on desktop) no
	// obvious way to reach the hidden tabs. Wrap the strip in a flex scroller and
	// flank it with chevron buttons that:
	//   - tap / click  -> scroll one "page" ( ~75% of the visible width )
	//   - press + hold -> scroll continuously until release
	//   - touch        -> the strip still swipes natively; buttons also tap/hold
	// Unified across mouse / touch / pen via Pointer Events. The buttons sit
	// BESIDE the strip (never over a tab) so no tab is ever covered. Works on iOS
	// Safari + Firefox, where the CSS scroll-timeline edge-fade does not.
	( function () {
		var EDGE = 2;        // px tolerance so sub-pixel widths do not flicker.
		var HOLD_MS = 350;   // press longer than this -> continuous scroll.
		var STEP_PX = 14;    // continuous-scroll speed, px per animation frame.

		// dir: -1 = toward start (inline-start), +1 = toward end. Returns the
		// signed scrollLeft delta, accounting for RTL ( negative scrollLeft ).
		function delta( strip, dir, px ) {
			var rtl = 'rtl' === getComputedStyle( strip ).direction;
			return px * dir * ( rtl ? -1 : 1 );
		}

		function update( strip ) {
			if ( ! strip._bnStart ) return;
			var max = strip.scrollWidth - strip.clientWidth;
			var rtl = 'rtl' === getComputedStyle( strip ).direction;
			var pos = rtl ? -strip.scrollLeft : strip.scrollLeft;
			strip._bnStart.hidden = ! ( max > EDGE && pos > EDGE );
			strip._bnEnd.hidden   = ! ( max > EDGE && pos < max - EDGE );
		}

		// True while there is still room to move in `dir` ( so a hold stops at
		// the edge instead of spinning forever ).
		function canMove( strip, dir ) {
			var max = strip.scrollWidth - strip.clientWidth;
			var rtl = 'rtl' === getComputedStyle( strip ).direction;
			var pos = rtl ? -strip.scrollLeft : strip.scrollLeft;
			return dir < 0 ? pos > EDGE : pos < max - EDGE;
		}

		function page( strip, dir ) {
			var px = Math.max( 120, Math.round( strip.clientWidth * 0.75 ) );
			strip.scrollBy( { left: delta( strip, dir, px ), behavior: 'smooth' } );
		}

		function makeBtn( edge ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'bn-navgroup__nav bn-navgroup__nav--' + edge;
			// Pointer affordance only; keyboard users scroll tabs into view by
			// tabbing through the tabs themselves, so keep these out of tab order
			// and out of the a11y tree to avoid duplicate, label-less stops.
			b.setAttribute( 'aria-hidden', 'true' );
			b.tabIndex = -1;
			b.hidden = true;
			return b;
		}

		function bindHold( strip, btn, dir ) {
			var holdTimer = null;
			var raf = null;

			function stop() {
				if ( holdTimer ) { clearTimeout( holdTimer ); holdTimer = null; }
				if ( raf ) { cancelAnimationFrame( raf ); raf = null; }
			}
			function loop() {
				if ( ! canMove( strip, dir ) ) { stop(); return; }
				strip.scrollLeft += delta( strip, dir, STEP_PX );
				update( strip );
				raf = requestAnimationFrame( loop );
			}
			btn.addEventListener( 'pointerdown', function ( e ) {
				// Primary button / touch / pen only; ignore right-click.
				if ( e.button && 0 !== e.button ) return;
				e.preventDefault();
				page( strip, dir );                       // immediate one-page nudge
				holdTimer = setTimeout( function () {     // ...then continuous if held
					holdTimer = null;
					loop();
				}, HOLD_MS );
			} );
			// Release in every way a press can end.
			[ 'pointerup', 'pointercancel', 'pointerleave' ].forEach( function ( ev ) {
				btn.addEventListener( ev, stop );
			} );
			window.addEventListener( 'blur', stop );
		}

		function wire( strip ) {
			if ( strip.dataset.bnOverflowWired ) { update( strip ); return; }
			var group = strip.parentElement;
			if ( ! group || ! group.classList.contains( 'bn-navgroup' ) ) return;
			strip.dataset.bnOverflowWired = '1';

			// Wrap the strip in a flex scroller and flank it with the buttons so
			// they sit beside the tabs, never on top of them.
			var scroller = document.createElement( 'div' );
			scroller.className = 'bn-navgroup__scroller';
			group.insertBefore( scroller, strip );
			var startBtn = makeBtn( 'start' );
			var endBtn   = makeBtn( 'end' );
			scroller.appendChild( startBtn );
			scroller.appendChild( strip );
			scroller.appendChild( endBtn );
			strip._bnStart = startBtn;
			strip._bnEnd   = endBtn;

			bindHold( strip, startBtn, -1 );
			bindHold( strip, endBtn, 1 );

			strip.addEventListener( 'scroll', function () { update( strip ); }, { passive: true } );
			if ( window.ResizeObserver ) {
				new ResizeObserver( function () { update( strip ); } ).observe( strip );
			}
			update( strip );
		}

		function init() {
			var strips = document.querySelectorAll( '.bn-navgroup > .bn-tabs' );
			for ( var i = 0; i < strips.length; i++ ) { wire( strips[ i ] ); }
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
		// Re-measure on viewport changes and re-wire after a client-side
		// navigation swaps the nav bar ( navigate.js -> buddynext:navigated ).
		window.addEventListener( 'resize', init, { passive: true } );
		document.addEventListener( 'buddynext:navigated', init );
	}() );

	/*
	 * Shell viewport fit — feed .bn-app the real chrome above it.
	 *
	 * .bn-app { min-height: calc(100dvh - var(--bn-shell-chrome)) } (bn-shell.css)
	 * needs the exact height rendered above the shell: the host theme header + BN
	 * toolbar, plus the WP admin bar only when an admin is logged in. That total
	 * is not knowable in CSS (it varies by theme, and a subscriber has no admin
	 * bar at all), so measure the shell's own top offset from the document top and
	 * publish it as --bn-shell-chrome. Without this the shell reserves a full
	 * viewport while sitting below the chrome, and short-content hubs show dead
	 * canvas between the content and the footer (card 10124166442).
	 *
	 * scrollY is added so the value is correct even if the page is measured while
	 * scrolled. Re-runs on resize (a theme's header height changes at its own
	 * breakpoints) and after a client-side navigation.
	 */
	( function () {
		var app = document.getElementById( 'bn-app' );
		if ( ! app ) {
			return;
		}
		function fitShell() {
			var top = Math.round( app.getBoundingClientRect().top + window.scrollY );
			app.style.setProperty( '--bn-shell-chrome', ( top > 0 ? top : 0 ) + 'px' );
		}
		/*
		 * Publish the mobile nav's REAL height as --bn-mobile-nav-h.
		 *
		 * Every surface pinned near the bottom of the viewport has to clear the fixed
		 * mobile nav, and until now each one guessed: the toast sat at 24px, the cookie
		 * bar at 16px, the edit-profile save bar hardcoded `52px` with a comment calling
		 * that "its ~52px height". The nav does not declare a height at all — it is
		 * emergent (44px tap-target items + padding + env(safe-area-inset-bottom)) and
		 * measures 60px on a 390px phone, so the 52px literal was already 8px short and
		 * the save bar rendered underneath the nav.
		 *
		 * One measurement, published once, consumed by every bottom-anchored surface —
		 * so a change to the nav's padding or an iOS safe-area inset can never again
		 * leave one surface behind. Set on the root element so surfaces OUTSIDE .bn-app
		 * (the toast container is appended to <body>) can read it too.
		 */
		function fitMobileNav() {
			var nav = document.querySelector( '.bn-mobile-nav' );
			var shown = nav && window.getComputedStyle( nav ).display !== 'none';

			if ( shown ) {
				document.documentElement.style.setProperty(
					'--bn-mobile-nav-h',
					Math.round( nav.getBoundingClientRect().height ) + 'px'
				);
			} else {
				// Desktop, or a hub with the bar disabled — hand it back to the stylesheet.
				document.documentElement.style.removeProperty( '--bn-mobile-nav-h' );
			}
		}

		function fitAll() {
			fitShell();
			fitMobileNav();
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fitAll );
		} else {
			fitAll();
		}
		window.addEventListener( 'resize', fitAll, { passive: true } );
		document.addEventListener( 'buddynext:navigated', fitAll );
	}() );

	/* ── Profile bio: collapse a long one behind Show more ─────────────────── */
	( function () {
		'use strict';

		// The bio renders in full in the profile hero and nowhere else, so a long
		// one pushed the tabs and every bit of profile content below the fold —
		// measured 883px of hero for a ~1000-char bio against 496px with none.
		//
		// Collapse is applied HERE rather than in CSS on purpose: a CSS-default
		// clamp would truncate the bio outright for anyone whose JS fails, because
		// the control that opens it would never appear. Rendering open and
		// collapsing once the control exists loses nothing.
		function clampBios() {
			document.querySelectorAll( '.bn-pf-bio' ).forEach( function ( bio ) {
				if ( bio.dataset.bnClampBound ) {
					return;
				}
				bio.dataset.bnClampBound = '1';

				// Measure first: only collapse a bio that is actually too tall.
				bio.setAttribute( 'data-bn-clamped', '1' );
				if ( bio.scrollHeight <= bio.clientHeight + 1 ) {
					bio.removeAttribute( 'data-bn-clamped' );
					return;
				}

				var i18n = ( window.bnShellData && window.bnShellData.i18n ) || {};
				var more = i18n.bioShowMore || 'Show more';
				var less = i18n.bioShowLess || 'Show less';

				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'bn-pf-bio__toggle';
				btn.textContent = more;
				btn.setAttribute( 'aria-expanded', 'false' );

				btn.addEventListener( 'click', function () {
					var collapsed = '1' === bio.getAttribute( 'data-bn-clamped' );
					if ( collapsed ) {
						bio.removeAttribute( 'data-bn-clamped' );
					} else {
						bio.setAttribute( 'data-bn-clamped', '1' );
					}
					btn.textContent = collapsed ? less : more;
					btn.setAttribute( 'aria-expanded', collapsed ? 'true' : 'false' );
				} );

				bio.insertAdjacentElement( 'afterend', btn );
			} );
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', clampBios );
		} else {
			clampBios();
		}
		document.addEventListener( 'buddynext:navigated', clampBios );
	}() );

	// ── Smart sticky right rail ──────────────────────────────────────────────
	// Pin the right sidebar so a long feed never leaves the column blank, and —
	// when the rail is TALLER than the viewport (many spaces, Notifications, or
	// owner-added cards) — bottom-stick it so scrolling reveals its last card
	// instead of clipping it. Pure-CSS sticky can only pin the top (cutting a
	// tall rail's bottom) or clip with a max-height (the cut-off look); the
	// correct offset is min(topGap, viewportH - railHeight), which needs a
	// measured height. Recomputed on load, resize, image load, and SPA nav.
	( function () {
		function positionRail() {
			var rail = document.querySelector( '.bn-app__right' );
			if ( ! rail ) {
				return;
			}
			var bar    = document.getElementById( 'wpadminbar' );
			var topGap = ( bar ? bar.offsetHeight : 0 ) + 16;
			var vh     = window.innerHeight;
			var railH  = rail.offsetHeight;
			// Short rail → pin at topGap. Tall rail → negative top so its bottom
			// lands ~16px above the viewport bottom (bottom-stick), keeping the
			// last card reachable.
			rail.style.top = Math.min( topGap, vh - railH - 16 ) + 'px';
		}

		var scheduled = false;
		function schedule() {
			if ( scheduled ) {
				return;
			}
			scheduled = true;
			window.requestAnimationFrame( function () {
				scheduled = false;
				positionRail();
			} );
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', positionRail );
		} else {
			positionRail();
		}
		window.addEventListener( 'resize', schedule );
		window.addEventListener( 'load', positionRail );
		document.addEventListener( 'buddynext:navigated', positionRail );
	}() );
}() );
