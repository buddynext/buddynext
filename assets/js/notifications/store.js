/**
 * BuddyNext — Notifications Interactivity API store.
 *
 * Powers both the on-page list (`/notifications/`) and the mobile nav badge.
 * Exposes a reactive `state.unreadCount` so the badge updates live after
 * markAsRead / markAllRead without a page refresh, with optimistic UI and
 * rollback on 4xx.
 *
 * Companion service: includes/Notifications/NotificationMessageService.php.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';
import { onNavReady } from '../shell/nav-init.js';

// Relation-removal handler for the Muted-list sidecard's Unmute button
// (Pattern D-15). Side-effect import — installs a single document-level
// click listener that any data-bn-relation-remove button can use.
import '../social/relation-remove.js';

/**
 * Format an unread count for display: empty when zero, "99+" when over 99.
 *
 * @param {number} count Raw unread count.
 * @return {string} Display label.
 */
function formatBadge( count ) {
	if ( ! count || count <= 0 ) {
		return '';
	}
	if ( count > 99 ) {
		return '99+';
	}
	return String( count );
}

/**
 * Map a raw notification type (e.g. `bn.post_reacted`) to its filter-tab key.
 * Mirrors the server-side filter→type map in templates/notifications/index.php
 * so a single mark-read updates the right type tab too. Returns '' for types
 * with no dedicated tab.
 *
 * @param {string} type Raw notification type.
 * @return {string} Filter key, or '' when none.
 */
function filterKeyForType( type ) {
	var map = {
		'bn.post_reacted': 'reaction',
		'bn.post_commented': 'comment',
		'bn.mention': 'mention',
		'bn.new_follower': 'follow',
		'bn.connection_accepted': 'follow',
		'bn.connection_requested': 'follow',
		'bn.space_invite': 'space',
		'bn.space_join_requested': 'space',
		'bn.space_new_post': 'space',
		'bn.new_message': 'message',
	};
	return map[ type ] || '';
}

/**
 * Adjust the unread filter-tab count badges by `delta`.
 *
 * Every notification belongs to All + Unread, so both always move. When a row
 * type is supplied, its dedicated tab (Mentions / Reactions / Comments /
 * Spaces / …) is moved too, so every visible count stays in step without a
 * reload. The rail bell badge tracks ctx.unreadCount separately.
 *
 * @param {number} delta Amount to add (use -1 when marking one read).
 * @param {string} [type] Raw notification type, to also move its type tab.
 */
function adjustUnreadTabBadges( delta, type ) {
	var filters = [ 'all', 'unread' ];
	var typeKey = type ? filterKeyForType( type ) : '';
	if ( typeKey ) {
		filters.push( typeKey );
	}
	filters.forEach( function ( filter ) {
		var badge = document.querySelector( '.bn-tab[data-filter="' + filter + '"] .bn-tab__count' );
		if ( ! badge ) {
			return;
		}
		var next = Math.max( 0, ( parseInt( badge.textContent, 10 ) || 0 ) + delta );
		badge.textContent = String( next );
		badge.hidden = ( 0 === next );
	} );
}

/**
 * Minimal toast helper. Uses a global `window.bnToast` when the shell
 * provides one (assets/js/shell/extras.js), otherwise falls back to a
 * console warning so the rollback signal is at least visible to developers.
 *
 * @param {string} message Message body.
 * @param {string} tone    'success' | 'error' | 'info'.
 */
function toast( message, tone ) {
	if ( typeof window !== 'undefined' && typeof window.bnToast === 'function' ) {
		window.bnToast( message, tone );
		return;
	}
	if ( typeof window !== 'undefined' && window.console ) {
		window.console.warn( '[buddynext]', tone, message );
	}
}

/* popstate guard so browser back / forward navigates between filter tabs
 * without a full reload. The simplest correct behaviour is a full reload
 * here — partial-swap reflects the URL change on forward navigation, but
 * the simplest contract is that popstate restores the prior page state
 * from scratch. Attached once per page load. */
