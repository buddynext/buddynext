/* BuddyNext — Moderation Interactivity API store.
 *
 * Powers both the site-wide moderation queue (moderation/queue.php) and
 * space-level moderation panel (spaces/moderation.php).
 */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm } from '../shell/dialog.js';

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

		* removeContent() {
			const ctx = getContext();
			if ( ! ctx.reportId || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: 'Remove this content?',
				body: 'The reported item will be removed from public view.',
				confirmLabel: 'Remove',
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			try {
				const res = yield fetch( ctx.restUrl + 'reports/' + ctx.reportId, {
					method: 'PUT',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { action: 'remove' } ),
				} );
				if ( res.ok ) {
					const row = document.querySelector( '[data-report-id="' + ctx.reportId + '"]' );
					if ( row ) { row.remove(); }
				}
			} catch ( _e ) {}
		},

		* warnUser() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			try {
				yield fetch( ctx.restUrl + 'users/' + ctx.userId + '/warn', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { message: 'Content policy violation' } ),
				} );
			} catch ( _e ) {}
		},

		* strikeUser() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			try {
				yield fetch( ctx.restUrl + 'users/' + ctx.userId + '/warn', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { message: 'Strike issued', strike: true } ),
				} );
			} catch ( _e ) {}
		},

		* suspendUser() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: 'Suspend this user?',
				body: 'They will be unable to post or interact for 7 days.',
				confirmLabel: 'Suspend',
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			try {
				yield fetch( ctx.restUrl + 'users/' + ctx.userId + '/suspend', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { reason: 'Moderation action', duration_days: 7 } ),
				} );
			} catch ( _e ) {}
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
