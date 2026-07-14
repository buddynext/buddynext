/**
 * BuddyNext albums store (Interactivity API, `buddynext/media-albums`).
 *
 * Drives the Media-tab sub-nav (Media | Albums) and the entire albums surface:
 * the album cards grid, the create-album modal, the album detail view, and the
 * add-media picker. It is a SEPARATE island from the upload composer
 * (`buddynext/media`) so the composer stays self-contained; this one wraps it and
 * toggles the two views. All engine access is via the BuddyNext album endpoints
 * (buddynext/v1/*), owner-gated server-side.
 */

import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';
import { onNavReady } from '../shell/nav-init.js';
import { bnToast, bnConfirm } from '../shell/dialog.js';
// The SAME upload path the composer uses. The picker must not grow a second way to
// upload a file — one endpoint, one validation, one set of limits.
import { uploadMedia, validateMedia } from './upload-core.js';

let cfg = { nonce: '', owner: 0, t: {} };

/* Server-injected dictionary (AssetService::i18n_media) for the strings this
 * store renders imperatively but the template's data-wp-context does not carry.
 * Populated at the bottom of the module from the store's own state. */
let I18N = {};

/* The currently-open album id, mirrored for document-delegated handlers
 * (cover/reorder) that run outside the island context. */
let currentAlbumId = 0;

function t( key, fallback ) {
	if ( cfg.t && Object.prototype.hasOwnProperty.call( cfg.t, key ) ) {
		return cfg.t[ key ];
	}
	if ( I18N && I18N[ key ] ) {
		return I18N[ key ];
	}
	return fallback;
}

/**
 * Escape a plain string for safe interpolation into an innerHTML template.
 *
 * @param {string} value Raw text.
 * @return {string} HTML-escaped text.
 */
function escHtml( value ) {
	const span = document.createElement( 'span' );
	span.textContent = String( value == null ? '' : value );
	return span.innerHTML;
}

/* Map an API album summary to a display row (per-item display strings, since
 * Interactivity directives resolve a property path, not an expression). */
function toCard( a ) {
	const count = Number( a.media_count ) || 0;
	return {
		id:          Number( a.id ) || 0,
		title:       String( a.title || '' ),
		description: String( a.description || '' ),
		cover:       String( a.cover_url || '' ),
		hasCover:    !! a.cover_url,
		privacy:     String( a.privacy || 'public' ),
		countLabel:  count === 1
			? ( cfg.t.oneItem || '1 item' )
			: ( cfg.t.nItems || '%d items' ).replace( '%d', String( count ) ),
	};
}

/* WP Interactivity's data-wp-bind--value does not drive a <select>'s selected
 * option, so the privacy select is set imperatively when a modal opens. Deferred
 * one tick so it runs AFTER the modal subtree's (first-open) render, which would
 * otherwise reset the select to its default option. The select stays uncontrolled
 * (no value binding), so subsequent renders never touch it. */
function setPrivacySelect( value ) {
	const apply = () => {
		const sel = document.querySelector( '[data-wp-interactive="buddynext/media-albums"] .bn-album-privacy-select' );
		if ( sel ) { sel.value = value || 'public'; }
	};
	apply();
	setTimeout( apply, 0 );
}

async function fetchAlbums( ctx ) {
	const owner = Number( ctx.ownerId ) || 0;
	try {
		const res = await restFetch( '/users/' + owner + '/albums?per_page=48', {
			method: 'GET', nonce: ctx.restNonce, toastOnError: false,
		} );
		const list = ( res.ok && res.data && Array.isArray( res.data.albums ) ) ? res.data.albums : [];
		ctx.albums = list.map( toCard );
		ctx.hasAlbums = ctx.albums.length > 0;
	} catch ( e ) {
		ctx.albums = [];
		ctx.hasAlbums = false;
	}
}

