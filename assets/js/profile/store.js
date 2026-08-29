/* BuddyNext - Profile Interactivity API store. */
import { store, getContext, getElement } from '@wordpress/interactivity';
import { bnToast, bnConfirm, bnResolveConnectNote } from '@buddynext/shell-dialog';
import { restFetch } from '@buddynext/rest-client';
import { openCoverReposModal } from '@buddynext/cover-reposition';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_profile) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/profile namespace below; each lookup keeps the English literal as a
 * fallback so the UI never breaks if the state is absent. fmt() fills
 * sprintf-style '%s' / '%d' placeholders in order. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%[sd]/g, () => String( vals[ i++ ] ?? '' ) ); }

/* -- Two-factor enrolment QR -------------------------------------------- */
/* The setup endpoint already returns a complete otpauth:// provisioning URI -
 * the exact string a QR encodes - so only the rendering was missing, and a
 * member on a laptop with their authenticator on their phone (the normal case)
 * had to transcribe 32 characters by eye.
 *
 * The encoder is imported dynamically so its 52KB is fetched when a member
 * actually opens enrolment, not on every profile page view. It is vendored
 * rather than pulled from a CDN: enrolling in two-factor must not depend on a
 * third-party host, and the plugin has to work on a site with no outbound
 * access.
 *
 * Rendered as an inline SVG rather than <img src="data:...">, because a CSP
 * that forbids data: image sources would otherwise silently show a broken
 * image on a security screen. If anything here fails the panel is unchanged -
 * the setup key and the otpauth link are still on screen, so enrolment never
 * depends on the QR succeeding.
 */
async function renderTwofaQr( uri ) {
	const host = document.querySelector( '[data-bn-2fa-qr]' );
	if ( ! host || ! uri ) { return; }

	host.replaceChildren();

	try {
		const { default: qrcode } = await import( '@buddynext/qrcode' );
		// Type 0 = pick the smallest version that fits. 'M' is the level
		// authenticator apps expect and tolerates a phone camera at an angle.
		const qr = qrcode( 0, 'M' );
		qr.addData( uri );
		qr.make();

		// createSvgTag returns a self-contained <svg>; scalable means it stays
		// crisp at any size and inherits the surface colour behind it.
		host.innerHTML = qr.createSvgTag( { scalable: true, margin: 1 } );
		const svg = host.querySelector( 'svg' );
		if ( svg ) {
			svg.setAttribute( 'role', 'img' );
			svg.setAttribute( 'aria-label', t( 'twofaQrAlt', 'QR code for your authenticator app' ) );
		}
		host.hidden = false;
	} catch ( _e ) {
		// Leave the panel exactly as it was: setup key + otpauth link.
		host.hidden = true;
	}
}

/* -- Shared helpers ----------------------------------------------------- */

var slugTimer = null;
var slugAbort = null;

function nonce() {
	return getContext().restNonce || '';
}

/**
 * Swap the hero avatar preview between a custom photo and the initials
 * fallback. The preview <img>/initials are server-rendered, not reactively
 * bound, so upload + remove update it imperatively. Pass an empty url to
 * revert to the initials read from data-bn-initials.
 */
/**
 * Open a hero popover UPWARD when opening downward would put it under the fixed
 * bottom nav.
 *
 * The action row wraps tall on a phone, so the trigger sits near the fold and the
 * menu opened past it: measured at 390x844, the More menu ran 735-945 and
 * `elementFromPoint` on "Report" returned .bn-mobile-nav (z-index 9500). Nothing
 * was unreachable - the menu stays open on scroll - but Block and Report are the
 * safety controls, and a member reaching for those is not in a patient mood.
 * Facebook and LinkedIn both flip a near-fold menu rather than making you scroll
 * one you just opened (Basecamp 10236292515).
 *
 * The nav's height is read from --bn-mobile-nav-h, the measured value
 * shell/extras.js already publishes for every bottom-pinned surface, so this does
 * not become a sixth place that guesses at it.
 *
 * Measured in rAF because the menu is display:none until the Interactivity class
 * binding lands, and a hidden element has no height to measure.
 *
 * @param {string} wrapSel  Selector for the popover wrapper.
 * @param {string} menuSel  Selector for the popover itself.
 * @param {string} ctxKey   Context key holding the flip flag.
 */
function flipIfItWouldLandUnderTheNav( wrapSel, menuSel, ctxKey ) {
	const ref = getElement() && getElement().ref;
	if ( ! ref ) { return; }

	const wrap = ref.closest( wrapSel ) || ref.querySelector( wrapSel );
	if ( ! wrap ) { return; }

	// getContext() only resolves inside an action's synchronous call stack, so
	// take the proxy here and mutate it from the callback. Calling it inside the
	// rAF throws "Cannot read properties of undefined (reading 'context')".
	const ctx = getContext();
	if ( ! ctx ) { return; }

	requestAnimationFrame( () => {
		const menu = wrap.querySelector( menuSel );
		if ( ! menu ) { return; }

		const navH = parseFloat(
			getComputedStyle( document.documentElement ).getPropertyValue( '--bn-mobile-nav-h' )
		) || 0;

		const trigger = wrap.getBoundingClientRect();
		const safeBottom = window.innerHeight - navH;
		const wouldOverflow = trigger.bottom + menu.offsetHeight > safeBottom;

		// Only flip when flipping actually helps — near the top of the viewport an
		// upward menu clips off the top instead, which is the same bug mirrored.
		ctx[ ctxKey ] = wouldOverflow && trigger.top - menu.offsetHeight > 0;
	} );
}

function setAvatarPreview( url ) {
	var box = document.querySelector( '.bn-ep-avatar-preview' );
	if ( ! box ) { return; }
	if ( url ) {
		var img = box.querySelector( 'img' );
		if ( ! img ) {
			box.textContent = '';
			img = document.createElement( 'img' );
			box.appendChild( img );
		}
		img.src = url;
		img.alt = '';
	} else {
		box.textContent = box.getAttribute( 'data-bn-initials' ) || '';
	}
}

/** Show/hide the "Remove photo" control based on whether a custom avatar exists. */
function toggleAvatarRemove( show ) {
	var btn = document.querySelector( '[data-bn-avatar-remove]' );
	if ( btn ) { btn.hidden = ! show; }
}

/**
 * Point the cover <img> at a URL, or clear it back to the empty (no-cover) state.
 * The hero cover is not reactively bound, so it is refreshed directly — mirrors
 * the local-preview path in handleCoverFileChange.
 */
function setCoverPreview( url ) {
	var img = document.querySelector( '[data-bn-cover-preview]' );
	if ( ! img ) { return; }
	var wrap = img.closest( '.bn-pf-cover' );
	if ( url ) {
		img.src           = url;
		img.style.display = '';
		if ( wrap ) { wrap.classList.add( 'bn-pf-cover--has-image' ); }
	} else {
		img.removeAttribute( 'src' );
		img.style.display        = 'none';
		img.style.objectPosition = '';
		img.style.transform      = '';
		if ( wrap ) { wrap.classList.remove( 'bn-pf-cover--has-image' ); }
	}
}

/** Show/hide the "Remove cover" control based on whether a cover exists. */
function toggleCoverRemove( show ) {
	var btn = document.querySelector( '[data-bn-cover-remove]' );
	if ( btn ) { btn.hidden = ! show; }
}

/**
 * Build the bn-icon "x" SVG via the SVG namespace (never innerHTML). Matches the
 * markup IconService::render( 'x' ) emits, so a JS-added repeater remove button is
 * visually identical to a server-rendered one when no icon is on the page to clone.
 */
function buildXIcon() {
	var ns  = 'http://www.w3.org/2000/svg';
	var svg = document.createElementNS( ns, 'svg' );
	svg.setAttribute( 'class', 'bn-icon' );
	svg.setAttribute( 'viewBox', '0 0 24 24' );
	svg.setAttribute( 'fill', 'none' );
	svg.setAttribute( 'stroke', 'currentColor' );
	svg.setAttribute( 'stroke-width', '2' );
	svg.setAttribute( 'stroke-linecap', 'round' );
	svg.setAttribute( 'stroke-linejoin', 'round' );
	[ 'M18 6 6 18', 'm6 6 12 12' ].forEach( function ( d ) {
		var path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', d );
		svg.appendChild( path );
	} );
	return svg;
}

/*
   Avatar crop modal — opens a centred dialog with the selected image
   on a canvas. User drags the image to position it under a circular
   mask, scrolls/pinches to zoom. The output is a 512×512 JPEG blob
   suitable for the existing /me/avatar endpoint. Returns null when
   the user cancels.

   No external library: pure Canvas API + pointer events.
   ---------------------------------------------------------------- */
async function openAvatarCropModal( file ) {
	return new Promise( ( resolve ) => {
		const url = URL.createObjectURL( file );
		const img = new Image();
		img.onload = () => {
			URL.revokeObjectURL( url );
			renderCropModal( img, resolve );
		};
		img.onerror = () => {
			URL.revokeObjectURL( url );
			resolve( null );
		};
		img.src = url;
	} );
}