function bindPopState() {
	if ( typeof window === 'undefined' || window.__bnNotifPopstateBound ) { return; }
	window.__bnNotifPopstateBound = true;
	window.addEventListener( 'popstate', function () {
		// Only respond when our entry pushed the state — other pages on
		// this site own their own popstate semantics.
		if ( window.history.state && window.history.state.bnFilter ) {
			window.location.reload();
		}
	} );
}

onNavReady( bindPopState, { once: true } );

store( 'buddynext/notifications', {
	state: {
		get unreadLabel() {
			var ctx = getContext();
			return formatBadge( ctx && ctx.unreadCount ? ctx.unreadCount : 0 );
		},
		get badgeHidden() {
			var ctx = getContext();
			return ! ctx || ! ctx.unreadCount || ctx.unreadCount <= 0;
		},
		get hasError() {
			var ctx = getContext();
			return !! ( ctx && ctx.hasError );
		},
	},

	actions: {
		markAllRead: async function () {
			var ctx = getContext();
			if ( ! ctx || ! ctx.restUrl ) {
				return;
			}
			var previous = ctx.unreadCount || 0;
			// Optimistic update.
			ctx.unreadCount = 0;
			ctx.markedAll   = true;

			try {
				var res = await restFetch( '/read-all', {
					base: ctx.restUrl,
					nonce: ctx.nonce,
					method: 'POST',
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
				// Strip presentation state for every visible row.
				var counts = document.querySelectorAll( '.bn-tab__count, .bn-notif-badge' );
				counts.forEach( function ( el ) { el.hidden = true; } );
				var pulses = document.querySelectorAll( '.bn-notif-row__pulse' );
				pulses.forEach( function ( el ) { el.hidden = true; } );
				var rows = document.querySelectorAll( '.bn-notif-row--unread' );
				rows.forEach( function ( el ) { el.classList.remove( 'bn-notif-row--unread' ); } );
			} catch ( _e ) {
				// Rollback.
				ctx.unreadCount = previous;
				ctx.markedAll   = false;
				toast( 'Could not mark all as read.', 'error' );
			}
		},

		markRead: async function ( event ) {
			var ctx     = getContext();
			var row     = event.target.closest( '[data-notif-id]' );
			var notifId = row ? row.dataset.notifId : null;
			var linkUrl = row ? row.dataset.notifLink : null;
			if ( ! notifId ) { return; }
			// Inline action buttons own their own behaviour.
			if ( event.target.closest( '.bn-notif-row__actions' ) ) {
				return;
			}
			var wasUnread = row.classList.contains( 'bn-notif-row--unread' );
			var previous  = ctx ? ( ctx.unreadCount || 0 ) : 0;

			// Optimistic.
			if ( wasUnread ) {
				var pulse = row.querySelector( '.bn-notif-row__pulse' );
				if ( pulse ) { pulse.hidden = true; }
				row.classList.remove( 'bn-notif-row--unread' );
				if ( ctx && ctx.unreadCount > 0 ) {
					ctx.unreadCount = ctx.unreadCount - 1;
				}
				adjustUnreadTabBadges( -1, row.dataset.notifType );
			}

			try {
				var res = await restFetch( '/' + notifId + '/read', {
					base: ctx.restUrl,
					nonce: ctx.nonce,
					method: 'POST',
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
			} catch ( _e ) {
				if ( wasUnread ) {
					row.classList.add( 'bn-notif-row--unread' );
					var pulse2 = row.querySelector( '.bn-notif-row__pulse' );
					if ( pulse2 ) { pulse2.hidden = false; }
					if ( ctx ) {
						ctx.unreadCount = previous;
					}
					adjustUnreadTabBadges( 1, row ? row.dataset.notifType : '' );
				}
				toast( 'Could not mark this notification as read.', 'error' );
				return;
			}

			if ( linkUrl ) {
				window.location.href = linkUrl;
			}
		},

		markReadOnly: async function ( event ) {
			var ctx     = getContext();
			var btn     = event.target.closest( '[data-notif-id]' );
			var row     = event.target.closest( '.bn-notif-row' );
			var notifId = btn ? btn.dataset.notifId : null;
			if ( ! notifId ) { return; }
			event.stopPropagation();

			var wasUnread = row ? row.classList.contains( 'bn-notif-row--unread' ) : false;
			var previous  = ctx ? ( ctx.unreadCount || 0 ) : 0;

			if ( row && wasUnread ) {
				var pulse = row.querySelector( '.bn-notif-row__pulse' );
				if ( pulse ) { pulse.hidden = true; }
				row.classList.remove( 'bn-notif-row--unread' );
				if ( ctx && ctx.unreadCount > 0 ) {
					ctx.unreadCount = ctx.unreadCount - 1;
				}
				adjustUnreadTabBadges( -1, row.dataset.notifType );
				if ( btn ) {
					var parent = btn.parentElement;
					btn.remove();
					if ( parent && 0 === parent.children.length ) {
						parent.remove();
					}
				}
			}

			try {
				var res = await restFetch( '/' + notifId + '/read', {
					base: ctx.restUrl,
					nonce: ctx.nonce,
					method: 'POST',
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
			} catch ( _e ) {
				if ( ctx ) {
					ctx.unreadCount = previous;
				}
				if ( wasUnread ) {
					adjustUnreadTabBadges( 1, row ? row.dataset.notifType : '' );
				}
				toast( 'Could not mark this notification as read.', 'error' );
			}
		},

		dismiss: async function ( event ) {
			// Per-row delete — DELETE /me/notifications/{id}. Optimistically
			// removes the row from the DOM and decrements the badge if the
			// row was unread. Restores on error.
			var ctx     = getContext();
			var btn     = event.target.closest( '[data-notif-id]' );
			var row     = event.target.closest( '.bn-notif-row' );
			var notifId = btn ? btn.dataset.notifId : null;
			if ( ! notifId || ! row || ! ctx ) { return; }
			event.stopPropagation();

			var wasUnread = row.classList.contains( 'bn-notif-row--unread' );
			var previousUnread = ctx.unreadCount || 0;
			var parent = row.parentNode;
			var nextSibling = row.nextSibling;

			row.remove();
			if ( wasUnread && ctx.unreadCount > 0 ) {
				ctx.unreadCount = ctx.unreadCount - 1;
			}

			try {
				var res = await restFetch( '/' + notifId, {
					base: ctx.restUrl,
					nonce: ctx.nonce,
					method: 'DELETE',
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
			} catch ( _e ) {
				if ( parent ) {
					parent.insertBefore( row, nextSibling );
				}
				ctx.unreadCount = previousUnread;
				toast( 'Could not dismiss. Try again.', 'error' );
				return;
			}
		},

		// Accept a space invitation straight from its notification. POSTs to the
		// space join endpoint (which promotes the 'invited' row to active), marks
		// the notification read, and follows the notification link to the space.
		acceptSpaceInvite: async function ( event ) {
			var ctx     = getContext();
			var btn     = event.target.closest( '[data-object-id]' );
			var row     = event.target.closest( '.bn-notif-row' );
			if ( ! btn || ! row || ! ctx ) { return; }
			event.stopPropagation();

			var spaceId = btn.dataset.objectId;
			var notifId = btn.dataset.notifId;
			if ( ! spaceId ) { return; }

			var buttons = row.querySelectorAll( '.bn-notif-row__actions button' );
			for ( var i = 0; i < buttons.length; i++ ) { buttons[ i ].disabled = true; }

			// restUrl is the notifications collection (…/buddynext/v1/me/notifications);
			// reduce it to the buddynext/v1 root to reach the spaces endpoints.
			var apiBase = ctx.restUrl.replace( /(\/buddynext\/v1)\/.*$/, '$1' );

			try {
				var res  = await restFetch( '/spaces/' + spaceId + '/join', {
					base: apiBase,
					nonce: ctx.nonce,
					method: 'POST',
					toastOnError: false,
				} );
				var data = res.data || {};

				if ( res.ok && data.joined ) {
					if ( notifId ) {
						restFetch( '/' + notifId + '/read', { base: ctx.restUrl, nonce: ctx.nonce, method: 'POST', toastOnError: false } );
						if ( row.classList.contains( 'bn-notif-row--unread' ) ) {
							row.classList.remove( 'bn-notif-row--unread' );
							if ( ctx.unreadCount > 0 ) { ctx.unreadCount = ctx.unreadCount - 1; }
							adjustUnreadTabBadges( -1, row.dataset.notifType );
						}
					}
					toast( 'Invitation accepted — you have joined the space.', 'success' );
					if ( row.dataset.notifLink ) {
						window.location.href = row.dataset.notifLink;
					} else {
						row.remove();
					}
				} else {
					for ( var j = 0; j < buttons.length; j++ ) { buttons[ j ].disabled = false; }
					toast( ( data && data.message ) || 'Could not accept the invitation.', 'error' );
				}
			} catch ( _e ) {
				for ( var k = 0; k < buttons.length; k++ ) { buttons[ k ].disabled = false; }
				toast( 'Network error. Try again.', 'error' );
			}
		},

		// Decline a space invitation from its notification. POSTs to the space
		// leave endpoint (removes the 'invited' row), marks the notification read,
		// and removes the row.
		declineSpaceInvite: async function ( event ) {
			var ctx     = getContext();
			var btn     = event.target.closest( '[data-object-id]' );
			var row     = event.target.closest( '.bn-notif-row' );
			if ( ! btn || ! row || ! ctx ) { return; }
			event.stopPropagation();

			var spaceId = btn.dataset.objectId;
			var notifId = btn.dataset.notifId;
			if ( ! spaceId ) { return; }

			var buttons = row.querySelectorAll( '.bn-notif-row__actions button' );
			for ( var i = 0; i < buttons.length; i++ ) { buttons[ i ].disabled = true; }

			var apiBase = ctx.restUrl.replace( /(\/buddynext\/v1)\/.*$/, '$1' );

			try {
				var res = await restFetch( '/spaces/' + spaceId + '/leave', {
					base: apiBase,
					nonce: ctx.nonce,
					method: 'POST',
					toastOnError: false,
				} );

				if ( res.ok ) {
					if ( notifId ) {
						restFetch( '/' + notifId + '/read', { base: ctx.restUrl, nonce: ctx.nonce, method: 'POST', toastOnError: false } );
						if ( row.classList.contains( 'bn-notif-row--unread' ) && ctx.unreadCount > 0 ) {
							ctx.unreadCount = ctx.unreadCount - 1;
							adjustUnreadTabBadges( -1, row.dataset.notifType );
						}
					}
					toast( 'Invitation declined.', 'info' );
					row.remove();
				} else {
					for ( var j = 0; j < buttons.length; j++ ) { buttons[ j ].disabled = false; }
					toast( 'Could not decline the invitation.', 'error' );
				}
			} catch ( _e ) {
				for ( var k = 0; k < buttons.length; k++ ) { buttons[ k ].disabled = false; }
				toast( 'Network error. Try again.', 'error' );
			}
		},

		openAndMark: async function ( event ) {
			// Anchor's native navigation handles the URL; we only need to
			// fire the mark-as-read request so the badge updates before
			// the page transitions.
			var ctx     = getContext();
			var btn     = event.target.closest( '[data-notif-id]' );
			var notifId = btn ? btn.dataset.notifId : null;
			if ( ! notifId || ! ctx ) { return; }
			try {
				await restFetch( '/' + notifId + '/read', {
					base: ctx.restUrl,
					nonce: ctx.nonce,
					method: 'POST',
					toastOnError: false,
				} );
				if ( ctx.unreadCount > 0 ) {
					ctx.unreadCount = ctx.unreadCount - 1;
				}
			} catch ( _e ) {
				// Swallow — navigation will still occur. Worst case the
				// badge re-renders on the next page load.
			}
		},

		retry: async function () {
			var ctx = getContext();
			if ( ctx ) {
				ctx.hasError = false;
			}
			window.location.reload();
		},

		/**
		 * Reactive filter-tab switch — no full page reload.
		 *
		 * Click handler on each .bn-tab anchor. Suppresses native navigation,
		 * fetches the same URL with HX-Request: true so PageRouter returns the
		 * raw template content (no theme chrome), parses the response, and
		 * adopts the child nodes of the `[data-bn-notif-content]` region into
		 * the current document. Updates the URL via history.pushState so
		 * back/forward works, and keeps the Interactivity store's activeFilter
		 * in sync.
		 *
		 * Same-origin only — the response comes from our own PageRouter, which
		 * has already run every esc_* / esc_url / esc_html on the template.
		 * We never inject foreign HTML.
		 */
		setFilter: async function ( event ) {
			var ctx = getContext();
			var tab = event && event.target ? event.target.closest( '[data-filter]' ) : null;
			if ( ! tab || ! ctx ) { return; }

			// Let cmd/ctrl + click open in a new tab as a regular anchor.
			if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) { return; }
			event.preventDefault();

			var filter = tab.dataset.filter || 'all';
			if ( ctx.activeFilter === filter ) { return; }

			var href = tab.getAttribute( 'href' ) || '';
			if ( ! href ) { return; }

			// Mark the active tab immediately for visual feedback.
			var allTabs = document.querySelectorAll( '.bn-notif-tabs .bn-tab' );
			allTabs.forEach( function ( t ) {
				var isActive = ( t.dataset.filter === filter );
				t.classList.toggle( 'is-active', isActive );
				t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				t.setAttribute( 'aria-current', isActive ? 'page' : 'false' );
			} );

			ctx.activeFilter = filter;

			var contentEl = document.querySelector( '[data-bn-notif-content]' );
			if ( contentEl ) {
				contentEl.setAttribute( 'aria-busy', 'true' );
			}

			try {
				var res = await fetch( href, {
					method:  'GET',
					credentials: 'same-origin',
					headers: { 'HX-Request': 'true', 'X-Requested-With': 'XMLHttpRequest' },
				} );
				if ( ! res.ok ) { throw new Error( 'http_' + res.status ); }
				var html = await res.text();

				// Parse the same-origin partial in an inert document so no
				// script runs and resources do not pre-fetch. Adopt the
				// children of the fresh content region into the current page.
				var doc = ( new DOMParser() ).parseFromString( html, 'text/html' );
				var fresh = doc.querySelector( '[data-bn-notif-content]' );
				if ( fresh && contentEl ) {
					// Drain old children, then adopt new ones. Avoids
					// innerHTML so we never re-parse already-server-safe HTML
					// through the live document.
					while ( contentEl.firstChild ) {
						contentEl.removeChild( contentEl.firstChild );
					}
					var node = fresh.firstChild;
					while ( node ) {
						var next = node.nextSibling;
						contentEl.appendChild( document.adoptNode( node ) );
						node = next;
					}
				}

				// Replace URL without scroll jump.
				if ( window.history && window.history.pushState ) {
					window.history.pushState( { bnFilter: filter }, '', href );
				}

				// Pull the updated unread count from the new content's data
				// attribute (set below in the template) so the badge stays
				// in sync.
				var freshCount = contentEl ? contentEl.getAttribute( 'data-unread-count' ) : null;
				if ( freshCount !== null && ctx ) {
					ctx.unreadCount = Number( freshCount ) || 0;
				}
			} catch ( _e ) {
				// Fallback: full navigation.
				window.location.href = href;
				return;
			} finally {
				if ( contentEl ) {
					contentEl.removeAttribute( 'aria-busy' );
				}
			}
		},


		refreshUnreadCount: async function () {
			var ctx = getContext();
			if ( ! ctx || ! ctx.restUrl ) { return; }
			try {
				var res = await restFetch( '/unread-count', {
					base: ctx.restUrl,
					nonce: ctx.nonce || '',
					method: 'GET',
					toastOnError: false,
				} );
				if ( ! res.ok ) { return; }
				var json = res.data;
				if ( json && typeof json.count !== 'undefined' ) {
					ctx.unreadCount = Number( json.count ) || 0;
				}
			} catch ( _e ) {
				// Silent — the badge keeps showing the previous count.
			}
		},
	},
} );

/**
 * Background unread-count polling.
 *
 * C1 from the notifications completion walk. Keeps the mobile nav badge
 * (and any future header dropdown) in sync with server state without
 * relying on the user revisiting the /notifications/ page.
 *
 * Cadence:
 *   - 30s when the tab is idle
 *   - 5s for the first 60s after a user action ("hot" mode)
 *   - paused when document.hidden (browser tab in background)
 *
 * Pro Realtime (P3.1) dispatches a `bn:notification:new` CustomEvent on
 * window when Soketi pushes a new event; we kick the count refresh
 * immediately so Pro can pre-empt the poll. Free relies on the poll alone.
 */
function bootstrapNotifPolling() {
	if ( typeof window === 'undefined' || typeof document === 'undefined' ) { return; }
	if ( window.__bnNotifPollInstalled ) { return; }
	window.__bnNotifPollInstalled = true;

	var COLD_INTERVAL = 30000;
	var HOT_INTERVAL  = 5000;
	var HOT_DURATION  = 60000;

	var hotUntil = 0;
	var timerId  = null;
	// Last unread count seen by the poll, so a count increase (a newly-arrived
	// notification) can trigger the sound. Null until the first poll seeds it —
	// a page load with pre-existing unread items must not chime.
	var lastPolledCount = null;

	function findContext() {
		var wrap = document.querySelector( '[data-wp-interactive="buddynext/notifications"]' );
		return wrap || null;
	}

	function readRestData() {
		var wrap = findContext();
		if ( ! wrap ) { return null; }
		var raw = wrap.getAttribute( 'data-wp-context' );
		if ( ! raw ) { return null; }
		try {
			return JSON.parse( raw );
		} catch ( _e ) {
			return null;
		}
	}

	function paintBadge( count ) {
		// Mobile nav badge.
		var badge = document.querySelector( '.bn-mobile-nav__badge' );
		if ( badge ) {
			var label = count > 99 ? '99+' : String( count );
			badge.textContent = label;
			badge.hidden = ! count || count <= 0;
		}
		// Header pill (legacy bn-shell dropdown badge), if rendered by host theme.
		var pill = document.querySelector( '.bn-nav-notif-wrap .bn-nav-pill' );
		if ( pill ) {
			if ( count > 0 ) {
				pill.textContent = count > 99 ? '99+' : String( count );
				pill.hidden = false;
			} else {
				pill.hidden = true;
			}
		}
		// Any data-wp-text="state.unreadLabel" elements will re-render
		// when ctx.unreadCount is mutated by the store; this paint pass
		// covers no-Interactivity DOM (the header pill).
	}

	async function poll() {
		if ( document.hidden ) { return; }
		var ctx = readRestData();
		if ( ! ctx || ! ctx.restUrl ) { return; }

		try {
			var res = await restFetch( '/unread-count', {
				base: ctx.restUrl,
				nonce: ctx.nonce || '',
				method: 'GET',
				toastOnError: false,
			} );
			if ( ! res.ok ) { return; }
			var json = res.data;
			if ( ! json || typeof json.count === 'undefined' ) { return; }

			var fresh = Number( json.count ) || 0;
			paintBadge( fresh );

			// Play the notification sound when the poll detects newly-arrived
			// notifications (the count went up). bn:notification:new has no
			// producer on Free, so without this the "Play a sound" channel never
			// fired for poll-driven (non-realtime) notifications. The first poll
			// only seeds lastPolledCount so an initial load with existing unread
			// items doesn't chime.
			if ( null !== lastPolledCount && fresh > lastPolledCount ) {
				maybePlaySound();
			}
			lastPolledCount = fresh;

			// Sync the Interactivity context (in-place mutation) so
			// state.unreadLabel and state.badgeHidden re-evaluate.
			var wrap = findContext();
			if ( wrap ) {
				var current = readRestData();
				if ( current && current.unreadCount !== fresh ) {
					current.unreadCount = fresh;
					wrap.setAttribute( 'data-wp-context', JSON.stringify( current ) );
				}
			}
		} catch ( _e ) {
			// Network failure — try again next tick.
		}
	}

	function schedule() {
		if ( timerId ) { window.clearTimeout( timerId ); }
		var interval = ( Date.now() < hotUntil ) ? HOT_INTERVAL : COLD_INTERVAL;
		timerId = window.setTimeout( function () {
			poll().finally( schedule );
		}, interval );
	}

	function kickHot() {
		hotUntil = Date.now() + HOT_DURATION;
		// Reset the schedule so the next poll lands on the hot cadence.
		schedule();
	}

	// Pro realtime seam — dispatch `bn:notification:new` on window to refresh
	// immediately. Free has no producer; this is a no-op until Pro is active.
	//
	// Sound: when channels.sound === true and document.visible, play a soft
	// chime. The audio asset is optional — if assets/sounds/notif.mp3 is
	// missing we silently skip playback. Pro can override this seam by
	// listening to the same event and rendering its own banner / sound.
	window.addEventListener( 'bn:notification:new', function () {
		kickHot();
		poll();
		maybePlaySound();
	} );

	var soundEnabledCache = null;
	async function getSoundEnabled() {
		if ( null !== soundEnabledCache ) { return soundEnabledCache; }
		try {
			var ctx = readRestData();
			if ( ! ctx || ! ctx.restUrl ) { return false; }
			// restUrl points at /me/notifications — derive the channels URL.
			var base = ctx.restUrl.replace( /\/me\/notifications.*$/, '/me/notification-channels' );
			var res = await restFetch( '', {
				base: base,
				nonce: ctx.nonce || '',
				method: 'GET',
				toastOnError: false,
			} );
			if ( ! res.ok ) { soundEnabledCache = false; return false; }
			var json = res.data;
			soundEnabledCache = !! ( json && json.channels && json.channels.sound );
			return soundEnabledCache;
		} catch ( _e ) {
			soundEnabledCache = false;
			return false;
		}
	}

	function maybePlaySound() {
		if ( document.hidden ) { return; }
		getSoundEnabled().then( function ( enabled ) {
			if ( ! enabled ) { return; }
			try {
				// Optional asset — paths under wp-content. If the file is
				// 404 the browser logs a warning; we swallow the rejection.
				var url = ( window.bnShellData && window.bnShellData.notifSoundUrl ) || '';
				if ( ! url ) { return; }
				var audio = new Audio( url );
				audio.volume = 0.4;
				var p = audio.play();
				if ( p && typeof p.catch === 'function' ) {
					p.catch( function () { /* autoplay blocked — ignore */ } );
				}
			} catch ( _e ) {
				// no-op
			}
		} );
	}

	// Re-poll on tab focus so the badge is fresh the moment the user
	// returns to the app.
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			poll();
			schedule();
		}
	} );

	// Mark a user action as a hot trigger (clicks, keypresses) so the
	// next 60s of polling runs at 5s cadence.
	document.addEventListener( 'click', kickHot, { passive: true, capture: true } );

	// Bootstrap the polling schedule (singleton — installed once above).
	schedule();
}

onNavReady( bootstrapNotifPolling, { once: true } );
