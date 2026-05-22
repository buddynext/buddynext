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
				var res = await fetch( ctx.restUrl + '/read-all', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
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
			}

			try {
				var res = await fetch( ctx.restUrl + '/' + notifId + '/read', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
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
				if ( btn ) {
					var parent = btn.parentElement;
					btn.remove();
					if ( parent && 0 === parent.children.length ) {
						parent.remove();
					}
				}
			}

			try {
				var res = await fetch( ctx.restUrl + '/' + notifId + '/read', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
				} );
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
			} catch ( _e ) {
				if ( ctx ) {
					ctx.unreadCount = previous;
				}
				toast( 'Could not mark this notification as read.', 'error' );
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
				await fetch( ctx.restUrl + '/' + notifId + '/read', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
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

		refreshUnreadCount: async function () {
			var ctx = getContext();
			if ( ! ctx || ! ctx.restUrl ) { return; }
			try {
				var res = await fetch( ctx.restUrl + '/unread-count', {
					method:  'GET',
					headers: { 'X-WP-Nonce': ctx.nonce || '' },
				} );
				if ( ! res.ok ) { return; }
				var json = await res.json();
				if ( json && typeof json.count !== 'undefined' ) {
					ctx.unreadCount = Number( json.count ) || 0;
				}
			} catch ( _e ) {
				// Silent — the badge keeps showing the previous count.
			}
		},
	},
} );
