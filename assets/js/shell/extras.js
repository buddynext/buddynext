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
	var _n       = bnI18n._n || function ( one, many, n ) { return 1 === n ? one : many; };

	var data = window.bnShellData || {};
	var nonce = data.restNonce || '';

	// ── Toast helper ───────────────────────────────────────────────────────
	// The one toast lives in the shell-dialog module (shell/dialog.js), which replaces this
	// function when it loads. A call made before that (a classic script racing the module
	// graph) is queued and replayed then, so there is a single toast implementation and no
	// second copy of its markup, timing or accessibility rules to keep in step.
	window.bnToast = function ( msg, type ) {
		( window.__bnToastQueue = window.__bnToastQueue || [] ).push( [ msg, type ] );
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
			// bn-modal-backdrop hands keyboard handling to the shared modal-a11y.js
			// primitive: a document-level Escape (from the input, the X or a result),
			// the Tab trap and focus return. Its layout stays top-aligned through the
			// scoped .bn-search-overlay.bn-modal-backdrop rule in bn-base.css.
			ov.className = 'bn-search-overlay bn-modal-backdrop';
			ov.hidden = true;

			var inner = document.createElement( 'div' );
			inner.className = 'bn-search-overlay__inner';
			inner.setAttribute( 'role', 'dialog' );
			inner.setAttribute( 'aria-modal', 'true' );
			inner.setAttribute( 'aria-label', __( 'Search', 'buddynext' ) );

			var inputWrap = document.createElement( 'div' );
			inputWrap.className = 'bn-search-overlay__input-wrap';

			var icon = document.createElement( 'span' );
			icon.className = 'bn-search-overlay__icon';
			icon.setAttribute( 'aria-hidden', 'true' );
			// Lucide "search" (assets/icons/search.svg), constant markup, no caller input.
			icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>';

			var input = document.createElement( 'input' );
			input.type = 'search';
			input.className = 'bn-search-overlay__input';
			input.placeholder = __( 'Search posts, people, spaces, discussions...', 'buddynext' );
			input.setAttribute( 'autocomplete', 'off' );

			var kbd = document.createElement( 'kbd' );
			kbd.className = 'bn-search-overlay__kbd';
			kbd.textContent = 'Esc';

			// A real, focusable close control — the overlay previously had none
			// (Esc and a backdrop click were the only ways out, and neither is
			// discoverable for a keyboard or touch member who doesn't know them).
			var closeBtn = document.createElement( 'button' );
			closeBtn.type = 'button';
			closeBtn.className = 'bn-modal__close';
			closeBtn.setAttribute( 'aria-label', __( 'Close search', 'buddynext' ) );
			// Lucide "x" (assets/icons/x.svg), constant markup, no caller input.
			closeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

			inputWrap.appendChild( icon );
			inputWrap.appendChild( input );
			inputWrap.appendChild( kbd );
			inputWrap.appendChild( closeBtn );
			inner.appendChild( inputWrap );

			// Screen readers hear the outcome of each search here (the result list
			// itself is not live, or every link would be read out).
			var status = document.createElement( 'div' );
			status.className = 'bn-sr-only';
			status.id = 'bn-search-status';
			status.setAttribute( 'role', 'status' );
			inner.appendChild( status );

			var results = document.createElement( 'div' );
			results.className = 'bn-search-overlay__results';
			results.id = 'bn-search-results';
			inner.appendChild( results );

			ov.appendChild( inner );
			document.body.appendChild( ov );
			this.el = ov;

			var self = this;
			ov.addEventListener( 'click', function ( e ) { if ( e.target === ov ) self.close(); } );
			// No Escape listener here: modal-a11y.js closes the overlay through
			// closeBtn wherever focus sits. The old input-only listener fired only
			// while the input had focus, so Escape did nothing on the X or a result.
			closeBtn.addEventListener( 'click', function () { self.close(); } );

			var timer = null;
			input.addEventListener( 'input', function () {
				clearTimeout( timer );
				var q = input.value.trim();
				if ( q.length < 2 ) { results.textContent = ''; return; }
				timer = setTimeout( function () { self.search( q, results ); }, 300 );
			} );
		},
		open: function ( opener ) {
			this.init();
			// Ctrl+K and "/" pass no opener: remember whatever had focus, so close()
			// returns the member to where they were instead of the top of the page.
			this.opener = opener || ( document.activeElement !== document.body ? document.activeElement : null );
			this.el.hidden = false;
			this.el.querySelector( 'input' ).value = '';
			this.el.querySelector( 'input' ).focus();
			document.getElementById( 'bn-search-results' ).textContent = '';
			document.getElementById( 'bn-search-status' ).textContent = '';
		},
		close: function () {
			if ( ! this.el ) return;
			this.el.hidden = true;
			if ( this.opener && document.contains( this.opener ) ) this.opener.focus();
			this.opener = null;
		},
		// Normalise the search REST response into a flat, capped list of
		// { type, title, url } items. The default (no-type) request returns the
		// grouped shape { grouped:true, results:{ types:[ { type, results[] } ] } };
		// a typed request returns { items:[] }. Both collapse to one list here.
		flatten: function ( payload ) {
			var out = [];
			if ( ! payload ) { return out; }

			var groups = payload.results && payload.results.types;
			if ( Array.isArray( groups ) ) {
				// One result per group per pass, so the first group (jobs) cannot fill
				// all eight rows and hide people, spaces and posts.
				for ( var i = 0; i < 3; i++ ) {
					groups.forEach( function ( group ) {
						var item = ( group.results || [] )[ i ];
						if ( item ) {
							out.push( {
								type: group.type,
								title: item.title || item.content || '',
								url: item.url || item.permalink || '#'
							} );
						}
					} );
				}
				return out.slice( 0, 8 );
			}

			// Typed-search fallback shape: a flat items[] array.
			var items = payload.items || payload.results || payload;
			return Array.isArray( items ) ? items.slice( 0, 8 ) : out;
		},
		typeLabel: function ( type ) {
			var labels = {
				post: __( 'Post', 'buddynext' ),
				user: __( 'Member', 'buddynext' ),
				space: __( 'Space', 'buddynext' ),
				job: __( 'Job', 'buddynext' ),
				listing: __( 'Listing', 'buddynext' )
			};
			return labels[ type ] || ( type.charAt( 0 ).toUpperCase() + type.slice( 1 ) );
		},
		search: function ( q, resultsEl ) {
			if ( ! data.restSearchUrl ) return;
			// `self` is only bound in init(); without this it resolved to window and
			// every search threw on self.flatten, landing on "Search failed".
			var self = this;
			var statusEl = document.getElementById( 'bn-search-status' );
			var announce = function ( text ) { if ( statusEl ) { statusEl.textContent = text; } };
			resultsEl.textContent = '';
			var loading = document.createElement( 'div' );
			loading.className = 'bn-search-overlay__loading';
			loading.textContent = __( 'Searching...', 'buddynext' );
			resultsEl.appendChild( loading );
			announce( loading.textContent );

			var url = data.restSearchUrl + '?q=' + encodeURIComponent( q ) + '&per_page=8';
			window.buddynextRest.restFetch( url, { nonce: nonce, toastOnError: false } )
				.then( function ( res ) { if ( ! res.ok ) { throw new Error( 'http' ); } return res.data; } )
				.then( function ( payload ) {
					resultsEl.textContent = '';
					var items = self.flatten( payload );
					if ( ! items.length ) {
						var empty = document.createElement( 'div' );
						empty.className = 'bn-search-overlay__empty';
						empty.textContent = sprintf( /* translators: %s: the search text. */ __( 'No results for "%s"', 'buddynext' ), q );
						resultsEl.appendChild( empty );
						announce( empty.textContent );
						return;
					}
					/* translators: %d: number of search results shown. */
					announce( sprintf( _n( '%d result', '%d results', items.length, 'buddynext' ), items.length ) );
					items.forEach( function ( item ) {
						var a = document.createElement( 'a' );
						a.className = 'bn-search-overlay__result';
						a.href = item.url || item.permalink || '#';
						var title = document.createElement( 'div' );
						title.className = 'bn-search-overlay__result-title';
						title.textContent = item.title || item.content || '';
						var meta = document.createElement( 'div' );
						meta.className = 'bn-search-overlay__result-meta';
						meta.textContent = item.author_name
							? sprintf( /* translators: 1: result type (Post, Member...), 2: author name. */ __( '%1$s by %2$s', 'buddynext' ), self.typeLabel( item.type || 'post' ), item.author_name )
							: self.typeLabel( item.type || 'post' );
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
					announce( err.textContent );
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

	// A header search link (buddynext_header_search) opens the palette in place. A
	// modified click or middle click keeps the link's own behaviour (new tab).
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest && e.target.closest( '[data-bn-search-open]' );
		if ( ! link || e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) return;
		e.preventDefault();
		window.bnSearchOverlay.open( link );
	} );

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
			var focusTo = open ? s.querySelector( '.bn-mobile-more__close' ) : toggle;
			if ( focusTo ) focusTo.focus();
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
				// Same rule as AvatarService::initials_for() (classic script, so not the module).
				var words    = name.trim().split( ' ' ).filter( Boolean );
				var initials = words.length >= 2
					? ( words[ 0 ].charAt( 0 ) + words[ words.length - 1 ].charAt( 0 ) ).toUpperCase()
					: ( words.length ? words[ 0 ].slice( 0, 2 ).toUpperCase() : '?' );
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
				function applyFollowState( following, pending ) {
					followBtn.hidden = false;
					var followI18n = ( window.bnShellData && window.bnShellData.i18n ) || {};
					var label = following ? ( followI18n.following || 'Following' ) : ( followI18n.follow || 'Follow' );
					followBtn.textContent = pending ? ( followI18n.requested || 'Requested' ) : label;
					followBtn.classList.toggle( 'is-following', !! following || !! pending );
					followBtn.onclick = function () {
						if ( ! data.restUserUrl ) return;
						var was = following || pending;
						window.buddynextRest.restFetch( data.restUserUrl + userId + '/follow', {
							method: was ? 'DELETE' : 'POST',
							nonce: nonce,
							toastOnError: false
						} ).then( function ( res ) {
							if ( ! res.ok ) {
								window.buddynextRest.toastRestError( res, followI18n.genericError );
								return;
							}
							var requested = ! was && !! ( res.data && res.data.pending );
							if ( window.bnToast ) {
								var key = requested ? 'hoverRequested' : ( pending ? 'hoverCancelled' : ( following ? 'hoverUnfollowed' : 'hoverFollowed' ) );
								window.bnToast( ( followI18n[ key ] || '%s' ).replace( '%s', handle || name ) );
							}
							applyFollowState( ! was && ! requested, requested );
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
						if ( ! u.is_self && ( u.can_follow || u.is_following || u.is_pending ) ) {
							applyFollowState( !! u.is_following, !! u.is_pending );
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

	// ── Fit or fold: show what fits, put only the rest under "More" ────────
	// One behaviour for every row that can run out of room (owner, 2026-11-04:
	// fold by space, never by a count). Everything stays in the row while it
	// fits; when it does not, items fold into a "More" menu from the end, the
	// current tab never folds, and with room for everything there is no More.
	//
	// Two kinds of row use it:
	//   - Primary tab strips ( .bn-navgroup > .bn-tabs ): profile, space, space
	//     settings, member settings. The menu is built from the folded tabs, so
	//     no template changes and every add-on tab is covered.
	//   - Any [data-bn-fit] row whose foldable items carry data-bn-fit-item="key"
	//     and whose server-rendered menu holds a matching data-bn-fit-copy="key"
	//     (the own-profile action row). Copies keep their own Interactivity
	//     directives, which a JS clone would lose.
	// Without JS a tab strip still scrolls sideways, as before.
	( function () {
		var EDGE = 1;
		var uid  = 0;

		// Overflow for a single-line strip (scrolls) or a wrapping row (an item
		// drops to a second line).
		function overflows( row ) {
			if ( row.scrollWidth > row.clientWidth + EDGE ) {
				return true;
			}
			var top = null;
			var kids = row.children;
			for ( var i = 0; i < kids.length; i++ ) {
				if ( kids[ i ].hidden || ! kids[ i ].offsetParent ) {
					continue;
				}
				if ( null === top ) {
					top = kids[ i ].offsetTop;
				} else if ( kids[ i ].offsetTop > top + 2 ) {
					return true;
				}
			}
			return false;
		}

		// Show everything, then fold from the end until the row fits. Returns
		// the folded items in their original order.
		function fold( row, items, more, keep ) {
			items.forEach( function ( el ) { el.hidden = false; } );
			more.hidden = true;
			if ( ! overflows( row ) ) {
				return [];
			}
			more.hidden = false;
			var folded = [];
			for ( var i = items.length - 1; i >= 0 && overflows( row ); i-- ) {
				if ( items[ i ] === keep ) {
					continue;
				}
				items[ i ].hidden = true;
				folded.unshift( items[ i ] );
			}
			return folded;
		}

		function closeMenu( more ) {
			var btn  = more.querySelector( '[aria-expanded]' );
			var menu = more.querySelector( '.bn-fit-menu' );
			if ( btn ) btn.setAttribute( 'aria-expanded', 'false' );
			if ( menu ) menu.hidden = true;
		}

		// Open downward, or upward when the space below is smaller: on a phone the
		// tab bar often sits just above the fixed bottom bar, which would cover a
		// menu that opens down. Cap the height to the side it opens on.
		function place( btn, menu ) {
			menu.classList.remove( 'is-up' );
			menu.style.removeProperty( 'max-block-size' );
			var mobile = document.querySelector( '.bn-mobile-nav' );
			var floor  = window.innerHeight - ( mobile && mobile.offsetParent ? mobile.getBoundingClientRect().height : 0 );
			var r      = btn.getBoundingClientRect();
			var below  = floor - r.bottom - 8;
			var above  = r.top - 8;
			var up     = menu.offsetHeight > below && above > below;
			menu.classList.toggle( 'is-up', up );
			menu.style.maxBlockSize = Math.max( 120, Math.floor( up ? above : below ) ) + 'px';
		}

		function bindMenu( more ) {
			if ( more._bnFitBound ) return;
			more._bnFitBound = true;
			var btn  = more.querySelector( '[aria-expanded]' );
			var menu = more.querySelector( '.bn-fit-menu' );
			btn.addEventListener( 'click', function () {
				var open = 'true' !== btn.getAttribute( 'aria-expanded' );
				btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				menu.hidden = ! open;
				if ( open ) {
					place( btn, menu );
					var first = menu.querySelector( 'a, button' );
					if ( first ) first.focus();
				}
			} );
			more.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && ! menu.hidden ) {
					closeMenu( more );
					btn.focus();
				}
			} );
			menu.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( 'a, button' ) ) closeMenu( more );
			} );
			document.addEventListener( 'click', function ( e ) {
				if ( ! menu.hidden && ! more.contains( e.target ) ) closeMenu( more );
			} );
			// Close when keyboard focus tabs out. Not while a pointer is pressed inside:
			// iOS Safari moves focus from the tapped link to the page container on
			// mousedown, which fires focusout before the click and would close the menu
			// under the finger, so the tap never reached the link.
			var pressing = false;
			more.addEventListener( 'pointerdown', function () {
				pressing = true;
				setTimeout( function () { pressing = false; }, 600 );
			} );
			more.addEventListener( 'focusout', function ( e ) {
				if ( ! pressing && e.relatedTarget && ! more.contains( e.relatedTarget ) ) closeMenu( more );
			} );
		}

		// ── Tab strips ──
		function moreControl( strip ) {
			var id   = 'bn-fit-menu-' + ( ++uid );
			var more = document.createElement( 'div' );
			more.className = 'bn-fit-more';
			more.hidden = true;
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'bn-tab bn-fit-more__btn';
			btn.setAttribute( 'aria-expanded', 'false' );
			btn.setAttribute( 'aria-controls', id );
			btn.innerHTML = '<span></span><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>';
			btn.firstChild.textContent = __( 'More', 'buddynext' );
			var menu = document.createElement( 'ul' );
			menu.className = 'bn-fit-menu';
			menu.id = id;
			menu.hidden = true;
			menu.setAttribute( 'aria-label', strip.getAttribute( 'aria-label' ) || __( 'More', 'buddynext' ) );
			more.appendChild( btn );
			more.appendChild( menu );
			return more;
		}

		function fitStrip( strip ) {
			var bar = strip.parentElement;
			if ( ! bar.classList.contains( 'bn-navgroup__bar' ) ) {
				// Wrap once: the strip and its More share one row and one baseline.
				var group = bar;
				bar = document.createElement( 'div' );
				bar.className = 'bn-navgroup__bar';
				group.insertBefore( bar, strip );
				bar.appendChild( strip );
				bar.appendChild( moreControl( strip ) );
				strip.classList.add( 'is-fit' );
				bindMenu( bar.lastChild );
				if ( window.ResizeObserver ) {
					new ResizeObserver( function () { fitStrip( strip ); } ).observe( bar );
				}
			}
			var more  = bar.lastChild;
			var tabs  = Array.prototype.slice.call( strip.querySelectorAll( ':scope > .bn-tab' ) );
			var keep  = strip.querySelector( ':scope > .bn-tab[aria-current]:not([aria-current="false"]), :scope > .bn-tab[aria-selected="true"]' );
			var folded = fold( strip, tabs, more, keep );
			var menu = more.querySelector( '.bn-fit-menu' );
			menu.textContent = '';
			folded.forEach( function ( tab ) {
				var li = document.createElement( 'li' );
				var a  = document.createElement( 'a' );
				a.className = 'bn-fit-menu__item';
				a.href = tab.getAttribute( 'href' );
				a.innerHTML = tab.innerHTML;
				if ( tab.hasAttribute( 'data-bn-full-load' ) ) a.setAttribute( 'data-bn-full-load', '' );
				li.appendChild( a );
				menu.appendChild( li );
			} );
			if ( ! folded.length ) closeMenu( more );
		}

		// ── Pre-rendered rows ──
		function fitRow( row ) {
			var more  = row.querySelector( ':scope > [data-bn-fit-more]' );
			if ( ! more ) return;
			var items = Array.prototype.slice.call( row.querySelectorAll( ':scope > [data-bn-fit-item]' ) );
			var folded = fold( row, items, more, null );
			var keys = folded.map( function ( el ) { return el.getAttribute( 'data-bn-fit-item' ); } );
			row.querySelectorAll( '[data-bn-fit-copy]' ).forEach( function ( copy ) {
				copy.hidden = keys.indexOf( copy.getAttribute( 'data-bn-fit-copy' ) ) < 0;
			} );
			// A menu that only ever held foldable copies has nothing left to show.
			if ( ! folded.length && ! more.hasAttribute( 'data-bn-fit-always' ) ) more.hidden = true;
			if ( ! row._bnFitObserved && window.ResizeObserver ) {
				row._bnFitObserved = true;
				new ResizeObserver( function () { fitRow( row ); } ).observe( row.parentElement );
			}
		}

		function init() {
			document.querySelectorAll( '.bn-navgroup > .bn-tabs, .bn-navgroup__bar > .bn-tabs' ).forEach( fitStrip );
			document.querySelectorAll( '[data-bn-fit]' ).forEach( fitRow );
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
		// Web fonts change label widths; a client-side navigation swaps the bar
		// or moves the current tab ( navigate.js -> buddynext:navigated ).
		if ( document.fonts && document.fonts.ready ) {
			document.fonts.ready.then( init );
		}
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
