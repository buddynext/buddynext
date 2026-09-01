/* BuddyNext — Shared dialog primitives.
 *
 * Provides accessible replacements for the native window.confirm,
 * window.prompt, and ad-hoc toast surfaces. Built on the v2 design system
 * primitives in assets/css/bn-base.css:
 *
 *   .bn-modal-backdrop  +  .bn-modal__panel[data-tone]
 *   .bn-toast-container +  .bn-toast--{success,error}
 *
 * All three helpers are framework-agnostic — they use plain DOM APIs so
 * they work both inside WP Interactivity generator actions (via `yield`)
 * and inside ordinary async event handlers (via `await`).
 *
 * Security: all caller-supplied strings (title, body, button labels,
 * placeholders) are written via .textContent / .placeholder / .value
 * setters. No innerHTML is used on translated or user-controlled text.
 */

/**
 * Read a shell-level translated string from window.bnShellData.i18n, falling
 * back to the English literal when the dictionary (or key) is absent.
 *
 * dialog.js is framework-agnostic — it is NOT an Interactivity Script Module, so
 * it cannot read a per-store wp_interactivity_state() i18n dict. Its own default
 * labels, and the report-reason list that no caller can reach, are translated
 * server-side in PageRouter ($bn_shell_data['i18n']) and read here. Callers that
 * pass a translated opt still override the default; this only localizes the
 * fallbacks and the internal report-reason strings.
 *
 * @param {string} key      Dictionary key (see PageRouter $bn_shell_data['i18n']).
 * @param {string} fallback English fallback when the dictionary is unavailable.
 * @return {string}
 */
function si( key, fallback ) {
	const dict = ( typeof window !== 'undefined' && window.bnShellData && window.bnShellData.i18n ) || {};
	return ( typeof dict[ key ] === 'string' && dict[ key ] ) ? dict[ key ] : fallback;
}

/**
 * Build the modal frame shared by bnConfirm and bnPrompt.
 *
 * @param {Object} opts
 * @param {string} opts.title         Modal title (required).
 * @param {string} [opts.body]        Optional body copy. `message` is accepted as an alias.
 * @param {string} [opts.message]     Alias for `body` — tolerated so a caller that passes the
 *                                    common `{ message }` shape can never render an empty modal.
 * @param {string} [opts.tone]        'danger' | 'default'.
 * @param {string} [opts.confirmLabel]
 * @param {string} [opts.cancelLabel]
 * @param {HTMLElement} [opts.extraNode] Optional node inserted into the body (e.g. textarea).
 * @return {{ backdrop: HTMLElement, panel: HTMLElement, confirmBtn: HTMLButtonElement, cancelBtn: HTMLButtonElement, closeBtn: HTMLButtonElement, titleId: string }}
 */
