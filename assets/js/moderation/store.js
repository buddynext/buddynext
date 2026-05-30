/* BuddyNext — Moderation Interactivity API store.
 *
 * Powers both the site-wide moderation queue (moderation/queue.php) and
 * space-level moderation panel (spaces/moderation.php).
 */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm, bnToast } from '../shell/dialog.js';

store( 'buddynext/moderation', {
	actions: {
		/* ── Site-wide queue actions ────────────────────────────────── */

		viewObject() {
			const ctx = getContext();
			const url = ctx.objectUrl || '#';
			window.open( url, '_blank' );
		},

		viewInContext() {
			const ctx = getContext();
			window.location.href = ctx.contextUrl || '#';
		},

		applySort( event ) {
			const val = event.target.value || event.target.dataset.sort;
			if ( val ) {
				const url = new URL( window.location.href );
				url.searchParams.set( 'sort', val );
				window.location.href = url.toString();
			}
		},

		* dismiss() {
			const ctx = getContext();
			if ( ! ctx.reportId || ! ctx.restNonce ) { return; }
			try {
				// Real route: POST /reports/{id}/dismiss (no body).
				const res = yield fetch( ctx.restUrl + 'reports/' + ctx.reportId + '/dismiss', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
				} );
				if ( res.ok ) {
					const row = document.querySelector( '[data-report-id="' + ctx.reportId + '"]' );
					if ( row ) { row.remove(); }
				} else {
					bnToast( 'Could not dismiss the report. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not dismiss the report. Try again.', { tone: 'danger' } );
			}
		},

		* removeContent() {
			const ctx = getContext();
			if ( ! ctx.reportId || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: 'Remove this content?',
				body: 'The reported item will be taken down from public view and the report marked resolved.',
				confirmLabel: 'Remove',
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			try {
				// Real route: POST /reports/{id}/remove — soft-removes the
				// content (status → removed) and resolves the report.
				const res = yield fetch( ctx.restUrl + 'reports/' + ctx.reportId + '/remove', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
				} );
				if ( res.ok ) {
					const row = document.querySelector( '[data-report-id="' + ctx.reportId + '"]' );
					if ( row ) { row.remove(); }
					bnToast( 'Content removed.', { tone: 'success' } );
				} else {
					bnToast( 'Could not remove the content. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not remove the content. Try again.', { tone: 'danger' } );
			}
		},

		* warnUser() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			try {
				// Real route: POST /users/{id}/warn { message } — logs the warning
				// and notifies the user (no strike penalty).
				const res = yield fetch( ctx.restUrl + 'users/' + ctx.userId + '/warn', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { message: 'Content policy reminder' } ),
				} );
				bnToast( res.ok ? 'Warning sent.' : 'Could not warn the user.', { tone: res.ok ? 'success' : 'danger' } );
			} catch ( _e ) {
				bnToast( 'Could not warn the user.', { tone: 'danger' } );
			}
		},

		* strikeUser() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			try {
				// Real route: POST /users/{id}/strikes { reason }.
				const res = yield fetch( ctx.restUrl + 'users/' + ctx.userId + '/strikes', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { reason: 'Strike issued for reported content' } ),
				} );
				bnToast( res.ok ? 'Strike issued.' : 'Could not issue a strike.', { tone: res.ok ? 'success' : 'danger' } );
			} catch ( _e ) {
				bnToast( 'Could not issue a strike.', { tone: 'danger' } );
			}
		},

		* suspendUser() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: 'Suspend this user?',
				body: 'They will be unable to post or interact for 7 days, and their posts will be hidden.',
				confirmLabel: 'Suspend',
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			try {
				// Real route: POST /users/{id}/suspend { reason, duration_days, hide_posts }.
				const res = yield fetch( ctx.restUrl + 'users/' + ctx.userId + '/suspend', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { reason: 'Moderation action', duration_days: 7, hide_posts: true } ),
				} );
				bnToast( res.ok ? 'User suspended for 7 days.' : 'Could not suspend the user.', { tone: res.ok ? 'success' : 'danger' } );
			} catch ( _e ) {
				bnToast( 'Could not suspend the user.', { tone: 'danger' } );
			}
		},

		/* ── Space moderation actions ──────────────────────────────── */

		viewReportedPost() {
			const ctx = getContext();
			window.open( ctx.postUrl || '#', '_blank' );
		},

		* dismissReport() {
			const ctx = getContext();
			if ( ! ctx.reportId || ! ctx.restNonce ) { return; }
			try {
				const res = yield fetch( ctx.restUrl + 'reports/' + ctx.reportId, {
					method: 'PUT',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { action: 'dismiss' } ),
				} );
				if ( res.ok ) {
					const row = document.querySelector( '[data-report-id="' + ctx.reportId + '"]' );
					if ( row ) { row.remove(); }
				}
			} catch ( _e ) {}
		},

		* warnMember() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			try {
				yield fetch( ctx.restUrl + 'users/' + ctx.userId + '/warn', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { message: 'Space rule violation' } ),
				} );
			} catch ( _e ) {}
		},

		* removeFromSpace() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.spaceId || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: 'Remove this member from the space?',
				body: 'They will lose access to this space immediately.',
				confirmLabel: 'Remove',
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			try {
				yield fetch( ctx.restUrl + 'spaces/' + ctx.spaceId + '/members/' + ctx.userId, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				window.location.reload();
			} catch ( _e ) {}
		},

		* approveJoinRequest() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.spaceId || ! ctx.restNonce ) { return; }
			try {
				yield fetch( ctx.restUrl + 'spaces/' + ctx.spaceId + '/members/' + ctx.userId + '/approve', {
					method: 'PUT',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				window.location.reload();
			} catch ( _e ) {}
		},

		* declineJoinRequest() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.spaceId || ! ctx.restNonce ) { return; }
			try {
				yield fetch( ctx.restUrl + 'spaces/' + ctx.spaceId + '/members/' + ctx.userId, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				window.location.reload();
			} catch ( _e ) {}
		},
	},
} );