const albumsStore = store( 'buddynext/media-albums', {
	state: {
		get viewIsMedia()  { return getContext().view !== 'albums'; },
		get viewIsAlbums() { return getContext().view === 'albums'; },
		get hasAlbums()    { return !! getContext().hasAlbums; },
		get albumOpen()    { return !! getContext().albumOpen; },
		get listVisible()  { const c = getContext(); return c.view === 'albums' && ! c.albumOpen; },
		get createValid()  { return ( getContext().createTitle || '' ).trim().length > 0; },
		get isEditing()    { return !! getContext().editingAlbumId; },
	},
	actions: {
		showMedia()  { getContext().view = 'media'; },
		showAlbums() {
			const ctx = getContext();
			ctx.view = 'albums';
			if ( ! ctx.albumsLoaded ) {
				ctx.albumsLoaded = true;
				fetchAlbums( ctx );
			}
		},

		/* ── Create / edit album ────────────────────────────────────────── */
		openCreateAlbum() {
			const ctx = getContext();
			ctx.editingAlbumId = 0;
			ctx.createTitle = '';
			ctx.createDesc = '';
			ctx.createPrivacy = 'public';
			ctx.createOpen = true;
			setPrivacySelect( 'public' );
		},
		openEditAlbum() {
			const ctx = getContext();
			ctx.editingAlbumId = ctx.activeAlbumId;
			ctx.createTitle = ctx.activeAlbumTitle || '';
			ctx.createDesc = ctx.activeAlbumDesc || '';
			ctx.createPrivacy = ctx.activeAlbumPrivacy || 'public';
			ctx.createOpen = true;
			setPrivacySelect( ctx.createPrivacy );
		},
		closeCreateAlbum() {
			const ctx = getContext();
			ctx.createOpen = false;
			ctx.editingAlbumId = 0;
		},
		setCreateTitle( e ) { getContext().createTitle = e.target.value; },
		setCreateDesc( e )  { getContext().createDesc = e.target.value; },
		setCreatePrivacy( e ) { getContext().createPrivacy = e.target.value; },
		async submitCreateAlbum() {
			const ctx = getContext();
			const title = ( ctx.createTitle || '' ).trim();
			if ( ! title || ctx.creating ) { return; }
			ctx.creating = true;
			const editing = Number( ctx.editingAlbumId ) || 0;
			const body = { title, description: ctx.createDesc || '', privacy: ctx.createPrivacy || 'public' };
			try {
				const res = await restFetch( editing ? ( '/me/albums/' + editing ) : '/me/albums', {
					method: editing ? 'PUT' : 'POST', nonce: ctx.restNonce, toastOnError: false, body,
				} );
				if ( res.ok && res.data && res.data.id ) {
					const card = toCard( res.data );
					if ( editing ) {
						ctx.albums = ( ctx.albums || [] ).map( ( a ) => ( a.id === editing ? card : a ) );
						ctx.activeAlbumTitle = card.title;
						ctx.activeAlbumDesc = card.description;
						ctx.activeAlbumPrivacy = card.privacy;
						bnToast( t( 'albumSaved', 'Album updated.' ), { tone: 'success' } );
					} else {
						ctx.albums = [ card, ...( ctx.albums || [] ) ];
						ctx.hasAlbums = true;
						bnToast( t( 'albumCreated', 'Album created.' ), { tone: 'success' } );
					}
					ctx.createOpen = false;
					ctx.editingAlbumId = 0;
				} else {
					bnToast( ( res.data && res.data.message ) || t( 'createFailed', 'Could not save the album.' ), { tone: 'danger' } );
				}
			} catch ( e ) {
				bnToast( t( 'createFailed', 'Could not save the album.' ), { tone: 'danger' } );
			}
			ctx.creating = false;
		},
		async deleteAlbum() {
			const ctx = getContext();
			const id = Number( ctx.activeAlbumId ) || 0;
			if ( ! id ) { return; }
			const ok = await bnConfirm( {
				title:        t( 'confirmDeleteAlbumTitle', 'Delete this album?' ),
				body:         t( 'confirmDeleteAlbumBody', 'The photos stay in your media.' ),
				confirmLabel: t( 'delete', 'Delete' ),
				tone:         'danger',
			} );
			if ( ! ok ) { return; }
			try {
				const res = await restFetch( '/me/albums/' + id, { method: 'DELETE', nonce: ctx.restNonce, toastOnError: false } );
				if ( res.ok ) {
					ctx.albums = ( ctx.albums || [] ).filter( ( a ) => a.id !== id );
					ctx.hasAlbums = ctx.albums.length > 0;
					ctx.albumOpen = false;
					ctx.activeAlbumId = 0;
					bnToast( t( 'albumDeleted', 'Album deleted.' ), { tone: 'success' } );
				} else {
					bnToast( t( 'deleteFailed', 'Could not delete the album.' ), { tone: 'danger' } );
				}
			} catch ( e ) {
				bnToast( t( 'deleteFailed', 'Could not delete the album.' ), { tone: 'danger' } );
			}
		},

		/* ── Album detail ───────────────────────────────────────────────── */
		async openAlbum( e ) {
			const ctx = getContext();
			const card = e.target.closest( '[data-album-id]' );
			const id = card ? parseInt( card.getAttribute( 'data-album-id' ), 10 ) : 0;
			if ( ! id ) { return; }
			const meta = ( ctx.albums || [] ).find( ( a ) => a.id === id );
			ctx.activeAlbumId = id;
			ctx.activeAlbumTitle = meta ? meta.title : '';
			ctx.activeAlbumCount = meta ? meta.countLabel : '';
			ctx.activeAlbumDesc = meta ? meta.description : '';
			ctx.activeAlbumPrivacy = meta ? meta.privacy : 'public';
			ctx.albumOpen = true;
			await loadAlbumDetail( ctx );
		},
		closeAlbum() {
			const ctx = getContext();
			ctx.albumOpen = false;
			ctx.activeAlbumId = 0;
		},

		/* ── Add media picker ───────────────────────────────────────────── */
		async openAddMedia() {
			const ctx = getContext();
			clearPickerSel();
			ctx.pickerOpen = true;
			await loadPicker( ctx );
		},
		closeAddMedia() { clearPickerSel(); getContext().pickerOpen = false; },

		/**
		 * Upload straight from the device, from inside the picker.
		 *
		 * The picker used to show ONLY already-uploaded media, so a member adding a
		 * photo to an album had to leave the album, upload it on the Media tab, come
		 * back, reopen the picker and hunt for it. That is a dead end dressed up as a
		 * workflow.
		 *
		 * Uploads go through the composer's uploadMedia() — the SAME endpoint, the same
		 * validation, the same limits. The picker does not grow a second way to upload.
		 *
		 * Once the files are in, the grid is reloaded FROM THE SERVER rather than having
		 * tiles built here: MediaRenderer emits a different tile per media type (image /
		 * video / audio), and a hand-rolled client copy of that markup would drift the
		 * first time the renderer changed. The newly uploaded ids are then pre-selected,
		 * so "Add" includes them without the member having to find them again.
		 *
		 * Failures are per-file: one rejected file does not lose the rest of the batch.
		 */
		async uploadToPicker( event ) {
			const ctx   = getContext();
			const input = event && event.target;
			const files = input && input.files ? Array.from( input.files ) : [];
			if ( ! files.length ) { return; }

			ctx.pickerUploading = true;

			const uploaded = [];

			for ( const file of files ) {
				const invalid = validateMedia( file );
				if ( invalid ) {
					bnToast( invalid, { tone: 'danger' } );
					continue;
				}

				const res = await uploadMedia( file, { nonce: ctx.restNonce } );
				if ( ! res.ok || ! res.mediaId ) {
					bnToast( res.message || t( 'uploadFailed', 'Could not upload that file.' ), { tone: 'danger' } );
					continue;
				}

				uploaded.push( res.mediaId );
			}

			// Let the member choose the same file again after a failure — without this the
			// input keeps its value and re-selecting it fires no change event at all.
			if ( input ) { input.value = ''; }

			if ( uploaded.length ) {
				await loadPicker( ctx );
				selectPickerIds( uploaded );
				bnToast(
					1 === uploaded.length
						? t( 'uploadedOne', 'Uploaded and selected. Choose Add to put it in the album.' )
						: t( 'uploadedMany', 'Uploaded and selected. Choose Add to put them in the album.' ),
					{ tone: 'success' }
				);
			}

			ctx.pickerUploading = false;
		},

		async confirmAddMedia() {
			const ctx = getContext();
			const ids = pickerSel.slice();
			if ( ! ids.length || ! ctx.activeAlbumId ) { return; }
			try {
				const res = await restFetch( '/me/albums/' + ctx.activeAlbumId + '/items', {
					method: 'POST', nonce: ctx.restNonce, toastOnError: false,
					body: { media_ids: ids },
				} );
				if ( res.ok ) {
					clearPickerSel();
					ctx.pickerOpen = false;
					bnToast( t( 'added', 'Added to album.' ), { tone: 'success' } );
					await loadAlbumDetail( ctx );
					syncCountFromServer( ctx, res.data );
				} else {
					bnToast( t( 'addFailed', 'Could not add media.' ), { tone: 'danger' } );
				}
			} catch ( e ) {
				bnToast( t( 'addFailed', 'Could not add media.' ), { tone: 'danger' } );
			}
		},
	},
	callbacks: {
		initAlbums() {
			const ctx = getContext();
			ctx.view = ctx.view || 'media';
			ctx.albums = ctx.albums || [];
			ctx.hasAlbums = false;
			ctx.albumsLoaded = false;
			ctx.albumOpen = false;
			ctx.createOpen = false;
			ctx.pickerOpen = false;
			cfg = { nonce: ctx.restNonce, owner: Number( ctx.ownerId ) || 0, t: ctx.t || {} };
			onNavReady( () => { bindDetailDelete( ctx ); bindDetailCover( ctx ); bindDetailRetry( ctx ); bindPicker(); } );
		},
	},
} );