function renderCropModal( img, resolve ) {
	const SIZE   = 360; // canvas square side
	const OUTPUT = 512; // exported JPEG side

	const overlay = document.createElement( 'div' );
	overlay.className = 'bn-avatar-crop-overlay';
	overlay.setAttribute( 'role', 'dialog' );
	overlay.setAttribute( 'aria-modal', 'true' );
	overlay.setAttribute( 'aria-label', t( 'cropAvatar', 'Crop avatar' ) );

	const panel = document.createElement( 'div' );
	panel.className = 'bn-avatar-crop-panel';

	const title = document.createElement( 'h2' );
	title.className = 'bn-avatar-crop-title';
	title.textContent = t( 'positionAvatar', 'Position your avatar' );
	panel.appendChild( title );

	const stage = document.createElement( 'div' );
	stage.className = 'bn-avatar-crop-stage';
	stage.style.width  = SIZE + 'px';
	stage.style.height = SIZE + 'px';
	const canvas = document.createElement( 'canvas' );
	canvas.width  = SIZE;
	canvas.height = SIZE;
	canvas.className = 'bn-avatar-crop-canvas';
	stage.appendChild( canvas );
	panel.appendChild( stage );

	const ctx = canvas.getContext( '2d' );

	// Fit the image inside the canvas with cover semantics.
	const minScale = Math.max( SIZE / img.width, SIZE / img.height );
	let scale = minScale;
	let tx = ( SIZE - img.width * scale ) / 2;
	let ty = ( SIZE - img.height * scale ) / 2;

	const clampOffsets = () => {
		const w = img.width * scale;
		const h = img.height * scale;
		// Constrain so the image always covers the canvas.
		tx = Math.min( 0, Math.max( SIZE - w, tx ) );
		ty = Math.min( 0, Math.max( SIZE - h, ty ) );
	};

	const draw = () => {
		clampOffsets();
		ctx.clearRect( 0, 0, SIZE, SIZE );
		ctx.drawImage( img, tx, ty, img.width * scale, img.height * scale );
		// Circular mask overlay — dim the corners.
		ctx.save();
		ctx.fillStyle = 'rgba(0,0,0,0.4)';
		ctx.fillRect( 0, 0, SIZE, SIZE );
		ctx.globalCompositeOperation = 'destination-out';
		ctx.beginPath();
		ctx.arc( SIZE / 2, SIZE / 2, SIZE / 2 - 8, 0, Math.PI * 2 );
		ctx.fill();
		ctx.restore();
		// Stroke the crop circle for definition.
		ctx.strokeStyle = '#fff';
		ctx.lineWidth = 2;
		ctx.beginPath();
		ctx.arc( SIZE / 2, SIZE / 2, SIZE / 2 - 8, 0, Math.PI * 2 );
		ctx.stroke();
	};

	// Pointer drag.
	let dragging = false;
	let lastX = 0;
	let lastY = 0;
	canvas.addEventListener( 'pointerdown', ( e ) => {
		dragging = true;
		lastX = e.clientX;
		lastY = e.clientY;
		canvas.setPointerCapture( e.pointerId );
	} );
	canvas.addEventListener( 'pointermove', ( e ) => {
		if ( ! dragging ) { return; }
		tx += e.clientX - lastX;
		ty += e.clientY - lastY;
		lastX = e.clientX;
		lastY = e.clientY;
		draw();
	} );
	canvas.addEventListener( 'pointerup', () => { dragging = false; } );
	canvas.addEventListener( 'pointercancel', () => { dragging = false; } );

	// Wheel zoom — zoom around the centre.
	canvas.addEventListener( 'wheel', ( e ) => {
		e.preventDefault();
		const factor = e.deltaY < 0 ? 1.05 : 0.95;
		const newScale = Math.max( minScale, Math.min( 5, scale * factor ) );
		// Keep canvas centre point under the cursor.
		const cx = SIZE / 2;
		const cy = SIZE / 2;
		tx = cx - ( ( cx - tx ) / scale ) * newScale;
		ty = cy - ( ( cy - ty ) / scale ) * newScale;
		scale = newScale;
		draw();
	}, { passive: false } );

	// Slider zoom for accessibility / non-wheel devices.
	const slider = document.createElement( 'input' );
	slider.type = 'range';
	// 100 == minScale == the image exactly covering the crop frame. Below that the
	// image is smaller than the frame and the uncovered area is not part of the
	// picture at all — which the JPEG export then bakes in as solid black. The
	// wheel handler already clamps to minScale; the slider was the one way in.
	// Every mainstream cropper (Instagram, X) clamps minimum zoom to "fills frame"
	// for the same reason.
	slider.min  = '100';
	slider.max  = '300';
	slider.value = '100';
	slider.className = 'bn-avatar-crop-zoom';
	slider.setAttribute( 'aria-label', t( 'zoom', 'Zoom' ) );
	slider.addEventListener( 'input', () => {
		// Defensive floor as well as the min attribute: a browser that ignores
		// min, or a future change to it, must not be able to reintroduce the
		// uncovered-area case.
		const newScale = Math.max( minScale, minScale * ( parseInt( slider.value, 10 ) / 100 ) );
		const cx = SIZE / 2;
		const cy = SIZE / 2;
		tx = cx - ( ( cx - tx ) / scale ) * newScale;
		ty = cy - ( ( cy - ty ) / scale ) * newScale;
		scale = newScale;
		draw();
	} );
	panel.appendChild( slider );

	// Action row.
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
		resolve( value );
	};

	cancel.addEventListener( 'click', () => cleanup( null ) );
	overlay.addEventListener( 'click', ( e ) => {
		if ( e.target === overlay ) { cleanup( null ); }
	} );

	apply.addEventListener( 'click', () => {
		// Render at OUTPUT × OUTPUT (no mask overlay) for upload.
		const out = document.createElement( 'canvas' );
		out.width  = OUTPUT;
		out.height = OUTPUT;
		const outCtx = out.getContext( '2d' );
		const ratio = OUTPUT / SIZE;
		// Fill before drawing. The context starts fully TRANSPARENT, and toBlob()
		// below encodes image/jpeg, which has no alpha — browsers composite those
		// untouched pixels onto BLACK, so any area the image does not cover is
		// baked black into the uploaded file before the request is even made.
		//
		// The min-zoom clamp above stops the large uncovered area, but this is not
		// redundant: at exactly minScale the drawn width is a float, and sub-pixel
		// rounding can leave a 1px uncovered seam on an edge — which JPEG renders
		// as a black hairline. It also keeps apply() correct by construction,
		// since it reads the raw tx/ty/scale closure vars and does not re-clamp.
		outCtx.fillStyle = '#ffffff';
		outCtx.fillRect( 0, 0, OUTPUT, OUTPUT );
		outCtx.drawImage(
			img,
			tx * ratio,
			ty * ratio,
			img.width * scale * ratio,
			img.height * scale * ratio
		);
		// toBlob can yield null if the browser fails to encode (rare, but real).
		// Surface it as an error instead of silently resolving null (which the
		// caller treats as a cancel), so the user knows the crop did not apply.
		out.toBlob( ( blob ) => {
			if ( ! blob ) {
				if ( typeof window !== 'undefined' && typeof window.bnToast === 'function' ) {
					window.bnToast( t( 'couldNotProcessImage', 'Could not process the image. Try a different file.' ), { tone: 'danger' } );
				}
				cleanup( null );
				return;
			}
			cleanup( blob );
		}, 'image/jpeg', 0.9 );
	} );

	const onKey = ( e ) => {
		if ( e.key === 'Escape' ) { cleanup( null ); }
		if ( e.key === 'Enter'  ) { apply.click(); }
	};
	document.addEventListener( 'keydown', onKey );

	overlay.appendChild( panel );
	document.body.appendChild( overlay );
	draw();
}


/* Strip a trailing "[]" from a control name to get the key the server expects.
   Checkbox groups and <select multiple> render name="key[]"; the payload key is
   the bare `key`. */
function controlKey( name ) {
	return /\[\]$/.test( name ) ? name.slice( 0, -2 ) : name;
}

/* Read ONE form control into `bag` under `key`, honouring the control's type.
   This is the single definition of "what is this control's value", shared by BOTH
   collectors below.

   It is shared deliberately. Every one of the bugs the branches below guard against
   was first fixed in the FLAT collector and silently left broken in the REPEATER
   one, because the two kept their own copies of this logic:

     - a radio group in a repeater saved the LAST option regardless of which the
       member picked (each option overwrote the entry via `el.value`), so a customer
       reported "whichever I choose, it shows the last one";
     - a <select multiple> / checkbox group in a repeater was dropped from the
       payload entirely — saved with a success toast, data gone.

   One collector cannot now be fixed without the other. Do not re-inline this. */
function assignControlValue( bag, key, el ) {
	// Checkbox GROUP (multiselect / category_multiselect render name="key[]"):
	// the checked values are an ARRAY under the bare key. Reading el.value stored a
	// literal "key[]" entry holding whatever checkbox came last, checked or not —
	// a key the server never recognised, so the field silently never saved.
	if ( 'checkbox' === el.type && /\[\]$/.test( el.name ) ) {
		if ( ! Array.isArray( bag[ key ] ) ) { bag[ key ] = []; }
		if ( el.checked ) { bag[ key ].push( el.value ); }
		return;
	}

	// Single checkbox (boolean field): the checked STATE is the value — reading
	// el.value sends "1" even when unchecked.
	if ( 'checkbox' === el.type ) {
		bag[ key ] = el.checked ? '1' : '';
		return;
	}

	// Radio group: ONLY the checked option wins (default empty). Every option in the
	// group carries the same key, so reading el.value lets the LAST rendered option
	// overwrite the member's actual choice.
	if ( 'radio' === el.type ) {
		if ( ! ( key in bag ) ) { bag[ key ] = ''; }
		if ( el.checked ) { bag[ key ] = el.value; }
		return;
	}

	// <select multiple> (Pro multi_select_advanced): el.value returns only the FIRST
	// selected option, so 2+ picks collapsed to one. Collect them all.
	if ( 'select-multiple' === el.type ) {
		bag[ key ] = Array.prototype.slice.call( el.selectedOptions ).map( function ( o ) { return o.value; } );
		return;
	}

	bag[ key ] = el.value;
}

/* Collect all named flat inputs/textareas (excluding the slug input). */
function collectFlatData( wrap ) {
	var data = {};
	wrap.querySelectorAll( 'input[name], textarea[name], select[name]' ).forEach( function ( el ) {
		// Skip the slug input (handled by its own endpoint) and any repeater fields.
		if ( el.id === 'bn-ep-slug' ) { return; }
		if ( /\[\d+\]\[/.test( el.name ) ) { return; }

		assignControlValue( data, controlKey( el.name ), el );
	} );
	return data;
}

/* Map a repeater group key to its rendered DOM container id. The edit
   template builds the id as `bn-ep-{group-with-dashes}-entries`
   (templates/profile/edit.php), e.g. `education` -> `bn-ep-education-entries`
   and `work_experience` -> `bn-ep-work-experience-entries`. Keeping this in one
   place prevents the JS and PHP from drifting (a stale short id like
   `bn-ep-edu-entries` silently dropped the section from the save payload). */
function repeaterContainerId( group ) {
	return 'bn-ep-' + String( group ).replace( /_/g, '-' ) + '-entries';
}

/* Collect repeater entries from a container by data-entry-index children.

   Entry controls are named `group[index][key]`, and multi-value ones (checkbox
   group, <select multiple>) add a trailing `[]` -> `group[index][key][]`. The
   trailing `[]` is optional in the pattern below: when it was not, the regex was
   $-anchored on `]` and never matched a multi-value control, so a multiselect
   inside a repeater was silently dropped from the payload — the member saw a
   success toast and lost the data.

   Value semantics are delegated to assignControlValue(), the same function the flat
   collector uses, so a control type can never again be handled correctly in one
   collector and wrongly in the other. */
function collectRepeaterEntries( containerId ) {
	var container = document.getElementById( containerId );
	if ( ! container ) { return []; }
	var entries = [];
	container.querySelectorAll( '.bn-ep-repeater-entry' ).forEach( function ( row ) {
		var entry = {};
		// Include select[name] so the per-entry privacy lock (_visibility) is
		// collected — matches collectFlatData(). Without it the member's privacy
		// choice on Work Experience / Education rows was never sent and reset to
		// the default on reload.
		row.querySelectorAll( 'input[name], textarea[name], select[name]' ).forEach( function ( el ) {
			var m = el.name.match( /\[\d+\]\[([^\]]+)\](\[\])?$/ );
			if ( ! m ) { return; }
			assignControlValue( entry, m[1], el );
		} );
		entries.push( entry );
	} );
	return entries;
}

/* Build save payload: flat fields + the section groups that are actually on the
   page. The buddynext/profile store now drives partial surfaces too (the Privacy
   settings tab renders only its own fields), so the work / education repeaters are
   included ONLY when their UI is present — otherwise a partial save would send
   empty values and wipe sections the page never rendered. Every dynamic profile
   field (including Skills / Interests) is a flat input picked up by
   collectFlatData. The REST endpoint updates just the keys it receives. */
function buildPayload( ctx ) {
	var wrap = document.querySelector( '[data-wp-interactive="buddynext/profile"]' );
	var data = collectFlatData( wrap );
	// EVERY repeater container on the page, not a hardcoded pair: admin-created
	// repeater groups were invisible to the payload, so their values saved with
	// a success toast but never reached the server (silent data loss). The
	// container carries its real group key in data-bn-repeater-group; the two
	// legacy id lookups remain as a fallback for cached markup without it.
	var seen = {};
	var containers = document.querySelectorAll( '[data-bn-repeater-group]' );
	for ( var i = 0; i < containers.length; i++ ) {
		var groupKey = containers[ i ].getAttribute( 'data-bn-repeater-group' );
		if ( groupKey && ! seen[ groupKey ] ) {
			seen[ groupKey ] = true;
			data[ groupKey ] = collectRepeaterEntries( containers[ i ].id );
		}
	}
	var legacy = [ 'work_experience', 'education' ];
	for ( var j = 0; j < legacy.length; j++ ) {
		if ( ! seen[ legacy[ j ] ] && document.getElementById( repeaterContainerId( legacy[ j ] ) ) ) {
			data[ legacy[ j ] ] = collectRepeaterEntries( repeaterContainerId( legacy[ j ] ) );
		}
	}
	return data;
}

/* Human label for a required control, for the inline error message. Prefers the
   associated <label>, falling back to the field's name. */
function requiredLabelFor( el ) {
	var label = '';
	if ( el.id ) {
		var byFor = document.querySelector( 'label[for="' + el.id + '"]' );
		if ( byFor ) { label = byFor.textContent || ''; }
	}
	if ( ! label ) {
		var wrapLabel = el.closest( '.bn-ep-field, .bn-ep-hero-field' );
		if ( wrapLabel ) {
			var lbl = wrapLabel.querySelector( 'label' );
			if ( lbl ) { label = lbl.textContent || ''; }
		}
	}
	label = label.replace( /\*/g, '' ).trim();
	if ( ! label ) {
		label = ( el.getAttribute( 'name' ) || t( 'thisField', 'This field' ) ).replace( /_/g, ' ' );
	}
	return label;
}

/* Validate a single URL value client-side (matches PHP wp_http_validate_url). */
function isValidUrlClient( raw ) {
	if ( ! raw ) { return true; }
	var candidate = /^https?:\/\//i.test( raw ) ? raw : 'https://' + raw.replace( /^\/+/, '' );
	try {
		var u = new URL( candidate );
		return u.protocol === 'http:' || u.protocol === 'https:';
	} catch ( _e ) {
		return false;
	}
}

/* Reset errors object (Interactivity state cannot mutate keys via delete). */
function clearErrors( ctx ) {
	ctx.errors = {};
	document.querySelectorAll( '.bn-ep-injected-error' ).forEach( function ( el ) {
		el.remove();
	} );
}

/* Surface a 422's per-field errors on their controls and bring the first
   failing control into view. The server-rendered Interactivity error slots
   exist only for FLAT engine fields — a rejection keyed to a repeater
   sub-field (e.g. work_experience[0][team_size]) rendered nowhere, so beyond
   the generic toast the save read as silent, with the member left staring at
   an "Unsaved changes" bar and no visible reason. Keys that have a slot keep
   using it (context.errors drives them); everything else gets an injected
   error span in its field wrapper, removed again on the next save attempt. */
