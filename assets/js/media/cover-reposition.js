/**
 * Shared cover-photo reposition modal.
 *
 * One drag-to-pan / scroll-or-slider-to-zoom modal used by BOTH the member
 * profile cover (buddynext/profile) and the space cover (buddynext/spaces), so
 * the two frame a cover the same way and can never drift apart. The modal is
 * pure DOM (no Interactivity store internals): it opens on a picked File (a new
 * cover) or on the URL of a cover that is already set (reposition), and
 * resolves to { x, y, zoom } (percentages 0..100 and a 1..3 zoom) or null when
 * cancelled. The caller stages or saves that framing.
 *
 * Keyboard: the frame takes focus on open; arrow keys pan (Shift = bigger
 * steps), + and - zoom, Tab stays inside the window, Escape cancels, and Enter
 * only presses the button that has focus (it never applies on its own).
 *
 * @package BuddyNext
 */

const PAN_STEP       = 2;
const PAN_STEP_LARGE = 10;
const ZOOM_STEP      = 0.1;

/**
 * Open the reposition modal.
 *
 * @param {File|string} source Cover to frame: a File just chosen, or the URL of the cover already set.
 * @param {Function}    [t]    Optional translator `(key, fallback) => string`. When omitted, the
 *                             fallback strings are used verbatim so the modal still works untranslated.
 * @param {Object}      [opts]
 * @param {{x:number,y:number,zoom:number}} [opts.initial] Framing to start from (a saved one).
 * @param {number}      [opts.aspect]  Width / height of the real cover frame on this screen, so the
 *                                     window shows what everyone will see. Default: wide on desktop,
 *                                     taller on a phone.
 * @param {Function}    [opts.onApply] Called with the framing when Apply is pressed. May return a
 *                                     Promise: while it runs both buttons are locked, and if it rejects
 *                                     the window stays open with the error and nothing closes.
 * @return {Promise<{x:number,y:number,zoom:number}|null>} Framing, or null on cancel.
 */
export function openCoverReposModal( source, t, opts ) {
	const translate = 'function' === typeof t ? t : ( _k, fb ) => fb;
	const options   = opts || {};
	const isFile    = 'string' !== typeof source;
	return new Promise( ( resolve ) => {
		const url = isFile ? URL.createObjectURL( source ) : source;
		const img = new Image();
		img.onload = () => {
			// Keep the object URL alive: the modal's preview <img> uses it as its
			// src. It is revoked in the modal's cleanup() when it closes.
			renderCoverReposModal( url, isFile, translate, options, resolve );
		};
		img.onerror = () => {
			if ( isFile ) { URL.revokeObjectURL( url ); }
			resolve( null );
		};
		img.src = url;
	} );
}

/**
 * Build and mount the modal. Resolves the promise on Apply/Cancel/Escape.
 *
 * @param {string}   url     Picture URL (an object URL for a new file).
 * @param {boolean}  isFile  True when `url` is an object URL this module must revoke.
 * @param {Function} t       Translator `(key, fallback) => string`.
 * @param {Object}   options Options from openCoverReposModal().
 * @param {Function} resolve Promise resolver.
 * @return {void}
 */
