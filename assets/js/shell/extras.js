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

	var data = window.bnShellData || {};
	var nonce = data.restNonce || '';

	// ── Toast helper ───────────────────────────────────────────────────────
	window.bnToast = function ( msg, type ) {
		var c = document.querySelector( '.bn-toast-container' );
		if ( ! c ) {
			c = document.createElement( 'div' );
			c.className = 'bn-toast-container';
			document.body.appendChild( c );
		}
		var t = document.createElement( 'div' );
		t.className = 'bn-toast' + ( type ? ' bn-toast--' + type : '' );
		t.textContent = msg;
		c.appendChild( t );
		setTimeout( function () { t.remove(); }, 3000 );
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
			fetch( data.restNotifsUrl, {
				headers: { 'X-WP-Nonce': nonce }
			} ).then( function ( r ) { return r.json(); } ).then( function ( payload ) {
				var list = document.getElementById( 'bn-notif-dropdown-list' );
				if ( ! list ) return;
				var items = payload.items || payload;
				if ( ! items || ! items.length ) {
					list.textContent = '';
					var empty = document.createElement( 'div' );
					empty.className = 'bn-notif-dropdown__loading';
					empty.textContent = 'No notifications yet';
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
					err.textContent = 'Could not load';
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
			fetch( data.restNotifsReadUrl, {
				method: 'PUT',
				headers: { 'X-WP-Nonce': nonce }
			} ).then( function () {
				var pill = document.querySelector( '.bn-nav-notif-wrap .bn-nav-pill' );
				if ( pill ) pill.remove();
				document.querySelectorAll( '.bn-notif-dropdown__item--unread' ).forEach( function ( el ) {
					el.classList.remove( 'bn-notif-dropdown__item--unread' );
				} );
				if ( window.bnToast ) window.bnToast( 'All notifications marked read' );
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
			input.placeholder = 'Search posts, people, spaces, discussions...';
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
		search: function ( q, resultsEl ) {
			if ( ! data.restSearchUrl ) return;
			resultsEl.textContent = '';
			var loading = document.createElement( 'div' );
			loading.className = 'bn-search-overlay__loading';
			loading.textContent = 'Searching...';
			resultsEl.appendChild( loading );

			var url = data.restSearchUrl + '?q=' + encodeURIComponent( q ) + '&per_page=8';
			fetch( url, { headers: { 'X-WP-Nonce': nonce } } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( payload ) {
					resultsEl.textContent = '';
					var items = payload.items || payload.results || payload;
					if ( ! items || ! items.length ) {
						var empty = document.createElement( 'div' );
						empty.className = 'bn-search-overlay__empty';
						empty.textContent = 'No results for "' + q + '"';
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
					err.textContent = 'Search failed';
					resultsEl.appendChild( err );
				} );
		}
	};

	// ── Keyboard shortcuts ─────────────────────────────────────────────────
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
			window.bnToast( '/ search  |  n new post  |  g+f feed  |  g+s spaces  |  g+m members' );
		}
	} );

	// ── User hover card ────────────────────────────────────────────────────
	( function () {
		var card = null;
		var hoverTimer = null;
		var leaveTimer = null;

		document.addEventListener( 'mouseenter', function ( e ) {
			if ( ! e.target || ! e.target.closest ) return;
			var el = e.target.closest( '.bn-hover-user' );
			if ( ! el ) return;
			clearTimeout( leaveTimer );

			hoverTimer = setTimeout( function () {
				var userId = el.dataset.bnUserId;
				var name = el.dataset.bnUserName || '';
				var handle = el.dataset.bnUserHandle || '';
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
				var av = document.createElement( 'div' );
				av.className = 'bn-hover-card__avatar';
				av.textContent = initials;
				// Keep a handle so the profile fetch below can swap the initials
				// for the real avatar image once it resolves.
				var avatarBox = av;
				var info = document.createElement( 'div' );
				var nm = document.createElement( 'div' );
				nm.className = 'bn-hover-card__name';
				nm.textContent = name;
				var hd = document.createElement( 'div' );
				hd.className = 'bn-hover-card__handle';
				hd.textContent = handle ? '@' + handle : '';
				info.appendChild( nm );
				info.appendChild( hd );
				header.appendChild( av );
				header.appendChild( info );
				card.appendChild( header );

				var stats = document.createElement( 'div' );
				stats.className = 'bn-hover-card__stats';
				card.appendChild( stats );

				var followBtn = document.createElement( 'button' );
				followBtn.className = 'bn-hover-card__follow';
				followBtn.textContent = 'Follow';
				followBtn.onclick = function () {
					if ( ! data.restUserUrl ) return;
					fetch( data.restUserUrl + userId + '/follow', {
						method: 'POST',
						headers: { 'X-WP-Nonce': nonce }
					} ).then( function () {
						followBtn.textContent = 'Following';
						if ( window.bnToast ) window.bnToast( 'Followed ' + name );
					} );
				};
				card.appendChild( followBtn );

				var rect = el.getBoundingClientRect();
				card.style.top = ( rect.bottom + 8 ) + 'px';
				card.style.left = Math.max( 8, Math.min( rect.left, window.innerWidth - 296 ) ) + 'px';
				card.hidden = false;

				if ( data.restUserUrl ) {
					// Canonical profile route is /users/{id}/profile — the bare
					// /users/{id} returns 404, so avatar/bio/stats never loaded.
					fetch( data.restUserUrl + userId + '/profile', {
						headers: { 'X-WP-Nonce': nonce }
					} ).then( function ( r ) { return r.json(); } ).then( function ( u ) {
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
					} ).catch( function () { /* ignore */ } );
				}
			}, 400 );
		}, true );

		document.addEventListener( 'mouseleave', function ( e ) {
			if ( ! e.target || ! e.target.closest || ! e.target.closest( '.bn-hover-user' ) ) return;
			clearTimeout( hoverTimer );
			leaveTimer = setTimeout( function () { if ( card ) card.hidden = true; }, 200 );
		}, true );
	}() );
}() );
