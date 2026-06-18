/* BuddyNext — Spaces Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';
import { onNavReady } from '../shell/nav-init.js';

/* ── Shared helpers ────────────────────────────────────────────────── */

/**
 * The space-settings form the user last touched (the one the reactive savebar
 * submits / rolls back). Set by the savebarMarkDirty action; read by the
 * savebar submit/cancel actions and the beforeunload guard.
 *
 * @type {HTMLFormElement|null}
 */
var bnSavebarDirtyForm = null;

/**
 * Roll a space-settings form back to its captured pristine values (data-bn-default).
 *
 * @param {HTMLFormElement} form Form to restore.
 */
function bnSavebarRollback( form ) {
	if ( ! form ) { return; }
	var inputs = form.querySelectorAll( 'input, textarea, select' );
	for ( var i = 0; i < inputs.length; i++ ) {
		var el = inputs[ i ];
		if ( 'hidden' === el.type ) { continue; }
		if ( ! ( 'bnDefault' in el.dataset ) ) { continue; }
		if ( 'checkbox' === el.type || 'radio' === el.type ) {
			el.checked = '1' === el.dataset.bnDefault;
		} else {
			el.value = el.dataset.bnDefault;
		}
	}
}

/**
 * Resolve nonce. Tries the Interactivity API context first (works when
 * called inside a directive callback), then reads the data-wp-context
 * attribute from the root interactive element (works from plain DOM
 * event listeners where getContext() has no active element scope), then
 * falls back to the global wpApiSettings nonce.
 */
function resolveNonce() {
	try {
		var ctx = getContext();
		if ( ctx && ctx.restNonce ) { return ctx.restNonce; }
	} catch ( _e ) {}
	try {
		var root = document.querySelector( '[data-wp-interactive="buddynext/spaces"]' );
		if ( root && root.dataset.wpContext ) {
			var rootCtx = JSON.parse( root.dataset.wpContext );
			if ( rootCtx && rootCtx.restNonce ) { return rootCtx.restNonce; }
		}
	} catch ( _e ) {}
	return ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';
}

/**
 * Walk up the DOM from `el` and return the first element that has a
 * `data-space-id` attribute.  The home template puts it on the wrapper
 * div; the directory template puts it directly on the button.
 *
 * @param {Element|null} el Starting element.
 * @return {string} The space ID string, or '' if not found.
 */
function resolveSpaceId( el ) {
	var node = el;
	while ( node && node !== document.body ) {
		if ( node.dataset && node.dataset.spaceId ) {
			return node.dataset.spaceId;
		}
		node = node.parentElement;
	}
	return '';
}

/**
 * Walk up from `el` to find the nearest element with `data-post-id`.
 *
 * @param {Element|null} el Starting element.
 * @return {string} The post ID string, or '' if not found.
 */
function resolvePostId( el ) {
	var node = el;
	while ( node && node !== document.body ) {
		if ( node.dataset && node.dataset.postId ) {
			return node.dataset.postId;
		}
		node = node.parentElement;
	}
	return '';
}

/**
 * Swap the visual state of a membership button after a successful API
 * call, so the UI reflects the new state without a page reload. Drives
 * the v2 attribute API (`.bn-btn[data-variant]`) and falls back to the
 * legacy class set when the button hasn't been swept yet.
 *
 * @param {Element} btn      The button that was clicked.
 * @param {string}  newState One of 'joined' | 'pending' | 'join' | 'request'.
 */
function swapButtonState( btn, newState ) {
	if ( ! btn ) { return; }

	var variantMap = {
		joined:  'secondary',
		pending: 'secondary',
		join:    'primary',
		request: 'secondary',
	};
	var labelMap = {
		joined:  'Joined',
		pending: 'Requested',
		join:    'Join',
		request: 'Request to join',
	};
	var actionMap = {
		joined:  'leaveSpace',
		pending: 'cancelJoinRequest',
		join:    'joinSpace',
		request: 'requestJoin',
	};
	var legacyClassMap = {
		joined:  'bn-btn-joined',
		pending: 'bn-btn-pending',
		join:    'bn-btn-join',
		request: 'bn-btn-request',
	};

	// v2 path — drive data-variant on .bn-btn.
	if ( btn.classList.contains( 'bn-btn' ) ) {
		btn.setAttribute( 'data-variant', variantMap[ newState ] );
	} else {
		// Legacy path — swap class set.
		Object.values( legacyClassMap ).forEach( function ( cls ) { btn.classList.remove( cls ); } );
		btn.classList.add( legacyClassMap[ newState ] );
	}

	// Update visible label. Wipes any child icon — by design, post-swap
	// the button shows a clean label (Joined / Requested / Join / Request to join).
	btn.textContent = labelMap[ newState ];

	btn.setAttribute( 'data-wp-on--click', 'actions.' + actionMap[ newState ] );
	btn.dataset.currentState = newState;
	btn.disabled             = false;
}

/**
 * Detect a gated-space join denial in a REST error body.
 *
 * Free returns `code: 'cannot_join_space'` for gate denials. Pro enriches the
 * error data with a `paywall` object carrying CTA + tier metadata + rendered
 * HTML. Either signal is sufficient to treat the response as a paywall denial.
 *
 * @param {Object} data Parsed JSON error body.
 * @return {boolean} True when this is a gated-space denial.
 */
function isGatedDenial( data ) {
	if ( ! data || typeof data !== 'object' ) { return false; }
	if ( data.code === 'cannot_join_space' ) { return true; }
	if ( data.data && data.data.paywall ) { return true; }
	return false;
}

/**
 * Surface the paywall for a gated-space denial.
 *
 * Prefers the server-rendered HTML in `data.data.paywall.html` (single source
 * of truth with the SSR template). Injects it into the space hero, replacing
 * the join action cluster, and wires the checkout button if present. Falls back
 * to a minimal CTA built from the paywall metadata when no HTML was returned
 * (e.g. an older Pro build). When there is no paywall payload at all, surfaces a
 * neutral members-only notice so the click is never a silent dead end.
 *
 * @param {Element|null} btn     The join/request button that was clicked.
 * @param {string}       spaceId The space ID.
 * @param {Object}       data    Parsed JSON error body.
 */
function surfacePaywall( btn, spaceId, data ) {
	var paywall = ( data && data.data && data.data.paywall ) ? data.data.paywall : null;

	// Mount point: the hero action cluster the button lives in, else the hero.
	var mount = null;
	if ( btn ) {
		mount = btn.closest( '.bn-sh-hero__actions' ) || btn.closest( '.bn-sh-hero' ) || btn.parentElement;
	}
	if ( ! mount ) {
		mount = document.querySelector( '.bn-sh-hero' ) || document.body;
	}

	// If a paywall is already on the page (SSR), just reveal/scroll to it.
	var existing = document.querySelector( '.bn-paywall' );
	if ( existing ) {
		if ( btn ) { btn.disabled = true; btn.setAttribute( 'hidden', '' ); }
		existing.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		return;
	}

	if ( paywall && typeof paywall.html === 'string' && paywall.html.trim() ) {
		// Trusted, server-rendered HTML: built entirely in the Free
		// spaces/paywall.php template with esc_html/esc_url/esc_attr and
		// returned by our own REST endpoint — not user-controlled input. Parse
		// via <template> and append.
		var tpl = document.createElement( 'template' );
		tpl.innerHTML = paywall.html.trim();
		var node = tpl.content.firstElementChild;
		if ( node ) {
			if ( btn ) { btn.setAttribute( 'hidden', '' ); }
			mount.appendChild( node );
			node.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			return;
		}
	}

	// Fallback: build a minimal paywall from metadata using safe DOM APIs.
	var wrap = document.createElement( 'div' );
	wrap.className = 'bn-paywall';
	wrap.dataset.spaceId = String( spaceId );

	var body = document.createElement( 'div' );
	body.className = 'bn-paywall__body';

	var h = document.createElement( 'h2' );
	h.className = 'bn-paywall__heading';
	h.textContent = ( paywall && paywall.heading ) ? paywall.heading : __i18n( 'This space is available to members only.' );
	body.appendChild( h );

	if ( paywall && paywall.description ) {
		var p = document.createElement( 'p' );
		p.className = 'bn-paywall__description';
		p.textContent = paywall.description;
		body.appendChild( p );
	}

	var label = ( paywall && paywall.cta_label ) ? paywall.cta_label : __i18n( 'Become a Member' );

	if ( paywall && paywall.checkout && paywall.tier_slug ) {
		var cBtn = document.createElement( 'button' );
		cBtn.type = 'button';
		cBtn.className = 'bn-btn bn-paywall__cta';
		cBtn.setAttribute( 'data-variant', 'primary' );
		cBtn.setAttribute( 'data-tier-slug', paywall.tier_slug );
		cBtn.textContent = label;
		cBtn.addEventListener( 'click', function ( ev ) {
			storeInstance.actions.startCheckout( ev );
		} );
		body.appendChild( cBtn );
	} else if ( paywall && paywall.cta_url ) {
		var a = document.createElement( 'a' );
		a.className = 'bn-btn bn-paywall__cta';
		a.setAttribute( 'data-variant', 'primary' );
		a.href = paywall.cta_url;
		a.textContent = label;
		body.appendChild( a );
	} else {
		var note = document.createElement( 'p' );
		note.className = 'bn-paywall__unconfigured';
		note.textContent = __i18n( 'Membership purchase is not configured yet. Please check back soon.' );
		body.appendChild( note );
	}

	wrap.appendChild( body );
	if ( btn ) { btn.setAttribute( 'hidden', '' ); }
	mount.appendChild( wrap );
	wrap.scrollIntoView( { behavior: 'smooth', block: 'center' } );
}

/**
 * Approve or decline a pending join request from the space moderation queue.
 *
 * Shared body for the `approveJoinRequest` / `declineJoinRequest` store actions.
 * Reads `data-user-id` and `data-space-id` off the clicked button (the moderation
 * template puts both on each Approve/Decline button), POSTs to the spec-conformant
 * member route, and on success removes the request row and decrements the two
 * pending counters (the tab badge `.bn-tab__count` and the summary stat
 * `.bn-stat__value`). Degrades gracefully: any missing element is simply skipped,
 * and a network/permission failure re-enables the row's buttons and surfaces a
 * toast when one is available — never a silent dead end, never a fatal.
 *
 * @param {Event}  event  Click event from the Approve/Decline button.
 * @param {string} action Either 'approve' or 'decline'.
 * @return {Promise<void>} Resolves once the request settles.
 */
async function moderateJoinRequest( event, action ) {
	var btn = event && event.target && event.target.closest( 'button' );
	if ( ! btn ) { return; }

	var spaceId = btn.getAttribute( 'data-space-id' );
	var userId  = btn.getAttribute( 'data-user-id' );
	if ( ! spaceId || ! userId ) { return; }

	var row     = btn.closest( '.bn-space-mod__pending-row' );
	var rowBtns = row ? row.querySelectorAll( 'button' ) : [ btn ];

	// Lock the whole row while the request is in flight.
	for ( var i = 0; i < rowBtns.length; i++ ) { rowBtns[ i ].disabled = true; }
	if ( row ) { row.style.opacity = '0.5'; }

	try {
		var res = await restFetch(
			'/spaces/' + spaceId + '/members/' + userId + '/' + action,
			{
				method:  'POST',
				nonce:   resolveNonce(),
				toastOnError: false,
			}
		);
		var data = res.data || {};

		var ok = res.ok && ( ( 'approve' === action ) ? data.approved : data.declined );

		if ( ok ) {
			// Remove the request row, then sync the counters to the live count.
			if ( row && row.parentNode ) { row.parentNode.removeChild( row ); }

			syncPendingCounters();

			if ( window.bnToast ) {
				window.bnToast(
					( 'approve' === action )
						? __i18n( 'Request approved.' )
						: __i18n( 'Request declined.' ),
					'success'
				);
			}
			return;
		}

		// Failure: unlock the row and surface a reason.
		if ( row ) { row.style.opacity = '1'; }
		for ( var j = 0; j < rowBtns.length; j++ ) { rowBtns[ j ].disabled = false; }
		if ( window.bnToast ) {
			var msg = ( data && data.message )
				? data.message
				: ( ( 'approve' === action )
					? __i18n( 'Could not approve the request.' )
					: __i18n( 'Could not decline the request.' ) );
			window.bnToast( msg, 'danger' );
		}
	} catch ( _e ) {
		if ( row ) { row.style.opacity = '1'; }
		for ( var k = 0; k < rowBtns.length; k++ ) { rowBtns[ k ].disabled = false; }
		if ( window.bnToast ) { window.bnToast( __i18n( 'Network error.' ), 'danger' ); }
	}
}

/**
 * Re-sync the two "pending requests" counters on the moderation hub to the live
 * number of request rows still in the list, after one has been removed: the
 * Pending tab badge and the "Pending member requests" summary stat.
 *
 * The hub renders a `.bn-tab__count` on multiple tabs (Reports, Pending) and four
 * `.bn-stat__value` tiles, so neither can be selected blindly. The Pending tab is
 * located by the `bn_mtab=pending` href; the pending stat is the only tile whose
 * label contains the word "pending" (the localized "Pending member requests"
 * label). The remaining count is read from the live `.bn-space-mod__pending-row`
 * elements so the display is self-correcting. A zeroed tab badge is removed to
 * mirror the server-rendered empty state. If the markup ever changes such that a
 * pending element is not found, the counter update is simply skipped — the row is
 * still removed, so the moderator's action is never lost.
 *
 * @return {void}
 */
