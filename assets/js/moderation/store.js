/* BuddyNext — Moderation Interactivity API store.
 *
 * Powers both the site-wide moderation queue (moderation/queue.php) and
 * space-level moderation panel (spaces/moderation.php).
 */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm, bnToast } from '@buddynext/shell-dialog';
import { restFetch } from '@buddynext/rest-client';

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

	/* ── Derived state: the offender's live strike standing ─────────────────
	 *
	 * Each queue row's context carries `strikes` — the offender's active
	 * (non-reversed) strike count, seeded server-side by the queue's enrich pass.
	 * strikeUser() and reverseStrike() write it back, so the strike dots, the
	 * count label, and the Reverse control all track the real standing without a
	 * reload. Interactivity directives cannot evaluate `!` or `>=` inline, so the
	 * booleans are derived here; getContext() resolves to the row the directive
	 * lives in. ────────────────────────────────────────────────────────────── */
	state: {
		get strikeCount() {
			const ctx = getContext();
			return Math.max( 0, parseInt( ( ctx && ctx.strikes ) || 0, 10 ) || 0 );
		},
		// Hides the strike dots, the count label and the Reverse control for a
		// member with a clean record. `hidden` is a real DOM property, so a plain
		// boolean is correct here.
		get noStrikes() { return moderationStore.state.strikeCount < 1; },
		/* The dot getters return true|null, NOT true|false. `data-active` is a
		 * data-* attribute, and Preact only removes those when the bound value is
		 * null/undefined — a literal `false` is written out as data-active="false",
		 * which still matches the CSS [data-active] rule and would paint every dot
		 * red. null is the only value that clears the attribute. */
		get strikeDot1() { return moderationStore.state.strikeCount >= 1 || null; },
		get strikeDot2() { return moderationStore.state.strikeCount >= 2 || null; },
		get strikeDot3() { return moderationStore.state.strikeCount >= 3 || null; },
		get strikeCountLabel() {
			const n = moderationStore.state.strikeCount;
			return fmt( 1 === n ? t( 'strikeCountOne', '%d strike' ) : t( 'strikeCountOther', '%d strikes' ), n );
		},
		get reverseStrikeAria() {
			return fmt( t( 'reverseStrikeAria', 'Reverse the most recent strike (%d active)' ), moderationStore.state.strikeCount );
		},
	},

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
			// Real route: POST /users/{id}/warn { message, space_id } — logs the
			// warning and notifies the user (no strike penalty). space_id carries
			// the space the report came from: a site admin does not need it, but a
			// space moderator's authority to warn is scoped to their own space, so
			// the endpoint would 403 without it.
			const res = yield restFetch( 'users/' + ctx.userId + '/warn', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				body: { message: 'Content policy reminder', space_id: ctx.spaceId || 0 },
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
			if ( res.ok ) {
				// Reflect the new standing so the row's strike dots/count update and
				// the Reverse control appears — the admin who just struck someone by
				// mistake must be able to undo it without hunting for a reload.
				ctx.strikes = ( parseInt( ctx.strikes || 0, 10 ) || 0 ) + 1;
			}
			bnToast( res.ok ? t( 'strikeIssued', 'Strike issued.' ) : t( 'strikeUserFailed', 'Could not issue a strike.' ), { tone: res.ok ? 'success' : 'danger' } );
		},

		/**
		 * Reverse (undo) the offender's most recent active strike.
		 *
		 * The counterpart to strikeUser. Both the read and the write already exist
		 * at the REST layer — nothing new is registered here:
		 *   GET  /users/{id}/strikes               → { count, strikes[] }, newest first,
		 *                                            non-reversed only (ModerationService::get_strikes)
		 *   POST /users/{id}/strikes/{sid}/reverse → { reversed: true }
		 * The list call is what resolves {sid}: the queue row knows the offender and
		 * the count, not the individual strike IDs.
		 *
		 * No confirm dialog on purpose. This is a corrective, non-destructive action
		 * (it clears a sanction) — gating it behind a scary "are you sure" would
		 * misrepresent its weight. Both outcomes are toasted because this action
		 * opts out of restFetch's shared error toast.
		 */
		* reverseStrike() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }

			const list = yield restFetch( 'users/' + ctx.userId + '/strikes', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				toastOnError: false,
			} );

			if ( ! list.ok ) {
				const lmsg = ( list.data && list.data.message ) ? list.data.message : t( 'reverseStrikeFailed', 'Could not reverse the strike. Try again.' );
				bnToast( lmsg, { tone: 'danger' } );
				return;
			}

			const strikes = ( list.data && Array.isArray( list.data.strikes ) ) ? list.data.strikes : [];
			if ( ! strikes.length ) {
				// Someone else already cleared it. Re-sync the row rather than leave a
				// Reverse control pointing at nothing.
				ctx.strikes = 0;
				bnToast( t( 'noActiveStrikes', 'This member has no active strikes.' ), { tone: 'info' } );
				return;
			}

			const res = yield restFetch( 'users/' + ctx.userId + '/strikes/' + strikes[ 0 ].id + '/reverse', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				toastOnError: false,
			} );

			if ( res.ok ) {
				ctx.strikes = Math.max( 0, strikes.length - 1 );
				bnToast( t( 'strikeReversed', 'Strike reversed.' ), { tone: 'success' } );
			} else {
				const emsg = ( res.data && res.data.message ) ? res.data.message : t( 'reverseStrikeFailed', 'Could not reverse the strike. Try again.' );
				bnToast( emsg, { tone: 'danger' } );
			}
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

		/* Every action below opts out of restFetch's default error toast
		 * (toastOnError: false) because it owns its own feedback. Each one MUST
		 * therefore report both outcomes itself — an unreported failure (a 403,
		 * say) is indistinguishable from success to the moderator. */

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
			} else {
				bnToast( t( 'dismissFailed', 'Could not dismiss the report. Try again.' ), { tone: 'danger' } );
			}
		},

		* warnMember() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.restNonce ) { return; }
			// space_id scopes the warning to this space — it is what authorises a
			// space moderator (who holds no site-wide moderation capability) to
			// issue it.
			const res = yield restFetch( 'users/' + ctx.userId + '/warn', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				body: { message: 'Space rule violation', space_id: ctx.spaceId || 0 },
				toastOnError: false,
			} );
			bnToast( res.ok ? t( 'memberWarned', 'Warning sent.' ) : t( 'warnMemberFailed', 'Could not warn the member.' ), { tone: res.ok ? 'success' : 'danger' } );
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
			const res = yield restFetch( 'spaces/' + ctx.spaceId + '/members/' + ctx.userId, {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'DELETE',
				toastOnError: false,
			} );
			// Only reload on success — reloading after a failure just re-renders
			// the unchanged list and reads as "nothing happened".
			if ( res.ok ) {
				window.location.reload();
			} else {
				bnToast( t( 'removeFromSpaceFailed', 'Could not remove the member from this space. Try again.' ), { tone: 'danger' } );
			}
		},

		* approveJoinRequest() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.spaceId || ! ctx.restNonce ) { return; }
			// Spec-conformant route is POST /spaces/{id}/members/{uid}/approve — the
			// PUT used here matched no registered route, so the request silently failed.
			const res = yield restFetch( 'spaces/' + ctx.spaceId + '/members/' + ctx.userId + '/approve', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				toastOnError: false,
			} );
			if ( res.ok ) {
				window.location.reload();
			} else {
				bnToast( t( 'approveRequestFailed', 'Could not approve the join request. Try again.' ), { tone: 'danger' } );
			}
		},

		* declineJoinRequest() {
			const ctx = getContext();
			if ( ! ctx.userId || ! ctx.spaceId || ! ctx.restNonce ) { return; }
			// Spec-conformant route is POST /spaces/{id}/members/{uid}/decline. The old
			// DELETE /spaces/{id}/members/{uid} hit the remove-member route (or none) and
			// silently failed for a pending request.
			const res = yield restFetch( 'spaces/' + ctx.spaceId + '/members/' + ctx.userId + '/decline', {
				base: ctx.restUrl,
				nonce: ctx.restNonce,
				method: 'POST',
				toastOnError: false,
			} );
			if ( res.ok ) {
				window.location.reload();
			} else {
				bnToast( t( 'declineRequestFailed', 'Could not decline the join request. Try again.' ), { tone: 'danger' } );
			}
		},
	},
} );

I18N = ( moderationStore.state && moderationStore.state.i18n ) || {};