function surfaceFieldErrors( errors ) {
	var firstControl = null;
	Object.keys( errors || {} ).forEach( function ( key ) {
		var control = document.querySelector( '[name="' + key + '"], [name="' + key + '[]"]' );
		if ( ! control ) { return; }
		if ( ! firstControl ) { firstControl = control; }
		if ( document.getElementById( 'bn-ep-error-' + key ) ) {
			return; // The server-rendered slot renders this one reactively.
		}
		var span = document.createElement( 'span' );
		span.className = 'bn-ep-field-error bn-ep-injected-error';
		span.setAttribute( 'role', 'alert' );
		span.textContent = String( errors[ key ] );
		var wrap = control.closest( '.bn-ep-field, .bn-ep-hero-field' ) || control.parentElement;
		wrap.appendChild( span );
	} );
	if ( firstControl ) {
		firstControl.scrollIntoView( { block: 'center', behavior: 'smooth' } );
	}
}

/* Resolve the profile-save endpoint. When the edit surface is editing another
   member (data-bn-profile-user on the interactive root, set by edit.php for
   edit-any holders), save to that user's admin route; otherwise the own
   /me/profile route. */
function profileSaveUrl() {
	var root   = document.querySelector( '[data-wp-interactive="buddynext/profile"]' );
	var target = root ? parseInt( root.getAttribute( 'data-bn-profile-user' ) || '0', 10 ) : 0;
	return target > 0
		? '/users/' + target + '/profile'
		: '/me/profile';
}

/* True when the current edit surface submits the COMPLETE profile (the full
   profile editor, edit.php), as opposed to a partial surface (privacy tab,
   per-field autosave). The full editor marks its interactive root with
   data-bn-full-write; partial surfaces omit it. The server enforces required
   fields across ABSENT keys only on a full write, so a partial save never
   demands every field. */
function isFullWriteSurface() {
	var root = document.querySelector( '[data-wp-interactive="buddynext/profile"]' );
	return !! ( root && root.getAttribute( 'data-bn-full-write' ) === '1' );
}

/* Resolve a profile sub-resource endpoint (avatar / cover) for the user being
   edited — /users/{id}/{segment} when an admin is editing someone else (the same
   data-bn-profile-user target profileSaveUrl uses), else the own /me/{segment}.
   Without this, avatar/cover uploads always hit /me/* and an admin editing
   another member's profile would overwrite their OWN avatar/cover. */
function profileResourcePath( segment ) {
	var root   = document.querySelector( '[data-wp-interactive="buddynext/profile"]' );
	var target = root ? parseInt( root.getAttribute( 'data-bn-profile-user' ) || '0', 10 ) : 0;
	return target > 0 ? '/users/' + target + '/' + segment : '/me/' + segment;
}

/* Staged avatar/cover changes held client-side until the master Save.
   The crop/reposition modal stages the chosen image here and shows a LOCAL
   preview (object URL) but does NOT upload — so a Cancel/Leave reverts cleanly
   with nothing persisted. doSave() flushes these after the profile PUT. */
var _pendingAvatar = null; // { blob }
var _pendingCover  = null; // { file, x, y, zoom }

/* Persist any staged avatar/cover after a successful profile save. Each upload
   reuses the captured REST nonce (ctx.restNonce); failures surface a toast but
   don't fail the overall save (the field data is already persisted). */
async function flushStagedMedia( ctx ) {
	var allOk = true;
	if ( _pendingAvatar ) {
		var avatarOk = false;
		// Reactive busy flag: drives the spinner overlay on the avatar while its
		// deferred upload runs on Save (the local preview already swapped on select,
		// but nothing signalled the actual network write).
		ctx.avatarUploading = true;
		var avFd     = new FormData();
		avFd.append( 'avatar', _pendingAvatar.blob, 'avatar.jpg' );
		try {
			var avRes = await restFetch( profileResourcePath( 'avatar' ), {
				method:       'POST',
				nonce:        ctx.restNonce,
				body:         avFd,
				toastOnError: false,
			} );
			var avData = avRes.data || {};
			if ( avRes.ok && avData.avatar_url ) {
				avatarOk      = true;
				ctx.avatarUrl = avData.avatar_url;
				setAvatarPreview( avData.avatar_url );
				toggleAvatarRemove( true );
			} else {
				allOk = false;
				bnToast( ( avData && avData.message ) || t( 'avatarSaveFailed', 'Avatar could not be saved' ), { tone: 'danger' } );
			}
		} catch ( _e ) {
			allOk = false;
			bnToast( t( 'avatarSaveFailed', 'Avatar could not be saved' ), { tone: 'danger' } );
		}
		// Only drop the staged image once it is actually stored. Clearing it on
		// failure threw away the member's chosen file while the save bar told them
		// everything had saved — so pressing Save again silently uploaded nothing.
		if ( avatarOk ) {
			_pendingAvatar = null;
		}
		ctx.avatarUploading = false;
	}

	if ( _pendingCover ) {
		var coverOk = false;
		// Reactive busy flag for the cover's deferred upload (see avatar note).
		ctx.coverUploading = true;
		var cvFd    = new FormData();
		cvFd.append( 'avatar', _pendingCover.file );
		cvFd.append( 'focal_x', String( _pendingCover.x ) );
		cvFd.append( 'focal_y', String( _pendingCover.y ) );
		cvFd.append( 'focal_zoom', String( _pendingCover.zoom ) );
		try {
			var cvRes = await restFetch( profileResourcePath( 'cover' ), {
				method:       'POST',
				nonce:        ctx.restNonce,
				body:         cvFd,
				toastOnError: false,
			} );
			var cvData = cvRes.data || {};
			if ( cvRes.ok && cvData.cover_url ) {
				coverOk      = true;
				ctx.coverUrl = cvData.cover_url;
				toggleCoverRemove( true );
			} else {
				allOk = false;
				bnToast( ( cvData && cvData.message ) || t( 'coverSaveFailed', 'Cover could not be saved' ), { tone: 'danger' } );
			}
		} catch ( _e ) {
			allOk = false;
			bnToast( t( 'coverSaveFailed', 'Cover could not be saved' ), { tone: 'danger' } );
		}
		// Retain a rejected cover so Save retries it (see the avatar note above).
		if ( coverOk ) {
			_pendingCover = null;
		}
		ctx.coverUploading = false;
	}
	return allOk;
}

/* Master save flow - submits all fields, handles 200 / 422 / 5xx. */
async function doSave( ctx ) {
	if ( ctx.saving ) { return; }
	ctx.saving = true;
	ctx.saved  = false;
	clearErrors( ctx );

	try {
		var payload = buildPayload( ctx );
		// The complete profile editor (edit.php) marks its root data-bn-full-write;
		// partial surfaces (privacy tab, per-field autosave) omit it. Signal a full
		// write so the server enforces required fields across absent keys too.
		if ( isFullWriteSurface() ) {
			payload.full_write = true;
		}
		var res = await restFetch( profileSaveUrl(), {
			method:       'PUT',
			nonce:        nonce(),
			body:         payload,
			toastOnError: false,
		} );

		var json = res.data || {};

		if ( res.ok ) {
			// Persist staged avatar/cover now that the field save succeeded, so
			// they survive reload — and a pre-save Cancel/Leave reverts them.
			var mediaOk = await flushStagedMedia( ctx );

			// The field data IS persisted at this point, but the save as a whole only
			// succeeded if the staged avatar/cover uploaded too. Claiming otherwise is
			// what produced the conflicting messages: the save bar showed a green
			// "All changes saved" check at the same moment a danger toast said the
			// cover could not be saved. So the completed state — the tick, the cleared
			// dirty flag, and the success toast — is announced only when the media
			// actually landed. On failure the bar stays "Unsaved changes", the staged
			// image is retained (see flushStagedMedia), and pressing Save retries it.
			if ( ! mediaOk ) {
				// flushStagedMedia already toasted the specific reason (too large,
				// wrong type, …). Do not stack a second message on top of it.
				//
				// Keep the bar on "Unsaved changes": the staged image is still
				// pending, so that is the truth, and it gives the member something to
				// press to retry after picking a different file. Reporting nothing at
				// all would leave them with a dismissed toast and no state.
				ctx.isDirty = true;
				syncDirtyAttr( true );
				return;
			}

			ctx.saved   = true;
			ctx.isDirty = false;
			// Mirror the cleared dirty state onto the DOM attribute at the source —
			// the beforeunload guard reads data-bn-dirty, and relying only on
			// saveProfile's .then() left a window where a re-render could surface
			// the unsaved-changes prompt after a fully successful save.
			syncDirtyAttr( false );
			bnToast( ( window.bnI18n && window.bnI18n.profileSaved ) || t( 'profileSaved', 'Profile saved' ), { tone: 'success' } );
			setTimeout( function () { ctx.saved = false; }, 3000 );
		} else if ( res.status === 422 && json && json.errors ) {
			ctx.errors = json.errors;
			surfaceFieldErrors( json.errors );
			bnToast( ( window.bnI18n && window.bnI18n.fieldsNeedAttention ) || t( 'fieldsNeedAttention', 'Some fields need attention' ), { tone: 'danger' } );
		} else {
			bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || t( 'saveFailed', 'Could not save. Please try again.' ), { tone: 'danger' } );
		}
	} catch ( _e ) {
		bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || t( 'saveFailed', 'Could not save. Please try again.' ), { tone: 'danger' } );
	} finally {
		ctx.saving = false;
	}
}

/* Silent autosave - used by per-field blur where defined. Failure stays quiet. */
async function doAutoSave( ctx ) {
	if ( ctx.saving ) { return; }
	ctx.saving = true;
	try {
		var res = await restFetch( profileSaveUrl(), {
			method:       'PUT',
			nonce:        nonce(),
			body:         buildPayload( ctx ),
			toastOnError: false,
		} );
		if ( res.ok ) {
			ctx.saved   = true;
			ctx.isDirty = false;
			// Per-field autosave (sliders/toggles) must also clear the DOM dirty
			// marker the beforeunload guard reads — otherwise a silent autosave
			// leaves data-bn-dirty="1" and the unsaved-changes prompt fires on
			// navigation even though everything is saved.
			syncDirtyAttr( false );
			setTimeout( function () { ctx.saved = false; }, 3000 );
		}
	} catch ( _e ) {
		/* silent for autosave */
	} finally {
		ctx.saving = false;
	}
}

/* Re-number visible repeater entries after add/remove. */
function renumberEntries( containerId ) {
	var container = document.getElementById( containerId );
	if ( ! container ) { return; }
	var entries = container.querySelectorAll( '.bn-ep-repeater-entry' );
	entries.forEach( function ( row, i ) {
		var numEl = row.querySelector( '.bn-ep-repeater-num' );
		if ( numEl ) { numEl.textContent = String( i + 1 ); }
		row.dataset.entryIndex = String( i );
		row.querySelectorAll( '[name]' ).forEach( function ( el ) {
			el.name = el.name.replace( /\[\d+\]/, '[' + i + ']' );
		} );
		var removeBtn = row.querySelector( '.bn-ep-repeater-remove' );
		if ( removeBtn ) { removeBtn.dataset.entryIndex = String( i ); }
	} );
}

/* Pristine first-entry clone per repeater container, captured at edit-page
   init BEFORE the member touches anything. buildEntryNodeFromClone() needs a
   seed row to clone; once the member removes the LAST entry of a group the
   live DOM has none, and without this snapshot Add Entry became a silent dead
   button until reload. Keyed by container id. */
var pristineSeeds = {};

function snapshotRepeaterSeeds() {
	var containers = document.querySelectorAll( '[data-bn-repeater-group]' );
	var i;
	for ( i = 0; i < containers.length; i++ ) {
		if ( containers[ i ].id && ! pristineSeeds[ containers[ i ].id ] ) {
			var entry = containers[ i ].querySelector( '.bn-ep-repeater-entry' );
			if ( entry ) { pristineSeeds[ containers[ i ].id ] = entry.cloneNode( true ); }
		}
	}
	var legacy = [ 'work_experience', 'education' ];
	for ( i = 0; i < legacy.length; i++ ) {
		var cid = repeaterContainerId( legacy[ i ] );
		var c   = document.getElementById( cid );
		if ( c && ! pristineSeeds[ cid ] ) {
			var seed = c.querySelector( '.bn-ep-repeater-entry' );
			if ( seed ) { pristineSeeds[ cid ] = seed.cloneNode( true ); }
		}
	}
}

/* Build a blank repeater entry by cloning the server-rendered entry and
   resetting it — for EVERY group, built-in and admin-created alike. The server
   renders every repeater group through the field-type engine and always emits at
   least one schema-bearing entry (even when empty), so the clone reproduces the
   exact markup, current labels, placeholders, descriptions and field set with no
   hardcoded field map. The old hardcoded Work Experience / Education map is gone
   on purpose: it froze the groups' original field definitions into JS, so admin
   renames and added fields never reached an Add Entry row.

   When the live DOM has no entry left to clone (the member removed every row),
   the pristine snapshot taken at page init is the seed — Add Entry keeps
   working on an emptied group instead of silently doing nothing. */