function renderCoverReposModal( url, isFile, t, options, resolve ) {
	// The frame has the shape of the real cover on this screen. A phone gets a
	// taller frame than a desktop, so what is framed here is what is shown.
	const phone  = window.matchMedia && window.matchMedia( '(max-width: 640px)' ).matches;
	const aspect = Math.max( 1.5, Math.min( 4, options.aspect > 0 ? options.aspect : ( phone ? 2 : 3.2 ) ) );
	const W      = Math.max( 240, Math.min( 480, window.innerWidth - 72 ) );
	const H      = Math.round( W / aspect );

	const opener = document.activeElement;

	const overlay = document.createElement( 'div' );
	overlay.className = 'bn-avatar-crop-overlay';
	overlay.setAttribute( 'role', 'dialog' );
	overlay.setAttribute( 'aria-modal', 'true' );
	overlay.setAttribute( 'aria-label', t( 'repositionCover', 'Reposition cover photo' ) );

	const panel = document.createElement( 'div' );
	panel.className = 'bn-avatar-crop-panel';

	const title = document.createElement( 'h2' );
	title.className = 'bn-avatar-crop-title';
	title.id = 'bn-cover-repos-hint';
	title.textContent = t( 'coverDragHint', 'Drag to reposition · scroll or use the slider to zoom' );
	panel.appendChild( title );

	const stage = document.createElement( 'div' );
	stage.className = 'bn-cover-repos-stage';
	stage.style.width  = W + 'px';
	stage.style.height = H + 'px';
	stage.tabIndex = 0;
	stage.setAttribute( 'role', 'group' );
	stage.setAttribute( 'aria-label', t( 'coverFraming', 'Cover framing. Use the arrow keys to move the picture, plus and minus to zoom.' ) );

	// The preview <img> uses the same display contract as the hero, so the modal
	// is true WYSIWYG: object-fit cover + object-position (pan) + scale.
	const preview = document.createElement( 'img' );
	preview.className = 'bn-cover-repos-img';
	preview.src = url;
	preview.alt = '';
	stage.appendChild( preview );
	panel.appendChild( stage );

	const start = options.initial || {};
	const pos   = {
		x:    Number.isFinite( Number( start.x ) ) ? Math.max( 0, Math.min( 100, Number( start.x ) ) ) : 50,
		y:    Number.isFinite( Number( start.y ) ) ? Math.max( 0, Math.min( 100, Number( start.y ) ) ) : 50,
		zoom: Number.isFinite( Number( start.zoom ) ) ? Math.max( 1, Math.min( 3, Number( start.zoom ) ) ) : 1,
	};
	const apply3 = () => {
		preview.style.objectPosition = `${ pos.x }% ${ pos.y }%`;
		preview.style.transform      = `scale(${ pos.zoom })`;
	};
	apply3();

	const clampPan = ( v ) => Math.max( 0, Math.min( 100, v ) );

	// Pointer drag → pan. Natural direction: dragging the image right reveals its
	// left side (object-position-x decreases). Sensitivity is scaled down a touch
	// so a full-frame drag doesn't slam to the edge instantly.
	let dragging = false;
	let lastX = 0;
	let lastY = 0;
	stage.addEventListener( 'pointerdown', ( e ) => {
		dragging = true;
		lastX = e.clientX;
		lastY = e.clientY;
		stage.setPointerCapture( e.pointerId );
	} );
	stage.addEventListener( 'pointermove', ( e ) => {
		if ( ! dragging ) { return; }
		pos.x = clampPan( pos.x - ( ( e.clientX - lastX ) / W ) * 100 );
		pos.y = clampPan( pos.y - ( ( e.clientY - lastY ) / H ) * 100 );
		lastX = e.clientX;
		lastY = e.clientY;
		apply3();
	} );
	stage.addEventListener( 'pointerup',     () => { dragging = false; } );
	stage.addEventListener( 'pointercancel', () => { dragging = false; } );

	const setZoom = ( z ) => {
		pos.zoom = Math.max( 1, Math.min( 3, z ) );
		slider.value = String( Math.round( pos.zoom * 100 ) );
		apply3();
	};

	stage.addEventListener( 'wheel', ( e ) => {
		e.preventDefault();
		setZoom( pos.zoom * ( e.deltaY < 0 ? 1.05 : 0.95 ) );
	}, { passive: false } );

	// Keyboard pan/zoom. The arrow moves the picture the way a drag would: the
	// left arrow moves the picture left, which reveals more of its right side.
	stage.addEventListener( 'keydown', ( e ) => {
		const step = e.shiftKey ? PAN_STEP_LARGE : PAN_STEP;
		let handled = true;
		switch ( e.key ) {
			case 'ArrowLeft':  pos.x = clampPan( pos.x + step ); break;
			case 'ArrowRight': pos.x = clampPan( pos.x - step ); break;
			case 'ArrowUp':    pos.y = clampPan( pos.y + step ); break;
			case 'ArrowDown':  pos.y = clampPan( pos.y - step ); break;
			case '+':
			case '=':          setZoom( pos.zoom + ZOOM_STEP ); return e.preventDefault();
			case '-':
			case '_':          setZoom( pos.zoom - ZOOM_STEP ); return e.preventDefault();
			default:           handled = false;
		}
		if ( handled ) {
			e.preventDefault();
			apply3();
		}
	} );

	const slider = document.createElement( 'input' );
	slider.type  = 'range';
	slider.min   = '100';
	slider.max   = '300';
	slider.value = String( Math.round( pos.zoom * 100 ) );
	slider.className = 'bn-avatar-crop-zoom';
	slider.setAttribute( 'aria-label', t( 'zoom', 'Zoom' ) );
	slider.addEventListener( 'input', () => setZoom( parseInt( slider.value, 10 ) / 100 ) );
	panel.appendChild( slider );

	// A failed save keeps the window open and says why; the cover is unchanged.
	const error = document.createElement( 'p' );
	error.className = 'bn-cover-repos-error';
	error.setAttribute( 'role', 'alert' );
	error.hidden = true;
	panel.appendChild( error );

	const actions = document.createElement( 'div' );
	actions.className = 'bn-avatar-crop-actions';
	const cancel = document.createElement( 'button' );
	cancel.type = 'button';
	cancel.className = 'bn-btn';
	cancel.dataset.variant = 'ghost';
	cancel.textContent = t( 'cancel', 'Cancel' );
	const apply = document.createElement( 'button' );
	apply.type = 'button';
	apply.className = 'bn-btn';
	apply.dataset.variant = 'primary';
	apply.textContent = t( 'apply', 'Apply' );
	actions.appendChild( cancel );
	actions.appendChild( apply );
	panel.appendChild( actions );

	let saving = false;

	const cleanup = ( value ) => {
		overlay.remove();
		document.removeEventListener( 'keydown', onKey, true );
		if ( isFile ) { URL.revokeObjectURL( url ); }
		// Hand focus back to the control that opened the window.
		if ( opener && 'function' === typeof opener.focus && document.contains( opener ) ) { opener.focus(); }
		resolve( value );
	};

	const lock = ( on ) => {
		saving = on;
		cancel.disabled = on;
		apply.disabled  = on;
		apply.textContent = on ? t( 'saving', 'Saving...' ) : t( 'apply', 'Apply' );
	};

	cancel.addEventListener( 'click', () => { if ( ! saving ) { cleanup( null ); } } );
	apply.addEventListener( 'click', () => {
		if ( saving ) { return; }
		// Whole-number-friendly values: no floating-point tails in what is sent and stored.
		const framing = { x: Math.round( pos.x * 100 ) / 100, y: Math.round( pos.y * 100 ) / 100, zoom: Math.round( pos.zoom * 1000 ) / 1000 };
		if ( 'function' !== typeof options.onApply ) {
			cleanup( framing );
			return;
		}
		error.hidden = true;
		lock( true );
		Promise.resolve()
			.then( () => options.onApply( framing ) )
			.then( () => { lock( false ); cleanup( framing ); } )
			.catch( ( err ) => {
				lock( false );
				error.textContent = ( err && err.message ) ? err.message : t( 'coverPositionFailed', 'Could not save the position. Try again.' );
				error.hidden = false;
				apply.focus();
			} );
	} );
	overlay.addEventListener( 'click', ( e ) => {
		if ( e.target === overlay && ! saving ) { cleanup( null ); }
	} );

	// Escape cancels; Tab stays inside the window. Enter is deliberately NOT handled here:
	// it presses whichever button has focus, so Enter on Cancel cancels and never applies.
	const onKey = ( e ) => {
		if ( 'Escape' === e.key ) {
			e.preventDefault();
			if ( ! saving ) { cleanup( null ); }
			return;
		}
		if ( 'Tab' !== e.key ) { return; }
		const focusable = Array.from( panel.querySelectorAll( 'button:not([disabled]), input, [tabindex="0"]' ) );
		if ( 0 === focusable.length ) { return; }
		const first = focusable[ 0 ];
		const last  = focusable[ focusable.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		} else if ( ! panel.contains( document.activeElement ) ) {
			e.preventDefault();
			first.focus();
		}
	};
	document.addEventListener( 'keydown', onKey, true );

	overlay.appendChild( panel );
	document.body.appendChild( overlay );
	stage.focus();
}