/* ── Imperative detail / picker helpers ───────────────────────────────────── */

function detailGridEl() { return document.querySelector( '[data-bn-album-grid]' ); }

async function loadAlbumDetail( ctx ) {
	const grid = detailGridEl();
	if ( ! grid ) { return; }
	currentAlbumId = Number( ctx.activeAlbumId ) || 0;
	grid.innerHTML = '<div class="bn-album-detail__loading"></div>';
	try {
		const res = await restFetch( '/albums/' + ctx.activeAlbumId + '?per_page=48', {
			method: 'GET', nonce: ctx.restNonce, toastOnError: false,
		} );
		if ( res.ok && res.data ) {
			// Server-rendered, pre-escaped MediaRenderer markup (same as the gallery).
			grid.innerHTML = res.data.html || emptyAlbum();
			if ( res.data.is_owner ) { enhanceDetailTiles( grid ); }
		} else {
			// restFetch resolves { ok:false } on 403/500/offline — it never throws —
			// so without this branch the loading spinner was the terminal state and
			// the member waited on a load that had already failed.
			grid.innerHTML = errorState( 'detail', ( res.data && res.data.message ) || '' );
		}
	} catch ( e ) {
		grid.innerHTML = errorState( 'detail', '' );
	}
}

function emptyAlbum() {
	return '<div class="bn-empty-state"><div class="bn-empty-title">' + escHtml( t( 'emptyAlbum', 'This album is empty.' ) ) + '</div></div>';
}

