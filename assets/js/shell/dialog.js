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
 * Build the modal frame shared by bnConfirm and bnPrompt.
 *
 * @param {Object} opts
 * @param {string} opts.title         Modal title (required).
 * @param {string} [opts.body]        Optional body copy.
 * @param {string} [opts.tone]        'danger' | 'default'.
 * @param {string} [opts.confirmLabel]
 * @param {string} [opts.cancelLabel]
 * @param {HTMLElement} [opts.extraNode] Optional node inserted into the body (e.g. textarea).
 * @return {{ backdrop: HTMLElement, panel: HTMLElement, confirmBtn: HTMLButtonElement, cancelBtn: HTMLButtonElement, closeBtn: HTMLButtonElement, titleId: string }}
 */
function buildModalFrame( opts ) {
	const tone         = opts.tone || 'default';
	const confirmLabel = opts.confirmLabel || 'Confirm';
	const cancelLabel  = opts.cancelLabel || 'Cancel';

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
	closeBtn.setAttribute( 'aria-label', 'Close' );
	closeBtn.textContent = '×';
	head.appendChild( closeBtn );

	panel.appendChild( head );

	// Body.
	const body = document.createElement( 'div' );
	body.className = 'bn-modal__body';

	if ( opts.body ) {
		const para = document.createElement( 'p' );
		para.textContent = opts.body;
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
export function bnConfirm( opts ) {
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
 * @return {Promise<string|null>}
 */
export function bnPrompt( opts ) {
	const cfg = Object.assign( { tone: 'default' }, opts || {} );

	const input = document.createElement( 'textarea' );
	input.className = 'bn-input';
	input.rows = 3;
	input.placeholder = cfg.placeholder || '';
	input.value = cfg.defaultValue || '';
	input.style.marginTop = '12px';
	input.style.width = '100%';
	input.style.resize = 'vertical';

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
 * @return {Promise<{reason:string, notes:string}|null>}
 */
export function bnReportDialog( opts ) {
	const cfg = Object.assign( {
		title:        'Report',
		body:         'Reports are reviewed by moderators. The person you report is not notified.',
		confirmLabel: 'Submit report',
		cancelLabel:  'Cancel',
		tone:         'default',
	}, opts || {} );

	const REASONS = [
		[ 'spam',           'Spam' ],
		[ 'harassment',     'Harassment or hate speech' ],
		[ 'misinformation', 'Misinformation' ],
		[ 'inappropriate',  'Inappropriate content' ],
		[ 'impersonation',  'Impersonation' ],
		[ 'other',          'Something else' ],
	];

	const wrap = document.createElement( 'div' );
	wrap.style.display = 'flex';
	wrap.style.flexDirection = 'column';
	wrap.style.gap = '12px';
	wrap.style.marginTop = '12px';

	const reasonLabel = document.createElement( 'label' );
	reasonLabel.textContent = 'Reason';
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
	notesLabel.textContent = 'Additional details (optional)';
	notesLabel.style.fontWeight = '600';
	notesLabel.style.fontSize = '13px';
	const notes = document.createElement( 'textarea' );
	notes.className = 'bn-textarea';
	notes.rows = 3;
	notes.maxLength = 500;
	notes.placeholder = 'Tell us more about what you saw…';
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
		title:        'Add a note',
		body:         'Add a personal message to your connection request, or send it without one.',
		confirmLabel: 'Send request',
		cancelLabel:  'Cancel',
		placeholder:  'e.g. We met at the design meetup — I’d love to stay connected.',
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
export function bnToast( message, opts ) {
	// Accept either a tone string — bnToast(msg, 'success') — or an options
	// object — bnToast(msg, { tone, timeout }). Both call styles exist across
	// the app, so normalise here instead of forcing one at every call site.
	const cfg     = ( 'string' === typeof opts ) ? { tone: opts } : ( opts || {} );
	const tone    = cfg.tone || 'info';
	const timeout = typeof cfg.timeout === 'number' ? cfg.timeout : 3000;

	let container = document.querySelector( '.bn-toast-container' );
	if ( ! container ) {
		container = document.createElement( 'div' );
		container.className = 'bn-toast-container';
		document.body.appendChild( container );
	}

	const toast = document.createElement( 'div' );
	toast.className = 'bn-toast';
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
	toast.setAttribute( 'role', 'status' );
	toast.setAttribute( 'aria-live', 'polite' );
	toast.textContent = message;

	container.appendChild( toast );

	window.setTimeout( function () {
		toast.remove();
		if ( container && ! container.children.length ) {
			container.remove();
		}
	}, timeout );
}
