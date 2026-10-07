/**
 * BuddyNext — Account menu disclosure.
 *
 * The caret was a button with no behaviour at all: the dropdown was opened
 * purely by CSS, on :hover and :focus-within. Clicking the caret focused it,
 * which opened the menu, and clicking it AGAIN could not close it — focus never
 * left, so :focus-within stayed true. The only way out was to click somewhere
 * else on the page. A control that opens on click and refuses to close on the
 * next click reads as broken, and it is the one interaction every account menu
 * on every mainstream site supports.
 *
 * This makes the caret a real menu button: click toggles, Escape closes and
 * returns focus, an outside click closes, and moving focus out of the widget
 * closes. Hover-to-open is kept for pointer users, so nothing that worked
 * before stops working.
 *
 * Delegated from the document, because the header section renders through a
 * block, a shortcode, or a per-theme shim, on hub pages AND ordinary theme
 * pages, and survives client-side navigation — there is no single mount point
 * to bind to.
 *
 * The notification bell's preview panel uses the same rules (one open menu at a
 * time, Escape, outside click, focus leaving). Its list is fetched from
 * GET /me/notifications only when the panel opens, and opening it marks the
 * notifications SEEN (clears the badge) without marking them read, as the
 * notifications page does. On phones the bell stays a link to that page.
 *
 * @package BuddyNext
 */