/**
 * Error state for the album detail grid / the add-media picker grid, with a
 * retry button so a transient failure is recoverable in place.
 *
 * @param {string} scope   'detail' or 'picker' — which loader the retry re-runs.
 * @param {string} message Optional server-supplied reason.
 * @return {string} Markup.
 */
function errorState( scope, message ) {
	const title = 'picker' === scope
		? t( 'pickerFailed', 'Could not load your media.' )
		: t( 'detailFailed', 'Could not load this album.' );
	const body = message || t( 'loadFailedBody', 'Something went wrong. Check your connection and try again.' );
	return '<div class="bn-empty-state">' +
		'<div class="bn-empty-title">' + escHtml( title ) + '</div>' +
		'<p class="bn-empty-text">' + escHtml( body ) + '</p>' +
		'<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-bn-album-retry="' + escHtml( scope ) + '">' +
		escHtml( t( 'retry', 'Try again' ) ) +
		'</button></div>';
}

/* Retry affordance inside the detail / picker error states. Delegated on the
 * document because both grids are re-rendered imperatively. */
let detailRetryBound = false;
function bindDetailRetry( ctx ) {
	if ( detailRetryBound ) { return; }
	detailRetryBound = true;
	document.addEventListener( 'click', ( e ) => {
		const btn = e.target.closest( '[data-bn-album-retry]' );
		if ( ! btn ) { return; }
		e.preventDefault();
		e.stopPropagation();
		if ( 'picker' === btn.getAttribute( 'data-bn-album-retry' ) ) {
			loadPicker( ctx );
		} else {
			loadAlbumDetail( ctx );
		}
	} );
}