function syncPendingCounters() {
	var remaining = document.querySelectorAll( '.bn-space-mod__pending-row' ).length;

	// Pending tab: the moderation-filter tab linking to bn_mtab=pending.
	var pendingTab = null;
	var tabs = document.querySelectorAll( '.bn-tab' );
	for ( var i = 0; i < tabs.length; i++ ) {
		var href = tabs[ i ].getAttribute( 'href' ) || '';
		if ( href.indexOf( 'bn_mtab=pending' ) !== -1 ) { pendingTab = tabs[ i ]; break; }
	}

	if ( pendingTab ) {
		var badge = pendingTab.querySelector( '.bn-tab__count' );
		if ( remaining > 0 ) {
			if ( badge ) {
				badge.textContent = String( remaining );
			}
		} else if ( badge && badge.parentNode ) {
			badge.parentNode.removeChild( badge );
		}

	}

	// Pending stat tile: the only summary tile whose label mentions "pending"
	// ("Pending member requests"). Falls back silently if the label is absent.
	var stats = document.querySelectorAll( '.bn-stat' );
	for ( var j = 0; j < stats.length; j++ ) {
		var labelEl = stats[ j ].querySelector( '.bn-stat__label' );
		var valueEl = stats[ j ].querySelector( '.bn-stat__value' );
		if ( ! labelEl || ! valueEl ) { continue; }
		if ( ( labelEl.textContent || '' ).toLowerCase().indexOf( 'pending' ) !== -1 ) {
			valueEl.textContent = String( remaining );
			break;
		}
	}
}

/* ── Store ─────────────────────────────────────────────────────────── */

