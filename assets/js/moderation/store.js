/* BuddyNext — Moderation Interactivity API store.
 *
 * Powers both the site-wide moderation queue (moderation/queue.php) and
 * space-level moderation panel (spaces/moderation.php).
 */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm, bnToast } from '../shell/dialog.js';
import { restFetch } from '../shell/rest-client.js';

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
			// Real route: POST /reports/{id}/dismiss (no body).
			const res = yield restFetch( 'reports/' + ctx.reportId + '/dismiss', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				toastOnError: false,
			} );
			if ( res.ok ) {
				const row = document.querySelector( '[data-report-id="' + ctx.reportId + '"]' );
				if ( row ) { row.remove(); }
			} else {
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
			// Real route: POST /reports/{id}/remove — soft-removes the
			// content (status → removed) and resolves the report.
			const res = yield restFetch( 'reports/' + ctx.reportId + '/remove', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				toastOnError: false,
			} );
			if ( res.ok ) {
				const row = document.querySelector( '[data-report-id="' + ctx.reportId + '"]' );
				if ( row ) { row.remove(); }
				bnToast( 'Content removed.', { tone: 'success' } );
			} else {
				bnToast( 'Could not remove the content. Try again.', { tone: 'danger' } );
			}
		},

		* warnUser() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			// Real route: POST /users/{id}/warn { message } — logs the warning
			// and notifies the user (no strike penalty).
			const res = yield restFetch( 'users/' + ctx.userId + '/warn', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				body: { message: 'Content policy reminder' },
				toastOnError: false,
			} );
			bnToast( res.ok ? 'Warning sent.' : 'Could not warn the user.', { tone: res.ok ? 'success' : 'danger' } );
		},

		* strikeUser() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			// Real route: POST /users/{id}/strikes { reason }.
			const res = yield restFetch( 'users/' + ctx.userId + '/strikes', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				body: { reason: 'Strike issued for reported content' },
				toastOnError: false,
			} );
			bnToast( res.ok ? 'Strike issued.' : 'Could not issue a strike.', { tone: res.ok ? 'success' : 'danger' } );
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
			// Real route: POST /users/{id}/suspend { reason, duration_days, hide_posts }.
			const res = yield restFetch( 'users/' + ctx.userId + '/suspend', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				body: { reason: 'Moderation action', duration_days: 7, hide_posts: true },
				toastOnError: false,
			} );
			bnToast( res.ok ? 'User suspended for 7 days.' : 'Could not suspend the user.', { tone: res.ok ? 'success' : 'danger' } );
		},

		/* ── Space moderation actions ──────────────────────────────── */

		viewReportedPost() {
			const ctx = getContext();
			window.open( ctx.postUrl || '#', '_blank' );
		},

		* dismissReport() {
			const ctx = getContext();
			if ( ! ctx.reportId || ! ctx.restNonce ) { return; }
			const res = yield restFetch( 'reports/' + ctx.reportId + '/dismiss', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				toastOnError: false,
			} );
			if ( res.ok ) {
				const card = document.querySelector( '.bn-space-mod__report [data-report-id="' + ctx.reportId + '"]' );
				const row  = ( card && card.closest( '.bn-space-mod__report' ) ) || document.querySelector( '[data-report-id="' + ctx.reportId + '"]' );
				if ( row ) { row.remove(); }
			}
		},

		* warnMember() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			yield restFetch( 'users/' + ctx.userId + '/warn', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				body: { message: 'Space rule violation' },
				toastOnError: false,
			} );
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
			yield restFetch( 'spaces/' + ctx.spaceId + '/members/' + ctx.userId, {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'DELETE',
				toastOnError: false,
			} );
			window.location.reload();
		},

		* approveJoinRequest() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.spaceId || ! ctx.restNonce ) { return; }
			yield restFetch( 'spaces/' + ctx.spaceId + '/members/' + ctx.userId + '/approve', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'PUT',
				toastOnError: false,
			} );
			window.location.reload();
		},

		* declineJoinRequest() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.spaceId || ! ctx.restNonce ) { return; }
			yield restFetch( 'spaces/' + ctx.spaceId + '/members/' + ctx.userId, {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'DELETE',
				toastOnError: false,
			} );
			window.location.reload();
		},
	},
} );