/* Owner detail tiles get hover controls (set-cover + remove) and become
 * draggable for reordering. */
function enhanceDetailTiles( grid ) {
	grid.querySelectorAll( '.bn-media-gallery > .bn-media-tile[data-bn-media-id]' ).forEach( ( tile ) => {
		const id = tile.getAttribute( 'data-bn-media-id' );
		const cell = document.createElement( 'div' );
		cell.className = 'bn-media-cell';
		cell.setAttribute( 'draggable', 'true' );
		cell.setAttribute( 'data-bn-cell-media', id );
		tile.parentNode.insertBefore( cell, tile );
		cell.appendChild( tile );

		const cover = document.createElement( 'button' );
		cover.type = 'button';
		cover.className = 'bn-media-cell__cover';
		cover.setAttribute( 'data-bn-album-cover', id );
		cover.setAttribute( 'aria-label', t( 'setCover', 'Set as cover' ) );
		cover.textContent = '★';
		cell.appendChild( cover );

		const del = document.createElement( 'button' );
		del.type = 'button';
		del.className = 'bn-media-cell__delete';
		del.setAttribute( 'data-bn-album-remove', id );
		del.setAttribute( 'aria-label', t( 'removeFromAlbum', 'Remove from album' ) );
		del.textContent = '×';
		cell.appendChild( del );
	} );
	bindDetailDnD( grid );
}

/* Native drag-reorder on the detail grid (mouse). Persists the new order on
 * dragend. Bound once on the persistent grid element. */
let detailDnDBound = false;
let dragCell = null;
/* Order captured at dragstart — the truth to roll back to when the server
 * rejects the reorder. */
let orderBeforeDrag = [];
function bindDetailDnD( grid ) {
	if ( detailDnDBound ) { return; }
	detailDnDBound = true;
	grid.addEventListener( 'dragstart', ( e ) => {
		const cell = e.target.closest( '.bn-media-cell' );
		if ( ! cell ) { return; }
		dragCell = cell;
		orderBeforeDrag = currentOrder( grid );
		cell.classList.add( 'is-dragging' );
		try {
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', cell.getAttribute( 'data-bn-cell-media' ) || '' );
		} catch ( x ) { /* some browsers restrict dataTransfer */ }
	} );
	grid.addEventListener( 'dragover', ( e ) => {
		if ( ! dragCell ) { return; }
		e.preventDefault();
		const over = e.target.closest( '.bn-media-cell' );
		if ( over && over !== dragCell && over.parentNode ) {
			const rect = over.getBoundingClientRect();
			const after = ( e.clientY - rect.top ) > rect.height / 2;
			over.parentNode.insertBefore( dragCell, after ? over.nextSibling : over );
		}
	} );
	grid.addEventListener( 'drop', ( e ) => e.preventDefault() );
	grid.addEventListener( 'dragend', () => {
		if ( ! dragCell ) { return; }
		dragCell.classList.remove( 'is-dragging' );
		dragCell = null;
		persistOrder( grid );
	} );
}

/* The tile ids in their current DOM order. */
function currentOrder( grid ) {
	return Array.from( grid.querySelectorAll( '.bn-media-cell[data-bn-cell-media]' ) )
		.map( ( c ) => parseInt( c.getAttribute( 'data-bn-cell-media' ), 10 ) || 0 )
		.filter( Boolean );
}

/* Put the tiles back in `ids` order — the DOM must never keep showing an order
 * the server refused. */
function restoreOrder( grid, ids ) {
	const cells = new Map();
	grid.querySelectorAll( '.bn-media-cell[data-bn-cell-media]' ).forEach( ( c ) => {
		cells.set( parseInt( c.getAttribute( 'data-bn-cell-media' ), 10 ) || 0, c );
	} );
	ids.forEach( ( id ) => {
		const cell = cells.get( id );
		if ( cell && cell.parentNode ) { cell.parentNode.appendChild( cell ); }
	} );
}

