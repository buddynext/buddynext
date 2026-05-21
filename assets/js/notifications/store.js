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
					/* Remove the unread tab counters — query the live DOM since
					   tabs sit outside this interactive region. */
					var counts = document.querySelectorAll( '.bn-tab__count, .bn-notif-badge' );
					counts.forEach( function ( el ) { el.hidden = true; } );
					/* Hide all unread pulse indicators. */
					var pulses = document.querySelectorAll( '.bn-notif-row__pulse' );
					pulses.forEach( function ( el ) { el.hidden = true; } );
					/* Strip the unread modifier so rows recolour. */
					var rows = document.querySelectorAll( '.bn-notif-row--unread' );
					rows.forEach( function ( el ) { el.classList.remove( 'bn-notif-row--unread' ); } );
				}
			} catch ( _e ) {}
		},

		markRead: async function ( event ) {
			var ctx      = getContext();
			var row      = event.target.closest( '[data-notif-id]' );
			var notifId  = row ? row.dataset.notifId : null;
			var linkUrl  = row ? row.dataset.notifLink : null;
			if ( ! notifId ) { return; }
			/* If the click landed on an inline action button, let its own
			   handler run and do not redirect. */
			if ( event.target.closest( '.bn-notif-row__actions' ) ) {
				return;
			}
			try {
				await fetch( ctx.restUrl + '/' + notifId + '/read', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
				} );
				var pulse = row.querySelector( '.bn-notif-row__pulse' );
				if ( pulse ) { pulse.hidden = true; }
				row.classList.remove( 'bn-notif-row--unread' );
			} catch ( _e ) {}
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
			try {
				await fetch( ctx.restUrl + '/' + notifId + '/read', {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
				} );
				if ( row ) {
					var pulse = row.querySelector( '.bn-notif-row__pulse' );
					if ( pulse ) { pulse.hidden = true; }
					row.classList.remove( 'bn-notif-row--unread' );
					if ( btn ) {
						var parent = btn.parentElement;
						btn.remove();
						if ( parent && 0 === parent.children.length ) {
							parent.remove();
						}
					}
				}
			} catch ( _e ) {}
		},
	},
} );
