/* BuddyNext - Profile Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';
import { bnToast } from '../shell/dialog.js';

/* -- Shared helpers ----------------------------------------------------- */

var slugTimer = null;
var slugAbort = null;

function apiUrl( path ) {
	return ( window.wpApiSettings && window.wpApiSettings.root || '/wp-json/' ) + path;
}

function nonce() {
	return getContext().restNonce || '';
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
	overlay.setAttribute( 'aria-label', 'Crop avatar' );

	const panel = document.createElement( 'div' );
	panel.className = 'bn-avatar-crop-panel';

	const title = document.createElement( 'h2' );
	title.className = 'bn-avatar-crop-title';
	title.textContent = 'Position your avatar';
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
	slider.min  = '1';
	slider.max  = '300';
	slider.value = '100';
	slider.className = 'bn-avatar-crop-zoom';
	slider.setAttribute( 'aria-label', 'Zoom' );
	slider.addEventListener( 'input', () => {
		const newScale = minScale * ( parseInt( slider.value, 10 ) / 100 );
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
	cancel.textContent = 'Cancel';
	const apply = document.createElement( 'button' );
	apply.type = 'button';
	apply.className = 'bn-btn';
	apply.dataset.variant = 'primary';
	apply.textContent = 'Apply';
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
		outCtx.drawImage(
			img,
			tx * ratio,
			ty * ratio,
			img.width * scale * ratio,
			img.height * scale * ratio
		);
		out.toBlob( ( blob ) => cleanup( blob ), 'image/jpeg', 0.9 );
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

/*
   Cover reposition modal — LinkedIn-style. Shows the picked cover in a
   frame at the hero's display proportions and lets the user DRAG to
   reposition (pan) and ZOOM with a slider/wheel. The result is non
   destructive: {x, y, zoom} where x/y are object-position percentages
   and zoom is a scale factor. profile-hero.php applies them to an
   <img class="bn-pf-cover__img"> via object-position + transform:scale,
   so the same source stays sharp and responsive at any viewport width
   (a fixed-ratio baked crop would mis-fit the responsive cover height).

   No external library: object-fit:cover + pointer events + a range input.
   ---------------------------------------------------------------- */
async function openCoverReposModal( file ) {
	return new Promise( ( resolve ) => {
		const url = URL.createObjectURL( file );
		const img = new Image();
		img.onload = () => {
			URL.revokeObjectURL( url );
			renderCoverReposModal( img, resolve );
		};
		img.onerror = () => {
			URL.revokeObjectURL( url );
			resolve( null );
		};
		img.src = url;
	} );
}

function renderCoverReposModal( img, resolve ) {
	const W = 480;
	const H = 150; // ~3.2:1 — representative of the desktop hero cover.

	const overlay = document.createElement( 'div' );
	overlay.className = 'bn-avatar-crop-overlay';
	overlay.setAttribute( 'role', 'dialog' );
	overlay.setAttribute( 'aria-modal', 'true' );
	overlay.setAttribute( 'aria-label', 'Reposition cover photo' );

	const panel = document.createElement( 'div' );
	panel.className = 'bn-avatar-crop-panel';

	const title = document.createElement( 'h2' );
	title.className = 'bn-avatar-crop-title';
	title.textContent = 'Drag to reposition · scroll or use the slider to zoom';
	panel.appendChild( title );

	const stage = document.createElement( 'div' );
	stage.className = 'bn-cover-repos-stage';
	stage.style.width  = W + 'px';
	stage.style.height = H + 'px';

	// The preview <img> uses the same display contract as the hero, so the
	// modal is true WYSIWYG: object-fit cover + object-position (pan) + scale.
	const preview = document.createElement( 'img' );
	preview.className = 'bn-cover-repos-img';
	preview.src = img.src;
	preview.alt = '';
	stage.appendChild( preview );
	panel.appendChild( stage );

	const pos = { x: 50, y: 50, zoom: 1 };
	const apply3 = () => {
		preview.style.objectPosition = `${ pos.x }% ${ pos.y }%`;
		preview.style.transform      = `scale(${ pos.zoom })`;
	};
	apply3();

	// Pointer drag → pan. Natural direction: dragging the image right reveals
	// its left side (object-position-x decreases). Sensitivity is scaled down a
	// touch so a full-frame drag doesn't slam to the edge instantly.
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
	slider.setAttribute( 'aria-label', 'Zoom' );
	slider.addEventListener( 'input', () => setZoom( parseInt( slider.value, 10 ) / 100 ) );
	panel.appendChild( slider );

	const actions = document.createElement( 'div' );
	actions.className = 'bn-avatar-crop-actions';
	const cancel = document.createElement( 'button' );
	cancel.type = 'button';
	cancel.className = 'bn-btn';
	cancel.dataset.variant = 'ghost';
	cancel.textContent = 'Cancel';
	const apply = document.createElement( 'button' );
	apply.type = 'button';
	apply.className = 'bn-btn';
	apply.dataset.variant = 'primary';
	apply.textContent = 'Apply';
	actions.appendChild( cancel );
	actions.appendChild( apply );
	panel.appendChild( actions );

	const cleanup = ( value ) => {
		overlay.remove();
		document.removeEventListener( 'keydown', onKey );
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

/* Collect all named flat inputs/textareas (excluding the slug input). */
function collectFlatData( wrap ) {
	var data = {};
	wrap.querySelectorAll( 'input[name], textarea[name], select[name]' ).forEach( function ( el ) {
		// Skip the slug input (handled by its own endpoint) and any repeater fields.
		if ( el.id === 'bn-ep-slug' ) { return; }
		if ( /\[\d+\]\[/.test( el.name ) ) { return; }
		data[ el.name ] = el.value;
	} );
	return data;
}

/* Collect repeater entries from a container by data-entry-index children. */
function collectRepeaterEntries( containerId ) {
	var container = document.getElementById( containerId );
	if ( ! container ) { return []; }
	var entries = [];
	container.querySelectorAll( '.bn-ep-repeater-entry' ).forEach( function ( row ) {
		var entry = {};
		row.querySelectorAll( 'input[name], textarea[name]' ).forEach( function ( el ) {
			var m = el.name.match( /\[\d+\]\[([^\]]+)\]$/ );
			if ( m ) { entry[ m[1] ] = el.value; }
		} );
		entries.push( entry );
	} );
	return entries;
}

/* Build full save payload: flat fields + repeater groups. */
function buildPayload( ctx ) {
	var wrap = document.querySelector( '[data-wp-interactive="buddynext/profile"]' );
	var data = collectFlatData( wrap );
	data.interests       = ctx.interests.join( ',' );
	data.work_experience = collectRepeaterEntries( 'bn-ep-work-entries' );
	data.education       = collectRepeaterEntries( 'bn-ep-edu-entries' );
	return data;
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
}

/* Master save flow - submits all fields, handles 200 / 422 / 5xx. */
async function doSave( ctx ) {
	if ( ctx.saving ) { return; }
	ctx.saving = true;
	ctx.saved  = false;
	clearErrors( ctx );

	try {
		var res = await fetch( apiUrl( 'buddynext/v1/me/profile' ), {
			method:  'PUT',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
			body:    JSON.stringify( buildPayload( ctx ) ),
		} );

		var json = {};
		try { json = await res.json(); } catch ( _e ) {}

		if ( res.ok ) {
			ctx.saved   = true;
			ctx.isDirty = false;
			bnToast( ( window.bnI18n && window.bnI18n.profileSaved ) || 'Profile saved', { tone: 'success' } );
			setTimeout( function () { ctx.saved = false; }, 3000 );
		} else if ( res.status === 422 && json && json.errors ) {
			ctx.errors = json.errors;
			bnToast( ( window.bnI18n && window.bnI18n.fieldsNeedAttention ) || 'Some fields need attention', { tone: 'danger' } );
		} else {
			bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || 'Could not save. Please try again.', { tone: 'danger' } );
		}
	} catch ( _e ) {
		bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || 'Could not save. Please try again.', { tone: 'danger' } );
	} finally {
		ctx.saving = false;
	}
}

/* Silent autosave - used by per-field blur where defined. Failure stays quiet. */
async function doAutoSave( ctx ) {
	if ( ctx.saving ) { return; }
	ctx.saving = true;
	try {
		var res = await fetch( apiUrl( 'buddynext/v1/me/profile' ), {
			method:  'PUT',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
			body:    JSON.stringify( buildPayload( ctx ) ),
		} );
		if ( res.ok ) {
			ctx.saved   = true;
			ctx.isDirty = false;
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

/* Build a blank repeater entry DOM node for a given group. */
function buildEntryNode( group, index ) {
	var groupConfig = {
		work_experience: {
			containerId: 'bn-ep-work-entries',
			removeLabel: 'Remove this position',
			fields: [
				{ key: 'work_company',     label: 'Company',     type: 'text',     placeholder: 'Company name' },
				{ key: 'work_title',       label: 'Job Title',   type: 'text',     placeholder: 'Your role' },
				{ key: 'work_location',    label: 'Location',    type: 'text',     placeholder: 'City or Remote' },
				{ key: 'work_daterange',   label: 'Date Range',  type: 'text',     placeholder: 'e.g. Jan 2020 to Present' },
				{ key: 'work_description', label: 'Description', type: 'textarea', placeholder: 'Brief description of your role', fullWidth: true },
			],
		},
		education: {
			containerId: 'bn-ep-edu-entries',
			removeLabel: 'Remove this entry',
			fields: [
				{ key: 'edu_institution', label: 'Institution',    type: 'text', placeholder: 'School or University' },
				{ key: 'edu_degree',      label: 'Degree',         type: 'text', placeholder: 'e.g. Bachelor of Science' },
				{ key: 'edu_field',       label: 'Field of Study', type: 'text', placeholder: 'e.g. Computer Science' },
				{ key: 'edu_daterange',   label: 'Date Range',     type: 'text', placeholder: 'e.g. 2016 to 2020' },
			],
		},
	};
	var cfg = groupConfig[ group ];
	if ( ! cfg ) { return null; }

	var entry = document.createElement( 'div' );
	entry.className          = 'bn-ep-repeater-entry';
	entry.dataset.entryIndex = String( index );

	var header = document.createElement( 'div' );
	header.className = 'bn-ep-repeater-header';
	var num = document.createElement( 'span' );
	num.className   = 'bn-ep-repeater-num';
	num.textContent = String( index + 1 );
	var removeBtn = document.createElement( 'button' );
	removeBtn.className              = 'bn-ep-repeater-remove';
	removeBtn.type                   = 'button';
	removeBtn.dataset.group          = group;
	removeBtn.dataset.entryIndex     = String( index );
	removeBtn.setAttribute( 'aria-label', cfg.removeLabel );
	removeBtn.textContent            = '×';
	removeBtn.addEventListener( 'click', function () {
		entry.remove();
		renumberEntries( cfg.containerId );
	} );
	header.appendChild( num );
	header.appendChild( removeBtn );
	entry.appendChild( header );

	var row = null;
	cfg.fields.forEach( function ( fieldDef, fi ) {
		if ( fieldDef.fullWidth ) {
			var grp = document.createElement( 'div' );
			grp.className = 'bn-ep-group';
			var lbl = document.createElement( 'label' );
			lbl.className   = 'bn-ep-label';
			lbl.textContent = fieldDef.label;
			var ta = document.createElement( 'textarea' );
			ta.className   = 'bn-ep-input';
			ta.name        = group + '[' + index + '][' + fieldDef.key + ']';
			ta.rows        = 3;
			ta.placeholder = fieldDef.placeholder;
			grp.appendChild( lbl );
			grp.appendChild( ta );
			entry.appendChild( grp );
			row = null;
			return;
		}
		if ( fi % 2 === 0 ) {
			row = document.createElement( 'div' );
			row.className = 'bn-ep-repeater-row';
			entry.appendChild( row );
		}
		var grp = document.createElement( 'div' );
		grp.className = 'bn-ep-group';
		var lbl = document.createElement( 'label' );
		lbl.className   = 'bn-ep-label';
		lbl.textContent = fieldDef.label;
		var inp = document.createElement( 'input' );
		inp.className   = 'bn-ep-input';
		inp.type        = fieldDef.type;
		inp.name        = group + '[' + index + '][' + fieldDef.key + ']';
		inp.placeholder = fieldDef.placeholder;
		grp.appendChild( lbl );
		grp.appendChild( inp );
		if ( row ) { row.appendChild( grp ); }
	} );

	return entry;
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

/* -- Tab URL sync ------------------------------------------------------- */

var BN_VALID_TABS = [ 'posts', 'replies', 'media', 'likes', 'followers', 'following', 'connections', 'discussions' ];

function applyTabId( tabId ) {
	if ( ! tabId || BN_VALID_TABS.indexOf( tabId ) === -1 ) {
		tabId = 'posts';
	}
	document.querySelectorAll( '.bn-pf-tabs .bn-tab' ).forEach( function ( t ) {
		var isActive = t.dataset.tab === tabId;
		t.classList.toggle( 'active', isActive );
		t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
	} );
	document.querySelectorAll( '[data-tab-panel]' ).forEach( function ( p ) {
		p.hidden = p.dataset.tabPanel !== tabId;
	} );
	var postsContent = document.querySelector( '.bn-profile-posts-panel' );
	if ( postsContent ) {
		postsContent.hidden = tabId !== 'posts';
	}
}

function pushTabToUrl( tabId ) {
	if ( ! window.history || typeof window.history.pushState !== 'function' ) { return; }
	var url = new URL( window.location.href );
	if ( tabId && tabId !== 'posts' ) {
		url.searchParams.set( 'tab', tabId );
	} else {
		url.searchParams.delete( 'tab' );
	}
	window.history.pushState( { bnTab: tabId }, '', url.toString() );
}

function applyTabFromUrl() {
	var params = new URLSearchParams( window.location.search );
	var tab    = params.get( 'tab' );
	if ( ! tab ) {
		// No ?tab= override — honour the server-rendered active tab so path-based
		// deep links (e.g. /members/x/followers/) open the right in-page tab.
		try { tab = getContext().activeTab; } catch ( _e ) {}
	}
	applyTabId( tab || 'posts' );
}

/* -- Store ------------------------------------------------------------- */

store( 'buddynext/profile', {
	state: {
		get muteLabel()     { return getContext().isMuted      ? 'Unmute'     : 'Mute'; },
		get restrictLabel() { return getContext().isRestricted ? 'Unrestrict' : 'Restrict'; },
		get blockLabel()    { return getContext().isBlocked    ? 'Unblock'    : 'Block'; },
		/* Two-factor stage visibility (mutually exclusive). */
		get twofaShowStart()  { const c = getContext(); return ! c.twofaEnabled && c.twofaStage === 'idle'; },
		get twofaShowSetup()  { return getContext().twofaStage === 'setup'; },
		get twofaShowBackup() { return getContext().twofaStage === 'backup'; },
		get twofaShowManage() { const c = getContext(); return !! c.twofaEnabled && c.twofaStage === 'idle'; },
		get twofaBackupText() {
			const n = Number( getContext().twofaBackupRemaining ) || 0;
			return n === 1 ? '1 backup code left.' : n + ' backup codes left.';
		},
	},
	callbacks: {
		/* Init for the edit page: register the beforeunload guard once. */
		initEditGuard() {
			ensureUnloadGuard();
		},
		/* Init for the view page: read ?tab=... and wire popstate. */
		initView() {
			applyTabFromUrl();
			if ( ! window.__bnProfilePopstateBound ) {
				window.addEventListener( 'popstate', applyTabFromUrl );
				window.__bnProfilePopstateBound = true;
			}
		},
	},
	actions: {

		/* Profile tab switching - Posts / Replies / Media / Likes
		 *
		 * Updates aria/active state on tab buttons, toggles panels, and
		 * pushes the active tab into the URL (?tab=replies) so reload +
		 * back-button work. The popstate handler in initView() reverses
		 * the transition when the user hits Back.
		 */
		setTab( event ) {
			const tab    = event.target.closest( '[data-tab]' );
			if ( ! tab ) { return; }
			// Preserve "open in new tab" for modified/middle clicks on stat-chip
			// links; a plain left-click switches the panel in place (no reload).
			if ( event && ( event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1 ) ) { return; }
			if ( event && typeof event.preventDefault === 'function' ) { event.preventDefault(); }
			const tabId  = tab.dataset.tab;
			applyTabId( tabId );
			pushTabToUrl( tabId );
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
			const title = document.querySelector( '.bn-pf-name' )?.textContent?.trim() || 'Profile';
			const toast = ( typeof window !== 'undefined' && typeof window.bnToast === 'function' ) ? window.bnToast : null;
			if ( navigator.share ) {
				navigator.share( { title, url } ).catch( () => {} );
				return;
			}
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url ).then(
					() => { if ( toast ) { toast( 'Profile link copied', { tone: 'info' } ); } },
					() => { if ( toast ) { toast( 'Could not copy. Long-press the URL.', { tone: 'danger' } ); } }
				);
				return;
			}
			window.prompt( 'Copy this URL:', url );
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
				var res = await fetch( apiUrl( 'buddynext/v1/me/social/' + provider ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': nonce() },
				} );
				if ( ! res.ok ) { throw new Error( 'http_' + res.status ); }
				bnToast( ( window.bnI18n && window.bnI18n.socialUnlinked ) || 'Account unlinked', { tone: 'success' } );
				var row = btn.closest( '.bn-social-link' );
				if ( row ) {
					var a = document.createElement( 'a' );
					a.className = 'bn-btn';
					a.setAttribute( 'data-variant', 'ghost' );
					a.setAttribute( 'data-size', 'sm' );
					a.href = '/oauth/' + provider + '/';
					a.textContent = 'Connect';
					btn.replaceWith( a );
				}
			} catch ( _e ) {
				bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || 'Could not unlink. Try again.', { tone: 'danger' } );
			}
		},

		/* Self-assign a member type (own profile, self-select types only). PUTs
		 * the chosen slug to /users/{id}/member-type; the endpoint enforces the
		 * self_select gate server-side. Saves immediately on change. */
		async setMemberType( event ) {
			var sel    = event.target;
			var userId = sel.getAttribute( 'data-user-id' );
			if ( ! userId ) { return; }
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + userId + '/member-type' ), {
					method:  'PUT',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
					body:    JSON.stringify( { type_slug: sel.value } ),
				} );
				if ( ! res.ok ) { throw new Error( 'http_' + res.status ); }
				bnToast( ( window.bnI18n && window.bnI18n.memberTypeSaved ) || 'Member type updated', { tone: 'success' } );
			} catch ( _e ) {
				bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || 'Could not update member type', { tone: 'danger' } );
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
				var res = await fetch( apiUrl( 'buddynext/v1/me/profile' ), {
					method:  'PUT',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
					body:    JSON.stringify( payload ),
				} );
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
				bnToast(
					( window.bnI18n && window.bnI18n.prefSaved ) || 'Preference saved',
					{ tone: 'success' }
				);
			} catch ( _e ) {
				btn.setAttribute( 'aria-checked', prev ? 'true' : 'false' );
				bnToast(
					( window.bnI18n && window.bnI18n.saveFailed ) || 'Could not save. Please try again.',
					{ tone: 'danger' }
				);
			}
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
					errors.display_name = 'Display name is required.';
				} else {
					delete errors.display_name;
				}
			} else if ( name === 'website' || name.indexOf( 'social_' ) === 0 ) {
				if ( val !== '' && ! isValidUrlClient( val ) ) {
					errors[ name ] = 'Enter a valid URL (https://example.com).';
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
			var nameInput = document.getElementById( 'bn-ep-name' );
			if ( nameInput && ( nameInput.value || '' ).trim() === '' ) {
				errors.display_name = 'Display name is required.';
				nameInput.focus();
			}
			[ 'website', 'social_twitter', 'social_linkedin', 'social_github', 'social_instagram', 'social_youtube' ].forEach( function ( fname ) {
				var el = document.querySelector( '[name="' + fname + '"]' );
				if ( ! el ) { return; }
				var v = ( el.value || '' ).trim();
				if ( v !== '' && ! isValidUrlClient( v ) ) {
					errors[ fname ] = 'Enter a valid URL (https://example.com).';
				}
			} );
			if ( Object.keys( errors ).length > 0 ) {
				ctx.errors = errors;
				bnToast( 'Some fields need attention', { tone: 'danger' } );
				return;
			}

			doSave( ctx ).then( function () {
				syncDirtyAttr( ctx.isDirty );
				if ( ctx.saved ) {
					// Smooth redirect after save: profileUrl is in context.
					setTimeout( function () {
						if ( ctx.profileUrl ) {
							window.location.href = ctx.profileUrl;
						}
					}, 700 );
				}
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
				fetch(
					apiUrl( 'buddynext/v1/profile-slug/check?slug=' + encodeURIComponent( slug ) ),
					{ headers: { 'X-WP-Nonce': ctx.restNonce }, signal: slugAbort.signal }
				).then( function ( r ) { return r.json(); } )
				 .then( function ( json ) {
				 	ctx.slugAvailable = json.available;
				 	ctx.slugChecking  = false;
				 } )
				 .catch( function ( e ) {
				 	if ( e.name !== 'AbortError' ) { ctx.slugChecking = false; }
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

			fetch( apiUrl( 'buddynext/v1/me/profile-slug' ), {
				method:  'PUT',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
				body:    JSON.stringify( { slug: slug } ),
			} ).then( function ( r ) { return r.json(); } )
			   .then( function ( json ) {
			   	ctx.profileUrl  = json.url;
			   	ctx.profileSlug = json.slug;
			   	ctx.slugSaved   = true;
			   	setTimeout( function () { ctx.slugSaved = false; }, 3000 );
			   } )
			   .catch( function () {} )
			   .finally( function () { ctx.slugSaving = false; } );
		},

		addEntry( event ) {
			var btn   = event.target.closest( '[data-group]' );
			var group = btn ? btn.dataset.group : null;
			if ( ! group ) { return; }

			var containerMap = {
				work_experience: 'bn-ep-work-entries',
				education:       'bn-ep-edu-entries',
			};
			var containerId = containerMap[ group ];
			if ( ! containerId ) { return; }

			var container = document.getElementById( containerId );
			if ( ! container ) { return; }

			var index = container.querySelectorAll( '.bn-ep-repeater-entry' ).length;
			var node  = buildEntryNode( group, index );
			if ( node ) { container.appendChild( node ); }
			// Adding a row counts as a dirty edit.
			getContext().isDirty = true;
			syncDirtyAttr( true );
		},

		removeEntry( event ) {
			var btn = event.target.closest( '[data-group]' );
			if ( ! btn ) { return; }

			var group = btn.dataset.group;
			var containerMap = {
				work_experience: 'bn-ep-work-entries',
				education:       'bn-ep-edu-entries',
			};
			var containerId = containerMap[ group ];

			var entryEl = btn.closest( '.bn-ep-repeater-entry' );
			if ( entryEl ) { entryEl.remove(); }
			if ( containerId ) { renumberEntries( containerId ); }
			getContext().isDirty = true;
			syncDirtyAttr( true );
		},

		addInterestOnEnter( event ) {
			if ( event.key !== 'Enter' ) { return; }
			event.preventDefault();
			var ctx = getContext();
			var val = event.target.value.trim();
			if ( val && ctx.interests.indexOf( val ) === -1 ) {
				ctx.interests = ctx.interests.concat( [ val ] );
				ctx.isDirty   = true;
				syncDirtyAttr( true );
			}
			event.target.value = '';
		},

		removeInterest( event ) {
			var ctx      = getContext();
			var btn      = event.target.closest( '[data-interest]' );
			var interest = btn ? btn.dataset.interest : null;
			if ( interest ) {
				ctx.interests = ctx.interests.filter( function ( i ) { return i !== interest; } );
				ctx.isDirty   = true;
				syncDirtyAttr( true );
			}
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

			// Open the in-browser crop modal. User picks the square they
			// want; we ship the cropped blob to the existing endpoint so
			// no server-side cropping is needed. Cancel restores the
			// file input value to empty.
			try {
				var cropped = await openAvatarCropModal( file );
				if ( ! cropped ) {
					event.target.value = '';
					return;
				}

				var formData = new FormData();
				formData.append( 'avatar', cropped, 'avatar.jpg' );

				ctx.avatarUploading = true;

				var res  = await fetch( apiUrl( 'buddynext/v1/me/avatar' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': nonce() },
					body:    formData,
				} );
				var data = await res.json();
				if ( res.ok && data.avatar_url ) {
					ctx.avatarUrl = data.avatar_url;
					bnToast( 'Avatar updated', { tone: 'success' } );
				} else {
					bnToast( ( data && data.message ) || 'Upload failed', { tone: 'danger' } );
				}
			} catch ( err ) {
				bnToast( 'Upload failed', { tone: 'danger' } );
			} finally {
				ctx.avatarUploading = false;
				event.target.value  = '';
			}
		},

		async handleCoverFileChange( event ) {
			var file = event.target.files[ 0 ];
			if ( ! file ) { return; }

			// Capture context before the await (see handleAvatarFileChange).
			var ctx = getContext();

			// Open the reposition modal: the user pans + zooms the cover
			// (LinkedIn-style). The raw file uploads as-is; the chosen
			// position {x, y} and zoom are sent alongside and applied to the
			// cover render via object-position + transform:scale — kept
			// non-destructive so the source stays responsive at any width.
			try {
				var repos = await openCoverReposModal( file );
				if ( ! repos ) {
					event.target.value = '';
					return;
				}

				var formData = new FormData();
				formData.append( 'avatar', file );
				formData.append( 'focal_x', String( repos.x ) );
				formData.append( 'focal_y', String( repos.y ) );
				formData.append( 'focal_zoom', String( repos.zoom ) );

				ctx.coverUploading = true;

				var res  = await fetch( apiUrl( 'buddynext/v1/me/cover' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': nonce() },
					body:    formData,
				} );
				var data = await res.json();
				if ( res.ok && data.cover_url ) {
					ctx.coverUrl    = data.cover_url;
					ctx.coverFocalX = repos.x;
					ctx.coverFocalY = repos.y;
					ctx.coverZoom   = repos.zoom;
					// Live-refresh the edit-page cover preview (the <img> is not
					// reactively bound — it is a one-off hero preview).
					var coverImg = document.querySelector( '[data-bn-cover-preview]' );
					if ( coverImg ) {
						coverImg.src = data.cover_url;
						coverImg.style.display = '';
						coverImg.style.objectPosition = repos.x + '% ' + repos.y + '%';
						coverImg.style.transform = 'scale(' + repos.zoom + ')';
						var wrap = coverImg.closest( '.bn-pf-cover' );
						if ( wrap ) { wrap.classList.add( 'bn-pf-cover--has-image' ); }
					}
					bnToast( 'Cover updated', { tone: 'success' } );
				} else {
					bnToast( ( data && data.message ) || 'Upload failed', { tone: 'danger' } );
				}
			} catch ( err ) {
				bnToast( 'Upload failed', { tone: 'danger' } );
			} finally {
				ctx.coverUploading = false;
				event.target.value = '';
			}
		},

		/* -- Social actions (profile view page) ------------------------- */

		async follow() {
			var ctx = getContext();
			if ( ctx.isFollowing ) { return; }
			// Optimistic.
			ctx.isFollowing   = true;
			ctx.followerCount = ( ctx.followerCount || 0 ) + 1;
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/follow' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'follow_failed' ); }
				bnToast( 'Followed', { tone: 'success' } );
			} catch ( _e ) {
				ctx.isFollowing   = false;
				ctx.followerCount = Math.max( 0, ( ctx.followerCount || 1 ) - 1 );
				bnToast( 'Could not follow. Try again.', { tone: 'danger' } );
			}
		},

		async unfollow() {
			var ctx = getContext();
			if ( ! ctx.isFollowing ) { return; }
			ctx.isFollowing   = false;
			ctx.followerCount = Math.max( 0, ( ctx.followerCount || 1 ) - 1 );
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/follow' ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'unfollow_failed' ); }
				bnToast( 'Unfollowed', { tone: 'info' } );
			} catch ( _e ) {
				ctx.isFollowing   = true;
				ctx.followerCount = ( ctx.followerCount || 0 ) + 1;
				bnToast( 'Could not unfollow. Try again.', { tone: 'danger' } );
			}
		},

		async connect() {
			var ctx = getContext();
			if ( ! ctx.showConnect ) { return; }
			ctx.connectionPending = true;
			ctx.showConnect       = false;
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'connect_failed' ); }
				bnToast( 'Connection request sent', { tone: 'success' } );
			} catch ( _e ) {
				ctx.connectionPending = false;
				ctx.showConnect       = true;
				bnToast( 'Could not send request', { tone: 'danger' } );
			}
		},

		async withdrawRequest() {
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect' ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.connectionPending = false;
					ctx.showConnect       = true;
					bnToast( 'Request withdrawn', { tone: 'info' } );
				}
			} catch ( _e ) {}
		},

		async acceptRequest() {
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect/accept' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.connectionReceived = false;
					ctx.isConnected        = true;
					ctx.showConnect        = false;
					bnToast( 'Connected', { tone: 'success' } );
				}
			} catch ( _e ) {}
		},

		async declineRequest() {
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect/decline' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.connectionReceived = false;
					ctx.showConnect        = true;
					bnToast( 'Request declined', { tone: 'info' } );
				}
			} catch ( _e ) {}
		},

		async disconnectUser() {
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect' ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.isConnected       = false;
					ctx.showConnect       = true;
					ctx.connectionPending = false;
					bnToast( 'Disconnected', { tone: 'info' } );
				}
			} catch ( _e ) {}
		},


		/* -- More-options menu -------------------------------------- */

		toggleMoreMenu() {
			var ctx = getContext();
			ctx.moreMenuOpen = ! ctx.moreMenuOpen;
			if ( ctx.moreMenuOpen ) {
				ctx.shareMenuOpen = false;
			}
		},

		/* -- Share-profile popover ---------------------------------- */

		toggleShareMenu() {
			var ctx = getContext();
			ctx.shareMenuOpen = ! ctx.shareMenuOpen;
			if ( ctx.shareMenuOpen ) {
				ctx.moreMenuOpen = false;
			}
		},

		async copyProfileLink( event ) {
			var ctx = getContext();
			var btn = event.target.closest( '[data-share-url]' );
			var url = btn ? btn.dataset.shareUrl : window.location.href;
			try {
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					await navigator.clipboard.writeText( url );
				} else {
					var ta = document.createElement( 'textarea' );
					ta.value = url;
					document.body.appendChild( ta );
					ta.select();
					document.execCommand( 'copy' );
					document.body.removeChild( ta );
				}
				bnToast( ( window.bnI18n && window.bnI18n.linkCopied ) || 'Profile link copied', { tone: 'success' } );
			} catch ( _e ) {
				bnToast( ( window.bnI18n && window.bnI18n.copyFailed ) || 'Could not copy link.', { tone: 'danger' } );
			}
			ctx.shareMenuOpen = false;
		},

		async toggleMute() {
			var ctx    = getContext();
			var wasMuted = !! ctx.isMuted;
			// Optimistic.
			ctx.isMuted      = ! wasMuted;
			ctx.moreMenuOpen = false;
			var method = wasMuted ? 'DELETE' : 'POST';
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/mute' ), {
					method:  method,
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'mute_failed' ); }
				bnToast( wasMuted ? 'Unmuted' : 'Muted', { tone: 'success' } );
			} catch ( _e ) {
				ctx.isMuted = wasMuted;
				bnToast( 'Could not update mute state', { tone: 'danger' } );
			}
		},

		async toggleRestrict() {
			var ctx           = getContext();
			var wasRestricted = !! ctx.isRestricted;
			ctx.isRestricted = ! wasRestricted;
			ctx.moreMenuOpen = false;
			var method = wasRestricted ? 'DELETE' : 'POST';
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/restrict' ), {
					method:  method,
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'restrict_failed' ); }
				bnToast(
					wasRestricted
						? 'No longer restricted'
						: 'Restricted. They can still see your profile, but their comments are hidden from others.',
					{ tone: wasRestricted ? 'info' : 'success' }
				);
			} catch ( _e ) {
				ctx.isRestricted = wasRestricted;
				bnToast( 'Could not update restrict state', { tone: 'danger' } );
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

		async confirmBlock() {
			var ctx = getContext();
			if ( ctx.blockSubmitting ) { return; }
			ctx.blockSubmitting = true;
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/block' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'block_failed' ); }
				ctx.isBlocked        = true;
				ctx.blockConfirmOpen = false;
				bnToast( ( ctx.displayName ? ctx.displayName + ' blocked' : 'Member blocked' ), { tone: 'success' } );
				// After block we redirect to the members directory since the profile is no longer accessible.
				setTimeout( function () {
					window.location.href = ( ctx.peopleUrl || '/members/' );
				}, 800 );
			} catch ( _e ) {
				bnToast( 'Could not block. Try again.', { tone: 'danger' } );
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
				var res = await fetch( apiUrl( 'buddynext/v1/reports' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
					body:    JSON.stringify( {
						object_type: 'user',
						object_id:   ctx.profileUserId,
						reason:      ctx.reportReason || 'other',
						notes:       ctx.reportNotes  || '',
					} ),
				} );
				if ( ! res.ok && res.status !== 201 ) { throw new Error( 'report_failed' ); }
				ctx.reportOpen = false;
				bnToast( 'Report submitted. Thanks for keeping the community safe.', { tone: 'success' } );
			} catch ( _e ) {
				bnToast( 'Could not submit report. Try again.', { tone: 'danger' } );
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
				var res = await fetch( apiUrl( 'buddynext/v1/auth/change-email' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
					body:    JSON.stringify( { email: email } ),
				} );
				var json = {};
				try { json = await res.json(); } catch ( _e ) {}
				if ( res.ok && json && json.saved ) {
					ctx.emailChangeOpen = false;
					if ( input ) { input.value = ''; }
					bnToast( json.message || 'Check your inbox to confirm.', { tone: 'success' } );
				} else if ( res.status === 422 && json && json.errors ) {
					ctx.errors = Object.assign( {}, ctx.errors || {}, json.errors );
				} else {
					bnToast( 'Could not send verification email. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not send verification email. Try again.', { tone: 'danger' } );
			} finally {
				ctx.emailChangeSubmitting = false;
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
			ctx.passwordStrengthLabel = [ 'Too short', 'Weak', 'Fair', 'Good', 'Strong', 'Excellent' ][ Math.min( score, 5 ) ] || '';
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
			if ( ! curr ) { localErrors.current_password = 'Enter your current password.'; }
			if ( ! next ) {
				localErrors.new_password = 'Enter a new password.';
			} else if ( next.length < 8 ) {
				localErrors.new_password = 'Use at least 8 characters.';
			}
			if ( next && next !== conf ) {
				localErrors.confirm_password = 'Passwords do not match.';
			}
			if ( Object.keys( localErrors ).length ) {
				ctx.errors = Object.assign( {}, ctx.errors || {}, localErrors );
				return;
			}

			ctx.passwordChangeSubmitting = true;
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/auth/change-password' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
					body:    JSON.stringify( { current_password: curr, new_password: next } ),
				} );
				var json = {};
				try { json = await res.json(); } catch ( _e ) {}
				if ( res.ok && json && json.saved ) {
					ctx.passwordChangeOpen = false;
					if ( curInput ) { curInput.value = ''; }
					if ( newInput ) { newInput.value = ''; }
					if ( conInput ) { conInput.value = ''; }
					ctx.passwordStrength = 0;
					ctx.passwordStrengthLabel = '';
					bnToast( 'Password updated.', { tone: 'success' } );
				} else if ( res.status === 422 && json && json.errors ) {
					ctx.errors = Object.assign( {}, ctx.errors || {}, json.errors );
				} else {
					bnToast( 'Could not change password. Try again.', { tone: 'danger' } );
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
				var res = await fetch( apiUrl( 'buddynext/v1/auth/sign-out-everywhere' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
				} );
				if ( ! res.ok ) { throw new Error( 'http_' + res.status ); }
				bnToast( 'Signed out of every other session.', { tone: 'success' } );
			} catch ( _e ) {
				bnToast( 'Could not sign out everywhere. Try again.', { tone: 'danger' } );
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
				const res = await fetch( apiUrl( 'buddynext/v1/account/2fa/setup' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
				} );
				const json = await res.json();
				if ( res.ok && json && json.success ) {
					ctx.twofaSecret = json.secret || '';
					ctx.twofaUri = json.otpauth_uri || '';
					ctx.twofaCode = '';
					ctx.twofaStage = 'setup';
				} else {
					bnToast( ( json && json.message ) || 'Could not start setup. Try again.', { tone: 'danger' } );
				}
			} catch ( _e ) {
				bnToast( 'Could not start setup. Try again.', { tone: 'danger' } );
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
				const res = await fetch( apiUrl( 'buddynext/v1/account/2fa/confirm' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
					body:    JSON.stringify( { code: ctx.twofaCode || '' } ),
				} );
				const json = await res.json();
				if ( res.ok && json && json.success ) {
					ctx.twofaBackupCodes = json.backup_codes || [];
					ctx.twofaBackupRemaining = ctx.twofaBackupCodes.length;
					ctx.twofaEnabled = true;
					ctx.twofaSecret = '';
					ctx.twofaUri = '';
					ctx.twofaCode = '';
					ctx.twofaStage = 'backup';
				} else {
					ctx.twofaError = ( json && json.message ) || 'That code did not match.';
				}
			} catch ( _e ) {
				ctx.twofaError = 'Something went wrong. Try again.';
			} finally {
				ctx.twofaBusy = false;
			}
		},
		finishTwofa() {
			const ctx = getContext();
			ctx.twofaBackupCodes = [];
			ctx.twofaStage = 'idle';
			bnToast( 'Two-factor authentication is on.', { tone: 'success' } );
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
			if ( ! ( ctx.twofaPassword || '' ) ) { ctx.twofaError = 'Enter your password.'; return; }
			ctx.twofaBusy = true;
			ctx.twofaError = '';
			try {
				const res = await fetch( apiUrl( 'buddynext/v1/account/2fa/backup' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
					body:    JSON.stringify( { password: ctx.twofaPassword || '' } ),
				} );
				const json = await res.json();
				if ( res.ok && json && json.success ) {
					ctx.twofaBackupCodes = json.backup_codes || [];
					ctx.twofaBackupRemaining = ctx.twofaBackupCodes.length;
					ctx.twofaPassword = '';
					ctx.twofaStage = 'backup';
				} else {
					ctx.twofaError = ( json && json.message ) || 'Could not regenerate codes.';
				}
			} catch ( _e ) {
				ctx.twofaError = 'Something went wrong. Try again.';
			} finally {
				ctx.twofaBusy = false;
			}
		},
		async disableTwofa() {
			const ctx = getContext();
			if ( ctx.twofaBusy ) { return; }
			if ( ! ( ctx.twofaPassword || '' ) ) { ctx.twofaError = 'Enter your password.'; return; }
			ctx.twofaBusy = true;
			ctx.twofaError = '';
			try {
				const res = await fetch( apiUrl( 'buddynext/v1/account/2fa/disable' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
					body:    JSON.stringify( { password: ctx.twofaPassword || '' } ),
				} );
				const json = await res.json();
				if ( res.ok && json && json.success ) {
					ctx.twofaEnabled = false;
					ctx.twofaBackupRemaining = 0;
					ctx.twofaPassword = '';
					ctx.twofaStage = 'idle';
					bnToast( 'Two-factor authentication is off.', { tone: 'success' } );
				} else {
					ctx.twofaError = ( json && json.message ) || 'Could not turn off two-factor.';
				}
			} catch ( _e ) {
				ctx.twofaError = 'Something went wrong. Try again.';
			} finally {
				ctx.twofaBusy = false;
			}
		},
	},
} );

/* -- Helpers that need access to the store -------------------------- */

async function doUnblock( ctx ) {
	var wasBlocked = !! ctx.isBlocked;
	ctx.isBlocked    = false;
	ctx.moreMenuOpen = false;
	try {
		var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/block' ), {
			method:  'DELETE',
			headers: { 'X-WP-Nonce': ctx.restNonce },
		} );
		if ( ! res.ok ) { throw new Error( 'unblock_failed' ); }
		bnToast( 'Unblocked', { tone: 'info' } );
	} catch ( _e ) {
		ctx.isBlocked = wasBlocked;
		bnToast( 'Could not unblock', { tone: 'danger' } );
	}
}

/* Relation removal (unblock / unmute / unrestrict) — side-effect import.
   Handler now lives in social/relation-remove.js so the notifications
   sidebar muted widget gets the same behaviour. */
import '../social/relation-remove.js';