function persistOrder( grid ) {
	const ids = currentOrder( grid );
	const previous = orderBeforeDrag.slice();
	if ( ! ids.length || ! currentAlbumId ) { return; }
	// Nothing actually moved — don't spend a request (or risk a false failure).
	if ( previous.length === ids.length && previous.every( ( id, i ) => id === ids[ i ] ) ) { return; }
	const failed = () => {
		restoreOrder( grid, previous );
		bnToast( t( 'reorderFailed', 'Could not save the new order. Your photos were put back.' ), { tone: 'danger' } );
	};
	restFetch( '/me/albums/' + currentAlbumId + '/reorder', {
		method: 'PUT', nonce: cfg.nonce, toastOnError: false, body: { order: ids },
	} ).then( ( res ) => {
		// restFetch never throws on an HTTP error — an unchecked result meant the
		// member kept seeing an order the server had rejected.
		if ( ! res || ! res.ok ) { failed(); }
	} ).catch( failed );
}

let detailDeleteBound = false;
function bindDetailDelete( ctx ) {
	if ( detailDeleteBound ) { return; }
	detailDeleteBound = true;
	document.addEventListener( 'click', async ( e ) => {
		const btn = e.target.closest( '[data-bn-album-remove]' );
		if ( ! btn ) { return; }
		e.preventDefault();
		e.stopPropagation();
		const id = parseInt( btn.getAttribute( 'data-bn-album-remove' ), 10 ) || 0;
		if ( ! id || ! ctx.activeAlbumId ) { return; }
		const ok = await bnConfirm( {
			title: t( 'confirmRemove', 'Remove this from the album?' ),
			tone:  'danger',
		} );
		if ( ! ok ) { return; }
		try {
			const res = await restFetch( '/me/albums/' + ctx.activeAlbumId + '/items/' + id, {
				method: 'DELETE', nonce: cfg.nonce, toastOnError: false,
			} );
			if ( res.ok ) {
				const cell = btn.closest( '.bn-media-cell' );
				if ( cell ) { cell.remove(); }
				syncCountFromServer( ctx, res.data );
				bnToast( t( 'removedFromAlbum', 'Removed from album.' ), { tone: 'success' } );
			} else {
				bnToast( t( 'removeFailed', 'Could not remove.' ), { tone: 'danger' } );
			}
		} catch ( err ) {
			bnToast( t( 'removeFailed', 'Could not remove.' ), { tone: 'danger' } );
		}
	} );
}

let detailCoverBound = false;
function bindDetailCover( ctx ) {
	if ( detailCoverBound ) { return; }
	detailCoverBound = true;
	document.addEventListener( 'click', async ( e ) => {
		const btn = e.target.closest( '[data-bn-album-cover]' );
		if ( ! btn ) { return; }
		e.preventDefault();
		e.stopPropagation();
		const id = parseInt( btn.getAttribute( 'data-bn-album-cover' ), 10 ) || 0;
		if ( ! id || ! ctx.activeAlbumId ) { return; }
		try {
			const res = await restFetch( '/me/albums/' + ctx.activeAlbumId, {
				method: 'PUT', nonce: cfg.nonce, toastOnError: false, body: { cover_media_id: id },
			} );
			if ( res.ok && res.data ) {
				ctx.albums = ( ctx.albums || [] ).map( ( a ) => ( a.id === ctx.activeAlbumId ? toCard( res.data ) : a ) );
				bnToast( t( 'coverSet', 'Cover updated.' ), { tone: 'success' } );
			} else {
				bnToast( t( 'coverFailed', 'Could not set the cover.' ), { tone: 'danger' } );
			}
		} catch ( err ) {
			bnToast( t( 'coverFailed', 'Could not set the cover.' ), { tone: 'danger' } );
		}
	} );
}

/* Keep the open album's count + the matching card count in sync after add/remove. */
function syncCountFromServer( ctx, data ) {
	if ( ! data || typeof data.media_count === 'undefined' ) { return; }
	const count = Number( data.media_count ) || 0;
	const label = count === 1 ? ( cfg.t.oneItem || '1 item' ) : ( cfg.t.nItems || '%d items' ).replace( '%d', String( count ) );
	ctx.activeAlbumCount = label;
	const card = ( ctx.albums || [] ).find( ( a ) => a.id === ctx.activeAlbumId );
	if ( card ) {
		ctx.albums = ctx.albums.map( ( a ) => ( a.id === ctx.activeAlbumId ? { ...a, countLabel: label } : a ) );
	}
}