var storeInstance = store( 'buddynext/spaces', {

	/* ── Reactive UI state (single source) ─────────────────────────────────
	 *
	 * Drives the Space Settings sticky savebar and the danger-zone modals via
	 * data-wp-bind--hidden — replacing the old imperative showState() dataset/
	 * .hidden paint loop and the openSpaceModal()/.hidden modal toggles. The
	 * settings page is a single global instance, so these live on global store
	 * state (not per-element context); getters derive the per-block `hidden`
	 * booleans because Interactivity directives can't evaluate === / ! inline.
	 *
	 * savebarPhase: 'idle' | 'dirty' | 'saving' | 'saved'
	 * ─────────────────────────────────────────────────────────────────────── */
	state: {
		savebarPhase:  'idle',
		modalTransferOpen: false,
		modalDeleteOpen:   false,
		modalArchiveOpen:  false,

		// Savebar: the bar is hidden only in the idle phase; each status pill is
		// hidden unless its phase is the active one.
		get savebarHidden() { return 'idle' === storeInstance.state.savebarPhase; },
		get savebarDirtyHidden() { return 'dirty' !== storeInstance.state.savebarPhase; },
		get savebarSavingHidden() { return 'saving' !== storeInstance.state.savebarPhase; },
		get savebarSavedHidden() { return 'saved' !== storeInstance.state.savebarPhase; },

		// Danger-zone modals: hidden is the negation of the open flag.
		get modalTransferHidden() { return ! storeInstance.state.modalTransferOpen; },
		get modalDeleteHidden() { return ! storeInstance.state.modalDeleteOpen; },
		get modalArchiveHidden() { return ! storeInstance.state.modalArchiveOpen; },
	},

	actions: {

		/**
		 * Join an open (public) space immediately.
		 * Updates the button to "Joined" and increments the member count
		 * shown in the card's stat line without reloading.
		 */
		joinSpace: async function ( event ) {
			var btn     = event && event.target && event.target.closest( 'button' );
			var spaceId = resolveSpaceId( btn );
			if ( ! spaceId ) { return; }

			var origText  = btn ? btn.textContent : '';
			if ( btn ) { btn.disabled = true; btn.textContent = '\u2026'; }

			try {
				var res  = await restFetch( '/spaces/' + spaceId + '/join', {
					method:  'POST',
					nonce:   resolveNonce(),
					toastOnError: false,
				} );
				var data = res.data || {};

				if ( res.ok && data.joined ) {
					swapButtonState( btn, 'joined' );
					// Update member count label if present in the same card.
					var card = btn ? btn.closest( '[data-space-id]' ) || btn.closest( '.bn-space-card' ) : null;
					if ( card ) {
						var countEl = card.querySelector( '.bn-space-card__stats span' );
						if ( countEl ) {
							var match = countEl.textContent.match( /(\d[\d,]*)/ );
							if ( match ) {
								var next = parseInt( match[1].replace( /,/g, '' ), 10 ) + 1;
								countEl.textContent = countEl.textContent.replace( /\d[\d,]*/, next.toLocaleString() );
							}
						}
					}
				} else if ( isGatedDenial( data ) ) {
					surfacePaywall( btn, spaceId, data );
				} else if ( btn ) {
					btn.textContent = origText;
					btn.disabled    = false;
					if ( window.bnToast ) {
						window.bnToast( ( data && data.message ) || __i18n( 'Could not join this space.' ), 'danger' );
					}
				}
			} catch ( _e ) {
				if ( btn ) { btn.textContent = origText; btn.disabled = false; }
				if ( window.bnToast ) { window.bnToast( __i18n( 'Network error.' ), 'danger' ); }
			}
		},

		/**
		 * Accept a pending space invitation. POSTs to /spaces/{id}/join, which
		 * promotes the invited membership row to active (same endpoint a normal
		 * join uses). Reloads on success so the full member view renders.
		 */
		acceptInvite: async function ( event ) {
			var btn     = event && event.target && event.target.closest( 'button' );
			var spaceId = resolveSpaceId( btn );
			if ( ! spaceId ) { return; }

			var origText = btn ? btn.textContent : '';
			if ( btn ) { btn.disabled = true; btn.textContent = '…'; }

			try {
				var res  = await restFetch( '/spaces/' + spaceId + '/join', {
					method:  'POST',
					nonce:   resolveNonce(),
					toastOnError: false,
				} );
				var data = res.data || {};

				if ( res.ok && data.joined ) {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Invitation accepted.' ), 'success' ); }
					window.location.reload();
				} else if ( btn ) {
					btn.textContent = origText;
					btn.disabled    = false;
					if ( window.bnToast ) {
						window.bnToast( ( data && data.message ) || __i18n( 'Could not accept the invitation.' ), 'danger' );
					}
				}
			} catch ( _e ) {
				if ( btn ) { btn.textContent = origText; btn.disabled = false; }
			}
		},

		/**
		 * Decline a pending space invitation. POSTs to /spaces/{id}/leave, which
		 * removes the invited membership row. Reloads on success.
		 */
		declineInvite: async function ( event ) {
			var btn     = event && event.target && event.target.closest( 'button' );
			var spaceId = resolveSpaceId( btn );
			if ( ! spaceId ) { return; }

			var origText = btn ? btn.textContent : '';
			if ( btn ) { btn.disabled = true; btn.textContent = '…'; }

			try {
				var res = await restFetch( '/spaces/' + spaceId + '/leave', {
					method:  'POST',
					nonce:   resolveNonce(),
					toastOnError: false,
				} );

				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Invitation declined.' ), 'info' ); }
					window.location.reload();
				} else if ( btn ) {
					btn.textContent = origText;
					btn.disabled    = false;
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not decline the invitation.' ), 'danger' ); }
				}
			} catch ( _e ) {
				if ( btn ) { btn.textContent = origText; btn.disabled = false; }
			}
		},

		/**
		 * Request to join a private or invite-only space.
		 * The endpoint returns { pending: true } on success.
		 */
		requestJoin: async function ( event ) {
			var btn     = event && event.target && event.target.closest( 'button' );
			var spaceId = resolveSpaceId( btn );
			if ( ! spaceId ) { return; }

			var origText = btn ? btn.textContent : '';
			if ( btn ) { btn.disabled = true; btn.textContent = '\u2026'; }

			try {
				var res  = await restFetch( '/spaces/' + spaceId + '/join', {
					method:  'POST',
					nonce:   resolveNonce(),
					toastOnError: false,
				} );
				var data = res.data || {};

				if ( res.ok && ( data.pending || data.requested ) ) {
					swapButtonState( btn, 'pending' );
				} else if ( isGatedDenial( data ) ) {
					surfacePaywall( btn, spaceId, data );
				} else if ( btn ) {
					btn.textContent = origText;
					btn.disabled    = false;
				}
			} catch ( _e ) {
				if ( btn ) { btn.textContent = origText; btn.disabled = false; }
			}
		},

		/**
		 * Start first-party Stripe checkout for a gated space's required tier.
		 *
		 * Bound to the paywall CTA button (`data-wp-on--click="actions.startCheckout"`)
		 * when the site has linked a Stripe price to the tier. POSTs to the Pro
		 * checkout endpoint and redirects the browser to the returned Stripe
		 * Checkout Session URL. On any failure (Stripe not configured, network)
		 * the button is re-enabled and a toast surfaces the reason — never a
		 * silent dead end.
		 */
		startCheckout: async function ( event ) {
			var btn = event && event.target && event.target.closest( 'button' );
			if ( ! btn ) { return; }

			var cfg      = ( typeof window !== 'undefined' && window.bnProCheckout ) ? window.bnProCheckout : null;
			var tierSlug = btn.getAttribute( 'data-tier-slug' ) || ( cfg && cfg.tierSlug ) || '';
			var endpoint = ( cfg && cfg.endpoint ) || 'buddynext-pro/v1/me/checkout';

			if ( ! tierSlug ) {
				if ( window.bnToast ) { window.bnToast( __i18n( 'Membership purchase is not configured yet.' ), 'warn' ); }
				return;
			}

			var origText = btn.textContent;
			btn.disabled = true;
			btn.textContent = __i18n( 'Redirecting…' );

			var body = { tier_slug: tierSlug };
			if ( cfg && cfg.successUrl ) { body.success_url = cfg.successUrl; }
			if ( cfg && cfg.cancelUrl ) { body.cancel_url = cfg.cancelUrl; }

			try {
				var res  = await restFetch( '/' + endpoint, {
					base:    ( ( window.wpApiSettings && window.wpApiSettings.root ) || '/wp-json/' ).replace( /\/+$/, '' ),
					method:  'POST',
					nonce:   resolveNonce(),
					body:    body,
					toastOnError: false,
				} );
				var data = res.data || {};

				if ( res.ok && data && data.url ) {
					window.location.href = data.url;
					return;
				}

				// Surface a clear reason (e.g. Stripe not configured / no price linked).
				var msg = ( data && data.message ) ? data.message : __i18n( 'Could not start checkout. Please try again later.' );
				if ( window.bnToast ) { window.bnToast( msg, 'danger' ); }
				btn.textContent = origText;
				btn.disabled    = false;
			} catch ( _e ) {
				if ( window.bnToast ) { window.bnToast( __i18n( 'Network error. Please try again.' ), 'danger' ); }
				btn.textContent = origText;
				btn.disabled    = false;
			}
		},

		/**
		 * Leave a space the current user is already a member of.
		 * Reverts the button to "Join" (public) or "Request to join"
		 * (private/secret) and decrements the displayed member count.
		 */
		leaveSpace: async function ( event ) {
			var btn     = event && event.target && event.target.closest( 'button' );
			var spaceId = resolveSpaceId( btn );
			if ( ! spaceId ) { return; }

			var origText = btn ? btn.textContent : '';
			if ( btn ) { btn.disabled = true; btn.textContent = '\u2026'; }

			try {
				var res = await restFetch( '/spaces/' + spaceId + '/leave', {
					method:  'POST',
					nonce:   resolveNonce(),
					toastOnError: false,
				} );
				var data = res.data || {};

				if ( res.ok && data.left ) {
					// Decide button to show based on card privacy badge.
					// v2: badge carries data-tone="info" for open / "warn"|"danger" for private/secret.
					// Legacy: text match on i18n label.
					var card = btn ? btn.closest( '.bn-space-card__footer' ) || btn.closest( '.bn-space-card' ) : null;
					var privacyEl = card ? card.querySelector( '.bn-space-card__privacy' ) : null;
					var tone = privacyEl ? privacyEl.getAttribute( 'data-tone' ) : null;
					var isPrivate;
					if ( tone ) {
						isPrivate = ( tone !== 'info' );
					} else if ( privacyEl ) {
						isPrivate = ( privacyEl.textContent.toLowerCase().indexOf( 'public' ) === -1 );
					} else {
						isPrivate = false;
					}
					swapButtonState( btn, isPrivate ? 'request' : 'join' );

					// Decrement member count.
					if ( card ) {
						var countEl = card.querySelector( '.bn-space-card__stats span' );
						if ( countEl ) {
							var match = countEl.textContent.match( /(\d[\d,]*)/ );
							if ( match ) {
								var curr = parseInt( match[1].replace( /,/g, '' ), 10 );
								var next = Math.max( 0, curr - 1 );
								countEl.textContent = countEl.textContent.replace( /\d[\d,]*/, next.toLocaleString() );
							}
						}
					}
				} else if ( btn ) {
					btn.textContent = origText;
					btn.disabled    = false;
				}
			} catch ( _e ) {
				if ( btn ) { btn.textContent = origText; btn.disabled = false; }
			}
		},

		/**
		 * Cancel a pending join request (uses the leave endpoint, which
		 * the REST controller handles for pending-status members too).
		 */
		cancelJoinRequest: async function ( event ) {
			var btn     = event && event.target && event.target.closest( 'button' );
			var spaceId = resolveSpaceId( btn );
			if ( ! spaceId ) { return; }

			var origText = btn ? btn.textContent : '';
			if ( btn ) { btn.disabled = true; btn.textContent = '\u2026'; }

			try {
				var res  = await restFetch( '/spaces/' + spaceId + '/leave', {
					method:  'POST',
					nonce:   resolveNonce(),
					toastOnError: false,
				} );
				var data = res.data || {};

				if ( res.ok && ( data.left || data.cancelled ) ) {
					swapButtonState( btn, 'request' );
				} else if ( btn ) {
					btn.textContent = origText;
					btn.disabled    = false;
				}
			} catch ( _e ) {
				if ( btn ) { btn.textContent = origText; btn.disabled = false; }
			}
		},

		/**
		 * Approve a pending join request from the space moderation queue.
		 *
		 * Bound on the moderation template's "Approve" button
		 * (`templates/spaces/moderation.php`), which carries `data-user-id`
		 * and `data-space-id`. POSTs to the spec route
		 * `POST /spaces/{id}/members/{user}/approve` (returns `{approved:true}`),
		 * then removes the row and decrements the pending counters so the queue
		 * reflects the new state without a reload.
		 *
		 * @param {Event} event Click on a `data-wp-on--click="actions.approveJoinRequest"` button.
		 */
		approveJoinRequest: async function ( event ) {
			await moderateJoinRequest( event, 'approve' );
		},

		/**
		 * Decline a pending join request from the space moderation queue.
		 *
		 * Mirror of {@link approveJoinRequest} for the "Decline" button. POSTs to
		 * `POST /spaces/{id}/members/{user}/decline` (returns `{declined:true}`).
		 *
		 * @param {Event} event Click on a `data-wp-on--click="actions.declineJoinRequest"` button.
		 */
		declineJoinRequest: async function ( event ) {
			await moderateJoinRequest( event, 'decline' );
		},

		/**
		 * Open the post composer panel.
		 * Used both as a click handler (Post button) and a focus handler
		 * (clicking into the text input stub).
		 */
		openComposer: function () {
			var composer = document.querySelector( '.bn-composer' );
			if ( ! composer ) { return; }
			composer.classList.add( 'bn-composer--open' );

			// Replace the read-only stub input with an editable textarea on
			// first open, so users can actually type their post content.
			var stub = composer.querySelector( '.bn-composer__input[readonly]' );
			if ( stub ) {
				var ta = document.createElement( 'textarea' );
				ta.className   = 'bn-composer__textarea';
				ta.rows        = 4;
				ta.placeholder = stub.placeholder;
				ta.id          = 'bn-composer-content';
				stub.parentNode.replaceChild( ta, stub );
				ta.focus();
			} else {
				var existingTa = composer.querySelector( '#bn-composer-content' );
				if ( existingTa ) { existingTa.focus(); }
			}

			// Inject a minimal submit form if it does not exist yet.
			if ( ! composer.querySelector( '.bn-composer__actions' ) ) {
				var actions = document.createElement( 'div' );
				actions.className = 'bn-composer__actions';

				var submitBtn = document.createElement( 'button' );
				submitBtn.type      = 'button';
				submitBtn.className = 'bn-btn-primary bn-composer__submit';
				submitBtn.textContent = 'Post';
				submitBtn.addEventListener( 'click', function ( ev ) {
					storeInstance.actions.submitPost( ev );
				} );

				var cancelBtn = document.createElement( 'button' );
				cancelBtn.type      = 'button';
				cancelBtn.className = 'bn-btn-secondary bn-composer__cancel';
				cancelBtn.textContent = 'Cancel';
				cancelBtn.addEventListener( 'click', function () {
					storeInstance.actions.closeComposer();
				} );

				actions.appendChild( cancelBtn );
				actions.appendChild( submitBtn );
				composer.appendChild( actions );
			}
		},

		/**
		 * Close the post composer panel and clear any typed content.
		 */
		closeComposer: function () {
			var composer = document.querySelector( '.bn-composer' );
			if ( ! composer ) { return; }
			composer.classList.remove( 'bn-composer--open' );

			var ta = composer.querySelector( '#bn-composer-content' );
			if ( ta ) { ta.value = ''; }
		},

		/**
		 * Submit a new post to the space feed.
		 * Reads content from the composer textarea and posts to
		 * /buddynext/v1/posts with the current space_id.
		 */
		submitPost: async function ( event ) {
			var composer = document.querySelector( '.bn-composer' );
			if ( ! composer ) { return; }

			var ta = composer.querySelector( '#bn-composer-content' );
			if ( ! ta ) { return; }

			var content = ta.value.trim();
			if ( ! content ) { return; }

			// Resolve space_id from the root interactive container.
			var root    = document.querySelector( '[data-wp-interactive="buddynext/spaces"][data-space-id]' );
			var spaceId = root ? root.dataset.spaceId : resolveSpaceId( event && event.target );
			if ( ! spaceId ) { return; }

			var submitBtn = composer.querySelector( '.bn-composer__submit' );
			if ( submitBtn ) { submitBtn.disabled = true; submitBtn.textContent = 'Posting\u2026'; }

			try {
				var res  = await restFetch( '/posts', {
					method:  'POST',
					nonce:   resolveNonce(),
					body: {
						content:  content,
						space_id: parseInt( spaceId, 10 ),
						type:     'text',
					},
					toastOnError: false,
				} );

				if ( res.ok ) {
					// Close the composer and clear the field.
					ta.value = '';
					if ( composer ) { composer.classList.remove( 'bn-composer--open' ); }

					// Reload the page so the new post appears in the feed.
					// A full SPA update is out of scope for the initial store;
					// a page reload is the reliable fallback here.
					window.location.reload();
				} else {
					if ( submitBtn ) { submitBtn.disabled = false; submitBtn.textContent = 'Post'; }
				}
			} catch ( _e ) {
				if ( submitBtn ) { submitBtn.disabled = false; submitBtn.textContent = 'Post'; }
			}
		},

		/**
		 * Toggle a reaction on a post via POST /reactions/toggle.
		 * The endpoint handles both add and remove based on current state.
		 * Updates the displayed count optimistically.
		 */
		toggleReaction: async function ( event ) {
			var btn    = event && event.target && event.target.closest( 'button' );
			var postId = resolvePostId( btn );
			if ( ! postId || ! btn ) { return; }

			var countEl    = btn.querySelector( '.bn-reaction-count' );
			var hasReacted = btn.classList.contains( 'bn-reacted' );
			var count      = countEl ? ( parseInt( countEl.textContent, 10 ) || 0 ) : 0;

			// Optimistic update.
			if ( hasReacted ) {
				count = Math.max( 0, count - 1 );
				btn.classList.remove( 'bn-reacted' );
			} else {
				count = count + 1;
				btn.classList.add( 'bn-reacted' );
			}
			if ( countEl ) { countEl.textContent = String( count ); }

			try {
				await restFetch( '/reactions/toggle', {
					method:  'POST',
					nonce:   resolveNonce(),
					body: {
						object_type: 'post',
						object_id:   parseInt( postId, 10 ),
						emoji:       'love',
					},
					toastOnError: false,
				} );
			} catch ( _e ) {
				// Revert optimistic update on network failure.
				if ( hasReacted ) {
					count = count + 1;
					btn.classList.add( 'bn-reacted' );
				} else {
					count = Math.max( 0, count - 1 );
					btn.classList.remove( 'bn-reacted' );
				}
				if ( countEl ) { countEl.textContent = String( count ); }
			}
		},

		/**
		 * Navigate to the post's comment thread.
		 * The template does not render an inline comment area, so this
		 * action redirects to the canonical post permalink with the
		 * comments anchor.
		 */
		viewComments: function ( event ) {
			var btn    = event && event.target && event.target.closest( 'button' );
			var postId = resolvePostId( btn );
			if ( ! postId ) { return; }

			// Build the post permalink from the REST API response or fall
			// back to adding a query-var anchor to the current page.
			var root    = document.querySelector( '[data-wp-interactive="buddynext/spaces"]' );
			var spaceId = root ? root.dataset.spaceId : '';

			if ( spaceId ) {
				window.location.href = window.location.pathname +
					'?bn_post=' + encodeURIComponent( postId ) +
					'&bn_space=' + encodeURIComponent( spaceId ) +
					'#comments';
			} else {
				window.location.href = window.location.pathname +
					'?bn_post=' + encodeURIComponent( postId ) +
					'#comments';
			}
		},

		/**
		 * Toggle the "more options" dropdown menu on a post card.
		 * Closes all other open menus first, then opens/closes the
		 * target menu.
		 */
		openPostMenu: function ( event ) {
			var btn    = event && event.target && event.target.closest( 'button' );
			var postId = resolvePostId( btn );
			if ( ! btn ) { return; }

			// Close any already-open post menus.
			document.querySelectorAll( '.bn-post-card__menu-dropdown--open' ).forEach( function ( el ) {
				if ( el !== btn.nextElementSibling ) {
					el.classList.remove( 'bn-post-card__menu-dropdown--open' );
				}
			} );

			// Find or create the dropdown for this post.
			var dropdown = btn.nextElementSibling;
			if ( ! dropdown || ! dropdown.classList.contains( 'bn-post-card__menu-dropdown' ) ) {
				dropdown = document.createElement( 'div' );
				dropdown.className      = 'bn-post-card__menu-dropdown';
				dropdown.dataset.postId = postId;

				var reportItem = document.createElement( 'button' );
				reportItem.type        = 'button';
				reportItem.textContent = 'Report post';
				reportItem.className   = 'bn-post-card__menu-item';
				reportItem.addEventListener( 'click', function () {
					dropdown.classList.remove( 'bn-post-card__menu-dropdown--open' );
					restFetch( '/reports', {
						method:  'POST',
						nonce:   resolveNonce(),
						body:    { object_type: 'post', object_id: parseInt( postId, 10 ) },
						toastOnError: false,
					} ).then( function ( res ) {
						if ( res.ok || res.status === 201 ) {
							if ( window.bnToast ) { window.bnToast( 'Report submitted. Thanks for keeping the community safe.', { tone: 'success' } ); }
							return;
						}
						// Surface the server's reason (e.g. 409 already reported).
						var data = res.data;
						if ( window.bnToast ) { window.bnToast( ( data && data.message ) || 'Could not submit report. Try again.', { tone: 'danger' } ); }
					} ).catch( function () {
						if ( window.bnToast ) { window.bnToast( 'Could not submit report. Try again.', { tone: 'danger' } ); }
					} );
				} );

				dropdown.appendChild( reportItem );
				btn.parentNode.insertBefore( dropdown, btn.nextSibling );
			}

			dropdown.classList.toggle( 'bn-post-card__menu-dropdown--open' );

			// Close on outside click.
			if ( dropdown.classList.contains( 'bn-post-card__menu-dropdown--open' ) ) {
				var closeOnOutside = function ( e ) {
					if ( ! dropdown.contains( e.target ) && e.target !== btn ) {
						dropdown.classList.remove( 'bn-post-card__menu-dropdown--open' );
						document.removeEventListener( 'click', closeOnOutside );
					}
				};
				setTimeout( function () {
					document.addEventListener( 'click', closeOnOutside );
				}, 0 );
			}
		},

		/**
		 * Share a post. Calls POST /buddynext/v1/posts/{id}/share.
		 * Shows a brief "Shared!" label on the button after success.
		 */
		sharePost: async function ( event ) {
			var btn    = event && event.target && event.target.closest( 'button' );
			var postId = resolvePostId( btn );
			if ( ! postId || ! btn ) { return; }

			var origText = btn.textContent;
			btn.disabled = true;

			try {
				var res = await restFetch( '/posts/' + postId + '/share', {
					method:  'POST',
					nonce:   resolveNonce(),
					body:    {},
					toastOnError: false,
				} );

				if ( res.ok ) {
					btn.textContent = 'Shared!';
					setTimeout( function () {
						btn.textContent = origText;
						btn.disabled    = false;
					}, 2500 );
				} else {
					btn.textContent = origText;
					btn.disabled    = false;
				}
			} catch ( _e ) {
				btn.textContent = origText;
				btn.disabled    = false;
			}
		},

		/* ── Space-settings modal openers ──────────────────────────────── */

		/**
		 * Open the simple delete-space confirm modal (reactive flag).
		 */
		openDeleteSpaceModal: function () {
			storeInstance.state.modalDeleteOpen = true;
			focusFirstInModal( 'delete-space' );
		},

		/* ── Settings: members tab inline actions ──────────────────────── */

		/**
		 * Change a member's role optimistically via PUT /spaces/{id}/members/{user}/role.
		 *
		 * @param {Event} event Click on a `[data-bn-member-role]` button.
		 */
		setMemberRole: async function ( event ) {
			var btn = event && event.target && event.target.closest( '[data-bn-member-role]' );
			if ( ! btn ) { return; }
			var spaceId = btn.getAttribute( 'data-space-id' );
			var userId  = btn.getAttribute( 'data-user-id' );
			var role    = btn.getAttribute( 'data-bn-member-role' );
			if ( ! spaceId || ! userId || ! role ) { return; }

			btn.disabled = true;
			try {
				var res = await restFetch( '/spaces/' + spaceId + '/members/' + userId + '/role', {
					method:  'PUT',
					nonce:   resolveNonce(),
					body:    { role: role },
					toastOnError: false,
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Role updated.' ), 'success' ); }
					// Refresh the row label.
					var row = btn.closest( '[data-bn-member-row]' );
					if ( row ) {
						var badge = row.querySelector( '[data-bn-role-badge]' );
						if ( badge ) {
							badge.textContent = ( 'moderator' === role )
								? __i18n( 'Moderator' )
								: __i18n( 'Member' );
							badge.dataset.tone = ( 'moderator' === role ) ? 'info' : 'default';
						}
					}
				} else {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not update role.' ), 'danger' ); }
				}
			} catch ( _e ) {
				if ( window.bnToast ) { window.bnToast( __i18n( 'Network error.' ), 'danger' ); }
			} finally {
				btn.disabled = false;
			}
		},

		/**
		 * Kick a member optimistically via DELETE /spaces/{id}/members/{user}.
		 *
		 * @param {Event} event Click on a `[data-bn-member-kick]` button.
		 */
		kickMember: async function ( event ) {
			var btn = event && event.target && event.target.closest( '[data-bn-member-kick]' );
			if ( ! btn ) { return; }
			var spaceId = btn.getAttribute( 'data-space-id' );
			var userId  = btn.getAttribute( 'data-user-id' );
			if ( ! spaceId || ! userId ) { return; }
			var row = btn.closest( '[data-bn-member-row]' );
			if ( row ) { row.style.opacity = '0.4'; }
			btn.disabled = true;
			try {
				var res = await restFetch( '/spaces/' + spaceId + '/members/' + userId, {
					method:  'DELETE',
					nonce:   resolveNonce(),
					toastOnError: false,
				} );
				if ( res.ok ) {
					if ( row ) { row.parentNode && row.parentNode.removeChild( row ); }
					if ( window.bnToast ) { window.bnToast( __i18n( 'Member removed.' ), 'success' ); }
				} else {
					if ( row ) { row.style.opacity = '1'; }
					btn.disabled = false;
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not remove member.' ), 'danger' ); }
				}
			} catch ( _e ) {
				if ( row ) { row.style.opacity = '1'; }
				btn.disabled = false;
				if ( window.bnToast ) { window.bnToast( __i18n( 'Network error.' ), 'danger' ); }
			}
		},

		/**
		 * Ban a member via POST /spaces/{id}/bans (canonical plural route;
		 * user_id travels in the JSON body).
		 *
		 * @param {Event} event Click on a `[data-bn-member-ban]` button.
		 */
		banMember: async function ( event ) {
			var btn = event && event.target && event.target.closest( '[data-bn-member-ban]' );
			if ( ! btn ) { return; }
			var spaceId = btn.getAttribute( 'data-space-id' );
			var userId  = btn.getAttribute( 'data-user-id' );
			if ( ! spaceId || ! userId ) { return; }
			var row = btn.closest( '[data-bn-member-row]' );
			if ( row ) { row.style.opacity = '0.4'; }
			btn.disabled = true;
			try {
				var res = await restFetch( '/spaces/' + spaceId + '/bans', {
					method:  'POST',
					nonce:   resolveNonce(),
					body:    { user_id: parseInt( userId, 10 ) },
					toastOnError: false,
				} );
				if ( res.ok ) {
					if ( row ) { row.parentNode && row.parentNode.removeChild( row ); }
					if ( window.bnToast ) { window.bnToast( __i18n( 'Member banned.' ), 'success' ); }
				} else {
					if ( row ) { row.style.opacity = '1'; }
					btn.disabled = false;
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not ban member.' ), 'danger' ); }
				}
			} catch ( _e ) {
				if ( row ) { row.style.opacity = '1'; }
				btn.disabled = false;
			}
		},

		/* ── Settings: transfer ownership ─────────────────────────────── */

		/**
		 * Open the transfer-ownership confirm modal (reactive flag).
		 */
		openTransferOwnershipModal: function () {
			storeInstance.state.modalTransferOpen = true;
			focusFirstInModal( 'transfer-ownership' );
		},

		/**
		 * Confirm a transfer-ownership submission. Reads target user id
		 * from the modal's `[data-bn-transfer-target]` select and POSTs
		 * to /spaces/{id}/transfer.
		 *
		 * @param {Event} event Click on the modal's confirm button.
		 */
		transferOwnership: async function ( event ) {
			var btn = event && event.target && event.target.closest( 'button' );
			if ( ! btn ) { return; }
			var modal = document.querySelector( '[data-bn-modal="transfer-ownership"]' );
			if ( ! modal ) { return; }
			var spaceId    = modal.getAttribute( 'data-bn-space-id' );
			var targetSel  = modal.querySelector( '[data-bn-transfer-target]' );
			var newOwnerId = targetSel ? targetSel.value : '';
			if ( ! spaceId || ! newOwnerId ) {
				var err = modal.querySelector( '[data-bn-transfer-error]' );
				if ( err ) { err.textContent = __i18n( 'Choose a new owner.' ); err.removeAttribute( 'hidden' ); }
				return;
			}

			btn.disabled = true;
			try {
				var res = await restFetch( '/spaces/' + spaceId + '/transfer', {
					method:  'POST',
					nonce:   resolveNonce(),
					body:    { new_owner_id: parseInt( newOwnerId, 10 ) },
					toastOnError: false,
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Ownership transferred.' ), 'success' ); }
					closeAllSpaceModals();
					setTimeout( function () { window.location.reload(); }, 600 );
				} else {
					var data = res.data || {};
					var errEl = modal.querySelector( '[data-bn-transfer-error]' );
					if ( errEl ) {
						errEl.textContent = ( data && data.message ) || __i18n( 'Could not transfer ownership.' );
						errEl.removeAttribute( 'hidden' );
					}
					btn.disabled = false;
				}
			} catch ( _e ) {
				btn.disabled = false;
			}
		},

		/* ── Settings: delete with name-confirm gate ───────────────────── */

		/**
		 * Open the delete-space gated-confirm modal (name-typing gate).
		 */
		openDeleteSpaceConfirm: function () {
			openSpaceModal( 'delete-space-confirm' );
			var gate = document.querySelector( '[data-bn-delete-gate]' );
			if ( gate ) {
				gate.value = '';
				gate.focus();
			}
			var submit = document.querySelector( '[data-bn-delete-submit]' );
			if ( submit ) { submit.disabled = true; }
		},

		/**
		 * Execute the delete request once the gate has been satisfied.
		 *
		 * @param {Event} event Click on the modal's delete button.
		 */
		deleteSpaceConfirmed: async function ( event ) {
			var btn = event && event.target && event.target.closest( 'button' );
			if ( ! btn ) { return; }
			var modal = document.querySelector( '[data-bn-modal="delete-space-confirm"]' );
			if ( ! modal ) { return; }
			var spaceId  = modal.getAttribute( 'data-bn-space-id' );
			var expected = modal.getAttribute( 'data-bn-space-name' );
			var gate     = modal.querySelector( '[data-bn-delete-gate]' );
			var typed    = gate ? gate.value.trim() : '';
			var errEl    = modal.querySelector( '[data-bn-delete-error]' );

			if ( ! spaceId || typed !== expected ) {
				if ( errEl ) {
					errEl.textContent = __i18n( 'The name does not match.' );
					errEl.removeAttribute( 'hidden' );
				}
				return;
			}

			btn.disabled = true;
			try {
				var res = await restFetch( '/spaces/' + spaceId, {
					method:  'DELETE',
					nonce:   resolveNonce(),
					headers: { 'X-BN-Confirm-Space-Name': expected },
					toastOnError: false,
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Space deleted.' ), 'success' ); }
					var dest = ( window.bnSpaces && window.bnSpaces.directoryUrl )
						? window.bnSpaces.directoryUrl
						: '/spaces/';
					setTimeout( function () { window.location.href = dest; }, 500 );
				} else {
					if ( errEl ) {
						errEl.textContent = __i18n( 'Could not delete the space.' );
						errEl.removeAttribute( 'hidden' );
					}
					btn.disabled = false;
				}
			} catch ( _e ) {
				btn.disabled = false;
			}
		},

		/* ── Settings: general / permissions inline save ──────────────── */

		/**
		 * Save the General tab fields via PUT /spaces/{id} optimistically.
		 *
		 * @param {Event} event Click on the Save button.
		 */
		saveGeneral: async function ( event ) {
			var btn = event && event.target && event.target.closest( 'button' );
			if ( ! btn ) { return; }
			var form = document.querySelector( '[data-bn-settings-general-form]' );
			if ( ! form ) { return; }
			var spaceId = form.getAttribute( 'data-space-id' );
			if ( ! spaceId ) { return; }

			var payload = {
				name:        ( form.querySelector( '[name="space_name"]' ) || {} ).value,
				slug:        ( form.querySelector( '[name="space_slug"]' ) || {} ).value,
				description: ( form.querySelector( '[name="space_description"]' ) || {} ).value,
				category_id: parseInt( ( form.querySelector( '[name="space_category_id"]' ) || {} ).value || '0', 10 ),
				type:        ( form.querySelector( '[name="space_type"]' ) || {} ).value,
			};

			btn.disabled = true;
			var origLabel = btn.textContent;
			btn.textContent = __i18n( 'Saving…' );
			try {
				var res = await restFetch( '/spaces/' + spaceId, {
					method:  'PUT',
					nonce:   resolveNonce(),
					body:    payload,
					toastOnError: false,
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Changes saved.' ), 'success' ); }
				} else {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not save changes.' ), 'danger' ); }
				}
			} catch ( _e ) {
				if ( window.bnToast ) { window.bnToast( __i18n( 'Network error.' ), 'danger' ); }
			} finally {
				btn.disabled = false;
				btn.textContent = origLabel;
			}
		},

		/**
		 * Save the Permissions tab fields via PUT /spaces/{id}/permissions.
		 *
		 * @param {Event} event Click on the Save button.
		 */
		savePermissions: async function ( event ) {
			var btn = event && event.target && event.target.closest( 'button' );
			if ( ! btn ) { return; }
			var form = document.querySelector( '[data-bn-settings-permissions-form]' );
			if ( ! form ) { return; }
			var spaceId = form.getAttribute( 'data-space-id' );
			if ( ! spaceId ) { return; }

			var payload = {
				allow_member_posts:    ( form.querySelector( '[name="allow_member_posts"]' ) || {} ).checked ? 1 : 0,
				require_join_approval: ( form.querySelector( '[name="require_join_approval"]' ) || {} ).checked ? 1 : 0,
			};

			btn.disabled = true;
			try {
				var res = await restFetch( '/spaces/' + spaceId + '/permissions', {
					method:  'PUT',
					nonce:   resolveNonce(),
					body:    payload,
					toastOnError: false,
				} );
				if ( res.ok && window.bnToast ) {
					window.bnToast( __i18n( 'Permissions saved.' ), 'success' );
				} else if ( ! res.ok && window.bnToast ) {
					window.bnToast( __i18n( 'Could not save permissions.' ), 'danger' );
				}
			} catch ( _e ) {
				if ( window.bnToast ) { window.bnToast( __i18n( 'Network error.' ), 'danger' ); }
			} finally {
				btn.disabled = false;
			}
		},

		/**
		 * Open the archive-space confirm modal (reactive flag).
		 */
		openArchiveSpaceModal: function () {
			storeInstance.state.modalArchiveOpen = true;
			focusFirstInModal( 'archive-space' );
		},

		/**
		 * Confirm archiving the space. POSTs to /spaces/{id}/archive (owner/admin
		 * enforced server-side) and reloads so the space renders in its read-only
		 * state. Bound to the archive modal's confirm button.
		 *
		 * @param {Event} event Click event from the confirm button (carries data-space-id).
		 */
		archiveSpace: async function ( event ) {
			var btn = event && event.target && event.target.closest( 'button' );
			if ( ! btn ) { return; }
			var spaceId = btn.getAttribute( 'data-space-id' );
			if ( ! spaceId ) { return; }
			btn.disabled = true;
			try {
				var res = await restFetch( '/spaces/' + spaceId + '/archive', {
					method:  'POST',
					nonce:   resolveNonce(),
					toastOnError: false,
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Space archived.' ), 'success' ); }
					setTimeout( function () { window.location.reload(); }, 500 );
				} else {
					btn.disabled = false;
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not archive the space. Try again.' ), 'danger' ); }
				}
			} catch ( _e ) {
				btn.disabled = false;
				if ( window.bnToast ) { window.bnToast( __i18n( 'Could not archive the space. Try again.' ), 'danger' ); }
			}
		},

		/* ── Settings: reactive sticky savebar ─────────────────────────────
		 *
		 * Replaces the old imperative showState() dataset/.hidden paint loop.
		 * Each settings form fires input/change → savebarMarkDirty flips
		 * state.savebarPhase to 'dirty' (the bar + the dirty pill bind their
		 * hidden off that phase). Save submits the dirty form; Cancel rolls it
		 * back to the captured defaults. */

		/**
		 * Mark the touched settings form dirty and surface the savebar.
		 *
		 * @param {Event} event input/change event from a settings form.
		 */
		savebarMarkDirty: function ( event ) {
			var form = event && event.currentTarget
				? event.currentTarget
				: ( event && event.target ? event.target.closest( 'form' ) : null );
			if ( ! form ) { return; }
			bnSavebarDirtyForm = form;
			storeInstance.state.savebarPhase = 'dirty';
		},

		/**
		 * Submit the currently-dirty settings form (native POST preserves the
		 * wp_nonce field + validation). Flips to the 'saving' phase first.
		 */
		savebarSubmit: function () {
			var form = bnSavebarDirtyForm;
			if ( ! form ) { return; }
			if ( ! form.reportValidity || form.reportValidity() ) {
				storeInstance.state.savebarPhase = 'saving';
				// Let the beforeunload guard pass the POST through.
				form.dataset.bnSubmitting = '1';
				form.submit();
			}
		},

		/**
		 * Roll back unsaved edits and settle the savebar back to idle.
		 */
		savebarCancel: function () {
			if ( bnSavebarDirtyForm ) { bnSavebarRollback( bnSavebarDirtyForm ); }
			bnSavebarDirtyForm = null;
			storeInstance.state.savebarPhase = 'idle';
		},

		/* ── Space home: notification pref + tab switch ──────────────────── */

		/**
		 * Toggle the notification-preference popover open/closed.
		 *
		 * @param {Event} event Click on the bell button.
		 */
		toggleNotifPopover: function ( event ) {
			var trigger = event && event.target && event.target.closest( '[data-bn-notif-trigger]' );
			if ( ! trigger ) { return; }
			var popover = trigger.closest( '[data-bn-notif-popover]' );
			if ( ! popover ) { return; }
			var list = popover.querySelector( '[data-bn-notif-list]' );
			if ( ! list ) { return; }
			if ( list.hasAttribute( 'hidden' ) ) {
				list.removeAttribute( 'hidden' );
				trigger.setAttribute( 'aria-expanded', 'true' );
			} else {
				list.setAttribute( 'hidden', '' );
				trigger.setAttribute( 'aria-expanded', 'false' );
			}
		},

		/**
		 * Set the per-space notification preference for the current user.
		 *
		 * @param {Event} event Click on a `[data-bn-notif-pref]` option.
		 */
		setNotificationPref: async function ( event ) {
			var btn = event && event.target && event.target.closest( '[data-bn-notif-pref]' );
			if ( ! btn ) { return; }
			var pref    = btn.getAttribute( 'data-bn-notif-pref' );
			var spaceId = resolveSpaceId( btn ) ||
				( document.querySelector( '[data-wp-interactive="buddynext/spaces"][data-space-id]' ) || {} ).dataset?.spaceId;
			if ( ! pref || ! spaceId ) { return; }

			var options = document.querySelectorAll( '[data-bn-notif-pref]' );
			var previousSelected = null;
			for ( var i = 0; i < options.length; i++ ) {
				if ( options[ i ].getAttribute( 'aria-selected' ) === 'true' ) { previousSelected = options[ i ]; }
				options[ i ].setAttribute(
					'aria-selected',
					options[ i ] === btn ? 'true' : 'false'
				);
			}

			// Close popover after selection.
			var list    = document.querySelector( '[data-bn-notif-list]' );
			var trigger = document.querySelector( '[data-bn-notif-trigger]' );
			if ( list ) { list.setAttribute( 'hidden', '' ); }
			if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'false' ); }

			try {
				var res = await restFetch( '/spaces/' + spaceId + '/notification-pref', {
					method:  'POST',
					nonce:   resolveNonce(),
					body:    { pref: pref },
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					// Rollback.
					if ( previousSelected ) {
						for ( var j = 0; j < options.length; j++ ) {
							options[ j ].setAttribute(
								'aria-selected',
								options[ j ] === previousSelected ? 'true' : 'false'
							);
						}
					}
					if ( window.bnToast ) {
						window.bnToast( __i18n( 'Could not update notification preference.' ), 'danger' );
					}
				} else if ( window.bnToast ) {
					window.bnToast( __i18n( 'Notification preference saved.' ), 'success' );
				}
			} catch ( _e ) {
				if ( previousSelected ) {
					for ( var k = 0; k < options.length; k++ ) {
						options[ k ].setAttribute(
							'aria-selected',
							options[ k ] === previousSelected ? 'true' : 'false'
						);
					}
				}
			}
		},

		/**
		 * Switch the active space-home tab. Falls back to a navigation
		 * when the tab href is set on an anchor; otherwise updates the
		 * URL query var without a reload (where supported).
		 *
		 * @param {Event} event Click on a tab.
		 */
		setTab: function ( event ) {
			var link = event && event.target && event.target.closest( 'a' );
			if ( ! link || ! link.href ) { return; }
			// Default behaviour navigates; intentional so the new tab body is server-rendered.
		},

		/**
		 * Open the invite modal (owner/mod only).
		 */
		openInviteModal: function () {
			openSpaceModal( 'invite-member' );
			var input = document.querySelector( '[data-bn-invite-identifier]' );
			if ( input ) {
				input.value = '';
				input.focus();
			}
			var err = document.querySelector( '[data-bn-invite-error]' );
			if ( err ) {
				err.textContent = '';
				err.setAttribute( 'hidden', '' );
			}
		},

		/**
		 * Submit the invite modal: resolve the typed username/email and POST
		 * to /spaces/{id}/invite. Mirrors the settings-panel invite form.
		 *
		 * @param {Event} event Click on the modal's submit button.
		 */
		inviteMember: async function ( event ) {
			var btn = event && event.target && event.target.closest( 'button' );
			if ( ! btn ) { return; }
			var modal = document.querySelector( '[data-bn-modal="invite-member"]' );
			if ( ! modal ) { return; }
			var spaceId    = modal.getAttribute( 'data-bn-space-id' );
			var input      = modal.querySelector( '[data-bn-invite-identifier]' );
			var identifier = input ? input.value.trim() : '';
			var errEl      = modal.querySelector( '[data-bn-invite-error]' );

			if ( ! spaceId || '' === identifier ) {
				if ( errEl ) {
					errEl.textContent = __i18n( 'Enter a username or email address.' );
					errEl.removeAttribute( 'hidden' );
				}
				return;
			}

			btn.disabled = true;
			if ( errEl ) {
				errEl.textContent = '';
				errEl.setAttribute( 'hidden', '' );
			}

			try {
				var res = await restFetch( '/spaces/' + spaceId + '/invite', {
					method:  'POST',
					nonce:   resolveNonce(),
					body:    { identifier: identifier },
					toastOnError: false,
				} );
				if ( res.ok ) {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Invitation sent.' ), 'success' ); }
					closeAllSpaceModals();
				} else {
					var data = res.data || {};
					if ( errEl ) {
						errEl.textContent = ( data && data.message ) || __i18n( 'Could not send the invitation.' );
						errEl.removeAttribute( 'hidden' );
					}
					btn.disabled = false;
				}
			} catch ( _e ) {
				if ( errEl ) {
					errEl.textContent = __i18n( 'Could not send the invitation.' );
					errEl.removeAttribute( 'hidden' );
				}
				btn.disabled = false;
			}
		},

		/**
		 * Submit the invite modal when Enter is pressed in the identifier field.
		 *
		 * @param {Event} event Keydown event from the identifier input.
		 */
		inviteMemberKeydown: function ( event ) {
			if ( ! event || 'Enter' !== event.key ) { return; }
			event.preventDefault();
			var submit = document.querySelector( '[data-bn-modal="invite-member"] [data-bn-invite-submit]' );
			if ( submit ) { submit.click(); }
		},

		/* ── Directory: reactive filter / sort / search ────────────────── */

		/**
		 * Apply the current filter state and re-render the grid.
		 */
		applyFilter: function () {
			applySpacesFilter();
		},

		/**
		 * Select a scope/category chip and re-apply the filter.
		 *
		 * One single-select row: All Spaces / My Spaces / category chips. Lighting
		 * the clicked chip and clearing every other `[data-bn-scope-chip]` is what
		 * guarantees exactly one active filter (no "two chips lit" state).
		 *
		 * @param {Event} event Click event from a `[data-bn-scope-chip]`.
		 */
		setScope: function ( event ) {
			var btn = event && event.target && event.target.closest( '[data-bn-scope-chip]' );
			if ( ! btn ) { return; }
			var chips = document.querySelectorAll( '[data-bn-scope-chip]' );
			for ( var i = 0; i < chips.length; i++ ) {
				chips[ i ].setAttribute(
					'aria-selected',
					chips[ i ] === btn ? 'true' : 'false'
				);
			}
			applySpacesFilter();
		},

		/**
		 * Choose a sort option from the popover.
		 *
		 * @param {Event} event Click event from a `[data-bn-sort-value]`.
		 */
		setSort: function ( event ) {
			var btn = event && event.target && event.target.closest( '[data-bn-sort-value]' );
			if ( ! btn ) { return; }
			var nextSort = btn.getAttribute( 'data-bn-sort-value' ) || 'popular';
			var label    = btn.textContent.trim();
			closeSortPopover();
			var trigger   = document.querySelector( '[data-bn-sort-trigger]' );
			var labelHost = document.querySelector( '[data-bn-sort-label]' );
			if ( trigger ) { trigger.setAttribute( 'data-current-sort', nextSort ); }
			if ( labelHost ) { labelHost.textContent = label; }
			var options = document.querySelectorAll( '[data-bn-sort-value]' );
			for ( var i = 0; i < options.length; i++ ) {
				options[ i ].setAttribute(
					'aria-selected',
					options[ i ] === btn ? 'true' : 'false'
				);
			}
			applySpacesFilter();
		},

		/**
		 * Toggle the visible state of the sort popover.
		 *
		 * @param {Event} event Click event from the trigger.
		 */
		toggleSortPopover: function ( event ) {
			var trigger = event && event.target && event.target.closest( '[data-bn-sort-trigger]' );
			if ( ! trigger ) { return; }
			var popover = trigger.closest( '[data-bn-sort-popover]' );
			if ( ! popover ) { return; }
			var list = popover.querySelector( '[data-bn-sort-list]' );
			if ( ! list ) { return; }
			var open = list.hasAttribute( 'hidden' );
			if ( open ) {
				list.removeAttribute( 'hidden' );
				trigger.setAttribute( 'aria-expanded', 'true' );
			} else {
				list.setAttribute( 'hidden', '' );
				trigger.setAttribute( 'aria-expanded', 'false' );
			}
		},

		/**
		 * Reset all filter state to defaults and re-apply.
		 */
		resetFilters: function () {
			var searchInput = document.querySelector( 'input[name="bn_search"]' );
			if ( searchInput ) { searchInput.value = ''; }
			// Reset the single-select row back to "All Spaces"; scope and category
			// are reactive now, so this clears everything without a page reload.
			var chips = document.querySelectorAll( '[data-bn-scope-chip]' );
			for ( var i = 0; i < chips.length; i++ ) {
				chips[ i ].setAttribute(
					'aria-selected',
					chips[ i ].getAttribute( 'data-bn-scope-chip' ) === 'all' ? 'true' : 'false'
				);
			}
			applySpacesFilter();
		},

		/* ── Directory: create-space modal ─────────────────────────────── */

		/**
		 * Open the create-space modal partial.
		 */
		openCreate: function () {
			openSpaceModal( 'create-space' );
			var name = document.querySelector( '[data-bn-create-space-name]' );
			if ( name ) { name.focus(); }
		},

		/**
		 * Close the create-space modal.
		 */
		closeCreate: function () {
			closeAllSpaceModals();
		},

		/**
		 * Submit the create-space modal form to POST /buddynext/v1/spaces.
		 *
		 * @param {Event} event Click on the submit button.
		 */
		submitCreate: async function ( event ) {
			var btn  = event && event.target && event.target.closest( 'button' );
			var form = document.querySelector( '[data-bn-create-space-form]' );
			if ( ! form ) { return; }

			clearCreateSpaceErrors( form );

			var name        = ( form.querySelector( '[name="name"]' ) || {} ).value || '';
			var slug        = ( form.querySelector( '[name="slug"]' ) || {} ).value || '';
			var type        = ( form.querySelector( '[name="type"]' ) || {} ).value || 'open';
			var description = ( form.querySelector( '[name="description"]' ) || {} ).value || '';
			var categoryEl  = form.querySelector( '[name="category_id"]' );
			var categoryId  = categoryEl ? categoryEl.value : '';

			if ( ! name.trim() ) {
				showCreateSpaceError( form, 'name', __i18n( 'Please enter a name.' ) );
				return;
			}

			if ( btn ) { btn.disabled = true; btn.dataset.bnOrigText = btn.textContent; btn.textContent = __i18n( 'Creating…' ); }

			var payload = {
				name:        name.trim(),
				slug:        slug.trim(),
				type:        type,
				description: description.trim(),
			};
			if ( categoryId ) { payload.category_id = parseInt( categoryId, 10 ); }
			var bnParentEl = form.querySelector( '[name="parent_id"]' );
			if ( bnParentEl && parseInt( bnParentEl.value || '0', 10 ) > 0 ) { payload.parent_id = parseInt( bnParentEl.value, 10 ); }

			try {
				var res = await restFetch( '/spaces', {
					method:  'POST',
					nonce:   resolveNonce(),
					body:    payload,
					toastOnError: false,
				} );
				var data = res.data || {};

				if ( res.ok && data && data.id ) {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Space created.' ), 'success' ); }
					var slugOut = data.slug || slug;
					if ( slugOut ) {
						window.location.href = ( window.bnSpaces && window.bnSpaces.spaceUrlBase )
							? window.bnSpaces.spaceUrlBase.replace( '__slug__', slugOut )
							: ( window.location.origin + '/spaces/' + slugOut + '/' );
					} else {
						window.location.reload();
					}
					return;
				}

				if ( data && data.data && data.data.params ) {
					Object.keys( data.data.params ).forEach( function ( field ) {
						showCreateSpaceError( form, field, data.data.params[ field ] );
					} );
				} else if ( data && data.message ) {
					showCreateSpaceError( form, '_global', data.message );
				} else {
					showCreateSpaceError( form, '_global', __i18n( 'Could not create the space.' ) );
				}
			} catch ( _e ) {
				showCreateSpaceError( form, '_global', __i18n( 'Network error. Please try again.' ) );
			} finally {
				if ( btn ) {
					btn.disabled    = false;
					btn.textContent = btn.dataset.bnOrigText || __i18n( 'Create space' );
				}
			}
		},

	},
} );

/* ── Spaces directory filter helpers ─────────────────────────────── */

var bnSpacesFilterTimer = null;
var bnSpacesFilterAbort = null;

/**
 * Derive the current filter state from the live DOM.
 *
 * @return {Object} URL-encodable filter values.
 */
function readSpacesFilterState() {
	var search = document.querySelector( 'input[name="bn_search"]' );
	var sortEl = document.querySelector( '[data-bn-sort-trigger]' );
	var sort   = sortEl && sortEl.getAttribute( 'data-current-sort' );
	if ( ! sort ) {
		var selected = document.querySelector( '[data-bn-sort-value][aria-selected="true"]' );
		sort         = selected ? selected.getAttribute( 'data-bn-sort-value' ) : 'popular';
	}
	// One single-select chip drives scope + category. "mine" maps to the REST
	// `mine` flag; a category chip carries both its id (REST `category_id`) and
	// slug (for the shareable URL + the SSR highlight on reload). They are
	// mutually exclusive — only one chip is ever lit.
	var scopeChip    = document.querySelector( '[data-bn-scope-chip][aria-selected="true"]' );
	var scope        = scopeChip ? ( scopeChip.getAttribute( 'data-bn-scope-chip' ) || 'all' ) : 'all';
	var isCat        = ( 'cat' === scope && scopeChip );
	return {
		q:            search ? search.value : '',
		mine:         ( 'mine' === scope ),
		categoryId:   isCat ? ( scopeChip.getAttribute( 'data-bn-cat-id' ) || '' ) : '',
		categorySlug: isCat ? ( scopeChip.getAttribute( 'data-bn-cat-slug' ) || '' ) : '',
		sort:         sort || 'popular',
	};
}

/**
 * Build a single space card in the DOM from a REST row.
 * Mirrors the SSR markup of templates/spaces/directory.php using
 * createElement + textContent so injected strings are never interpreted
 * as HTML.
 *
 * @param {Object} row Space row from /buddynext/v1/spaces.
 * @return {HTMLElement} Article element ready to be appended to the grid.
 */
function buildSpaceCard( row ) {
	var name        = row.name || '';
	var slug        = row.slug || '';
	var description = row.description || '';
	var memberCount = ( row.member_count != null ) ? row.member_count : 0;
	var type        = row.type || 'open';
	var spaceId     = row.id;

	// Privacy label/tone come from the server (type_label/type_tone) so the
	// reactive card matches the SSR card exactly, including custom space types.
	var privacyLabel = row.type_label || __i18n( 'Public' );
	var privacyTone  = row.type_tone || ( 'open' === type ? 'info' : ( 'private' === type ? 'warn' : 'danger' ) );

	var coverTone = row.cover_tone || 'sky';

	var baseUrl = ( window.bnSpaces && window.bnSpaces.spaceUrlBase )
		? window.bnSpaces.spaceUrlBase.replace( '__slug__', slug )
		: ( '/spaces/' + slug + '/' );

	var article = document.createElement( 'article' );
	article.className = 'bn-card bn-sd-card';
	article.setAttribute( 'role', 'listitem' );
	article.setAttribute( 'aria-label', name + ' (' + privacyLabel + ')' );
	article.dataset.spaceId     = String( spaceId );
	article.dataset.interactive = '';

	// ── Cover (+ optional image) + emblem ──────────────────────────────
	var coverLink = document.createElement( 'a' );
	coverLink.href = baseUrl;
	coverLink.setAttribute( 'tabindex', '-1' );
	coverLink.setAttribute( 'aria-hidden', 'true' );
	coverLink.className = 'bn-sd-card__cover-link';

	var cover = document.createElement( 'div' );
	cover.className   = 'bn-sd-card__cover';
	cover.dataset.tone = coverTone;
	if ( row.cover_image_url ) {
		var coverImg = document.createElement( 'img' );
		coverImg.src     = row.cover_image_url;
		coverImg.alt     = '';
		coverImg.loading = 'lazy';
		cover.appendChild( coverImg );
	}

	// Emblem fallback chain: avatar image → category-icon (cloned from an
	// existing SSR card when available) → first-letter glyph. Never empty.
	var emblem = document.createElement( 'div' );
	emblem.className = 'bn-sd-card__emblem';
	emblem.setAttribute( 'aria-hidden', 'true' );
	if ( row.avatar_url ) {
		var avImg = document.createElement( 'img' );
		avImg.src     = row.avatar_url;
		avImg.alt     = '';
		avImg.loading = 'lazy';
		emblem.appendChild( avImg );
	} else {
		var letter = document.createElement( 'span' );
		letter.className   = 'bn-sd-card__emblem-letter';
		letter.textContent = ( name.charAt( 0 ) || '' ).toUpperCase();
		emblem.appendChild( letter );
	}
	cover.appendChild( emblem );
	coverLink.appendChild( cover );
	article.appendChild( coverLink );

	// ── Body ───────────────────────────────────────────────────────────
	var body = document.createElement( 'div' );
	body.className = 'bn-sd-card__body';

	var nameLink = document.createElement( 'a' );
	nameLink.href = baseUrl;
	nameLink.className = 'bn-sd-card__name-link';
	var h2 = document.createElement( 'h2' );
	h2.className = 'bn-sd-card__name';
	h2.setAttribute( 'aria-label', name + ' (' + privacyLabel + ')' );
	h2.appendChild( document.createTextNode( name ) );
	var badge = document.createElement( 'span' );
	badge.className = 'bn-badge';
	badge.dataset.tone = privacyTone;
	badge.textContent  = privacyLabel;
	h2.appendChild( badge );
	nameLink.appendChild( h2 );
	body.appendChild( nameLink );

	// Category line (icon cloned from a live SSR card if one exists).
	if ( row.category_name ) {
		var cat = document.createElement( 'div' );
		cat.className = 'bn-sd-card__category';
		var refIcon = document.querySelector( '.bn-sd-card__category svg' );
		if ( refIcon ) { cat.appendChild( refIcon.cloneNode( true ) ); }
		cat.appendChild( document.createTextNode( ' ' + row.category_name ) );
		body.appendChild( cat );
	}

	if ( description ) {
		var desc = document.createElement( 'p' );
		desc.className = 'bn-sd-card__desc';
		desc.textContent = description;
		body.appendChild( desc );
	}

	var stats = document.createElement( 'div' );
	stats.className = 'bn-sd-card__stats';
	var stat = document.createElement( 'span' );
	stat.className   = 'bn-sd-card__stat';
	/* translators: %s: member count. */
	stat.textContent = memberCount + ' ' + __i18n( 'members' );
	stats.appendChild( stat );
	body.appendChild( stats );

	// ── Foot: membership-aware CTA, mirroring directory.php ────────────
	var role        = row.membership_role || '';
	var status      = row.membership_status || '';
	var isAdminMod  = ( 'active' === status ) && ( 'owner' === role || 'admin' === role || 'moderator' === role );
	var isMember    = ( 'active' === status );
	var isPending   = ( 'pending' === status );
	var joinMethod  = row.join_method || ( 'open' === type ? 'direct' : 'request' );

	var foot = document.createElement( 'div' );
	foot.className = 'bn-sd-card__foot';

	var ctaEl;
	if ( isAdminMod ) {
		ctaEl = document.createElement( 'a' );
		ctaEl.href = baseUrl + 'settings/';
		ctaEl.className = 'bn-btn';
		ctaEl.dataset.variant = 'secondary';
		ctaEl.dataset.size    = 'sm';
		ctaEl.textContent     = __i18n( 'Manage' );
	} else if ( isMember ) {
		ctaEl = document.createElement( 'button' );
		ctaEl.className = 'bn-btn';
		ctaEl.dataset.variant      = 'secondary';
		ctaEl.dataset.size         = 'sm';
		ctaEl.dataset.currentState = 'joined';
		ctaEl.setAttribute( 'data-wp-on--click', 'actions.leaveSpace' );
		ctaEl.dataset.spaceId      = String( spaceId );
		ctaEl.setAttribute( 'aria-label', __i18n( 'Joined - click to leave' ) );
		ctaEl.textContent = __i18n( 'Joined' );
	} else if ( isPending ) {
		ctaEl = document.createElement( 'button' );
		ctaEl.className = 'bn-btn';
		ctaEl.dataset.variant      = 'secondary';
		ctaEl.dataset.size         = 'sm';
		ctaEl.dataset.currentState = 'pending';
		ctaEl.setAttribute( 'data-wp-on--click', 'actions.cancelJoinRequest' );
		ctaEl.dataset.spaceId      = String( spaceId );
		ctaEl.setAttribute( 'aria-label', __i18n( 'Request pending - click to cancel' ) );
		ctaEl.textContent = __i18n( 'Requested' );
	} else if ( 'direct' === joinMethod ) {
		ctaEl = document.createElement( 'button' );
		ctaEl.className = 'bn-btn';
		ctaEl.dataset.variant      = 'primary';
		ctaEl.dataset.size         = 'sm';
		ctaEl.dataset.currentState = 'join';
		ctaEl.setAttribute( 'data-wp-on--click', 'actions.joinSpace' );
		ctaEl.dataset.spaceId      = String( spaceId );
		ctaEl.textContent = __i18n( 'Join' );
	} else {
		ctaEl = document.createElement( 'button' );
		ctaEl.className = 'bn-btn';
		ctaEl.dataset.variant      = 'secondary';
		ctaEl.dataset.size         = 'sm';
		ctaEl.dataset.currentState = 'request';
		ctaEl.setAttribute( 'data-wp-on--click', 'actions.requestJoin' );
		ctaEl.dataset.spaceId      = String( spaceId );
		ctaEl.textContent = __i18n( 'Request to join' );
	}
	// This card is created after initial hydration, so its
	// data-wp-on--click directive is inert (the Interactivity API only binds
	// directives present in the server-rendered HTML). Mark the CTA so the
	// delegated click handler in the wiring section dispatches it instead.
	ctaEl.setAttribute( 'data-bn-dyn', '1' );
	foot.appendChild( ctaEl );
	body.appendChild( foot );

	article.appendChild( body );
	return article;
}

function __i18n( s ) {
	if ( window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function' ) {
		return window.wp.i18n.__( s, 'buddynext' );
	}
	return s;
}

function setDirectoryUiState( state ) {
	var loading = document.querySelector( '[data-bn-loading]' );
	var error   = document.querySelector( '[data-bn-error]' );
	var grid    = document.querySelector( '[data-bn-sd-grid]' );
	var empty   = document.querySelector( '[data-bn-sd-empty]' );
	var status  = document.querySelector( '[data-bn-filter-status]' );

	if ( loading ) {
		if ( state === 'loading' ) { loading.removeAttribute( 'hidden' ); }
		else { loading.setAttribute( 'hidden', '' ); }
	}
	if ( error ) {
		if ( state === 'error' ) { error.removeAttribute( 'hidden' ); }
		else { error.setAttribute( 'hidden', '' ); }
	}
	if ( status ) {
		if ( state === 'loading' ) { status.removeAttribute( 'hidden' ); }
		else { status.setAttribute( 'hidden', '' ); }
	}

	if ( state === 'empty' ) {
		if ( grid ) { grid.style.display = 'none'; }
		if ( empty ) { empty.style.display = ''; }
	} else if ( state === 'ready' ) {
		if ( grid ) { grid.style.display = ''; }
		if ( empty ) { empty.style.display = 'none'; }
	}
}

function applySpacesFilter() {
	if ( bnSpacesFilterTimer ) { clearTimeout( bnSpacesFilterTimer ); }
	bnSpacesFilterTimer = setTimeout( function () {
		executeSpacesFilter();
	}, 250 );
}

async function executeSpacesFilter() {
	if ( bnSpacesFilterAbort ) {
		try { bnSpacesFilterAbort.abort(); } catch ( _e ) {}
	}
	bnSpacesFilterAbort = ( typeof AbortController === 'function' ) ? new AbortController() : null;

	var state = readSpacesFilterState();
	setDirectoryUiState( 'loading' );

	var params = new URLSearchParams();
	if ( state.q ) { params.set( 'search', state.q ); }
	if ( state.mine ) { params.set( 'mine', '1' ); }
	if ( state.categoryId ) { params.set( 'category_id', state.categoryId ); }
	if ( state.sort ) { params.set( 'orderby', state.sort ); }
	params.set( 'per_page', '18' );

	try {
		var res = await restFetch( '/spaces?' + params.toString(), {
			method:  'GET',
			nonce:   resolveNonce(),
			signal:  bnSpacesFilterAbort ? bnSpacesFilterAbort.signal : undefined,
			toastOnError: false,
		} );

		if ( ! res.ok ) {
			// A superseded keystroke aborts the in-flight request; restFetch
			// resolves it to { ok:false, status:0 } rather than throwing, so a
			// status of 0 means "aborted/network" — never flash the error state
			// when the abort controller is the cause.
			if ( bnSpacesFilterAbort && bnSpacesFilterAbort.signal && bnSpacesFilterAbort.signal.aborted ) {
				return;
			}
			setDirectoryUiState( 'error' );
			return;
		}

		var rows = res.data;
		if ( ! Array.isArray( rows ) ) { rows = ( rows && rows.items ) || []; }

		var grid = document.querySelector( '[data-bn-sd-grid]' );
		if ( ! grid ) {
			// Grid not present (page was server-rendered empty). Reload to
			// pick up the SSR grid scaffold on the next request.
			window.location.search = params.toString();
			return;
		}

		// Clear current grid contents.
		while ( grid.firstChild ) { grid.removeChild( grid.firstChild ); }

		if ( 0 === rows.length ) {
			setDirectoryUiState( 'empty' );
			return;
		}

		for ( var i = 0; i < rows.length; i++ ) {
			grid.appendChild( buildSpaceCard( rows[ i ] ) );
		}
		setDirectoryUiState( 'ready' );

		// Update URL state without reload for shareable links. Scope + category
		// are written as bn_scope / bn_cat (slug) so a reload re-renders the same
		// filtered grid server-side and re-lights the matching chip.
		try {
			var url = new URL( window.location.href );
			if ( state.q ) { url.searchParams.set( 'bn_search', state.q ); }
			else { url.searchParams.delete( 'bn_search' ); }
			if ( state.mine ) { url.searchParams.set( 'bn_scope', 'mine' ); }
			else { url.searchParams.delete( 'bn_scope' ); }
			if ( state.categorySlug ) { url.searchParams.set( 'bn_cat', state.categorySlug ); }
			else { url.searchParams.delete( 'bn_cat' ); }
			url.searchParams.delete( 'bn_type' );
			url.searchParams.delete( 'bn_page' );
			if ( state.sort && 'popular' !== state.sort ) { url.searchParams.set( 'bn_sort', state.sort ); }
			else { url.searchParams.delete( 'bn_sort' ); }
			window.history.replaceState( {}, '', url.toString() );
		} catch ( _e ) {}
	} catch ( err ) {
		if ( err && 'AbortError' === err.name ) { return; }
		setDirectoryUiState( 'error' );
	}
}

function clearCreateSpaceErrors( form ) {
	var nodes = form.querySelectorAll( '[data-bn-error-for]' );
	for ( var i = 0; i < nodes.length; i++ ) {
		nodes[ i ].textContent = '';
		nodes[ i ].setAttribute( 'hidden', '' );
	}
}

function showCreateSpaceError( form, field, message ) {
	var node = form.querySelector( '[data-bn-error-for="' + field + '"]' );
	if ( ! node ) {
		node = form.querySelector( '[data-bn-error-for="_global"]' );
	}
	if ( ! node ) { return; }
	node.textContent = String( message );
	node.removeAttribute( 'hidden' );
}

function closeSortPopover() {
	var list    = document.querySelector( '[data-bn-sort-list]' );
	var trigger = document.querySelector( '[data-bn-sort-trigger]' );
	if ( list ) { list.setAttribute( 'hidden', '' ); }
	if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'false' ); }
}

/* ── Wiring: reactive listeners on the spaces directory ─────────────── */

/*
 * Delegated dispatch for dynamically-rebuilt directory cards.
 *
 * Filtering by tab/search replaces the server-rendered card grid with cards
 * built in JS (buildSpaceCard), whose data-wp-on--click directives the
 * Interactivity API never binds — directives only hydrate from the initial
 * server HTML. Without this, Join / Leave / Request / Cancel buttons on
 * filtered results are inert. Bound once at module load (delegation works for
 * nodes added later), and reads the action name fresh on every click so a
 * state-swapped button (Join -> Joined/Leave) dispatches the correct action.
 * Server-rendered cards lack the data-bn-dyn marker, so they continue to run
 * through the Interactivity API with no double-binding.
 */
document.addEventListener( 'click', function ( event ) {
	var btn = ( event.target && event.target.closest )
		? event.target.closest( 'button[data-bn-dyn][data-wp-on--click]' )
		: null;
	if ( ! btn ) { return; }
	var name = ( btn.getAttribute( 'data-wp-on--click' ) || '' ).replace( /^actions\./, '' );
	if ( ! name || ! storeInstance || ! storeInstance.actions || typeof storeInstance.actions[ name ] !== 'function' ) {
		return;
	}
	storeInstance.actions[ name ]( event );
} );

/**
 * Escape a string for safe innerHTML insertion (member names/handles are
 * user-controlled, so they must be escaped before going into the dropdown).
 *
 * @param {*} str Raw value.
 * @return {string} HTML-escaped value.
 */
function bnInviteEscape( str ) {
	return String( null == str ? '' : str ).replace( /[&<>"']/g, function ( c ) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ c ];
	} );
}

/**
 * Wire a member typeahead onto an invite-identifier input. Suggests existing
 * members via the same GET /members?search= endpoint the composer @-mention
 * uses; selecting one fills the input with the @handle and the sibling hidden
 * target_user_id. Non-members are invited by typing a full email — no
 * suggestions are shown for addresses, and the existing submit path resolves
 * them by email. Keys are handled in the capture phase so the dropdown preempts
 * the hero modal's Interactivity keydown when it is open.
 *
 * @param {HTMLInputElement} input Invite identifier input.
 */
function attachInviteTypeahead( input ) {
	if ( input.dataset.bnInviteEnhanced ) {
		return;
	}
	input.dataset.bnInviteEnhanced = '1';
	input.setAttribute( 'autocomplete', 'off' );

	var wrap     = input.parentElement;
	if ( wrap && 'static' === getComputedStyle( wrap ).position ) {
		wrap.style.position = 'relative';
	}
	var hidden   = input.form ? input.form.querySelector( 'input[name="target_user_id"]' ) : null;
	var dropdown = null;
	var items    = [];
	var active   = 0;
	var timer    = null;
	var abort    = null;

	function close() {
		if ( dropdown ) {
			dropdown.remove();
			dropdown = null;
		}
		items  = [];
		active = 0;
	}

	function render() {
		if ( ! dropdown ) {
			dropdown = document.createElement( 'div' );
			dropdown.className = 'bn-composer__typeahead';
			dropdown.setAttribute( 'role', 'listbox' );
			( wrap || input.parentElement ).appendChild( dropdown );
		}
		dropdown.innerHTML = items.map( function ( m, i ) {
			var avatar = m.avatar
				? '<img class="bn-composer__typeahead-avatar" src="' + bnInviteEscape( m.avatar ) + '" alt="" width="28" height="28" loading="lazy">'
				: '';
			var handle = m.handle
				? '<span class="bn-composer__typeahead-handle">@' + bnInviteEscape( m.handle ) + '</span>'
				: '';
			return '<button type="button" role="option" class="bn-composer__typeahead-item" data-i="' + i + '" aria-selected="' + ( i === active ? 'true' : 'false' ) + '">' +
				avatar +
				'<span class="bn-composer__typeahead-text"><span class="bn-composer__typeahead-name">' + bnInviteEscape( m.label ) + '</span>' + handle + '</span>' +
				'</button>';
		} ).join( '' );
		dropdown.querySelectorAll( '.bn-composer__typeahead-item' ).forEach( function ( btn ) {
			btn.addEventListener( 'mousedown', function ( e ) {
				e.preventDefault();
				select( parseInt( btn.dataset.i, 10 ) );
			} );
		} );
	}

	function select( i ) {
		var m = items[ i ];
		if ( ! m ) {
			return;
		}
		input.value = m.handle || m.label;
		if ( hidden ) {
			hidden.value = m.id || 0;
		}
		close();
		input.focus();
	}

	function search( q ) {
		if ( abort ) {
			abort.abort();
		}
		abort = new AbortController();
		restFetch( '/members?search=' + encodeURIComponent( q ) + '&per_page=6', { signal: abort.signal, nonce: resolveNonce(), toastOnError: false } )
			.then( function ( res ) { return res.data; } )
			.then( function ( data ) {
				var rows = data && Array.isArray( data.items ) ? data.items : [];
				items = rows.map( function ( m ) {
					var handle = m.handle || m.user_login || m.username || '';
					return { id: m.id || m.user_id || 0, handle: handle, label: m.display_name || handle, avatar: m.avatar_url || '' };
				} ).filter( function ( m ) { return m.handle || m.label; } );
				active = 0;
				if ( items.length ) { render(); } else { close(); }
			} )
			.catch( function () {} );
	}

	input.addEventListener( 'input', function () {
		if ( hidden ) {
			hidden.value = '0'; // Typing invalidates a prior selection.
		}
		var q = input.value.trim();
		clearTimeout( timer );
		// Skip suggestions for email addresses — non-members are invited by email.
		if ( q.length < 2 || -1 !== q.indexOf( '@' ) ) {
			close();
			return;
		}
		timer = setTimeout( function () { search( q ); }, 200 );
	} );

	input.addEventListener( 'keydown', function ( e ) {
		if ( ! dropdown || ! items.length ) {
			return;
		}
		if ( 'ArrowDown' === e.key ) {
			e.preventDefault(); e.stopImmediatePropagation();
			active = ( active + 1 ) % items.length; render();
		} else if ( 'ArrowUp' === e.key ) {
			e.preventDefault(); e.stopImmediatePropagation();
			active = ( active - 1 + items.length ) % items.length; render();
		} else if ( 'Enter' === e.key ) {
			e.preventDefault(); e.stopImmediatePropagation();
			select( active );
		} else if ( 'Escape' === e.key ) {
			close();
		}
	}, true );

	document.addEventListener( 'click', function ( e ) {
		if ( dropdown && ! dropdown.contains( e.target ) && e.target !== input ) {
			close();
		}
	} );
}

/**
 * Attach the invite typeahead to the settings-panel form input and the hero
 * invite-modal input (both are present only on space pages).
 */
function initInviteTypeahead() {
	[
		document.getElementById( 'bn_invite_identifier' ),
		document.getElementById( 'bn-invite-identifier' ),
	].filter( Boolean ).forEach( attachInviteTypeahead );
}

function initSpacesImperative() {
	initInviteTypeahead();

	var searchInput = document.querySelector( 'input[name="bn_search"]' );
	if ( searchInput && ! searchInput.dataset.bnSpaceSearchWired ) {
		searchInput.dataset.bnSpaceSearchWired = '1';
		searchInput.addEventListener( 'input', function () {
			applySpacesFilter();
		} );
	}
	// The scope/category chips drive applySpacesFilter via actions.setScope;
	// the search box (above) and the sort popover are the other reactive inputs.

	// Suppress the form submit on reactive filter forms so Enter does
	// not reload the page. Per-form guard so a re-init after a client-side
	// navigation can never double-bind the submit listener.
	var reactiveForm = document.querySelector( '[data-bn-reactive]' );
	if ( reactiveForm && ! reactiveForm.dataset.bnSpaceFormWired ) {
		reactiveForm.dataset.bnSpaceFormWired = '1';
		reactiveForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			applySpacesFilter();
		} );
	}

	// Auto-derive slug from name in the create-space modal.
	var nameInput = document.querySelector( '[data-bn-create-space-name]' );
	var slugInput = document.querySelector( '[data-bn-create-space-slug]' );
	if ( nameInput && slugInput && ! nameInput.dataset.bnSpaceSlugWired ) {
		nameInput.dataset.bnSpaceSlugWired = '1';
		var slugTouched = false;
		slugInput.addEventListener( 'input', function () {
			slugTouched = true;
		} );
		nameInput.addEventListener( 'input', function () {
			if ( slugTouched ) { return; }
			slugInput.value = nameInput.value
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, '-' )
				.replace( /^-+|-+$/g, '' )
				.slice( 0, 80 );
		} );
	}

	// Close sort popover on outside click. Document-level singleton — installed
	// once behind a window flag so re-init never stacks duplicate listeners.
	if ( ! window.__bnSpaceSortCloseBound ) {
		window.__bnSpaceSortCloseBound = true;
		document.addEventListener( 'click', function ( e ) {
			var popover = document.querySelector( '[data-bn-sort-popover]' );
			if ( ! popover ) { return; }
			var list = popover.querySelector( '[data-bn-sort-list]' );
			if ( ! list || list.hasAttribute( 'hidden' ) ) { return; }
			if ( ! popover.contains( e.target ) ) {
				closeSortPopover();
			}
		} );
	}

	// Close notif popover on outside click. Document-level singleton.
	if ( ! window.__bnSpaceNotifCloseBound ) {
		window.__bnSpaceNotifCloseBound = true;
		document.addEventListener( 'click', function ( e ) {
			var popover = document.querySelector( '[data-bn-notif-popover]' );
			if ( ! popover ) { return; }
			var list = popover.querySelector( '[data-bn-notif-list]' );
			if ( ! list || list.hasAttribute( 'hidden' ) ) { return; }
			if ( ! popover.contains( e.target ) ) {
				list.setAttribute( 'hidden', '' );
				var trigger = popover.querySelector( '[data-bn-notif-trigger]' );
				if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'false' ); }
			}
		} );
	}

	// Delete-space gate: enable submit only when typed name matches.
	var gate = document.querySelector( '[data-bn-delete-gate]' );
	if ( gate && ! gate.dataset.bnSpaceGateWired ) {
		gate.dataset.bnSpaceGateWired = '1';
		gate.addEventListener( 'input', function () {
			var modal    = gate.closest( '[data-bn-modal="delete-space-confirm"]' );
			var submit   = modal ? modal.querySelector( '[data-bn-delete-submit]' ) : null;
			var expected = modal ? modal.getAttribute( 'data-bn-space-name' ) : '';
			if ( submit ) {
				submit.disabled = ( gate.value.trim() !== expected );
			}
			var err = modal ? modal.querySelector( '[data-bn-delete-error]' ) : null;
			if ( err ) { err.setAttribute( 'hidden', '' ); err.textContent = ''; }
		} );
	}
}