function buildModalFrame( opts ) {
	const tone         = opts.tone || 'default';
	const confirmLabel = opts.confirmLabel || si( 'confirm', 'Confirm' );
	const cancelLabel  = opts.cancelLabel || si( 'cancel', 'Cancel' );

	const titleId = 'bn-modal-title-' + Math.random().toString( 36 ).slice( 2, 10 );

	const backdrop = document.createElement( 'div' );
	backdrop.className = 'bn-modal-backdrop';

	const panel = document.createElement( 'div' );
	panel.className = 'bn-modal__panel';
	panel.setAttribute( 'role', 'dialog' );
	panel.setAttribute( 'aria-modal', 'true' );
	panel.setAttribute( 'aria-labelledby', titleId );
	if ( tone === 'danger' ) {
		panel.setAttribute( 'data-tone', 'danger' );
	}

	// Head.
	const head = document.createElement( 'div' );
	head.className = 'bn-modal__head';

	const titleEl = document.createElement( 'h2' );
	titleEl.className = 'bn-modal__title';
	titleEl.id = titleId;
	titleEl.textContent = opts.title || '';
	head.appendChild( titleEl );

	const closeBtn = document.createElement( 'button' );
	closeBtn.type = 'button';
	closeBtn.className = 'bn-modal__close';
	closeBtn.setAttribute( 'aria-label', si( 'close', 'Close' ) );
	closeBtn.textContent = '×';
	head.appendChild( closeBtn );

	panel.appendChild( head );

	// Body.
	const body = document.createElement( 'div' );
	body.className = 'bn-modal__body';

	// `message` is a tolerated alias for `body`: several callers historically pass
	// `{ message }` (the natural word for confirm copy), which would otherwise render
	// a contentless dialog. Prefer `body`, fall back to `message`.
	const bodyText = opts.body || opts.message;
	if ( bodyText ) {
		const para = document.createElement( 'p' );
		para.textContent = bodyText;
		para.style.margin = '0';
		body.appendChild( para );
	}

	if ( opts.extraNode ) {
		body.appendChild( opts.extraNode );
	}

	panel.appendChild( body );

	// Foot.
	const foot = document.createElement( 'div' );
	foot.className = 'bn-modal__foot';

	const cancelBtn = document.createElement( 'button' );
	cancelBtn.type = 'button';
	cancelBtn.className = 'bn-btn';
	cancelBtn.setAttribute( 'data-variant', 'ghost' );
	cancelBtn.textContent = cancelLabel;
	foot.appendChild( cancelBtn );

	const confirmBtn = document.createElement( 'button' );
	confirmBtn.type = 'button';
	confirmBtn.className = 'bn-btn';
	confirmBtn.setAttribute( 'data-variant', tone === 'danger' ? 'danger' : 'primary' );
	confirmBtn.textContent = confirmLabel;
	foot.appendChild( confirmBtn );

	panel.appendChild( foot );

	backdrop.appendChild( panel );

	return { backdrop, panel, confirmBtn, cancelBtn, closeBtn, titleId };
}

/**
 * Focus-trap helper. Keeps Tab cycling inside the modal until it closes.
 *
 * @param {HTMLElement} container The modal panel.
 * @return {() => void} Cleanup function — call to remove the listener.
 */
