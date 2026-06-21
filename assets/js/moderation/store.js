/* BuddyNext — Moderation Interactivity API store.
 *
 * Powers both the site-wide moderation queue (moderation/queue.php) and
 * space-level moderation panel (spaces/moderation.php).
 */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm, bnToast } from '../shell/dialog.js';
import { restFetch } from '../shell/rest-client.js';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_moderation) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/moderation namespace below and shared by every store in this file;
 * each lookup keeps the English literal as a fallback so the UI never breaks if
 * the state is absent. fmt() fills sprintf-style '%s'/'%d' placeholders. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( vals[ pos ? pos - 1 : i++ ] ?? '' ) ); }

const moderationStore = store( 'buddynext/moderation', {
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
				bnToast( t( 'dismissFailed', 'Could not dismiss the report. Try again.' ), { tone: 'danger' } );
			}
		},

		* removeContent() {
			const ctx = getContext();
			if ( ! ctx.reportId || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: t( 'removeContentTitle', 'Remove this content?' ),
				body: t( 'removeContentBody', 'The reported item will be taken down from public view and the report marked resolved.' ),
				confirmLabel: t( 'removeLabel', 'Remove' ),
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
				bnToast( t( 'contentRemoved', 'Content removed.' ), { tone: 'success' } );
			} else {
				bnToast( t( 'removeContentFailed', 'Could not remove the content. Try again.' ), { tone: 'danger' } );
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
			bnToast( res.ok ? t( 'warningSent', 'Warning sent.' ) : t( 'warnUserFailed', 'Could not warn the user.' ), { tone: res.ok ? 'success' : 'danger' } );
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
			bnToast( res.ok ? t( 'strikeIssued', 'Strike issued.' ) : t( 'strikeUserFailed', 'Could not issue a strike.' ), { tone: res.ok ? 'success' : 'danger' } );
		},

		* suspendUser() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: t( 'suspendUserTitle', 'Suspend this user?' ),
				body: t( 'suspendUserBody', 'They will be unable to post or interact for 7 days, and their posts will be hidden.' ),
				confirmLabel: t( 'suspendLabel', 'Suspend' ),
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
			bnToast( res.ok ? t( 'userSuspended', 'User suspended for 7 days.' ) : t( 'suspendUserFailed', 'Could not suspend the user.' ), { tone: res.ok ? 'success' : 'danger' } );
		},

		/* ── Account-status (member-facing) ────────────────────────── */

		* submitAppeal( event ) {
			if ( event && event.preventDefault ) { event.preventDefault(); }
			const ctx     = getContext();
			const field   = document.getElementById( 'bn-acct-appeal-msg' );
			const message = field ? field.value.trim() : '';
			if ( ! ctx.suspensionId || ! ctx.restNonce ) { return; }
			if ( message.length < 10 ) {
				bnToast( t( 'appealTooShort', 'Please describe why you are appealing (at least 10 characters).' ), { tone: 'danger' } );
				if ( field ) { field.focus(); }
				return;
			}
			// Real route: POST /appeals { suspension_id, message }.
			const res = yield restFetch( 'appeals', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				body: { suspension_id: ctx.suspensionId, message },
				toastOnError: false,
			} );
			if ( res.ok ) {
				bnToast( t( 'appealSubmitted', 'Your appeal has been submitted.' ), { tone: 'success' } );
				// Reload so the banner re-renders in its "under review" state.
				window.location.reload();
			} else {
				const emsg = ( res.data && res.data.message ) ? res.data.message : t( 'appealSubmitFailed', 'Could not submit your appeal. Try again.' );
				bnToast( emsg, { tone: 'danger' } );
			}
		},

		/* ── Appeal review (community-admin Appeals tab) ───────────── */

		* approveAppeal() {
			const ctx = getContext();
			if ( ! ctx.appealId || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: t( 'approveAppealTitle', 'Approve this appeal?' ),
				body: t( 'approveAppealBody', 'The member’s suspension will be lifted and they will be notified.' ),
				confirmLabel: t( 'approveLabel', 'Approve' ),
			} );
			if ( ! ok ) { return; }
			// Real route: POST /appeals/{id}/resolve { decision }.
			const res = yield restFetch( 'appeals/' + ctx.appealId + '/resolve', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				body: { decision: 'approved' },
				toastOnError: false,
			} );
			if ( res.ok ) {
				bnToast( t( 'appealApproved', 'Appeal approved — suspension lifted.' ), { tone: 'success' } );
				const row = document.querySelector( '[data-appeal-id="' + ctx.appealId + '"]' );
				if ( row ) { row.remove(); }
			} else {
				const emsg = ( res.data && res.data.message ) ? res.data.message : t( 'approveAppealFailed', 'Could not approve the appeal. Try again.' );
				bnToast( emsg, { tone: 'danger' } );
			}
		},

		* denyAppeal() {
			const ctx = getContext();
			if ( ! ctx.appealId || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: t( 'denyAppealTitle', 'Deny this appeal?' ),
				body: t( 'denyAppealBody', 'The suspension stays in place. The member will be notified of the decision.' ),
				confirmLabel: t( 'denyLabel', 'Deny' ),
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			const res = yield restFetch( 'appeals/' + ctx.appealId + '/resolve', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				body: { decision: 'denied' },
				toastOnError: false,
			} );
			if ( res.ok ) {
				bnToast( t( 'appealDenied', 'Appeal denied.' ), { tone: 'success' } );
				const row = document.querySelector( '[data-appeal-id="' + ctx.appealId + '"]' );
				if ( row ) { row.remove(); }
			} else {
				const emsg = ( res.data && res.data.message ) ? res.data.message : t( 'denyAppealFailed', 'Could not deny the appeal. Try again.' );
				bnToast( emsg, { tone: 'danger' } );
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
				title: t( 'removeFromSpaceTitle', 'Remove this member from the space?' ),
				body: t( 'removeFromSpaceBody', 'They will lose access to this space immediately.' ),
				confirmLabel: t( 'removeLabel', 'Remove' ),
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

I18N = ( moderationStore.state && moderationStore.state.i18n ) || {};
