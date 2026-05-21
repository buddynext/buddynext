/* BuddyNext — Spaces Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';

/* ── Shared helpers ────────────────────────────────────────────────── */

function apiUrl( path ) {
	return ( window.wpApiSettings && window.wpApiSettings.root || '/wp-json/' ) + path;
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
		pending: 'ghost',
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

	btn.dataset.wpOnClick    = 'actions.' + actionMap[ newState ];
	btn.dataset.currentState = newState;
	btn.disabled             = false;
}

/* ── Store ─────────────────────────────────────────────────────────── */

var storeInstance = store( 'buddynext/spaces', {

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
				var res  = await fetch( apiUrl( 'buddynext/v1/spaces/' + spaceId + '/join' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': resolveNonce() },
				} );
				var data = await res.json();

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
				} else if ( btn ) {
					btn.textContent = origText;
					btn.disabled    = false;
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
				var res  = await fetch( apiUrl( 'buddynext/v1/spaces/' + spaceId + '/join' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': resolveNonce() },
				} );
				var data = await res.json();

				if ( res.ok && data.pending ) {
					swapButtonState( btn, 'pending' );
				} else if ( btn ) {
					btn.textContent = origText;
					btn.disabled    = false;
				}
			} catch ( _e ) {
				if ( btn ) { btn.textContent = origText; btn.disabled = false; }
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
				var res = await fetch( apiUrl( 'buddynext/v1/spaces/' + spaceId + '/leave' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': resolveNonce() },
				} );
				var data = await res.json();

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
				var res  = await fetch( apiUrl( 'buddynext/v1/spaces/' + spaceId + '/leave' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': resolveNonce() },
				} );
				var data = await res.json();

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
				var res  = await fetch( apiUrl( 'buddynext/v1/posts' ), {
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   resolveNonce(),
					},
					body: JSON.stringify( {
						content:  content,
						space_id: parseInt( spaceId, 10 ),
						type:     'text',
					} ),
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
				await fetch( apiUrl( 'buddynext/v1/reactions/toggle' ), {
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   resolveNonce(),
					},
					body: JSON.stringify( {
						object_type: 'post',
						object_id:   parseInt( postId, 10 ),
						emoji:       'love',
					} ),
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
					fetch( apiUrl( 'buddynext/v1/reports' ), {
						method:  'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce':   resolveNonce(),
						},
						body: JSON.stringify( { object_type: 'post', object_id: parseInt( postId, 10 ) } ),
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
				var res = await fetch( apiUrl( 'buddynext/v1/posts/' + postId + '/share' ), {
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   resolveNonce(),
					},
					body: JSON.stringify( {} ),
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

	},
} );
