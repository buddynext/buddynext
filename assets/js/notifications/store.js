/* BuddyNext — Notifications Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';

store( 'buddynext/notifications', {
	actions: {
		markAllRead: async function () {
			var ctx = getContext();
			try {
				var res = await fetch( ctx.restUrl + '/read-all', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
				} );
				if ( res.ok ) {
					ctx.markedAll = true;
					/* Remove the badge from the nav — query the live DOM since it is
					   outside this interactive region. */
					var badges = document.querySelectorAll( '.bn-ntab-badge, .bn-notif-badge' );
					badges.forEach( function ( el ) { el.textContent = ''; el.hidden = true; } );
					/* Hide all unread dots inline. */
					var dots = document.querySelectorAll( '.bn-notif-unread-dot, [aria-label="Unread"]' );
					dots.forEach( function ( el ) { el.hidden = true; } );
				}
			} catch ( _e ) {}
		},

		markRead: async function ( event ) {
			var ctx      = getContext();
			var row      = event.target.closest( '[data-notif-id]' );
			var notifId  = row ? row.dataset.notifId : null;
			var linkUrl  = row ? row.dataset.notifLink : null;
			if ( ! notifId ) { return; }
			try {
				await fetch( ctx.restUrl + '/' + notifId + '/read', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
				} );
				var dot = row.querySelector( '.bn-notif-unread-dot, [aria-label="Unread"]' );
				if ( dot ) { dot.hidden = true; }
			} catch ( _e ) {}
			if ( linkUrl ) {
				window.location.href = linkUrl;
			}
		},
	},
} );