function trapFocus( container ) {
	function onKey( ev ) {
		if ( ev.key !== 'Tab' ) {
			return;
		}
		const focusables = container.querySelectorAll(
			'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);
		if ( ! focusables.length ) {
			return;
		}
		const first = focusables[ 0 ];
		const last  = focusables[ focusables.length - 1 ];
		if ( ev.shiftKey && document.activeElement === first ) {
			ev.preventDefault();
			last.focus();
		} else if ( ! ev.shiftKey && document.activeElement === last ) {
			ev.preventDefault();
			first.focus();
		}
	}
	container.addEventListener( 'keydown', onKey );
	return function () {
		container.removeEventListener( 'keydown', onKey );
	};
}

/**
 * Present a confirm dialog. Resolves true on confirm, false on cancel
 * (cancel button, close button, backdrop click, or Escape key).
 *
 * @param {Object} opts
 * @param {string} opts.title
 * @param {string} [opts.body]
 * @param {string} [opts.tone]         'danger' | 'default'. Default 'danger'.
 * @param {string} [opts.confirmLabel] Default 'Confirm'.
 * @param {string} [opts.cancelLabel]  Default 'Cancel'.
 * @return {Promise<boolean>}
 */
export function bnConfirm( opts, legacyOpts ) {
	// Canonical signature is ONE options object ({ title, body, confirmLabel,
	// tone }). Some callers historically passed ( message, opts ) — a bare
	// string first — which Object.assign would spread into useless indexed
	// characters, rendering an EMPTY dialog (blank title and body). Normalize
	// the legacy shape so a drifted caller can never show a contentless
	// confirm again.
	if ( 'string' === typeof opts ) {
		opts = Object.assign( { body: opts }, legacyOpts || {} );
	}
	const cfg = Object.assign( { tone: 'danger' }, opts || {} );

	return new Promise( function ( resolve ) {
		const trigger = document.activeElement;
		const frame   = buildModalFrame( cfg );

		const releaseTrap = trapFocus( frame.panel );

		function close( result ) {
			document.removeEventListener( 'keydown', onEscape );
			releaseTrap();
			frame.backdrop.remove();
			if ( trigger && typeof trigger.focus === 'function' ) {
				trigger.focus();
			}
			resolve( result );
		}

		function onEscape( ev ) {
			if ( ev.key === 'Escape' ) {
				ev.preventDefault();
				close( false );
			}
		}

		frame.confirmBtn.addEventListener( 'click', function () { close( true ); } );
		frame.cancelBtn.addEventListener( 'click', function () { close( false ); } );
		frame.closeBtn.addEventListener( 'click', function () { close( false ); } );
		frame.backdrop.addEventListener( 'click', function ( ev ) {
			if ( ev.target === frame.backdrop ) {
				close( false );
			}
		} );
		document.addEventListener( 'keydown', onEscape );

		document.body.appendChild( frame.backdrop );

		// Focus the confirm button after paint so screen readers announce the dialog first.
		window.requestAnimationFrame( function () { frame.confirmBtn.focus(); } );
	} );
}

/**
 * Present a prompt dialog with a text input. Resolves the entered string
 * on confirm (possibly empty), null on cancel.
 *
 * @param {Object} opts
 * @param {string} opts.title
 * @param {string} [opts.body]
 * @param {string} [opts.placeholder]
 * @param {string} [opts.defaultValue]
 * @param {string} [opts.confirmLabel]
 * @param {string} [opts.cancelLabel]
 * @param {string} [opts.inputType]  'textarea' (default) or an <input> type such
 *                                   as 'password'. A single-line secret must not
 *                                   render as a resizable textarea that echoes
 *                                   what is typed, so the account-deletion
 *                                   re-auth asks for 'password' here rather than
 *                                   growing its own dialog.
 * @param {string} [opts.autocomplete] autocomplete hint for the input.
 * @return {Promise<string|null>}
 */
export function bnPrompt( opts ) {
	const cfg = Object.assign( { tone: 'default' }, opts || {} );

	const isTextarea = 'textarea' === ( cfg.inputType || 'textarea' );
	const input = document.createElement( isTextarea ? 'textarea' : 'input' );
	input.className = 'bn-input';
	if ( isTextarea ) {
		input.rows = 3;
		input.style.resize = 'vertical';
	} else {
		input.type = cfg.inputType;
		if ( cfg.autocomplete ) { input.autocomplete = cfg.autocomplete; }
	}
	input.placeholder = cfg.placeholder || '';
	input.value = cfg.defaultValue || '';
	input.style.marginTop = '12px';
	input.style.width = '100%';

	cfg.extraNode = input;

	return new Promise( function ( resolve ) {
		const trigger = document.activeElement;
		const frame   = buildModalFrame( cfg );

		const releaseTrap = trapFocus( frame.panel );

		function close( result ) {
			document.removeEventListener( 'keydown', onEscape );
			releaseTrap();
			frame.backdrop.remove();
			if ( trigger && typeof trigger.focus === 'function' ) {
				trigger.focus();
			}
			resolve( result );
		}

		function onEscape( ev ) {
			if ( ev.key === 'Escape' ) {
				ev.preventDefault();
				close( null );
			}
		}

		frame.confirmBtn.addEventListener( 'click', function () { close( input.value ); } );
		frame.cancelBtn.addEventListener( 'click', function () { close( null ); } );
		frame.closeBtn.addEventListener( 'click', function () { close( null ); } );
		frame.backdrop.addEventListener( 'click', function ( ev ) {
			if ( ev.target === frame.backdrop ) {
				close( null );
			}
		} );
		document.addEventListener( 'keydown', onEscape );

		document.body.appendChild( frame.backdrop );

		window.requestAnimationFrame( function () { input.focus(); } );
	} );
}

/**
 * Categorized report dialog — promise-based, mirrors bnPrompt() but
 * adds a reason `<select>` above the optional notes textarea. The
 * dropdown matches the canonical reason list used by the profile +
 * member-card report modals so triage stays consistent across
 * surfaces.
 *
 * Resolves to `{ reason: 'spam'|'harassment'|..., notes: string }`
 * on submit, `null` on cancel/Escape/backdrop click.
 *
 * @param {Object} [opts]
 * @param {string} [opts.title] Dialog title.
 * @param {string} [opts.body]  Helper paragraph below the title.
 * @param {string} [opts.confirmLabel] Submit-button label.
 * @param {Array<[string,string]>} [opts.reasons] Reason pairs [value, label]. Defaults to
 *        BuddyNext's own set. Pass the target queue's enum when reporting somewhere else.
 * @return {Promise<{reason:string, notes:string}|null>}
 */
export function bnReportDialog( opts ) {
	const cfg = Object.assign( {
		title:        si( 'reportTitle', 'Report' ),
		body:         si( 'reportBody', 'Reports are reviewed by moderators. The person you report is not notified.' ),
		confirmLabel: si( 'reportSubmit', 'Submit report' ),
		cancelLabel:  si( 'cancel', 'Cancel' ),
		tone:         'default',
	}, opts || {} );

	// The default reason set is BuddyNext's own (it must match the buddynext_report_reasons
	// filter, which is what /reports validates against). A caller reporting to a DIFFERENT
	// moderation queue has to pass that queue's reasons — WPMediaVerse's media report endpoint
	// validates against its own enum (it has nudity / violence / copyright, and no
	// inappropriate / impersonation), so sending ours would be rejected as an invalid reason.
	// The server sends the whole vocabulary as ordered [ slug, label ] pairs, so a
	// reason added through buddynext_report_reasons is OFFERED here rather than
	// merely accepted on submission. This list used to be hardcoded, which is why
	// the filter was invisible to every JS surface — and the copy had drifted too,
	// missing `fake` while the profile modal offered it.
	//
	// The literal below is a last-resort fallback for a page whose shell data did
	// not load; it is deliberately NOT the source of truth.
	const shellReasons = ( typeof window !== 'undefined' && window.bnShellData && window.bnShellData.reportReasons ) || null;
	const REASONS = ( Array.isArray( cfg.reasons ) && cfg.reasons.length )
		? cfg.reasons
		: ( Array.isArray( shellReasons ) && shellReasons.length )
			? shellReasons
			: [
				[ 'spam',           si( 'reasonSpam', 'Spam' ) ],
				[ 'harassment',     si( 'reasonHarassment', 'Harassment or hate speech' ) ],
				[ 'misinformation', si( 'reasonMisinformation', 'Misinformation' ) ],
				[ 'inappropriate',  si( 'reasonInappropriate', 'Inappropriate content' ) ],
				[ 'impersonation',  si( 'reasonImpersonation', 'Impersonation' ) ],
				[ 'other',          si( 'reasonOther', 'Something else' ) ],
			];

	const wrap = document.createElement( 'div' );
	wrap.style.display = 'flex';
	wrap.style.flexDirection = 'column';
	wrap.style.gap = '12px';
	wrap.style.marginTop = '12px';

	const reasonLabel = document.createElement( 'label' );
	reasonLabel.textContent = si( 'reportReasonLabel', 'Reason' );
	reasonLabel.style.fontWeight = '600';
	reasonLabel.style.fontSize = '13px';
	const select = document.createElement( 'select' );
	select.className = 'bn-input';
	REASONS.forEach( function ( pair ) {
		const opt = document.createElement( 'option' );
		opt.value = pair[ 0 ];
		opt.textContent = pair[ 1 ];
		select.appendChild( opt );
	} );

	const notesLabel = document.createElement( 'label' );
	notesLabel.textContent = si( 'reportNotesLabel', 'Additional details (optional)' );
	notesLabel.style.fontWeight = '600';
	notesLabel.style.fontSize = '13px';
	const notes = document.createElement( 'textarea' );
	notes.className = 'bn-textarea';
	notes.rows = 3;
	notes.maxLength = 500;
	notes.placeholder = si( 'reportNotesPlaceholder', 'Tell us more about what you saw…' );
	notes.style.width = '100%';
	notes.style.resize = 'vertical';

	wrap.appendChild( reasonLabel );
	wrap.appendChild( select );
	wrap.appendChild( notesLabel );
	wrap.appendChild( notes );

	cfg.extraNode = wrap;

	return new Promise( function ( resolve ) {
		const trigger = document.activeElement;
		const frame   = buildModalFrame( cfg );
		const releaseTrap = trapFocus( frame.panel );

		function close( result ) {
			document.removeEventListener( 'keydown', onEscape );
			releaseTrap();
			frame.backdrop.remove();
			if ( trigger && typeof trigger.focus === 'function' ) {
				trigger.focus();
			}
			resolve( result );
		}
		function onEscape( ev ) {
			if ( ev.key === 'Escape' ) { ev.preventDefault(); close( null ); }
		}

		frame.confirmBtn.addEventListener( 'click', function () {
			close( { reason: select.value || 'other', notes: notes.value || '' } );
		} );
		frame.cancelBtn.addEventListener( 'click', function () { close( null ); } );
		frame.closeBtn.addEventListener( 'click', function () { close( null ); } );
		frame.backdrop.addEventListener( 'click', function ( ev ) {
			if ( ev.target === frame.backdrop ) { close( null ); }
		} );
		document.addEventListener( 'keydown', onEscape );

		document.body.appendChild( frame.backdrop );

		window.requestAnimationFrame( function () { select.focus(); } );
	} );
}

/**
 * Connection-note dialog — LinkedIn-style "Add a note" before sending a
 * connection request. Promise-based, mirrors bnPrompt() but adds a 280-char
 * cap matching ConnectionService::send_request() and a live character counter.
 * The note is optional: confirming with an empty textarea sends a note-less
 * request, so this doubles as the "Send without a note" path.
 *
 * Resolves to the note string (possibly empty) on submit, `null` on
 * cancel / close / backdrop / Escape.
 *
 * @param {Object} [opts]
 * @param {string} [opts.title]        Dialog title.
 * @param {string} [opts.body]         Helper paragraph below the title.
 * @param {string} [opts.confirmLabel] Submit-button label.
 * @param {string} [opts.placeholder]  Textarea placeholder.
 * @return {Promise<string|null>}
 */
export function bnConnectNoteDialog( opts ) {
	const cfg = Object.assign( {
		title:        si( 'connectTitle', 'Add a note' ),
		body:         si( 'connectBody', 'Add a personal message to your connection request, or send it without one.' ),
		confirmLabel: si( 'connectSubmit', 'Send request' ),
		cancelLabel:  si( 'cancel', 'Cancel' ),
		placeholder:  si( 'connectPlaceholder', 'e.g. We met at the design meetup — I’d love to stay connected.' ),
		tone:         'default',
	}, opts || {} );

	const MAX = 280;

	const wrap = document.createElement( 'div' );
	wrap.style.marginTop = '12px';

	const note = document.createElement( 'textarea' );
	note.className = 'bn-input';
	note.rows = 3;
	note.maxLength = MAX;
	note.placeholder = cfg.placeholder;
	note.style.width = '100%';
	note.style.resize = 'vertical';

	const counter = document.createElement( 'div' );
	counter.style.marginTop = '6px';
	counter.style.fontSize = '12px';
	counter.style.textAlign = 'right';
	counter.style.color = 'var(--bn-ink-soft, #646970)';
	function syncCounter() {
		counter.textContent = note.value.length + '/' + MAX;
	}
	syncCounter();
	note.addEventListener( 'input', syncCounter );

	wrap.appendChild( note );
	wrap.appendChild( counter );

	cfg.extraNode = wrap;

	return new Promise( function ( resolve ) {
		const trigger = document.activeElement;
		const frame   = buildModalFrame( cfg );
		const releaseTrap = trapFocus( frame.panel );

		function close( result ) {
			document.removeEventListener( 'keydown', onEscape );
			releaseTrap();
			frame.backdrop.remove();
			if ( trigger && typeof trigger.focus === 'function' ) {
				trigger.focus();
			}
			resolve( result );
		}
		function onEscape( ev ) {
			if ( ev.key === 'Escape' ) { ev.preventDefault(); close( null ); }
		}

		frame.confirmBtn.addEventListener( 'click', function () { close( note.value || '' ); } );
		frame.cancelBtn.addEventListener( 'click', function () { close( null ); } );
		frame.closeBtn.addEventListener( 'click', function () { close( null ); } );
		frame.backdrop.addEventListener( 'click', function ( ev ) {
			if ( ev.target === frame.backdrop ) { close( null ); }
		} );
		document.addEventListener( 'keydown', onEscape );

		document.body.appendChild( frame.backdrop );
		window.requestAnimationFrame( function () { note.focus(); } );
	} );
}

/**
 * Resolve the note for a connection request according to the site's connect
 * style, so every Connect surface behaves identically from one switch.
 *
 * Default (Facebook 1-click): resolves '' immediately, no dialog — the request
 * sends in a single click. When the owner enables connectRequireNote (LinkedIn
 * style), this opens the note dialog and resolves the entered note ('' if sent
 * blank, null if the member cancels). The flag is read from the shared
 * window.bnShellData so there is one source of truth across all connect buttons.
 *
 * @param {Object} [opts] Forwarded to bnConnectNoteDialog when the dialog shows.
 * @return {Promise<string|null>} Note text, '' when none/disabled, null on cancel.
 */
export function bnResolveConnectNote( opts ) {
	const data = ( typeof window !== 'undefined' && window.bnShellData ) || {};
	if ( ! data.connectRequireNote ) {
		return Promise.resolve( '' );
	}
	return bnConnectNoteDialog( opts );
}

/**
 * Show a transient toast. Auto-dismisses after `timeout` ms (default 3000).
 * Multiple toasts stack inside a single .bn-toast-container.
 *
 * @param {string} message
 * @param {Object} [opts]
 * @param {('info'|'success'|'warn'|'danger')} [opts.tone] Default 'info'.
 * @param {number} [opts.timeout] Default 3000ms.
 * @return {void}
 */
/**
 * How many toasts may be on screen at once.
 *
 * Identical messages are collapsed (see bnToast), so this only bounds genuinely
 * DIFFERENT messages. Four is enough to show a page failing several ways at once
 * without the column running off the screen.
 *
 * @type {number}
 */
const MAX_TOASTS = 4;

/**
 * Identity of a toast: two toasts are "the same" when they say the same thing in the
 * same tone. Used to collapse repeats rather than stack them.
 *
 * @param {string} message Toast text.
 * @param {string} tone    Toast tone.
 * @return {string} Stable key, safe for a selector.
 */
function toastKey( message, tone ) {
	let hash = 0;
	const raw = `${ tone }|${ message }`;
	for ( let i = 0; i < raw.length; i++ ) {
		hash = ( ( hash << 5 ) - hash + raw.charCodeAt( i ) ) | 0;
	}
	return `t${ Math.abs( hash ) }`;
}

export function bnToast( message, opts ) {
	// Accept either a tone string — bnToast(msg, 'success') — or an options
	// object — bnToast(msg, { tone, timeout }). Both call styles exist across
	// the app, so normalise here instead of forcing one at every call site.
	const cfg     = ( 'string' === typeof opts ) ? { tone: opts } : ( opts || {} );
	const tone    = cfg.tone || 'info';
	// Errors and warnings carry information the user must read (a validation rule,
	// a failure reason), so they dwell longer than a transient success/info
	// confirmation. An explicit timeout still wins.
	const isAlert = ( 'danger' === tone || 'error' === tone || 'warn' === tone || 'warning' === tone );
	const timeout = typeof cfg.timeout === 'number' ? cfg.timeout : ( isAlert ? 7000 : 3000 );

	let container = document.querySelector( '.bn-toast-container' );
	if ( ! container ) {
		container = document.createElement( 'div' );
		container.className = 'bn-toast-container';
		document.body.appendChild( container );
	}

	// COLLAPSE A REPEAT instead of stacking another copy of it.
	//
	// Every call used to append a new node, unconditionally. So an action the server
	// keeps refusing — a rate limit, a plan limit, a permission denial — produced one
	// toast per attempt, and they piled up until they filled the screen. It is also an
	// accessibility failure: each toast is an assertive role="alert", so a screen
	// reader interrupts the user once per copy to read out the SAME sentence.
	//
	// The user does not need to be told twelve times. Refresh the existing toast's
	// dwell timer and count the repeats on it.
	const existing = container.querySelector( `.bn-toast[data-bn-toast-key="${ toastKey( message, tone ) }"]` );
	if ( existing ) {
		const count = ( parseInt( existing.getAttribute( 'data-bn-toast-count' ), 10 ) || 1 ) + 1;
		existing.setAttribute( 'data-bn-toast-count', String( count ) );

		let badge = existing.querySelector( '.bn-toast__count' );
		if ( ! badge ) {
			badge = document.createElement( 'span' );
			badge.className = 'bn-toast__count';
			existing.appendChild( badge );
		}
		badge.textContent = `×${ count }`;

		// Restart the dwell so the message stays while it is still happening, and
		// re-announce nothing: the text has not changed, so the live region is quiet.
		if ( 'function' === typeof existing._bnResetTimer ) {
			existing._bnResetTimer();
		}
		return;
	}

	// Hard cap on the stack. Distinct messages can still pile up (a page can fail in
	// several different ways at once), so drop the oldest rather than let the column
	// grow past the viewport and bury the page.
	while ( container.children.length >= MAX_TOASTS ) {
		container.firstElementChild.remove();
	}

	const toast = document.createElement( 'div' );
	toast.className = 'bn-toast';
	toast.setAttribute( 'data-bn-toast-key', toastKey( message, tone ) );
	toast.setAttribute( 'data-bn-toast-count', '1' );
	// Map tone to one of the four real toast classes (error/success/info/warning).
	if ( 'success' === tone ) {
		toast.classList.add( 'bn-toast--success' );
	} else if ( 'danger' === tone || 'error' === tone ) {
		toast.classList.add( 'bn-toast--error' );
	} else if ( 'warn' === tone || 'warning' === tone ) {
		toast.classList.add( 'bn-toast--warning' );
	} else if ( 'info' === tone ) {
		toast.classList.add( 'bn-toast--info' );
	}
	// Alerts announce assertively so a screen reader interrupts with the reason.
	toast.setAttribute( 'role', isAlert ? 'alert' : 'status' );
	toast.setAttribute( 'aria-live', isAlert ? 'assertive' : 'polite' );
	toast.textContent = message;

	// Optional action link.
	//
	// Some refusals are not retryable and the member needs somewhere to GO - a
	// suspension is the case this was added for: the server says why and ships
	// the appeal URL, and a toast that can only say "Try again" is worse than
	// useless, because retrying can never succeed.
	if ( cfg.action && cfg.action.href && cfg.action.label ) {
		const link = document.createElement( 'a' );
		link.className = 'bn-toast__action';
		link.href = cfg.action.href;
		link.textContent = cfg.action.label;
		// The toast dismisses on click; let the link navigate instead of being
		// swallowed by that handler.
		link.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
		} );
		toast.appendChild( link );
	}

	container.appendChild( toast );

	// JS owns the lifetime: fade out via the --leaving class, then remove. Clicking
	// the toast dismisses it early so a lingering error can be cleared on demand.
	let removeTimer;
	const dismiss = function () {
		window.clearTimeout( removeTimer );
		toast.classList.add( 'bn-toast--leaving' );
		window.setTimeout( function () {
			toast.remove();
			if ( container && ! container.children.length ) {
				container.remove();
			}
		}, 250 );
	};

	// Exposed so a collapsed repeat can restart the dwell: the message is still
	// current, so it should not vanish just because the first copy is timing out.
	toast._bnResetTimer = function () {
		window.clearTimeout( removeTimer );
		toast.classList.remove( 'bn-toast--leaving' );
		removeTimer = window.setTimeout( dismiss, timeout );
	};

	toast.addEventListener( 'click', dismiss );
	removeTimer = window.setTimeout( dismiss, timeout );
}

/*
 * The media lightbox is a classic script (it predates the module graph and is enqueued with
 * only wp-i18n), so it cannot import from here. Expose the report dialog on the window so it
 * can offer the SAME reason picker as every other Report in the product rather than growing a
 * second one. The lightbox checks for it and simply does not wire its Report button if the
 * dialog is absent — a missing control beats a control that does nothing.
 */
if ( typeof window !== 'undefined' ) {
	window.bnReportDialog = bnReportDialog;
	// Same reason as the report dialog above: the media lightbox is a classic
	// script that cannot import this module, but a member-facing surface must
	// use the accessible confirm/prompt, never a native window.confirm. Expose
	// them so the lightbox (and any other classic script) can reach the real
	// dialog instead of falling back to the browser primitive.
	window.bnConfirm = bnConfirm;
	window.bnPrompt  = bnPrompt;
}
