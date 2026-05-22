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

/* popstate guard so browser back / forward navigates between filter tabs
 * without a full reload. The simplest correct behaviour is a full reload
 * here — partial-swap reflects the URL change on forward navigation, but
 * the simplest contract is that popstate restores the prior page state
 * from scratch. Attached once per page load. */
var popstateBound = false;
function bindPopState() {
	if ( popstateBound || typeof window === 'undefined' ) { return; }
	popstateBound = true;
	window.addEventListener( 'popstate', function () {
		// Only respond when our entry pushed the state — other pages on
		// this site own their own popstate semantics.
		if ( window.history.state && window.history.state.bnFilter ) {
			window.location.reload();
		}
	} );
}

if ( typeof window !== 'undefined' && 'complete' === document.readyState ) {
	bindPopState();
} else if ( typeof window !== 'undefined' ) {
	window.addEventListener( 'DOMContentLoaded', bindPopState );
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