function buildEntryNodeFromClone( group, index ) {
	var container = document.getElementById( repeaterContainerId( group ) );
	if ( ! container ) { return null; }
	var seed = container.querySelector( '.bn-ep-repeater-entry' ) || pristineSeeds[ container.id ];
	if ( ! seed ) { return null; }

	var clone = seed.cloneNode( true );
	clone.dataset.entryIndex = String( index );

	var num = clone.querySelector( '.bn-ep-repeater-num' );
	if ( num ) { num.textContent = String( index + 1 ); }

	// Reindex control names group[old][key] -> group[index][key] so each entry is
	// an independent checkbox/radio group, and clear the cloned values.
	clone.querySelectorAll( 'input[name], textarea[name], select[name]' ).forEach( function ( el ) {
		el.name = el.name.replace( /\[\d+\]/, '[' + index + ']' );
		if ( 'checkbox' === el.type || 'radio' === el.type ) {
			el.checked = false;
		} else if ( 'SELECT' === el.tagName ) {
			// Every <select> resets to its FIRST option: for the per-entry
			// privacy lock that is the admin default (its options are
			// rank-ordered and filtered), and for a dropdown sub-field it is
			// the placeholder/first choice — never the seed row's picked value.
			if ( el.options.length ) { el.selectedIndex = 0; }
		} else {
			el.value = '';
		}
		// A seed entry with "currently working/attending" checked has its paired
		// end field disabled with an injected "Present" placeholder — the fresh
		// row's checkbox starts unchecked, so release that state on the clone.
		el.disabled = false;
		if ( el.getAttribute( 'placeholder' ) === t( 'present', 'Present' ) ) {
			el.removeAttribute( 'placeholder' );
		}
	} );

	// Keep ids/labels unique so a label click focuses THIS entry's control.
	//
	// Two id shapes live in a repeater entry and only one of them ends in the
	// entry index. A sub-field control is id'd from its NAME by
	// FieldType::input_id() -- 'bn-field-' + the name with everything outside
	// [A-Za-z0-9_-] stripped, so work_experience[1][work_company] becomes
	// bn-field-work_experience1work_company. The old `-\d+$` rule never matched
	// those, so a cloned entry kept entry 0's ids and every label in it focused
	// the FIRST entry's control. Names are already reindexed above, so the id is
	// re-derived from the new name here; the `-\d+$` path still covers the
	// per-entry privacy select, whose id does end in the index.
	clone.querySelectorAll( '[id]' ).forEach( function ( el ) {
		var newId;

		if ( 0 === el.id.indexOf( 'bn-field-' ) && el.name ) {
			newId = 'bn-field-' + el.name.replace( /[^A-Za-z0-9_-]/g, '' );
		} else {
			newId = el.id.replace( /-\d+$/, '-' + index );
		}

		if ( newId === el.id ) { return; }
		var lbl = clone.querySelector( 'label[for="' + el.id + '"]' );
		el.id = newId;
		if ( lbl ) { lbl.setAttribute( 'for', newId ); }
	} );

	// The server remove button binds via Interactivity (data-wp-on--click), which
	// only hydrates initial HTML — a cloned button never fires it. Drop that
	// attribute and wire an explicit handler, mirroring the built-in path.
	var removeBtn = clone.querySelector( '.bn-ep-repeater-remove' );
	if ( removeBtn ) {
		removeBtn.removeAttribute( 'data-wp-on--click' );
		removeBtn.dataset.entryIndex = String( index );
		removeBtn.addEventListener( 'click', function () {
			clone.remove();
			renumberEntries( repeaterContainerId( group ) );
		} );
	}

	return clone;
}


/* Pair each "currently here / attending" boolean to the end date/year it
   supersedes. Checking the box marks the role/study as ongoing, so the paired
   End field is cleared, disabled, and shown as "Present". */
var CURRENT_TOGGLE_PAIRS = {
	work_current: 'work_end_date',
	edu_current:  'edu_end_year',
};

/* Apply (or release) the "Present" state of a current-status checkbox onto its
   paired end field within the same repeater entry. Works for both the
   server-rendered rows and the JS-built ones (matched by name convention). */
function applyCurrentToggle( checkbox ) {
	var m = ( checkbox.name || '' ).match( /^([^\[]+)\[(\d+)\]\[([^\]]+)\]$/ );
	if ( ! m ) { return; }
	var endKey = CURRENT_TOGGLE_PAIRS[ m[3] ];
	if ( ! endKey ) { return; }
	var entry = checkbox.closest( '.bn-ep-repeater-entry' );
	if ( ! entry ) { return; }
	var endEl = entry.querySelector( '[name="' + m[1] + '[' + m[2] + '][' + endKey + ']"]' );
	if ( ! endEl ) { return; }
	if ( checkbox.checked ) {
		endEl.value    = '';
		endEl.disabled = true;
		// Remember whatever placeholder the field arrived with (usually the
		// owner's, set in Members > Profile Fields) so unchecking can put it back
		// rather than leaving the field bare. Stashed once — a second check must
		// not overwrite the stash with our own injected "Present".
		if ( ! endEl.hasAttribute( 'data-bn-placeholder-was' ) ) {
			endEl.setAttribute( 'data-bn-placeholder-was', endEl.getAttribute( 'placeholder' ) || '' );
		}
		endEl.setAttribute( 'placeholder', t( 'present', 'Present' ) );
	} else {
		endEl.disabled = false;

		// Release ONLY the state this function injected.
		//
		// This branch used to call removeAttribute( 'placeholder' ) unconditionally,
		// and wireCurrentToggles() runs an initial pass over EVERY current-status
		// checkbox on load — so on every page load each unchecked row had its paired
		// end field's placeholder deleted, owner-authored or not. An admin who set
		// "To" on Education > End Year saw it vanish the moment the page's JS
		// initialised, while "From" on the sibling Start Year survived (no toggle is
		// paired to it). buildEntryNodeFromClone() already had the correct guard;
		// this function never got it.
		var stashed = endEl.getAttribute( 'data-bn-placeholder-was' );
		if ( null !== stashed ) {
			if ( '' === stashed ) {
				endEl.removeAttribute( 'placeholder' );
			} else {
				endEl.setAttribute( 'placeholder', stashed );
			}
			endEl.removeAttribute( 'data-bn-placeholder-was' );
		} else if ( endEl.getAttribute( 'placeholder' ) === t( 'present', 'Present' ) ) {
			// No stash: the row arrived from the server already checked, so the only
			// placeholder that can be ours is the literal "Present".
			endEl.removeAttribute( 'placeholder' );
		}
	}
}

/* Bind one delegated change listener on the edit shell so every current-status
   checkbox — including entries added after load — toggles its paired end field,
   then run an initial pass for entries the server rendered already checked. */
function wireCurrentToggles() {
	var shell = document.querySelector( '[data-wp-interactive="buddynext/profile"]' );
	if ( ! shell || shell.__bnCurrentTogglesBound ) { return; }
	shell.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t && 'checkbox' === t.type && CURRENT_TOGGLE_PAIRS[ ( ( t.name || '' ).match( /\[([^\]]+)\]$/ ) || [] )[ 1 ] ] ) {
			applyCurrentToggle( t );
		}
	} );
	shell.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( cb ) {
		if ( CURRENT_TOGGLE_PAIRS[ ( ( cb.name || '' ).match( /\[([^\]]+)\]$/ ) || [] )[ 1 ] ] ) {
			applyCurrentToggle( cb );
		}
	} );
	shell.__bnCurrentTogglesBound = true;
}

/* Track the beforeunload listener so we can attach once and detach on save. */
var unloadHandlerAttached = false;
function ensureUnloadGuard() {
	if ( unloadHandlerAttached ) { return; }
	window.addEventListener( 'beforeunload', function ( event ) {
		var wrap = document.querySelector( '[data-wp-interactive="buddynext/profile"] .bn-ep-form-shell' );
		if ( ! wrap ) { return; }
		// Read the live context flag from a hidden marker - simplest is a data attribute we update on save/dirty.
		if ( wrap.dataset.bnDirty === '1' ) {
			event.preventDefault();
			event.returnValue = '';
			return '';
		}
	} );
	unloadHandlerAttached = true;
}

/* Mirror the reactive isDirty state onto the form element so beforeunload can read it cheaply. */
function syncDirtyAttr( dirty ) {
	var wrap = document.querySelector( '.bn-ep-form-shell' );
	if ( wrap ) { wrap.dataset.bnDirty = dirty ? '1' : '0'; }
}

/* -- Store ------------------------------------------------------------- */