/* ── Add-media picker (select from the owner's media grid) ─────────────────── */

function pickerGridEl() { return document.querySelector( '[data-bn-picker-grid]' ); }

/* Selection of media ids in the open picker (module-level, not reactive state). */
const pickerSel = [];

function clearPickerSel() {
	pickerSel.length = 0;
	syncPickerCount();
}

/**
 * Mark media as picked in the grid — used after an in-picker upload so the files the
 * member just chose are already selected and "Add" simply works.
 *
 * Applies the same class + selection array the click handler does, so a pre-selected
 * tile behaves exactly like a hand-picked one (clicking it deselects, the count is
 * right, Add sends it).
 *
 * @param {number[]} ids Media ids to select.
 */
function selectPickerIds( ids ) {
	const grid = pickerGridEl();
	if ( ! grid ) { return; }

	ids.forEach( ( id ) => {
		const tile = grid.querySelector( '.bn-media-tile[data-bn-media-id="' + id + '"]' );
		if ( ! tile ) { return; }
		tile.classList.add( 'is-picked' );
		if ( pickerSel.indexOf( id ) === -1 ) { pickerSel.push( id ); }
	} );

	syncPickerCount();
}

async function loadPicker( ctx ) {
	const grid = pickerGridEl();
	if ( ! grid ) { return; }
	grid.innerHTML = '<div class="bn-album-detail__loading"></div>';
	try {
		const res = await restFetch( '/users/' + ( Number( ctx.ownerId ) || 0 ) + '/media?per_page=48', {
			method: 'GET', nonce: ctx.restNonce, toastOnError: false,
		} );
		if ( ! res.ok ) {
			// A failed load is not an empty library — say so, and offer the retry.
			grid.innerHTML = errorState( 'picker', ( res.data && res.data.message ) || '' );
			return;
		}
		grid.innerHTML = ( res.data && res.data.html ) ? res.data.html : emptyAlbum();
	} catch ( e ) {
		grid.innerHTML = errorState( 'picker', '' );
	}
}

let pickerBound = false;
function bindPicker() {
	if ( pickerBound ) { return; }
	pickerBound = true;
	// Capture phase so this runs BEFORE the global lightbox's bubble-phase click
	// listener; stopImmediatePropagation then prevents the lightbox from opening
	// when a picker tile is tapped to select (the tile is a selection target here,
	// not a view target).
	document.addEventListener( 'click', ( e ) => {
		const grid = pickerGridEl();
		const picker = grid ? grid.closest( '.bn-album-picker' ) : null;
		if ( ! grid || ! picker || picker.hidden ) { return; }
		const tile = e.target.closest( '.bn-media-tile[data-bn-media-id]' );
		if ( ! tile || ! grid.contains( tile ) ) { return; }
		e.preventDefault();
		e.stopImmediatePropagation();
		const id = parseInt( tile.getAttribute( 'data-bn-media-id' ), 10 ) || 0;
		if ( ! id ) { return; }
		const picked = tile.classList.toggle( 'is-picked' );
		if ( picked ) {
			if ( pickerSel.indexOf( id ) === -1 ) { pickerSel.push( id ); }
		} else {
			const i = pickerSel.indexOf( id );
			if ( i !== -1 ) { pickerSel.splice( i, 1 ); }
		}
		syncPickerCount();
	}, true );
}

function syncPickerCount() {
	const island = document.querySelector( '[data-wp-interactive="buddynext/media-albums"]' );
	const btn = island ? island.querySelector( '[data-bn-picker-confirm]' ) : null;
	if ( btn ) {
		btn.disabled = pickerSel.length === 0;
		const label = btn.querySelector( '[data-bn-picker-count]' );
		if ( label ) { label.textContent = pickerSel.length ? ' (' + pickerSel.length + ')' : ''; }
	}
}

/* Strings the template's data-wp-context does not carry (imperative error/retry
 * copy) come from the server-injected state dictionary. Read once, after the
 * store is created; t() prefers the context dictionary and falls back to this. */
I18N = ( albumsStore.state && albumsStore.state.i18n ) || {};

export default albumsStore;
