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

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_notifications) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/notifications namespace below; each lookup keeps the English
 * literal as a fallback so the UI never breaks if the state is absent. fmt()
 * fills sprintf-style '%s'/'%d' placeholders. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( vals[ pos ? pos - 1 : i++ ] ?? '' ) ); }

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
 * Adjust the reactive unread filter-tab counts by `delta`.
 *
 * Every notification belongs to All + Unread, so both always move. When a row
 * type is supplied, its dedicated tab (Mentions / Reactions / Comments /
 * Spaces / …) is moved too, so every visible count stays in step without a
 * reload. Mutating ctx.tabCounts re-renders the tab badges via the reactive
 * data-wp-text / data-wp-bind--hidden bindings on the filter bar — no DOM
 * paint loop. The rail bell badge tracks ctx.unreadCount separately.
 *
 * @param {Object} ctx    Interactivity context (carries tabCounts).
 * @param {number} delta  Amount to add (use -1 when marking one read).
 * @param {string} [type] Raw notification type, to also move its type tab.
 */
function adjustUnreadTabBadges( ctx, delta, type ) {
	if ( ! ctx || ! ctx.tabCounts ) {
		return;
	}
	var filters = [ 'all', 'unread' ];
	var typeKey = type ? filterKeyForType( type ) : '';
	if ( typeKey ) {
		filters.push( typeKey );
	}
	filters.forEach( function ( filter ) {
		var current = parseInt( ctx.tabCounts[ filter ], 10 ) || 0;
		ctx.tabCounts[ filter ] = Math.max( 0, current + delta );
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

const notificationsStore = store( 'buddynext/notifications', {
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
		// Filter-bar tab derived state. Each .bn-tab carries its own
		// { tabKey } context; these getters resolve that key against the
		// shared activeFilter + tabCounts so the active highlight and unread
		// badge are reactive (no querySelector paint, no setFilter handler —
		// the real <a href> routes through the shell's navigate action).
		get tabIsActive() {
			var ctx = getContext();
			return !! ( ctx && ctx.tabKey && ctx.activeFilter === ctx.tabKey );
		},
		get tabCountLabel() {
			var ctx = getContext();
			if ( ! ctx || ! ctx.tabKey || ! ctx.tabCounts ) { return ''; }
			var n = parseInt( ctx.tabCounts[ ctx.tabKey ], 10 ) || 0;
			return n > 99 ? '99+' : String( n );
		},
		get tabCountHidden() {
			var ctx = getContext();
			if ( ! ctx || ! ctx.tabKey || ! ctx.tabCounts ) { return true; }
			return ( parseInt( ctx.tabCounts[ ctx.tabKey ], 10 ) || 0 ) <= 0;
		},
	},

	actions: {
		markAllRead: async function () {
			var ctx = getContext();
			if ( ! ctx || ! ctx.restUrl ) {
				return;
			}
			var previous     = ctx.unreadCount || 0;
			var prevCounts   = ctx.tabCounts ? JSON.parse( JSON.stringify( ctx.tabCounts ) ) : null;
			// Optimistic update. Zeroing tabCounts re-renders every tab badge
			// reactively via the filter bar's data-wp-text bindings.
			ctx.unreadCount = 0;
			ctx.markedAll   = true;
			if ( ctx.tabCounts ) {
				Object.keys( ctx.tabCounts ).forEach( function ( key ) {
					ctx.tabCounts[ key ] = 0;
				} );
			}

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
				// Clear the per-row unread presentation on the visible list.
				// These rows are server-rendered (not in the reactive context),
				// so toggling their unread class/pulse here is the legitimate
				// path — the badge counts are already reactive above.
				var pulses = document.querySelectorAll( '.bn-notif-row__pulse' );
				pulses.forEach( function ( el ) { el.hidden = true; } );
				var rows = document.querySelectorAll( '.bn-notif-row--unread' );
				rows.forEach( function ( el ) { el.classList.remove( 'bn-notif-row--unread' ); } );
			} catch ( _e ) {
				// Rollback.
				ctx.unreadCount = previous;
				ctx.markedAll   = false;
				if ( prevCounts ) {
					ctx.tabCounts = prevCounts;
				}
				toast( t( 'markAllReadFailed', 'Could not mark all as read.' ), 'error' );
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
				adjustUnreadTabBadges( ctx, -1, row.dataset.notifType );
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
					adjustUnreadTabBadges( ctx, 1, row ? row.dataset.notifType : '' );
				}
				toast( t( 'markReadFailed', 'Could not mark this notification as read.' ), 'error' );
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
				adjustUnreadTabBadges( ctx, -1, row.dataset.notifType );
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
					adjustUnreadTabBadges( ctx, 1, row ? row.dataset.notifType : '' );
				}
				toast( t( 'markReadFailed', 'Could not mark this notification as read.' ), 'error' );
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
				toast( t( 'dismissFailed', 'Could not dismiss. Try again.' ), 'error' );
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
							adjustUnreadTabBadges( ctx, -1, row.dataset.notifType );
						}
					}
					toast( t( 'inviteAccepted', 'Invitation accepted — you have joined the space.' ), 'success' );
					if ( row.dataset.notifLink ) {
						window.location.href = row.dataset.notifLink;
					} else {
						row.remove();
					}
				} else {
					for ( var j = 0; j < buttons.length; j++ ) { buttons[ j ].disabled = false; }
					toast( ( data && data.message ) || t( 'inviteAcceptFailed', 'Could not accept the invitation.' ), 'error' );
				}
			} catch ( _e ) {
				for ( var k = 0; k < buttons.length; k++ ) { buttons[ k ].disabled = false; }
				toast( t( 'networkError', 'Network error. Try again.' ), 'error' );
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
							adjustUnreadTabBadges( ctx, -1, row.dataset.notifType );
						}
					}
					toast( t( 'inviteDeclined', 'Invitation declined.' ), 'info' );
					row.remove();
				} else {
					for ( var j = 0; j < buttons.length; j++ ) { buttons[ j ].disabled = false; }
					toast( t( 'inviteDeclineFailed', 'Could not decline the invitation.' ), 'error' );
				}
			} catch ( _e ) {
				for ( var k = 0; k < buttons.length; k++ ) { buttons[ k ].disabled = false; }
				toast( t( 'networkError', 'Network error. Try again.' ), 'error' );
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

		// Filter-tab switching is handled entirely by the shell's `navigate`
		// action (assets/js/shell/navigate.js): each .bn-tab is a real <a href>
		// inside the data-wp-router-region="buddynext/main" region, so a click
		// swaps that region via the Interactivity Router and the server
		// re-renders the active tab + counts. The previous hand-rolled
		// fetch + DOMParser + pushState SPA router was removed in favour of it.

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

I18N = ( notificationsStore.state && notificationsStore.state.i18n ) || {};

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

	// Resolve the REST base + nonce for the unread-count poll. On the
	// /notifications/ page this reads the Interactivity wrapper's context
	// (so paintBadge can also mutate ctx.unreadCount and re-render the list's
	// reactive badges). Everywhere else that wrapper is absent, so fall back to
	// window.bnShellData — present on every hub — which lets the desktop header
	// bell badge stay fresh site-wide, not just on the notifications page.
	function readRestData() {
		var wrap = findContext();
		if ( wrap ) {
			var raw = wrap.getAttribute( 'data-wp-context' );
			if ( raw ) {
				try {
					return JSON.parse( raw );
				} catch ( _e ) {
					// Fall through to the shell-data fallback below.
				}
			}
		}

		var shell = ( typeof window !== 'undefined' && window.bnShellData ) || null;
		if ( ! shell ) { return null; }
		// restNotifsUrl is the notifications collection with a query string
		// (…/me/notifications?per_page=5); strip the query to get the base the
		// restFetch '/unread-count' path is appended to.
		var base = ( shell.restNotifsUrl || '' ).split( '?' )[ 0 ];
		if ( ! base ) { return null; }
		return { restUrl: base, nonce: shell.restNonce || '' };
	}

	function paintBadge( count ) {
		var label = count > 99 ? '99+' : String( count );

		// Mobile nav badge.
		var badge = document.querySelector( '.bn-mobile-nav__badge' );
		if ( badge ) {
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
		// Desktop header bell badge (templates/blocks/notification-bell.php).
		// The badge element is only server-rendered when the count is > 0, so it
		// is updated in place when present, hidden when the count drops to 0, and
		// created inside the bell link when a count first arrives — keeping it
		// live site-wide rather than only on the /notifications/ page.
		var bellLink = document.querySelector( '.bn-notification-bell-link' );
		if ( bellLink ) {
			var bellBadge = bellLink.querySelector( '.bn-notification-badge' );
			if ( count > 0 ) {
				if ( ! bellBadge ) {
					bellBadge = document.createElement( 'span' );
					bellBadge.className = 'bn-badge bn-notification-badge';
					bellBadge.setAttribute( 'data-tone', 'danger' );
					bellBadge.setAttribute( 'aria-hidden', 'true' );
					bellLink.appendChild( bellBadge );
				}
				bellBadge.textContent = label;
				bellBadge.hidden = false;
			} else if ( bellBadge ) {
				bellBadge.hidden = true;
			}
		}
		// Any data-wp-text="state.unreadLabel" elements will re-render
		// when ctx.unreadCount is mutated by the store; this paint pass
		// covers no-Interactivity DOM (the header pill + bell badge).
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