( function () {
	'use strict';

	var OPEN_CLASS = 'is-open';
	var ROOTS      = '.bn-header-user, .bn-block-notification-bell';
	var TOGGLES    = '.bn-header-user__caret, [data-bn-notif-toggle]';
	var PHONE      = '(max-width: 640px)';

	/**
	 * The widget root for a given node, or null.
	 *
	 * @param {Node} node Starting node.
	 * @return {HTMLElement|null} Root element.
	 */
	function rootOf( node ) {
		return node && node.closest ? node.closest( ROOTS ) : null;
	}

	/**
	 * The control that opens a widget.
	 *
	 * @param {HTMLElement} root Widget root.
	 * @return {HTMLElement|null} Toggle.
	 */
	function toggleOf( root ) {
		return root.querySelector( TOGGLES );
	}

	/**
	 * Open or close one menu.
	 *
	 * @param {HTMLElement} root Widget root.
	 * @param {boolean}     open Desired state.
	 * @return {void}
	 */
	function setOpen( root, open ) {
		var toggle = toggleOf( root );

		root.classList.toggle( OPEN_CLASS, open );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		if ( open && root.querySelector( '.bn-notif-panel' ) ) {
			keepInView( root.querySelector( '.bn-notif-panel' ) );
			loadNotifications( root.querySelector( '.bn-notif-panel' ), root );
		}
	}

	/**
	 * Keep the panel off the screen edge.
	 *
	 * It hangs from the bell's end edge, and a theme that puts the bell at the
	 * very edge of a narrow (tablet) header would leave the panel's border flush
	 * against the screen. Nudge it inward to an 8px gutter, in either direction.
	 *
	 * @param {HTMLElement} panel Panel.
	 * @return {void}
	 */
	function keepInView( panel ) {
		var gutter = 8;
		var rtl    = 'rtl' === getComputedStyle( panel ).direction;
		var r;

		panel.style.marginInlineEnd = '';
		r = panel.getBoundingClientRect();

		var overflow = rtl ? gutter - r.left : r.right - ( document.documentElement.clientWidth - gutter );
		if ( overflow > 0 ) {
			panel.style.marginInlineEnd = overflow + 'px';
		}
	}

	/**
	 * Fetch with the panel's REST nonce.
	 *
	 * @param {HTMLElement} panel  Panel carrying data-endpoint + data-nonce.
	 * @param {string}      path   Path after the endpoint ('' for the list).
	 * @param {string}      method HTTP method.
	 * @return {Promise<Response>} Response.
	 */
	function api( panel, path, method ) {
		return window.fetch( panel.dataset.endpoint + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': panel.dataset.nonce },
		} );
	}

	/**
	 * "5 minutes ago" in the page language, from an ISO UTC timestamp.
	 *
	 * @param {string} iso ISO 8601 UTC.
	 * @return {string} Relative time.
	 */
	function ago( iso ) {
		var secs = ( new Date( iso ).getTime() - Date.now() ) / 1000;
		var steps = [ [ 60, 'second' ], [ 60, 'minute' ], [ 24, 'hour' ], [ 7, 'day' ], [ 4.35, 'week' ], [ 12, 'month' ], [ Infinity, 'year' ] ];
		var i;
		if ( ! window.Intl || ! Intl.RelativeTimeFormat || isNaN( secs ) ) {
			return '';
		}
		for ( i = 0; i < steps.length && Math.abs( secs ) >= steps[ i ][ 0 ]; i++ ) {
			secs /= steps[ i ][ 0 ];
		}
		return new Intl.RelativeTimeFormat( document.documentElement.lang || undefined, { numeric: 'auto' } ).format( Math.round( secs ), steps[ i ][ 1 ] );
	}

	/**
	 * One panel row: avatar, message, time; unread rows carry is-unread.
	 *
	 * @param {Object} item REST notification item.
	 * @return {HTMLElement} List item.
	 */
	function row( item ) {
		var li   = document.createElement( 'li' );
		var a    = document.createElement( 'a' );
		var body = document.createElement( 'span' );
		var msg  = document.createElement( 'span' );
		var time = document.createElement( 'time' );
		var pic;

		a.className = 'bn-notif-panel__item' + ( item.is_read ? '' : ' is-unread' );
		a.href      = item.url || '#';

		if ( item.actor_avatar_url ) {
			pic        = document.createElement( 'img' );
			pic.src    = item.actor_avatar_url;
			pic.alt    = '';
			pic.width  = 32;
			pic.height = 32;
		} else {
			pic             = document.createElement( 'span' );
			pic.textContent = ( item.actor_name || item.label || '•' ).trim().charAt( 0 ).toUpperCase();
		}
		pic.className = 'bn-notif-panel__avatar';
		pic.setAttribute( 'aria-hidden', 'true' );

		body.className  = 'bn-notif-panel__body';
		msg.className   = 'bn-notif-panel__msg';
		msg.textContent = item.message || item.label || '';
		time.className  = 'bn-notif-panel__time';
		if ( item.created_at_gmt ) {
			time.dateTime    = item.created_at_gmt;
			time.textContent = ago( item.created_at_gmt );
		}

		body.append( msg, time );
		a.append( pic, body );
		li.appendChild( a );
		return li;
	}

	/**
	 * A one-line status row (loading, empty, error).
	 *
	 * @param {HTMLElement} list List.
	 * @param {string}      text Message.
	 * @return {void}
	 */
	function status( list, text ) {
		var li = document.createElement( 'li' );
		li.className   = 'bn-notif-panel__status';
		li.textContent = text;
		list.replaceChildren( li );
	}

	/**
	 * Fill the panel with the latest notifications and clear the badge.
	 *
	 * @param {HTMLElement} panel Panel.
	 * @param {HTMLElement} root  Bell widget root.
	 * @return {void}
	 */
	function loadNotifications( panel, root ) {
		var list = panel.querySelector( '.bn-notif-panel__list' );
		var mark = panel.querySelector( '[data-bn-notif-markall]' );
		var i18n = {};
		try {
			i18n = JSON.parse( panel.dataset.i18n || '{}' );
		} catch ( e ) {}

		status( list, i18n.loading || '' );
		list.setAttribute( 'aria-busy', 'true' );

		api( panel, '?per_page=8', 'GET' )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( String( res.status ) );
				}
				return res.json();
			} )
			.then( function ( data ) {
				var items = ( data && data.items ) || [];
				list.setAttribute( 'aria-busy', 'false' );
				if ( ! items.length ) {
					status( list, i18n.empty || '' );
					mark.hidden = true;
					return;
				}
				list.replaceChildren.apply( list, items.map( row ) );
				mark.hidden = ! items.some( function ( it ) {
					return ! it.is_read;
				} );
				// Seen, not read: the badge clears, the rows keep their unread dot.
				api( panel, '/seen', 'POST' ).then( function ( res ) {
					var badge = root.querySelector( '.bn-notification-badge' );
					if ( res.ok && badge ) {
						badge.remove();
					}
					// The same number is printed on the rail and the phone nav; one
					// that stayed until reload disagreed with the bell beside it.
					var bell = root.querySelector( '.bn-notification-bell-link' );
					if ( res.ok && bell ) {
						// A second bell (the phone header) carries the same number.
						document.querySelectorAll( '.bn-notification-bell-link .bn-notification-badge' ).forEach( function ( twin ) {
							twin.remove();
						} );
						// Matched by the bell's own address, so a renamed page still works.
						document.querySelectorAll( '.bn-rail__badge, .bn-mobile-nav__badge' ).forEach( function ( other ) {
							var link = other.closest( 'a[href]' );
							if ( link && link.pathname === bell.pathname ) {
								other.hidden = true;
							}
						} );
					}
				} );
			} )
			.catch( function () {
				list.setAttribute( 'aria-busy', 'false' );
				status( list, i18n.error || '' );
			} );
	}

	/**
	 * Close every open menu except an optional one to keep.
	 *
	 * @param {HTMLElement|null} keep Root to leave alone.
	 * @return {void}
	 */
	function closeAll( keep ) {
		var open = document.querySelectorAll( '.bn-header-user.' + OPEN_CLASS + ', .bn-block-notification-bell.' + OPEN_CLASS );
		var i;

		for ( i = 0; i < open.length; i++ ) {
			if ( open[ i ] !== keep ) {
				setOpen( open[ i ], false );
			}
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var caret = event.target.closest ? event.target.closest( TOGGLES ) : null;
		var markAll = event.target.closest ? event.target.closest( '[data-bn-notif-markall]' ) : null;

		if ( markAll ) {
			var panel = markAll.closest( '.bn-notif-panel' );
			event.stopPropagation();
			// A busy flag, not `disabled`: a disabled button drops focus, and focus
			// leaving the widget closes the panel mid-action.
			if ( 'true' === markAll.getAttribute( 'aria-busy' ) ) {
				return;
			}
			markAll.setAttribute( 'aria-busy', 'true' );
			api( panel, '/read-all', 'POST' ).then( function ( res ) {
				markAll.setAttribute( 'aria-busy', 'false' );
				if ( res.ok ) {
					panel.querySelectorAll( '.is-unread' ).forEach( function ( el ) {
						el.classList.remove( 'is-unread' );
					} );
					// Keep focus inside the panel as the button disappears.
					markAll.hidden = true;
					panel.querySelector( '.bn-notif-panel__all' ).focus();
				}
			} );
			return;
		}

		// A phone keeps the bell a plain link to the notifications page.
		if ( caret && caret.hasAttribute( 'data-bn-notif-toggle' ) && window.matchMedia( PHONE ).matches ) {
			return;
		}

		if ( caret ) {
			var root = rootOf( caret );
			if ( ! root ) {
				return;
			}

			// The caret is a real button inside no form, but stop the event from
			// also reaching the document handler below, which would immediately
			// close what this click just opened.
			event.preventDefault();
			event.stopPropagation();

			var willOpen = ! root.classList.contains( OPEN_CLASS );
			closeAll( root );
			setOpen( root, willOpen );

			return;
		}

		// A click inside the notifications panel (its rows are links) leaves it
		// to navigate; anywhere else closes every menu.
		if ( event.target.closest && event.target.closest( '.bn-notif-panel' ) ) {
			return;
		}
		closeAll( null );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key && 'Esc' !== event.key ) {
			return;
		}

		var root = rootOf( document.activeElement );
		var open = document.querySelector( '.bn-header-user.' + OPEN_CLASS + ', .bn-block-notification-bell.' + OPEN_CLASS );

		if ( ! open ) {
			return;
		}

		setOpen( open, false );

		// Return focus to the control that opened it, so a keyboard user is not
		// dropped at the top of the document.
		var caret = toggleOf( root || open );
		if ( caret ) {
			caret.focus();
		}
	} );

	// Tabbing out of the widget closes it. Deferred one tick because at focusout
	// time document.activeElement is still the element being left.
	document.addEventListener( 'focusout', function ( event ) {
		var root = rootOf( event.target );

		if ( ! root || ! root.classList.contains( OPEN_CLASS ) ) {
			return;
		}

		window.setTimeout( function () {
			if ( ! root.contains( document.activeElement ) ) {
				setOpen( root, false );
			}
		}, 0 );
	} );
}() );
