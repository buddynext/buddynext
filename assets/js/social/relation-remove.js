/* BuddyNext — generic relation-removal click handler.
 *
 * Any markup that follows this contract gets unblock / unmute /
 * unrestrict behaviour for free, anywhere in the app:
 *
 *   <element data-relation="block|mute|restrict" data-user-id="123">
 *     ...
 *     <button data-bn-relation-remove>Unblock</button>
 *   </element>
 *
 * On click the handler DELETEs /buddynext/v1/users/{id}/{relation}.
 * On success it removes the row from the DOM; on failure it surfaces
 * a danger toast via window.bnToast (if available) and re-enables the
 * button. The listener is attached to `document` once, so adding
 * relation rows later (e.g. via Interactivity hydration) works without
 * re-binding.
 *
 * Imported as a side-effect by every store/module that renders relation
 * rows: profile/store.js (Privacy section), notifications/store.js
 * (Muted sidebar widget). Idempotent — installing twice is harmless,
 * because the event listener is attached on a guard flag.
 */

import { restFetch } from '../shell/rest-client.js';
import { onNavReady } from '../shell/nav-init.js';

const INSTALLED = '__bnRelationRemoveInstalled';

function installRelationRemove() {
	if ( window[ INSTALLED ] ) { return; }
	window[ INSTALLED ] = true;

	document.addEventListener( 'click', async ( e ) => {
		const btn = e.target.closest( '[data-bn-relation-remove]' );
		if ( ! btn ) { return; }
		const row = btn.closest( '[data-relation][data-user-id]' );
		if ( ! row ) { return; }
		const userId  = parseInt( row.dataset.userId, 10 );
		const action  = row.dataset.relation; // "block" | "mute" | "restrict"
		if ( ! userId || ! action ) { return; }

		btn.disabled = true;

		// Resolve the REST nonce. Three sources, in priority order:
		//   1. Row-level data-bn-nonce — the widget emits its own nonce
		//      so it works even when rendered outside its surface's
		//      data-wp-context (sidebar sidecards mounted via the shell's
		//      buddynext_right_sidebar action are siblings of, not
		//      descendants of, the page's main wp-context root).
		//   2. window.wpApiSettings.nonce — the wp-api stock nonce. Only
		//      present when wp-api is enqueued (e.g. profile-edit).
		//   3. Nearest data-wp-context ancestor — last resort.
		let wpNonce = row.dataset.bnNonce || window.wpApiSettings?.nonce || '';
		if ( ! wpNonce ) {
			const ctxEl = row.closest( '[data-wp-context]' );
			if ( ctxEl ) {
				try {
					const ctx = JSON.parse( ctxEl.getAttribute( 'data-wp-context' ) );
					wpNonce = ctx.restNonce || ctx.nonce || '';
				} catch ( _err ) {}
			}
		}

		try {
			const res     = await restFetch( `/users/${ userId }/${ action }`, {
				method:       'DELETE',
				nonce:        wpNonce,
				toastOnError: false,
			} );
			if ( ! res.ok ) { throw new Error( 'remove_failed_' + res.status ); }
			row.remove();
		} catch ( _e ) {
			btn.disabled = false;
			if ( typeof window.bnToast === 'function' ) {
				window.bnToast( 'Could not update. Try again.', { tone: 'danger' } );
			}
		}
	} );
}

onNavReady( installRelationRemove, { once: true } );

export { installRelationRemove };