onNavReady( initSpacesImperative );

/* ── Delegated UI helpers (modal close + native-confirm bridge) ─────────
 *
 * Lives outside the Interactivity store so DOM-only buttons (those with
 * data-bn-modal-close / data-bn-confirm but no data-wp-on--click) still
 * work without registering one-off actions per surface.
 * ──────────────────────────────────────────────────────────────────── */

/**
 * Show a `[data-bn-modal="<name>"]` backdrop and trap initial focus.
 *
 * @param {string} name Modal name.
 */
function openSpaceModal( name ) {
	var modal = document.querySelector( '[data-bn-modal="' + name + '"]' );
	if ( ! modal ) { return; }
	modal.hidden = false;
	var focusable = modal.querySelector( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
	if ( focusable ) { focusable.focus(); }
}

/**
 * Move initial focus into a reactive modal after the directive flips its
 * `hidden` binding. The bind runs on the next render tick, so defer the focus
 * to a microtask/raf so the element is focusable (not display:none) by then.
 *
 * @param {string} name Modal name (data-bn-modal value).
 */
function focusFirstInModal( name ) {
	requestAnimationFrame( function () {
		var modal = document.querySelector( '[data-bn-modal="' + name + '"]' );
		if ( ! modal || modal.hidden ) { return; }
		var focusable = modal.querySelector( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
		if ( focusable ) { focusable.focus(); }
	} );
}

/**
 * Hide every modal on the page.
 *
 * The danger-zone modals (transfer / delete / archive) are REACTIVE — their
 * `hidden` is bound to store state — so clear those flags rather than poking
 * `.hidden` (which the binding would immediately overwrite). The remaining
 * imperative modals (invite / create-space / delete-space-confirm) still toggle
 * `.hidden` directly.
 */
function closeAllSpaceModals() {
	if ( storeInstance && storeInstance.state ) {
		storeInstance.state.modalTransferOpen = false;
		storeInstance.state.modalDeleteOpen   = false;
		storeInstance.state.modalArchiveOpen  = false;
	}
	var modals = document.querySelectorAll( '[data-bn-modal]' );
	for ( var i = 0; i < modals.length; i++ ) {
		// Skip the reactive danger modals — their binding owns `hidden`.
		var name = modals[ i ].getAttribute( 'data-bn-modal' );
		if ( 'transfer-ownership' === name || 'delete-space' === name || 'archive-space' === name ) {
			continue;
		}
		modals[ i ].hidden = true;
	}
}

/* ── Confirm modal ─────────────────────────────────────────────────────
 *
 * `data-bn-confirm="<message>"` on any button opens a v2 modal dialog
 * with the supplied message. If the user confirms, the original click
 * is re-dispatched on the same element with an acknowledged flag so
 * the underlying click pipeline (forms, wp Interactivity actions,
 * native links) runs unchanged.
 * ──────────────────────────────────────────────────────────────────── */

var BN_CONFIRM_FLAG = 'data-bn-confirm-acknowledged';
var bnConfirmBackdrop = null;
var bnConfirmRefs = null;

function buildConfirmModal() {
	var backdrop = document.createElement( 'div' );
	backdrop.className = 'bn-modal-backdrop';
	backdrop.setAttribute( 'role', 'dialog' );
	backdrop.setAttribute( 'aria-modal', 'true' );
	backdrop.setAttribute( 'data-bn-confirm-modal', '' );
	backdrop.hidden = true;

	var panel = document.createElement( 'div' );
	panel.className = 'bn-modal__panel';
	panel.setAttribute( 'data-tone', 'danger' );
	panel.setAttribute( 'data-size', 'sm' );

	var head = document.createElement( 'header' );
	head.className = 'bn-modal__head';
	var title = document.createElement( 'h2' );
	title.className = 'bn-modal__title';
	var closeBtn = document.createElement( 'button' );
	closeBtn.type = 'button';
	closeBtn.className = 'bn-modal__close';
	closeBtn.setAttribute( 'data-bn-confirm-cancel', '' );
	closeBtn.setAttribute( 'aria-label', 'Close' );
	closeBtn.textContent = '×';
	head.appendChild( title );
	head.appendChild( closeBtn );

	var body = document.createElement( 'div' );
	body.className = 'bn-modal__body';
	var message = document.createElement( 'p' );
	body.appendChild( message );

	var foot = document.createElement( 'div' );
	foot.className = 'bn-modal__foot';
	var cancelBtn = document.createElement( 'button' );
	cancelBtn.type = 'button';
	cancelBtn.className = 'bn-btn';
	cancelBtn.setAttribute( 'data-variant', 'ghost' );
	cancelBtn.setAttribute( 'data-size', 'md' );
	cancelBtn.setAttribute( 'data-bn-confirm-cancel', '' );
	var okBtn = document.createElement( 'button' );
	okBtn.type = 'button';
	okBtn.className = 'bn-btn';
	okBtn.setAttribute( 'data-variant', 'danger' );
	okBtn.setAttribute( 'data-size', 'md' );
	okBtn.setAttribute( 'data-bn-confirm-ok', '' );
	foot.appendChild( cancelBtn );
	foot.appendChild( okBtn );

	panel.appendChild( head );
	panel.appendChild( body );
	panel.appendChild( foot );
	backdrop.appendChild( panel );
	document.body.appendChild( backdrop );

	return {
		backdrop: backdrop,
		title:    title,
		message:  message,
		ok:       okBtn,
		cancel:   cancelBtn,
		close:    closeBtn,
	};
}

function ensureConfirmModal() {
	if ( bnConfirmRefs ) { return bnConfirmRefs; }
	bnConfirmRefs    = buildConfirmModal();
	bnConfirmBackdrop = bnConfirmRefs.backdrop;
	return bnConfirmRefs;
}

function openConfirmModal( triggerEl ) {
	var refs = ensureConfirmModal();
	refs.title.textContent   = triggerEl.dataset.bnConfirmTitle || 'Please confirm';
	refs.message.textContent = triggerEl.dataset.bnConfirm || '';
	refs.ok.textContent      = triggerEl.dataset.bnConfirmOk || 'Confirm';
	refs.cancel.textContent  = triggerEl.dataset.bnConfirmCancel || 'Cancel';

	if ( ! triggerEl.id ) {
		triggerEl.dataset.bnConfirmAutoId = 'bn-confirm-' + Math.random().toString( 36 ).slice( 2 );
	}
	refs.backdrop.dataset.bnConfirmTriggerId = triggerEl.id || triggerEl.dataset.bnConfirmAutoId;

	refs.backdrop.hidden = false;
	refs.ok.focus();
}

function closeConfirmModal() {
	if ( bnConfirmBackdrop ) {
		bnConfirmBackdrop.hidden = true;
	}
}

function resumeConfirmedClick() {
	if ( ! bnConfirmBackdrop ) { return; }
	var triggerId = bnConfirmBackdrop.dataset.bnConfirmTriggerId;
	closeConfirmModal();
	if ( ! triggerId ) { return; }
	var trigger = document.getElementById( triggerId ) ||
		document.querySelector( '[data-bn-confirm-auto-id="' + triggerId + '"]' );
	if ( ! trigger ) { return; }
	trigger.setAttribute( BN_CONFIRM_FLAG, '1' );
	trigger.click();
	trigger.removeAttribute( BN_CONFIRM_FLAG );
}

document.addEventListener( 'click', function ( event ) {
	// Confirm modal interactions take priority.
	if ( event.target.closest( '[data-bn-confirm-ok]' ) ) {
		event.preventDefault();
		event.stopImmediatePropagation();
		resumeConfirmedClick();
		return;
	}
	if ( event.target.closest( '[data-bn-confirm-cancel]' ) ) {
		event.preventDefault();
		event.stopImmediatePropagation();
		closeConfirmModal();
		return;
	}

	// Gate — buttons with data-bn-confirm open a modal instead of running.
	var confirmEl = event.target.closest( '[data-bn-confirm]' );
	if ( confirmEl && ! confirmEl.hasAttribute( BN_CONFIRM_FLAG ) ) {
		event.preventDefault();
		event.stopImmediatePropagation();
		openConfirmModal( confirmEl );
		return;
	}

	// Space-settings modal close — any element with data-bn-modal-close closes its modal.
	var closeEl = event.target.closest( '[data-bn-modal-close]' );
	if ( closeEl ) {
		event.preventDefault();
		closeAllSpaceModals();
		return;
	}

	// Backdrop click — clicking the backdrop (but not its panel) closes.
	var backdrop = event.target.closest( '.bn-modal-backdrop[data-bn-modal]' );
	if ( backdrop && event.target === backdrop ) {
		closeAllSpaceModals();
		return;
	}
	var confirmBackdrop = event.target.closest( '.bn-modal-backdrop[data-bn-confirm-modal]' );
	if ( confirmBackdrop && event.target === confirmBackdrop ) {
		closeConfirmModal();
	}
}, true );

document.addEventListener( 'keydown', function ( event ) {
	if ( 'Escape' === event.key ) {
		var openBackdrop = document.querySelector( '[data-bn-modal]:not([hidden])' );
		if ( openBackdrop ) { closeAllSpaceModals(); }
		if ( bnConfirmBackdrop && ! bnConfirmBackdrop.hidden ) { closeConfirmModal(); }
	}
} );

/* ──────────────────────────────────────────────────────────────────────────
 * Sticky save bar (Space Settings — parity with Profile edit + Notif prefs).
 *
 * Each settings tab renders its own native <form>. The bar lives at the page
 * footer; on first input/change inside any settings form it surfaces, latches
 * to the form that was touched, and adopts the savebar status pills. Submit
 * forwards to the underlying form (preserving native validation + the wp_nonce
 * field), then flips to the "saved" status briefly. Cancel re-reads the form
 * default values from each input's data-bn-default attribute to roll back
 * unsaved edits without reload. A beforeunload guard prevents accidental loss
 * of work while the page is dirty.
 * ────────────────────────────────────────────────────────────────────────── */

(function () {
	var savebar = document.querySelector( '[data-bn-space-settings-savebar]' );
	if ( ! savebar ) { return; }

	var root      = document.querySelector( '.bn-space-settings' );
	if ( ! root ) { return; }

	var forms     = root.querySelectorAll( 'form.bn-space-settings__form' );
	if ( ! forms.length ) { return; }

	function captureDefaults( form ) {
		var inputs = form.querySelectorAll( 'input, textarea, select' );
		for ( var i = 0; i < inputs.length; i++ ) {
			var el = inputs[ i ];
			if ( 'hidden' === el.type ) { continue; }
			if ( 'checkbox' === el.type || 'radio' === el.type ) {
				el.dataset.bnDefault = el.checked ? '1' : '0';
			} else {
				el.dataset.bnDefault = el.value || '';
			}
		}
	}

	// Capture each form's pristine values so Cancel can roll back without a
	// reload. Dirty-tracking, phase changes and submit are reactive now —
	// driven by the store's savebar* actions (data-wp-on--input/change/click in
	// the SSR markup), which set storeInstance.state.savebarPhase. This IIFE
	// only owns the pristine snapshot + the beforeunload guard.
	for ( var i = 0; i < forms.length; i++ ) {
		captureDefaults( forms[ i ] );
	}

	// If the page server-rendered with a success notice, flash "saved" briefly,
	// then settle back to idle — both via reactive state, no .hidden poke.
	if ( document.querySelector( '.bn-space-settings__notice[data-tone="success"]' ) ) {
		storeInstance.state.savebarPhase = 'saved';
		setTimeout( function () {
			if ( 'saved' === storeInstance.state.savebarPhase ) {
				storeInstance.state.savebarPhase = 'idle';
			}
		}, 2400 );
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		var dirtyForm = bnSavebarDirtyForm;
		if ( dirtyForm && '1' !== dirtyForm.dataset.bnSubmitting && 'saving' !== storeInstance.state.savebarPhase ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
})();

/* ──────────────────────────────────────────────────────────────────────────
 * Space image uploaders (Space Settings → General): cover + icon (avatar).
 *
 * Front-end is 100% REST — no wp.media, no attachments. Picking a file POSTs
 * it directly (multipart) to:
 *   POST   /buddynext/v1/spaces/{id}/cover   (field `image`)
 *   POST   /buddynext/v1/spaces/{id}/avatar  (field `image`)
 * which store organized per-owner WebP variations via ImageStorageService and
 * persist the URL to bn_spaces. The endpoint saves immediately, so there is no
 * dependence on the General form's Save button (the cover URL was never part
 * of that PUT payload). DELETE on the same routes clears the image.
 * ────────────────────────────────────────────────────────────────────────── */

(function () {
	var generalForm = document.querySelector( '[data-bn-settings-general-form]' );
	var spaceId     = generalForm ? generalForm.getAttribute( 'data-space-id' ) : null;
	if ( ! spaceId ) { return; }

	// This IIFE runs outside the Interactivity store, so resolveNonce() can't
	// read ctx.restNonce and wpApiSettings isn't enqueued here — the form
	// carries a fresh wp_rest nonce we use for every cover/icon REST call.
	var imageNonce = ( generalForm.getAttribute( 'data-rest-nonce' ) ) || resolveNonce();

	// A throwaway file input drives the OS picker; we never keep a value in it.
	function pickFile( onChosen ) {
		var picker = document.createElement( 'input' );
		picker.type   = 'file';
		picker.accept = 'image/jpeg,image/png,image/webp,image/gif';
		picker.style.display = 'none';
		picker.addEventListener( 'change', function () {
			var file = picker.files && picker.files[0];
			if ( file ) { onChosen( file ); }
			picker.remove();
		} );
		document.body.appendChild( picker );
		picker.click();
	}

	function uploadImage( kind, file ) {
		var body = new FormData();
		body.append( 'image', file );
		return restFetch( '/spaces/' + spaceId + '/' + kind, {
			method:  'POST',
			nonce:   imageNonce,
			body:    body,
			toastOnError: false,
		} );
	}

	function deleteImage( kind ) {
		return restFetch( '/spaces/' + spaceId + '/' + kind, {
			method:  'DELETE',
			nonce:   imageNonce,
			toastOnError: false,
		} );
	}

	/* ── Cover ──────────────────────────────────────────────────────────── */
	(function () {
		var field = document.querySelector( '[data-bn-cover-field]' );
		if ( ! field ) { return; }

		var preview   = field.querySelector( '[data-bn-cover-preview]' );
		var input     = field.querySelector( '[data-bn-cover-input]' );
		var removeBtn = field.querySelector( '[data-bn-cover-remove]' );
		var empty     = field.querySelector( '.bn-space-settings__cover-empty' );
		if ( ! preview ) { return; }

		function paint( url ) {
			if ( input ) { input.value = url || ''; }
			if ( url ) {
				preview.classList.add( 'has-image' );
				preview.style.backgroundImage    = "url('" + url.replace( /'/g, "\\'" ) + "')";
				preview.style.backgroundSize     = 'cover';
				preview.style.backgroundPosition = 'center';
				if ( empty ) { empty.hidden = true; }
				if ( removeBtn ) { removeBtn.hidden = false; }
			} else {
				preview.classList.remove( 'has-image' );
				preview.style.backgroundImage = '';
				if ( empty ) { empty.hidden = false; }
				if ( removeBtn ) { removeBtn.hidden = true; }
			}

			// Keep the page header cover in sync with the form preview so the change
			// is visible immediately, without a reload. The header markup carries an
			// <img> only when a cover is set (templates/spaces/settings.php).
			var headerCover = document.querySelector( '.bn-sh-cover' );
			if ( headerCover ) {
				var headerImg = headerCover.querySelector( 'img' );
				if ( url ) {
					if ( ! headerImg ) {
						headerImg = document.createElement( 'img' );
						headerImg.alt = '';
						headerImg.loading = 'lazy';
						headerCover.appendChild( headerImg );
					}
					headerImg.src = url;
				} else if ( headerImg ) {
					headerImg.remove();
				}
			}
		}

		function choose() {
			pickFile( function ( file ) {
				preview.setAttribute( 'aria-busy', 'true' );
				uploadImage( 'cover', file ).then( function ( res ) {
					return res.ok ? res.data : Promise.reject( res );
				} ).then( function ( data ) {
					paint( data.cover_image_url || '' );
					if ( window.bnToast ) { window.bnToast( __i18n( 'Cover updated.' ), 'success' ); }
				} ).catch( function () {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not upload cover.' ), 'danger' ); }
				} ).finally( function () {
					preview.removeAttribute( 'aria-busy' );
				} );
			} );
		}

		preview.addEventListener( 'click', function ( e ) { e.preventDefault(); choose(); } );
		preview.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ' ' === e.key ) { e.preventDefault(); choose(); }
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				removeBtn.disabled = true;
				deleteImage( 'cover' ).then( function ( res ) {
					if ( ! res.ok ) { return Promise.reject( res ); }
					paint( '' );
					if ( window.bnToast ) { window.bnToast( __i18n( 'Cover removed.' ), 'success' ); }
				} ).catch( function () {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not remove cover.' ), 'danger' ); }
				} ).finally( function () {
					removeBtn.disabled = false;
				} );
			} );
		}
	})();

	/* ── Icon (avatar) ──────────────────────────────────────────────────── */
	(function () {
		var btn = document.getElementById( 'bn_space_icon' );
		if ( ! btn ) { return; }
		var current   = document.querySelector( '.bn-space-settings__upload-current' );
		var removeBtn = document.querySelector( '[data-bn-icon-remove]' );
		var fallback  = document.querySelector( '.bn-space-settings__upload-fallback' );

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			pickFile( function ( file ) {
				btn.disabled = true;
				var orig = btn.textContent;
				btn.textContent = __i18n( 'Uploading…' );
				uploadImage( 'avatar', file ).then( function ( res ) {
					return res.ok ? res.data : Promise.reject( res );
				} ).then( function ( data ) {
					if ( current && data.avatar_url ) {
						current.innerHTML = '';
						var img = document.createElement( 'img' );
						img.src = data.avatar_url;
						img.alt = '';
						current.appendChild( img );
					}
					if ( removeBtn ) { removeBtn.hidden = false; }
					if ( window.bnToast ) { window.bnToast( __i18n( 'Icon updated.' ), 'success' ); }
				} ).catch( function () {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not upload icon.' ), 'danger' ); }
				} ).finally( function () {
					btn.disabled = false;
					btn.textContent = orig;
				} );
			} );
		} );

		// Remove the uploaded icon and restore the category-icon fallback.
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				removeBtn.disabled = true;
				deleteImage( 'avatar' ).then( function ( res ) {
					if ( ! res.ok ) { return Promise.reject( res ); }
					if ( current ) {
						current.innerHTML = '';
						if ( fallback && fallback.content ) {
							current.appendChild( fallback.content.cloneNode( true ) );
						}
					}
					removeBtn.hidden = true;
					if ( window.bnToast ) { window.bnToast( __i18n( 'Icon removed.' ), 'success' ); }
				} ).catch( function () {
					if ( window.bnToast ) { window.bnToast( __i18n( 'Could not remove icon.' ), 'danger' ); }
				} ).finally( function () {
					removeBtn.disabled = false;
				} );
			} );
		}
	})();
})();