const profileStore = store( 'buddynext/profile', {
	state: {
		get muteLabel()     { return getContext().isMuted      ? t( 'unmute', 'Unmute' )         : t( 'mute', 'Mute' ); },
		get restrictLabel() { return getContext().isRestricted ? t( 'unrestrict', 'Unrestrict' ) : t( 'restrict', 'Restrict' ); },
		get blockLabel()    { return getContext().isBlocked    ? t( 'unblock', 'Unblock' )       : t( 'block', 'Block' ); },

		// Follow has THREE states on a profile header — Follow / Requested /
		// Following — because following a private account lands as a pending
		// request. Computed rather than an inline expression in data-wp-bind so
		// the transitions live in one place.
		get followBtnHidden() { const c = getContext(); return !! c.isFollowing || !! c.followPending; },
		/* Two-factor stage visibility (mutually exclusive). */
		get twofaShowStart()  { const c = getContext(); return ! c.twofaEnabled && c.twofaStage === 'idle'; },
		get twofaShowSetup()  { return getContext().twofaStage === 'setup'; },
		get twofaShowBackup() { return getContext().twofaStage === 'backup'; },
		get twofaShowManage() { const c = getContext(); return !! c.twofaEnabled && c.twofaStage === 'idle'; },
		get twofaBackupText() {
			const n = Number( getContext().twofaBackupRemaining ) || 0;
			return n === 1
				? fmt( t( 'backupCodeLeftSingular', '%d backup code left.' ), n )
				: fmt( t( 'backupCodesLeftPlural', '%d backup codes left.' ), n );
		},
		/* Profile-URL slug availability. WP Interactivity only resolves a single
		 * property path (optionally prefixed with !), not compound expressions
		 * (||, ===, !==), so the slug indicator's comparisons must live here as
		 * derived state and be referenced as state.* in the template. */
		get slugStatusHidden() { const c = getContext(); return c.slugChecking || c.slugAvailable === null; },
		get slugIsOk()         { return getContext().slugAvailable === true; },
		get slugIsTaken()      { return getContext().slugAvailable === false; },
		get slugSaveDisabled() { const c = getContext(); return ! c.slugAvailable || c.slugSaving; },
		/* Same rule, applied to the save bar's "Unsaved changes" pill. It was bound to
		 * `!(context.isDirty && !context.saving && !context.saved)`, which the API
		 * resolved by stripping the "!", failing to resolve the rest as a path, and
		 * negating undefined to true — so the pill was hidden permanently and the bar
		 * never said why it had appeared. Its two siblings survived only because
		 * `!context.saved` / `!context.saving` happen to be valid single paths. */
		get saveDirtyHidden() { const c = getContext(); return ! ( c && c.isDirty && ! c.saving && ! c.saved ); },
	},
	callbacks: {
		/* Init for the edit page: register the beforeunload guard once. */
		initEditGuard() {
			ensureUnloadGuard();
			wireCurrentToggles();
			// Snapshot each repeater's pristine first entry before any edit, so
			// Add Entry can still build a row after the member empties a group.
			snapshotRepeaterSeeds();
			// Honour a #avatar / #cover deep-link (the view-hero "Edit avatar" /
			// "Edit cover" links land here) by opening the matching file picker, so
			// the anchor isn't a dead scroll-to-nothing. A short defer lets the
			// Interactivity store finish binding the inputs first.
			var bnHash = ( window.location.hash || '' ).toLowerCase();
			if ( '#avatar' === bnHash || '#cover' === bnHash ) {
				var bnPickerId = '#avatar' === bnHash ? 'bn-ep-avatar-file' : 'bn-ep-cover-file';
				setTimeout( function () {
					var el = document.getElementById( bnPickerId );
					if ( el ) { el.click(); }
				}, 200 );
			}
		},
	},
	actions: {

		/* Export the current member's own data as a downloadable JSON file.
		 * GET buddynext/v1/me/data-export (gated by the Privacy setting). */
		exportMyData: async function ( event ) {
			var btn = event && event.target && event.target.closest( 'button' );
			if ( btn ) { btn.disabled = true; }
			try {
				var res = await restFetch( '/me/data-export', {
					nonce:        nonce(),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'http_' + res.status ); }
				var data = res.data;
				var blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
				var url  = URL.createObjectURL( blob );
				var a    = document.createElement( 'a' );
				a.href     = url;
				a.download = 'my-data-export.json';
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( url );
				bnToast( t( 'dataExportDownloaded', 'Your data export has downloaded.' ), 'success' );
			} catch ( _e ) {
				bnToast( t( 'dataExportFailed', 'Could not export your data. Please try again.' ), 'danger' );
			} finally {
				if ( btn ) { btn.disabled = false; }
			}
		},

		/* Delete the current member's own account after a confirm modal.
		 * DELETE buddynext/v1/me/account (gated by the Privacy setting). */
		deleteMyAccount: async function ( event ) {
			// Capture the Interactivity context BEFORE the await, exactly as
			// removeAvatar()/removeCover() do. getContext() is only valid in an
			// action's synchronous portion; reading it after `await bnConfirm()`
			// has resolved throws, and because that throw happens before
			// restFetch() is ever called, the catch below fired and showed
			// "Could not delete your account. Please try again." while NO DELETE
			// request was sent. The account was never deleted and the member had
			// no way to tell the difference from a server refusal.
			var ctx = getContext();
			var btn = event && event.target && event.target.closest( 'button' );

			var ok = await bnConfirm( {
				title:        t( 'deleteAccountTitle', 'Delete your account?' ),
				body:         t( 'deleteAccountMessage', 'This permanently deletes your account and removes your data. This cannot be undone.' ),
				confirmLabel: t( 'deleteAccountConfirm', 'Delete my account' ),
				tone:         'danger',
			} );
			if ( ! ok ) { return; }

			if ( btn ) { btn.disabled = true; }
			try {
				var res  = await restFetch( '/me/account', {
					method:       'DELETE',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				var data = res.data || {};
				if ( res.ok && data.deleted ) {
					window.location.href = data.redirect_to || '/';
				} else {
					if ( btn ) { btn.disabled = false; }
					bnToast( ( data && data.message ) || t( 'deleteAccountFailed', 'Could not delete your account.' ), 'danger' );
				}
			} catch ( _e ) {
				if ( btn ) { btn.disabled = false; }
				bnToast( t( 'deleteAccountFailedRetry', 'Could not delete your account. Please try again.' ), 'danger' );
			}
		},

		/* Share profile — prefers the native Web Share API (iOS, Android,
		 * macOS Safari, Edge) and falls back to copying the URL to the
		 * clipboard with a toast confirmation. Fully accessible: triggers
		 * from a real <button> so keyboard activation works without extra
		 * handlers.
		 */
		shareProfile( event ) {
			const trigger = event.target.closest( '[data-share-url]' );
			if ( ! trigger ) { return; }
			const url   = trigger.dataset.shareUrl || window.location.href;
			const title = document.querySelector( '.bn-pf-name' )?.textContent?.trim() || t( 'profile', 'Profile' );
			const toast = ( typeof window !== 'undefined' && typeof window.bnToast === 'function' ) ? window.bnToast : null;
			if ( navigator.share ) {
				navigator.share( { title, url } ).catch( () => {} );
				return;
			}
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url ).then(
					() => { if ( toast ) { toast( t( 'profileLinkCopied', 'Profile link copied' ), { tone: 'info' } ); } },
					() => { if ( toast ) { toast( t( 'couldNotCopyLongPress', 'Could not copy. Long-press the URL.' ), { tone: 'danger' } ); } }
				);
				return;
			}
			// Last-resort fallback (no Web Share, no async clipboard): copy via a
			// temporary off-screen textarea + execCommand — never a native prompt().
			try {
				const ta = document.createElement( 'textarea' );
				ta.value = url;
				ta.setAttribute( 'readonly', '' );
				ta.style.position = 'absolute';
				ta.style.left     = '-9999px';
				document.body.appendChild( ta );
				ta.select();
				const ok = document.execCommand( 'copy' );
				document.body.removeChild( ta );
				if ( toast ) {
					toast( ok ? t( 'profileLinkCopied', 'Profile link copied' ) : fmt( t( 'copyThisLink', 'Copy this link: %s' ), url ), { tone: ok ? 'info' : 'danger' } );
				}
			} catch ( _e ) {
				if ( toast ) { toast( fmt( t( 'copyThisLink', 'Copy this link: %s' ), url ), { tone: 'danger' } ); }
			}
		},

		/* Mark form as dirty on any user input. */
		markDirty() {
			var ctx = getContext();
			if ( ! ctx.isDirty ) {
				ctx.isDirty = true;
				syncDirtyAttr( true );
			}
		},

		/* Unlink a connected social provider from the current account.
		 * DELETEs /me/social/{provider} and swaps the row's button back to Connect. */
		async unlinkSocial( event ) {
			var btn      = event.target.closest( '[data-provider]' );
			if ( ! btn ) { return; }
			var provider = btn.getAttribute( 'data-provider' );
			try {
				var res = await restFetch( '/me/social/' + provider, {
					method:       'DELETE',
					nonce:        nonce(),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'http_' + res.status ); }
				bnToast( ( window.bnI18n && window.bnI18n.socialUnlinked ) || t( 'socialUnlinked', 'Account unlinked' ), { tone: 'success' } );
				var row = btn.closest( '.bn-social-link' );
				if ( row ) {
					var a = document.createElement( 'a' );
					a.className = 'bn-btn';
					a.setAttribute( 'data-variant', 'ghost' );
					a.setAttribute( 'data-size', 'sm' );
					a.href = '/oauth/' + provider + '/';
					a.textContent = t( 'connect', 'Connect' );
					btn.replaceWith( a );
				}
			} catch ( _e ) {
				bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || t( 'socialUnlinkFailed', 'Could not unlink. Try again.' ), { tone: 'danger' } );
			}
		},

		/* Self-assign a member type (own profile, self-select types only). PUTs
		 * the chosen slug to /users/{id}/member-type; the endpoint enforces the
		 * self_select gate server-side. Saves immediately on change. */
		async setMemberType( event ) {
			var sel    = event.target;
			var userId = sel.getAttribute( 'data-user-id' );
			if ( ! userId ) { return; }
			// This select auto-saves on change, so it must NOT mark the manual-save
			// form dirty — otherwise the unsaved-changes guard fires right after a
			// successful save. Stop the change bubbling to the form's markDirty
			// listener; genuine edits to other fields still set the dirty flag.
			if ( event && typeof event.stopPropagation === 'function' ) { event.stopPropagation(); }
			try {
				var res = await restFetch( '/users/' + userId + '/member-type', {
					method:       'PUT',
					nonce:        nonce(),
					body:         { type_slug: sel.value },
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'http_' + res.status ); }
				bnToast( ( window.bnI18n && window.bnI18n.memberTypeSaved ) || t( 'memberTypeSaved', 'Member type updated' ), { tone: 'success' } );
			} catch ( _e ) {
				bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || t( 'memberTypeFailed', 'Could not update member type' ), { tone: 'danger' } );
			}
		},

		/* Toggle a single whitelisted boolean preference (privacy / notification
		 * email opt-ins). Updates the aria-checked state optimistically, fires
		 * a PUT /me/profile with the single key, rolls back + toasts on failure.
		 */
		async togglePref( event ) {
			var btn = event.target.closest( '[data-pref]' );
			if ( ! btn ) { return; }
			var prefKey = btn.dataset.pref;
			if ( ! prefKey ) { return; }

			var prev = btn.getAttribute( 'aria-checked' ) === 'true';
			var next = ! prev;
			btn.setAttribute( 'aria-checked', next ? 'true' : 'false' );

			var payload = {};
			payload[ prefKey ] = next;

			try {
				var res = await restFetch( '/me/profile', {
					method:       'PUT',
					nonce:        nonce(),
					body:         payload,
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
				bnToast(
					( window.bnI18n && window.bnI18n.prefSaved ) || t( 'prefSaved', 'Preference saved' ),
					{ tone: 'success' }
				);
			} catch ( _e ) {
				btn.setAttribute( 'aria-checked', prev ? 'true' : 'false' );
				bnToast(
					( window.bnI18n && window.bnI18n.saveFailed ) || t( 'saveFailed', 'Could not save. Please try again.' ),
					{ tone: 'danger' }
				);
			}
		},

		/* Keep the controlled display-name input in sync with reactive state so the
		 * re-render triggered when validateField writes context.errors on blur paints
		 * the value the member typed instead of resetting the uncontrolled input back
		 * to the server-rendered login. The form-level data-wp-on--input still fires
		 * for markDirty; this only mirrors the value into context.nameValue. */
		syncNameField( event ) {
			getContext().nameValue = ( event.target && event.target.value ) || '';
		},

		/* Inline field validation on blur. */
		validateField( event ) {
			var ctx   = getContext();
			var input = event.target;
			if ( ! input || ! input.name ) { return; }
			var name  = input.name;
			var val   = ( input.value || '' ).trim();

			// Clone errors to avoid mutating a frozen proxy.
			var errors = Object.assign( {}, ctx.errors || {} );

			if ( name === 'display_name' ) {
				if ( val === '' ) {
					errors.display_name = t( 'displayNameRequired', 'Display name is required.' );
				} else {
					delete errors.display_name;
				}
			} else if ( name === 'website' || name.indexOf( 'social_' ) === 0 ) {
				if ( val !== '' && ! isValidUrlClient( val ) ) {
					errors[ name ] = t( 'invalidUrl', 'Enter a valid URL (https://example.com).' );
				} else {
					delete errors[ name ];
				}
			}

			ctx.errors = errors;
		},

		/* Master save action. Triggered by the form submit / Save button. */
		saveProfile( event ) {
			if ( event && typeof event.preventDefault === 'function' ) {
				event.preventDefault();
			}
			var ctx = getContext();

			// Run a client-side pass so we don't bother the server with obviously bad payloads.
			var errors = {};
			var firstInvalid = null;

			// Required-field check across EVERY rendered required control (display
			// name + any admin-marked custom field). The control's `name` is the
			// field_key the server validates against, and the edit template renders
			// a matching context.errors[ field_key ] inline-error slot, so this
			// paints a per-field error next to the offending control instead of
			// only a generic toast. Repeater sub-fields (name="group[i][key]") are
			// skipped here; the server validates those per entry.
			var formEl = document.querySelector( '.bn-ep-form-shell' );
			var scope  = formEl || document;
			scope.querySelectorAll( '[required]' ).forEach( function ( el ) {
				// Strip a trailing [] before keying the error. A multi-value control
				// posts as `foo[]` while the payload key -- and the inline error slot
				// the template renders -- is `foo`, so without this the message is
				// filed under a key nothing displays: the save is blocked and the
				// member sees no reason why. Pro's multi_select_advanced is a real
				// <select multiple required> and is the first control to hit this.
				var key = ( el.getAttribute( 'name' ) || '' ).replace( /\[\]$/, '' );
				if ( ! key || /\[\d+\]\[/.test( key ) ) { return; }
				var empty = ( el.type === 'checkbox' )
					? ! el.checked
					: ( el.value || '' ).trim() === '';
				if ( empty ) {
					errors[ key ] = fmt( t( 'fieldRequired', '%s is required.' ), requiredLabelFor( el ) );
					if ( ! firstInvalid ) { firstInvalid = el; }
				}
			} );

			// Required GROUPS — radio and checkbox sets.
			//
			// HTML cannot say "at least one of these": `required` on a checkbox
			// demands that specific box, so a group cannot express the rule the
			// server enforces. FieldType therefore marks the fieldset with
			// data-bn-required, and the check lives here.
			//
			// Without this the group was invisible to validation: six field types
			// rendered no requiredness at all, the form submitted happily, and the
			// server answered 422 for a field the member was never told about
			// (Basecamp 10184320781). A refusal the form could have predicted
			// belongs at render time, not after a round trip.
			scope.querySelectorAll( '[data-bn-required]' ).forEach( function ( group ) {
				var inputs = group.querySelectorAll( 'input[type="checkbox"], input[type="radio"]' );
				if ( ! inputs.length ) { return; }

				// The name carries [] for checkbox groups; the payload key does not.
				var key = ( inputs[ 0 ].getAttribute( 'name' ) || '' ).replace( /\[\]$/, '' );
				if ( ! key || /\[\d+\]\[/.test( key ) ) { return; }

				var chosen = false;
				inputs.forEach( function ( input ) { if ( input.checked ) { chosen = true; } } );
				if ( chosen ) { return; }

				errors[ key ] = fmt( t( 'fieldRequired', '%s is required.' ), requiredLabelFor( group ) );
				if ( ! firstInvalid ) { firstInvalid = inputs[ 0 ]; }
			} );

			[ 'website', 'social_twitter', 'social_linkedin', 'social_github', 'social_instagram', 'social_youtube' ].forEach( function ( fname ) {
				var el = document.querySelector( '[name="' + fname + '"]' );
				if ( ! el ) { return; }
				var v = ( el.value || '' ).trim();
				if ( v !== '' && ! isValidUrlClient( v ) ) {
					errors[ fname ] = t( 'invalidUrl', 'Enter a valid URL (https://example.com).' );
					if ( ! firstInvalid ) { firstInvalid = el; }
				}
			} );

			if ( Object.keys( errors ).length > 0 ) {
				ctx.errors = errors;
				if ( firstInvalid && typeof firstInvalid.focus === 'function' ) {
					firstInvalid.focus();
				}
				bnToast( t( 'fieldsNeedAttention', 'Some fields need attention' ), { tone: 'danger' } );
				return;
			}

			doSave( ctx ).then( function () {
				syncDirtyAttr( ctx.isDirty );

				/*
				 * Stay on the edit screen.
				 *
				 * This used to navigate to profileUrl 700ms after a successful save,
				 * which dropped the member on their profile ROOT -- the Posts tab --
				 * having just edited their About fields. They lost their place, lost
				 * their scroll position, and could not carry on editing the next
				 * field without going back (Basecamp 10180604112).
				 *
				 * Nothing depended on that redirect. Changing the profile handle is a
				 * separate action (`/me/profile-slug`) which updates ctx.profileUrl in
				 * place and never navigated, and the edit screen already offers "My
				 * Profile" and "Cancel" for anyone who does want to leave. The save
				 * bar reports the result on its own, which is how the sibling settings
				 * screen has always behaved -- templates/settings/privacy.php passes an
				 * empty profileUrl for exactly this reason.
				 */
			} );
		},

		/* Cancel guard - the beforeunload listener handles the dirty-state prompt
		 * for any navigation away from the edit page (link clicks, back button,
		 * tab close). This action is a no-op kept for compatibility with any
		 * older template that still references it. */
		confirmCancel() {
			/* no-op: handled by beforeunload guard */
		},

		/* Per-field autosave kept for backwards compatibility (used by sliders / toggles). */
		autosave() { doAutoSave( getContext() ); },

		/* Slug availability check - debounced 400 ms. */
		checkSlug() {
			var ctx   = getContext();
			var input = document.getElementById( 'bn-ep-slug' );
			if ( ! input ) { return; }

			var raw  = input.value.trim().toLowerCase();
			var slug = raw.replace( /[^a-z0-9-]/g, '-' )
			              .replace( /-{2,}/g, '-' )
			              .replace( /^-|-$/g, '' );
			input.value = slug;

			if ( slug === '' ) {
				ctx.slugAvailable = null;
				ctx.slugChecking  = false;
				return;
			}

			ctx.slugChecking  = true;
			ctx.slugAvailable = null;
			clearTimeout( slugTimer );
			if ( slugAbort ) { slugAbort.abort(); }

			slugTimer = setTimeout( function () {
				slugAbort = new AbortController();
				var thisAbort = slugAbort;
				restFetch(
					'/profile-slug/check?slug=' + encodeURIComponent( slug ),
					{ nonce: ctx.restNonce, signal: thisAbort.signal, toastOnError: false }
				).then( function ( res ) {
					// A superseding keystroke aborts this request — leave the
					// checking state alone so the newer request owns the UI.
					if ( thisAbort.signal.aborted ) { return; }
					if ( res.ok && res.data ) {
						ctx.slugAvailable = res.data.available;
					}
					ctx.slugChecking = false;
				} );
			}, 400 );
		},

		saveSlug() {
			var ctx   = getContext();
			var input = document.getElementById( 'bn-ep-slug' );
			if ( ! input || ! ctx.slugAvailable ) { return; }
			var slug = input.value.trim();
			if ( ! slug ) { return; }

			ctx.slugSaving = true;
			ctx.slugSaved  = false;

			restFetch( '/me/profile-slug', {
				method:       'PUT',
				nonce:        ctx.restNonce,
				body:         { slug: slug },
				toastOnError: false,
			} ).then( function ( res ) {
				if ( res.ok && res.data ) {
					ctx.profileUrl  = res.data.url;
					ctx.profileSlug = res.data.slug;
					ctx.slugSaved   = true;
					setTimeout( function () { ctx.slugSaved = false; }, 3000 );
				}
			} )
			   .finally( function () { ctx.slugSaving = false; } );
		},

		addEntry( event ) {
			var btn   = event.target.closest( '[data-group]' );
			var group = btn ? btn.dataset.group : null;
			if ( ! group ) { return; }

			var containerId = repeaterContainerId( group );
			var container = document.getElementById( containerId );
			if ( ! container ) { return; }

			var index = container.querySelectorAll( '.bn-ep-repeater-entry' ).length;
			var node  = buildEntryNodeFromClone( group, index );
			if ( node ) {
				container.appendChild( node );
				// See the same dispatch in assets/js/admin/members.js: a cloned row
				// can hold any registered field type, and the richer controls are
				// dead markup until their owner wires them.
				document.dispatchEvent( new CustomEvent( 'buddynext:fields-added', {
					detail: { container: node }
				} ) );
			}
			// Adding a row counts as a dirty edit.
			getContext().isDirty = true;
			syncDirtyAttr( true );
		},

		removeEntry( event ) {
			var btn = event.target.closest( '[data-group]' );
			if ( ! btn ) { return; }

			var group = btn.dataset.group;
			var containerId = group ? repeaterContainerId( group ) : '';

			var entryEl = btn.closest( '.bn-ep-repeater-entry' );
			if ( entryEl ) { entryEl.remove(); }
			if ( containerId ) { renumberEntries( containerId ); }
			getContext().isDirty = true;
			syncDirtyAttr( true );
		},

		focusTagInput() {
			var el = document.querySelector( '.bn-ep-tag-input' );
			if ( el ) { el.focus(); }
		},

		triggerCoverUpload() {
			var el = document.getElementById( 'bn-ep-cover-file' );
			if ( el ) { el.click(); }
		},
		triggerAvatarUpload() {
			var el = document.getElementById( 'bn-ep-avatar-file' );
			if ( el ) { el.click(); }
		},

		async handleAvatarFileChange( event ) {
			var file = event.target.files[ 0 ];
			if ( ! file ) { return; }

			// Capture the Interactivity context BEFORE any await — getContext()
			// only resolves synchronously within the action's initial scope, so
			// reading it after `await openAvatarCropModal()` throws.
			var ctx = getContext();

			// Open the in-browser crop modal. The cropped blob is STAGED here
			// (not uploaded) and shown as a local preview; it is persisted only
			// when the member clicks "Save changes" (doSave → flushStagedMedia).
			// This makes a Cancel/Leave revert cleanly, since nothing was sent.
			try {
				var cropped = await openAvatarCropModal( file );
				if ( ! cropped ) {
					event.target.value = '';
					return;
				}

				_pendingAvatar = { blob: cropped };
				// Local object-URL preview — no network. The hero <img> is not
				// reactively bound, so refresh it directly and reveal Remove.
				setAvatarPreview( URL.createObjectURL( cropped ) );
				toggleAvatarRemove( true );
				// Mark dirty so Save enables and the beforeunload guard arms.
				ctx.isDirty = true;
				syncDirtyAttr( true );
				bnToast( t( 'avatarReady', 'Avatar ready — click Save changes to keep it' ), { tone: 'info' } );
			} catch ( err ) {
				bnToast( t( 'couldNotPrepareImage', 'Could not prepare image. Try again.' ), { tone: 'danger' } );
			} finally {
				event.target.value = '';
			}
		},

		async removeAvatar() {
			var ctx = getContext();

			var ok = await bnConfirm( {
				title: t( 'removePhotoTitle', 'Remove profile photo?' ),
				body: t( 'removePhotoBody', 'Your photo will be replaced with your initials. You can upload a new one any time.' ),
				confirmLabel: t( 'remove', 'Remove' ),
				tone: 'danger',
			} );
			if ( ! ok ) { return; }

			// Discard any staged (not-yet-saved) avatar — Remove means "no photo".
			_pendingAvatar = null;

			try {
				var res = await restFetch( profileResourcePath( 'avatar' ), {
					method:       'DELETE',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					ctx.avatarUrl = '';
					setAvatarPreview( '' ); // revert to initials
					toggleAvatarRemove( false );
					bnToast( t( 'photoRemoved', 'Profile photo removed' ), { tone: 'success' } );
				} else {
					bnToast( t( 'photoRemoveFailed', 'Could not remove your photo. Try again.' ), { tone: 'danger' } );
				}
			} catch ( err ) {
				bnToast( t( 'photoRemoveFailed', 'Could not remove your photo. Try again.' ), { tone: 'danger' } );
			}
		},

		async removeCover() {
			var ctx = getContext();

			var ok = await bnConfirm( {
				title: t( 'removeCoverTitle', 'Remove cover photo?' ),
				body: t( 'removeCoverBody', 'Your cover photo will be cleared. You can upload a new one any time.' ),
				confirmLabel: t( 'remove', 'Remove' ),
				tone: 'danger',
			} );
			if ( ! ok ) { return; }

			// Discard any staged (not-yet-saved) cover — Remove means "no cover".
			_pendingCover = null;

			try {
				var res = await restFetch( profileResourcePath( 'cover' ), {
					method:       'DELETE',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					ctx.coverUrl = '';
					setCoverPreview( '' ); // revert to the empty cover state
					toggleCoverRemove( false );
					bnToast( t( 'coverRemoved', 'Cover photo removed' ), { tone: 'success' } );
				} else {
					bnToast( t( 'coverRemoveFailed', 'Could not remove your cover. Try again.' ), { tone: 'danger' } );
				}
			} catch ( err ) {
				bnToast( t( 'coverRemoveFailed', 'Could not remove your cover. Try again.' ), { tone: 'danger' } );
			}
		},

		async handleCoverFileChange( event ) {
			var file = event.target.files[ 0 ];
			if ( ! file ) { return; }

			// Capture context before the await (see handleAvatarFileChange).
			var ctx = getContext();

			// Open the reposition modal: the user pans + zooms the cover
			// (LinkedIn-style). The chosen file + position {x, y} + zoom are
			// STAGED here and previewed locally; they upload only on "Save
			// changes" (doSave → flushStagedMedia), so Cancel/Leave reverts.
			try {
				var repos = await openCoverReposModal( file, t );
				if ( ! repos ) {
					event.target.value = '';
					return;
				}

				_pendingCover = { file: file, x: repos.x, y: repos.y, zoom: repos.zoom };
				ctx.coverFocalX = repos.x;
				ctx.coverFocalY = repos.y;
				ctx.coverZoom   = repos.zoom;

				// Local object-URL preview — no network. The cover <img> is not
				// reactively bound, so refresh it directly.
				var coverImg = document.querySelector( '[data-bn-cover-preview]' );
				if ( coverImg ) {
					coverImg.src = URL.createObjectURL( file );
					coverImg.style.display = '';
					coverImg.style.objectPosition = repos.x + '% ' + repos.y + '%';
					coverImg.style.transform = 'scale(' + repos.zoom + ')';
					var wrap = coverImg.closest( '.bn-pf-cover' );
					if ( wrap ) { wrap.classList.add( 'bn-pf-cover--has-image' ); }
				}
				toggleCoverRemove( true );
				// Mark dirty so Save enables and the beforeunload guard arms.
				ctx.isDirty = true;
				syncDirtyAttr( true );
				bnToast( t( 'coverReady', 'Cover ready — click Save changes to keep it' ), { tone: 'info' } );
			} catch ( err ) {
				bnToast( t( 'couldNotPrepareImage', 'Could not prepare image. Try again.' ), { tone: 'danger' } );
			} finally {
				event.target.value = '';
			}
		},

		/* -- Social actions (profile view page) ------------------------- */

		async follow() {
			var ctx = getContext();
			if ( ctx.isFollowing || ctx.followPending ) { return; }
			// Optimistic.
			ctx.isFollowing   = true;
			ctx.followerCount = ( ctx.followerCount || 0 ) + 1;
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/follow', {
					method:       'POST',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'follow_failed' ); }

				// A PRIVATE account stores the row as pending until the owner
				// approves it, and the endpoint says so ({ following, pending }).
				// Only res.ok was checked before, so the optimistic "Following"
				// stuck: the viewer was told they follow someone who has not
				// approved them, and the owner's follower count was inflated until
				// the next page load. Neither was true.
				// restFetch resolves { ok, status, data } — NOT a fetch Response, so
				// the payload is res.data and there is no .json() to call.
				var body = res.data || {};
				if ( body.pending ) {
					ctx.isFollowing   = false;
					ctx.followPending = true;
					// A pending request is not a follower yet — undo the optimistic
					// increment rather than leaving an inflated count on screen.
					ctx.followerCount = Math.max( 0, ( ctx.followerCount || 1 ) - 1 );
					bnToast( t( 'followRequested', 'Follow request sent' ), { tone: 'info' } );
					return;
				}

				ctx.followPending = false;
				bnToast( t( 'followed', 'Followed' ), { tone: 'success' } );
			} catch ( _e ) {
				ctx.isFollowing   = false;
				ctx.followPending = false;
				ctx.followerCount = Math.max( 0, ( ctx.followerCount || 1 ) - 1 );
				bnToast( t( 'couldNotFollow', 'Could not follow. Try again.' ), { tone: 'danger' } );
			}
		},

		async unfollow() {
			var ctx = getContext();
			// Also drives "Requested" -> withdraw. DELETE removes a pending row the
			// same way it removes an approved one, so one action serves both; the
			// count is only touched when an actual follow is being undone.
			if ( ! ctx.isFollowing && ! ctx.followPending ) { return; }
			var wasPending    = !! ctx.followPending;
			ctx.isFollowing   = false;
			ctx.followPending = false;
			if ( ! wasPending ) {
				ctx.followerCount = Math.max( 0, ( ctx.followerCount || 1 ) - 1 );
			}
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/follow', {
					method:       'DELETE',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'unfollow_failed' ); }
				bnToast(
					wasPending
						? t( 'followRequestWithdrawn', 'Follow request withdrawn' )
						: t( 'unfollowed', 'Unfollowed' ),
					{ tone: 'info' }
				);
			} catch ( _e ) {
				// Restore the state we actually came from, not "following" — a failed
				// withdraw must go back to Requested, not silently promote the viewer
				// to a follower they never were.
				if ( wasPending ) {
					ctx.followPending = true;
				} else {
					ctx.isFollowing   = true;
					ctx.followerCount = ( ctx.followerCount || 0 ) + 1;
				}
				bnToast( t( 'couldNotUnfollow', 'Could not unfollow. Try again.' ), { tone: 'danger' } );
			}
		},

		async connect() {
			var ctx = getContext();
			if ( ! ctx.showConnect ) { return; }
			// LinkedIn-style optional note. Cancelling leaves the CTA untouched.
			var note = await bnResolveConnectNote( {
				body: t( 'connectNoteBody', 'Add a personal message to your connection request, or send it without one.' ),
			} );
			if ( note === null ) { return; }
			ctx.connectionPending = true;
			ctx.showConnect       = false;
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/connect', {
					method:       'POST',
					nonce:        ctx.restNonce,
					body:         { note: note },
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'connect_failed' ); }
				bnToast( t( 'connectionSent', 'Connection request sent' ), { tone: 'success' } );
			} catch ( _e ) {
				ctx.connectionPending = false;
				ctx.showConnect       = true;
				bnToast( t( 'couldNotSendRequest', 'Could not send request' ), { tone: 'danger' } );
			}
		},

		async withdrawRequest() {
			var ctx = getContext();
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/connect', {
					method:       'DELETE',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					ctx.connectionPending = false;
					ctx.showConnect       = true;
					bnToast( t( 'requestWithdrawn', 'Request withdrawn' ), { tone: 'info' } );
				}
			} catch ( _e ) {}
		},

		async acceptRequest() {
			var ctx = getContext();
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/connect/accept', {
					method:       'POST',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					ctx.connectionReceived = false;
					ctx.isConnected        = true;
					ctx.showConnect        = false;
					bnToast( t( 'connected', 'Connected' ), { tone: 'success' } );
				}
			} catch ( _e ) {}
		},

		async declineRequest() {
			var ctx = getContext();
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/connect/decline', {
					method:       'POST',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					ctx.connectionReceived = false;
					ctx.showConnect        = true;
					bnToast( t( 'requestDeclined', 'Request declined' ), { tone: 'info' } );
				}
			} catch ( _e ) {}
		},

		async disconnectUser() {
			var ctx = getContext();
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/connect', {
					method:       'DELETE',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( res.ok ) {
					ctx.isConnected       = false;
					ctx.showConnect       = true;
					ctx.connectionPending = false;
					bnToast( t( 'disconnected', 'Disconnected' ), { tone: 'info' } );
				}
			} catch ( _e ) {}
		},


		/* -- More-options menu -------------------------------------- */

		toggleMoreMenu() {
			var ctx = getContext();
			ctx.moreMenuOpen = ! ctx.moreMenuOpen;
			if ( ctx.moreMenuOpen ) {
				flipIfItWouldLandUnderTheNav( '.bn-more-menu-wrap', '.bn-more-menu', 'moreMenuFlip' );
			} else {
				ctx.moreMenuFlip = false;
			}
		},

		/**
		 * Close the More-options menu and Share popover when a click lands outside
		 * their wrappers. Bound via data-wp-on-document--click on the .bn-pf-stack
		 * interactive root, so it closes through the same reactive state as the
		 * toggle actions. Without this the popovers stayed open until re-clicked —
		 * a click anywhere else (the expected dismiss gesture) did nothing. Mirrors
		 * the members-directory closeCardMenuOnOutside pattern.
		 *
		 * @param {MouseEvent} event The document click event.
		 */
		closeMenusOnOutside( event ) {
			var ctx = getContext();
			if ( ! ctx || ! ctx.moreMenuOpen ) { return; }
			var ref = getElement() && getElement().ref;
			if ( ! ref ) { return; }
			var moreWrap = ref.querySelector( '.bn-more-menu-wrap' );
			if ( ! moreWrap || ! moreWrap.contains( event.target ) ) {
				ctx.moreMenuOpen = false;
			}
		},

		async toggleMute() {
			var ctx    = getContext();
			var wasMuted = !! ctx.isMuted;
			// Optimistic.
			ctx.isMuted      = ! wasMuted;
			ctx.moreMenuOpen = false;
			var method = wasMuted ? 'DELETE' : 'POST';
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/mute', {
					method:       method,
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'mute_failed' ); }
				bnToast( wasMuted ? t( 'unmuted', 'Unmuted' ) : t( 'muted', 'Muted' ), { tone: 'success' } );
			} catch ( _e ) {
				ctx.isMuted = wasMuted;
				bnToast( t( 'muteFailed', 'Could not update mute state' ), { tone: 'danger' } );
			}
		},

		async toggleRestrict() {
			var ctx           = getContext();
			var wasRestricted = !! ctx.isRestricted;
			ctx.isRestricted = ! wasRestricted;
			ctx.moreMenuOpen = false;
			var method = wasRestricted ? 'DELETE' : 'POST';
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/restrict', {
					method:       method,
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'restrict_failed' ); }
				bnToast(
					wasRestricted
						? t( 'noLongerRestricted', 'No longer restricted' )
						: t( 'restricted', 'Restricted. They can still see your profile, but their comments are hidden from others.' ),
					{ tone: wasRestricted ? 'info' : 'success' }
				);
			} catch ( _e ) {
				ctx.isRestricted = wasRestricted;
				bnToast( t( 'restrictFailed', 'Could not update restrict state' ), { tone: 'danger' } );
			}
		},

		/* Block requires an explicit confirmation modal - destructive action. */
		toggleBlock() {
			var ctx = getContext();
			if ( ctx.isBlocked ) {
				// Unblock is reversible - no confirm needed.
				doUnblock( ctx );
				return;
			}
			ctx.blockConfirmOpen = true;
			ctx.moreMenuOpen     = false;
		},

		closeBlockConfirm() {
			getContext().blockConfirmOpen = false;
		},

		/**
		 * Dismiss the block-confirm modal when the dimmed backdrop itself is clicked
		 * (the standard modal gesture). Bound via data-wp-on--click on the backdrop;
		 * clicks that bubble up from the panel/controls have a descendant target, so
		 * only a direct backdrop click closes it. Previously only the X / Cancel
		 * buttons closed it.
		 *
		 * @param {MouseEvent} event The click event.
		 */
		backdropCloseBlock( event ) {
			if ( getElement() && event.target === getElement().ref ) {
				getContext().blockConfirmOpen = false;
			}
		},

		async confirmBlock() {
			var ctx = getContext();
			if ( ctx.blockSubmitting ) { return; }
			ctx.blockSubmitting = true;
			try {
				var res = await restFetch( '/users/' + ctx.profileUserId + '/block', {
					method:       'POST',
					nonce:        ctx.restNonce,
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'block_failed' ); }
				ctx.isBlocked        = true;
				ctx.blockConfirmOpen = false;
				bnToast( ( ctx.displayName ? fmt( t( 'memberBlockedNamed', '%s blocked' ), ctx.displayName ) : t( 'memberBlocked', 'Member blocked' ) ), { tone: 'success' } );
				// After block we redirect to the members directory since the profile is no longer accessible.
				setTimeout( function () {
					window.location.href = ( ctx.peopleUrl || '/members/' );
				}, 800 );
			} catch ( _e ) {
				bnToast( t( 'blockFailed', 'Could not block. Try again.' ), { tone: 'danger' } );
			} finally {
				ctx.blockSubmitting = false;
			}
		},

		/* -- Report modal ----------------------------------------------- */

		openReport() {
			var ctx = getContext();
			ctx.reportOpen      = true;
			ctx.reportReason    = 'spam';
			ctx.reportNotes     = '';
			ctx.moreMenuOpen    = false;
		},

		closeReport() {
			getContext().reportOpen = false;
		},

		/**
		 * Dismiss the report modal on a direct backdrop click — see
		 * backdropCloseBlock for the rationale.
		 *
		 * @param {MouseEvent} event The click event.
		 */
		backdropCloseReport( event ) {
			if ( getElement() && event.target === getElement().ref ) {
				getContext().reportOpen = false;
			}
		},

		setReportReason( event ) {
			getContext().reportReason = event.target.value;
		},

		setReportNotes( event ) {
			getContext().reportNotes = event.target.value;
		},

		async submitReport() {
			var ctx = getContext();
			if ( ctx.reportSubmitting ) { return; }
			ctx.reportSubmitting = true;
			try {
				var res = await restFetch( '/reports', {
					method:       'POST',
					nonce:        ctx.restNonce,
					toastOnError: false,
					body:         {
						object_type: 'user',
						object_id:   ctx.profileUserId,
						reason:      ctx.reportReason || 'other',
						notes:       ctx.reportNotes  || '',
					},
				} );
				if ( ! res.ok && res.status !== 201 ) {
					// Surface the server's reason — e.g. the 409 "You have already
					// reported this member." — rather than a generic retry message.
					var data = res.data || {};
					bnToast( data.message || t( 'reportFailed', 'Could not submit report. Try again.' ), { tone: 'danger' } );
					return;
				}
				ctx.reportOpen = false;
				bnToast( t( 'reportSubmitted', 'Report submitted. Thanks for keeping the community safe.' ), { tone: 'success' } );
			} catch ( _e ) {
				bnToast( t( 'reportFailed', 'Could not submit report. Try again.' ), { tone: 'danger' } );
			} finally {
				ctx.reportSubmitting = false;
			}
		},

		/* -- Email change ----------------------------------------------- */
		openEmailChange() {
			var ctx = getContext();
			ctx.emailChangeOpen = true;
			var errs = Object.assign( {}, ctx.errors || {} );
			delete errs.email;
			ctx.errors = errs;
		},
		closeEmailChange() {
			var ctx = getContext();
			ctx.emailChangeOpen = false;
		},
		async requestEmailChange() {
			var ctx = getContext();
			if ( ctx.emailChangeSubmitting ) { return; }
			var input = document.getElementById( 'bn-ep-new-email' );
			var email = input ? ( input.value || '' ).trim() : '';
			ctx.emailChangeSubmitting = true;
			try {
				var res = await restFetch( '/auth/change-email', {
					method:       'POST',
					nonce:        nonce(),
					body:         { email: email },
					toastOnError: false,
				} );
				var json = res.data || {};
				if ( res.ok && json && json.saved ) {
					ctx.emailChangeOpen = false;
					if ( input ) { input.value = ''; }
					bnToast( json.message || t( 'checkInboxConfirm', 'Check your inbox to confirm.' ), { tone: 'success' } );
				} else if ( res.status === 422 && json && json.errors ) {
					ctx.errors = Object.assign( {}, ctx.errors || {}, json.errors );
				} else {
					bnToast( t( 'verifyEmailFailed', 'Could not send verification email. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not send verification email. Try again.', { tone: 'danger' } );
			} finally {
				ctx.emailChangeSubmitting = false;
			}
		},

		/* -- Email verification ----------------------------------------- *
		 *
		 * For members the site never asked to verify: everyone who registered before
		 * verification was switched on is grandfathered past the ACCESS gate, so they
		 * proved nothing, carry no verified badge, and had no route to one.
		 *
		 * Two ways in. An administrator gets `selfVerifyEmail`, a one-click confirm,
		 * because the email round-trip is not a security boundary for someone who can
		 * set the usermeta directly. Everyone (admins included) can also take the
		 * email route, which is the only one that proves the mailbox receives mail.
		 */
		openEmailVerify() {
			var ctx = getContext();
			ctx.emailVerifyOpen = true;
		},
		closeEmailVerify() {
			var ctx = getContext();
			ctx.emailVerifyOpen = false;
		},
		async requestEmailVerification() {
			var ctx = getContext();
			if ( ctx.verifySubmitting ) { return; }
			ctx.verifySubmitting = true;
			try {
				var res = await restFetch( '/auth/verify/resend', {
					method:       'POST',
					nonce:        nonce(),
					toastOnError: false,
				} );
				var json = res.data || {};
				if ( res.ok ) {
					ctx.emailVerifyOpen = false;
					bnToast( json.message || t( 'checkInboxConfirm', 'Check your inbox to confirm.' ), { tone: 'success' } );
				} else {
					bnToast( ( json && json.message ) || t( 'verifyEmailFailed', 'Could not send verification email. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'verifyEmailFailed', 'Could not send verification email. Try again.' ), { tone: 'danger' } );
			} finally {
				ctx.verifySubmitting = false;
			}
		},
		async selfVerifyEmail() {
			var ctx = getContext();
			if ( ctx.verifySubmitting ) { return; }
			ctx.verifySubmitting = true;
			try {
				var res = await restFetch( '/auth/verify/self', {
					method:       'POST',
					nonce:        nonce(),
					toastOnError: false,
				} );
				var json = res.data || {};
				if ( res.ok ) {
					ctx.emailVerifyOpen = false;
					bnToast( json.message || t( 'emailVerified', 'Your email address is now marked as verified.' ), { tone: 'success' } );
					// The row and the profile badge are both server-rendered from the
					// meta this just wrote, so a reload is what makes the change
					// visible rather than a partial in-place patch that could disagree
					// with what the next page load shows.
					window.setTimeout( function () { window.location.reload(); }, 700 );
				} else {
					bnToast( ( json && json.message ) || t( 'verifyEmailFailed', 'Could not verify. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'verifyEmailFailed', 'Could not verify. Try again.' ), { tone: 'danger' } );
			} finally {
				ctx.verifySubmitting = false;
			}
		},

		/* -- Password change -------------------------------------------- */
		openPasswordChange() {
			var ctx = getContext();
			ctx.passwordChangeOpen = true;
			var errs = Object.assign( {}, ctx.errors || {} );
			delete errs.current_password;
			delete errs.new_password;
			delete errs.confirm_password;
			ctx.errors = errs;
		},
		closePasswordChange() {
			var ctx = getContext();
			ctx.passwordChangeOpen = false;
			ctx.passwordStrength = 0;
			ctx.passwordStrengthLabel = '';
		},
		measurePasswordStrength( event ) {
			var ctx = getContext();
			var val = event && event.target ? ( event.target.value || '' ) : '';
			var score = 0;
			if ( val.length >= 8 )  { score += 1; }
			if ( val.length >= 12 ) { score += 1; }
			if ( /[A-Z]/.test( val ) && /[a-z]/.test( val ) ) { score += 1; }
			if ( /\d/.test( val ) )            { score += 1; }
			if ( /[^A-Za-z0-9]/.test( val ) )  { score += 1; }
			ctx.passwordStrength = score;
			ctx.passwordStrengthLabel = [
				t( 'pwTooShort', 'Too short' ),
				t( 'pwWeak', 'Weak' ),
				t( 'pwFair', 'Fair' ),
				t( 'pwGood', 'Good' ),
				t( 'pwStrong', 'Strong' ),
				t( 'pwExcellent', 'Excellent' ),
			][ Math.min( score, 5 ) ] || '';
		},
		async changePassword() {
			var ctx = getContext();
			if ( ctx.passwordChangeSubmitting ) { return; }
			var curInput = document.getElementById( 'bn-ep-current-password' );
			var newInput = document.getElementById( 'bn-ep-new-password' );
			var conInput = document.getElementById( 'bn-ep-confirm-password' );
			var curr = curInput ? curInput.value : '';
			var next = newInput ? newInput.value : '';
			var conf = conInput ? conInput.value : '';

			var localErrors = {};
			if ( ! curr ) { localErrors.current_password = t( 'enterCurrentPassword', 'Enter your current password.' ); }
			if ( ! next ) {
				localErrors.new_password = t( 'enterNewPassword', 'Enter a new password.' );
			} else if ( next.length < 8 ) {
				localErrors.new_password = t( 'passwordMinChars', 'Use at least 8 characters.' );
			}
			if ( next && next !== conf ) {
				localErrors.confirm_password = t( 'passwordsNoMatch', 'Passwords do not match.' );
			}
			if ( Object.keys( localErrors ).length ) {
				ctx.errors = Object.assign( {}, ctx.errors || {}, localErrors );
				return;
			}

			ctx.passwordChangeSubmitting = true;
			try {
				var res = await restFetch( '/auth/change-password', {
					method:       'POST',
					nonce:        nonce(),
					body:         { current_password: curr, new_password: next },
					toastOnError: false,
				} );
				var json = res.data || {};
				if ( res.ok && json && json.saved ) {
					ctx.passwordChangeOpen = false;
					if ( curInput ) { curInput.value = ''; }
					if ( newInput ) { newInput.value = ''; }
					if ( conInput ) { conInput.value = ''; }
					ctx.passwordStrength = 0;
					ctx.passwordStrengthLabel = '';
					bnToast( t( 'passwordUpdated', 'Password updated.' ), { tone: 'success' } );
				} else if ( res.status === 422 && json && json.errors ) {
					ctx.errors = Object.assign( {}, ctx.errors || {}, json.errors );
				} else {
					bnToast( t( 'passwordChangeFailed', 'Could not change password. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not change password. Try again.', { tone: 'danger' } );
			} finally {
				ctx.passwordChangeSubmitting = false;
			}
		},

		/* -- Sign out everywhere ---------------------------------------- */
		async signOutEverywhere() {
			var ctx = getContext();
			if ( ctx.signOutSubmitting ) { return; }
			ctx.signOutSubmitting = true;
			try {
				var res = await restFetch( '/auth/sign-out-everywhere', {
					method:       'POST',
					nonce:        nonce(),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'http_' + res.status ); }
				bnToast( t( 'signedOutEverywhere', 'Signed out of every other session.' ), { tone: 'success' } );
			} catch ( _e ) {
				bnToast( t( 'signOutFailed', 'Could not sign out everywhere. Try again.' ), { tone: 'danger' } );
			} finally {
				ctx.signOutSubmitting = false;
			}
		},

		/* -- Two-factor authentication ---------------------------------- */
		toggleTwofaPanel() {
			const ctx = getContext();
			ctx.twofaPanelOpen = ! ctx.twofaPanelOpen;
		},
		setTwofaCode( event ) {
			getContext().twofaCode = event && event.target ? String( event.target.value || '' ) : '';
			getContext().twofaError = '';
		},
		setTwofaPassword( event ) {
			getContext().twofaPassword = event && event.target ? String( event.target.value || '' ) : '';
			getContext().twofaError = '';
		},
		async startTwofaSetup() {
			const ctx = getContext();
			if ( ctx.twofaBusy ) { return; }
			ctx.twofaBusy = true;
			ctx.twofaError = '';
			try {
				const res = await restFetch( '/account/2fa/setup', {
					method:       'POST',
					nonce:        nonce(),
					toastOnError: false,
				} );
				const json = res.data || {};
				if ( res.ok && json && json.success ) {
					ctx.twofaSecret = json.secret || '';
					ctx.twofaUri = json.otpauth_uri || '';
					ctx.twofaCode = '';
					ctx.twofaStage = 'setup';
					renderTwofaQr( ctx.twofaUri );
				} else {
					bnToast( ( json && json.message ) || t( 'twofaSetupFailed', 'Could not start setup. Try again.' ), { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( t( 'twofaSetupFailed', 'Could not start setup. Try again.' ), { tone: 'danger' } );
			} finally {
				ctx.twofaBusy = false;
			}
		},
		async confirmTwofa() {
			const ctx = getContext();
			if ( ctx.twofaBusy ) { return; }
			ctx.twofaBusy = true;
			ctx.twofaError = '';
			try {
				const res = await restFetch( '/account/2fa/confirm', {
					method:       'POST',
					nonce:        nonce(),
					body:         { code: ctx.twofaCode || '' },
					toastOnError: false,
				} );
				const json = res.data || {};
				if ( res.ok && json && json.success ) {
					ctx.twofaBackupCodes = json.backup_codes || [];
					ctx.twofaBackupRemaining = ctx.twofaBackupCodes.length;
					ctx.twofaEnabled = true;
					ctx.twofaSecret = '';
					ctx.twofaUri = '';
					ctx.twofaCode = '';
					ctx.twofaStage = 'backup';
				} else {
					ctx.twofaError = ( json && json.message ) || t( 'twofaCodeMismatch', 'That code did not match.' );
				}
			} catch ( _e ) {
				ctx.twofaError = t( 'somethingWentWrong', 'Something went wrong. Try again.' );
			} finally {
				ctx.twofaBusy = false;
			}
		},
		finishTwofa() {
			const ctx = getContext();
			ctx.twofaBackupCodes = [];
			ctx.twofaStage = 'idle';
			bnToast( t( 'twofaOn', 'Two-factor authentication is on.' ), { tone: 'success' } );
		},
		cancelTwofa() {
			const ctx = getContext();
			ctx.twofaStage = 'idle';
			ctx.twofaSecret = '';
			ctx.twofaUri = '';
			ctx.twofaCode = '';
			ctx.twofaError = '';
		},
		async regenerateBackup() {
			const ctx = getContext();
			if ( ctx.twofaBusy ) { return; }
			if ( ! ( ctx.twofaPassword || '' ) ) { ctx.twofaError = t( 'enterPassword', 'Enter your password.' ); return; }
			ctx.twofaBusy = true;
			ctx.twofaError = '';
			try {
				const res = await restFetch( '/account/2fa/backup', {
					method:       'POST',
					nonce:        nonce(),
					body:         { password: ctx.twofaPassword || '' },
					toastOnError: false,
				} );
				const json = res.data || {};
				if ( res.ok && json && json.success ) {
					ctx.twofaBackupCodes = json.backup_codes || [];
					ctx.twofaBackupRemaining = ctx.twofaBackupCodes.length;
					ctx.twofaPassword = '';
					ctx.twofaStage = 'backup';
				} else {
					ctx.twofaError = ( json && json.message ) || t( 'twofaRegenFailed', 'Could not regenerate codes.' );
				}
			} catch ( _e ) {
				ctx.twofaError = t( 'somethingWentWrong', 'Something went wrong. Try again.' );
			} finally {
				ctx.twofaBusy = false;
			}
		},
		async disableTwofa() {
			const ctx = getContext();
			if ( ctx.twofaBusy ) { return; }
			if ( ! ( ctx.twofaPassword || '' ) ) { ctx.twofaError = t( 'enterPassword', 'Enter your password.' ); return; }
			ctx.twofaBusy = true;
			ctx.twofaError = '';
			try {
				const res = await restFetch( '/account/2fa/disable', {
					method:       'POST',
					nonce:        nonce(),
					body:         { password: ctx.twofaPassword || '' },
					toastOnError: false,
				} );
				const json = res.data || {};
				if ( res.ok && json && json.success ) {
					ctx.twofaEnabled = false;
					ctx.twofaBackupRemaining = 0;
					ctx.twofaPassword = '';
					ctx.twofaStage = 'idle';
					bnToast( t( 'twofaOff', 'Two-factor authentication is off.' ), { tone: 'success' } );
				} else {
					ctx.twofaError = ( json && json.message ) || t( 'twofaDisableFailed', 'Could not turn off two-factor.' );
				}
			} catch ( _e ) {
				ctx.twofaError = t( 'somethingWentWrong', 'Something went wrong. Try again.' );
			} finally {
				ctx.twofaBusy = false;
			}
		},
	},
} );

// The server merges the injected dictionary into this namespace's state; read
// it once so every lookup above (and the helpers below) shares one table.
I18N = ( profileStore && profileStore.state && profileStore.state.i18n ) || {};

/* -- Helpers that need access to the store -------------------------- */

async function doUnblock( ctx ) {
	var wasBlocked = !! ctx.isBlocked;
	ctx.isBlocked    = false;
	ctx.moreMenuOpen = false;
	try {
		var res = await restFetch( '/users/' + ctx.profileUserId + '/block', {
			method:       'DELETE',
			nonce:        ctx.restNonce,
			toastOnError: false,
		} );
		if ( ! res.ok ) { throw new Error( 'unblock_failed' ); }
		bnToast( t( 'unblocked', 'Unblocked' ), { tone: 'info' } );
	} catch ( _e ) {
		ctx.isBlocked = wasBlocked;
		bnToast( t( 'unblockFailed', 'Could not unblock' ), { tone: 'danger' } );
	}
}

/* Relation removal (unblock / unmute / unrestrict) — side-effect import.
   Handler now lives in social/relation-remove.js so the notifications
   sidebar muted widget gets the same behaviour. */
import '../social/relation-remove.js';
