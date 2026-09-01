/**
 * Shared cover-photo reposition modal.
 *
 * One drag-to-pan / scroll-or-slider-to-zoom modal used by BOTH the member
 * profile cover (buddynext/profile) and the space cover (buddynext/spaces), so
 * the two frame a cover the same way and can never drift apart. The modal is
 * pure DOM (no Interactivity store internals): it opens on a picked File and
 * resolves to { x, y, zoom } (percentages 0..100 and a 1..3 zoom) or null when
 * cancelled. The caller stages that and uploads focal_x / focal_y / focal_zoom
 * alongside the image.
 *
 * @package BuddyNext
 */

/**
 * Open the reposition modal for a freshly picked cover file.
 *
 * @param {File}     file A cover image File chosen by the owner.
 * @param {Function} [t]  Optional translator `(key, fallback) => string`. When
 *                        omitted, the fallback strings are used verbatim so the
 *                        modal still works untranslated.
 * @return {Promise<{x:number,y:number,zoom:number}|null>} Focal point, or null on cancel.
 */
export function openCoverReposModal( file, t ) {
	const translate = 'function' === typeof t ? t : ( _k, fb ) => fb;
	return new Promise( ( resolve ) => {
		const url = URL.createObjectURL( file );
		const img = new Image();
		img.onload = () => {
			// Keep the object URL alive: the modal's preview <img> uses it as its
			// src. Revoking here (before render) left the crop preview blank. The
			// URL is revoked in the modal's cleanup() when it closes.
			renderCoverReposModal( url, translate, resolve );
		};
		img.onerror = () => {
			URL.revokeObjectURL( url );
			resolve( null );
		};
		img.src = url;
	} );
}

/**
 * Build and mount the modal. Resolves the promise on Apply/Cancel/Escape.
 *
 * @param {string}   url     Object URL of the picked file.
 * @param {Function} t       Translator `(key, fallback) => string`.
 * @param {Function} resolve Promise resolver.
 * @return {void}
 */
function renderCoverReposModal( url, t, resolve ) {
	const W = 480;
	const H = 150; // ~3.2:1 — representative of the desktop hero cover.

	const overlay = document.createElement( 'div' );
	overlay.className = 'bn-avatar-crop-overlay';
	overlay.setAttribute( 'role', 'dialog' );
	overlay.setAttribute( 'aria-modal', 'true' );
	overlay.setAttribute( 'aria-label', t( 'repositionCover', 'Reposition cover photo' ) );

	const panel = document.createElement( 'div' );
	panel.className = 'bn-avatar-crop-panel';

	const title = document.createElement( 'h2' );
	title.className = 'bn-avatar-crop-title';
	title.textContent = t( 'coverDragHint', 'Drag to reposition · scroll or use the slider to zoom' );
	panel.appendChild( title );

	const stage = document.createElement( 'div' );
	stage.className = 'bn-cover-repos-stage';
	stage.style.width  = W + 'px';
	stage.style.height = H + 'px';

	// The preview <img> uses the same display contract as the hero, so the modal
	// is true WYSIWYG: object-fit cover + object-position (pan) + scale.
	const preview = document.createElement( 'img' );
	preview.className = 'bn-cover-repos-img';
	preview.src = url;
	preview.alt = '';
	stage.appendChild( preview );
	panel.appendChild( stage );

	const pos = { x: 50, y: 50, zoom: 1 };
	const apply3 = () => {
		preview.style.objectPosition = `${ pos.x }% ${ pos.y }%`;
		preview.style.transform      = `scale(${ pos.zoom })`;
	};
	apply3();

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
		pos.x = Math.max( 0, Math.min( 100, pos.x - ( ( e.clientX - lastX ) / W ) * 100 ) );
		pos.y = Math.max( 0, Math.min( 100, pos.y - ( ( e.clientY - lastY ) / H ) * 100 ) );
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

	const slider = document.createElement( 'input' );
	slider.type  = 'range';
	slider.min   = '100';
	slider.max   = '300';
	slider.value = '100';
	slider.className = 'bn-avatar-crop-zoom';
	slider.setAttribute( 'aria-label', t( 'zoom', 'Zoom' ) );
	slider.addEventListener( 'input', () => setZoom( parseInt( slider.value, 10 ) / 100 ) );
	panel.appendChild( slider );

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

	const cleanup = ( value ) => {
		overlay.remove();
		document.removeEventListener( 'keydown', onKey );
		URL.revokeObjectURL( url );
		resolve( value );
	};

	cancel.addEventListener( 'click', () => cleanup( null ) );
	apply.addEventListener( 'click', () => cleanup( { x: pos.x, y: pos.y, zoom: pos.zoom } ) );
	overlay.addEventListener( 'click', ( e ) => {
		if ( e.target === overlay ) { cleanup( null ); }
	} );

	const onKey = ( e ) => {
		if ( e.key === 'Escape' ) { cleanup( null ); }
		if ( e.key === 'Enter'  ) { apply.click(); }
	};
	document.addEventListener( 'keydown', onKey );

	overlay.appendChild( panel );
	document.body.appendChild( overlay );
}
