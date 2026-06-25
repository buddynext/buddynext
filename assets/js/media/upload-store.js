/**
 * BuddyNext member media upload store (Interactivity API).
 *
 * Powers the owner-only upload composer on the profile Media tab. BuddyNext owns
 * 100% of this experience — no WPMediaVerse CSS/JS is loaded. Files POST to
 * buddynext/v1/me/media, which hands them to the engine's ingestion service
 * server-side; on success the gallery region is refreshed from
 * buddynext/v1/users/{id}/media so tile markup stays single-sourced in PHP
 * (MediaRenderer). Owner tiles get a hover delete control wired by delegation.
 *
 * All config + translated strings arrive via the island's data-wp-context
 * (seeded server-side), so there are no window globals and no inline scripts.
 *
 * Store namespace: buddynext/media
 */

import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';
import { onNavReady } from '../shell/nav-init.js';
import { bnToast, bnConfirm } from '../shell/dialog.js';
import { validateMedia, makeThumb, uploadMedia, deleteMedia } from './upload-core.js';

/* Staged File objects are not reactive state (only their display metadata is),
 * so they live in a module buffer keyed by the island element. */
const fileBuffers = new WeakMap();

/* Captured from the island context at init so the document-delegated delete
 * handler (which runs outside any element context) has the nonce + strings. */
let cfg = { nonce: '', owner: 0, t: {} };

function t( key, fallback ) {
	return cfg.t && Object.prototype.hasOwnProperty.call( cfg.t, key ) ? cfg.t[ key ] : fallback;
}

function islandEl() {
	return document.querySelector( '[data-wp-interactive="buddynext/media"]' );
}

function buffer() {
	const el = islandEl();
	if ( ! el ) {
		return [];
	}
	if ( ! fileBuffers.has( el ) ) {
		fileBuffers.set( el, [] );
	}
	return fileBuffers.get( el );
}

/* Validate a File via the shared core, threading this composer's i18n strings. */
function validate( file, ctx ) {
	return validateMedia( file, {
		maxSizeMB:   ctx.maxSizeMB,
		badTypeMsg:  ctx.t.badType,
		tooLargeMsg: ctx.t.tooLarge,
	} );
}

/* Mirror the File buffer into reactive display state. Per-item booleans are
 * precomputed because Interactivity directives resolve a property path, not an
 * expression (no `status === 'done'` in the template). */
function syncStaged( ctx ) {
	ctx.staged = buffer().map( ( s, i ) => ( {
		idx:         i,
		name:        s.name,
		preview:     s.preview,
		kind:        s.kind,
		status:      s.status,
		error:       s.error || '',
		isImage:      !! s.preview,
		isImageKind:  s.kind === 'image',
		thumbLoading: s.kind === 'image' && ! s.preview,
		isQueued:     s.status === 'queued',
		isUploading:  s.status === 'uploading',
		isDone:       s.status === 'done',
		isError:      s.status === 'error',
	} ) );
	ctx.hasStaged = ctx.staged.length > 0;
}

function addFiles( ctx, fileList ) {
	const buf = buffer();
	const maxFiles = Number( ctx.maxFiles ) || 10;
	let rejected = '';
	const fresh = [];
	for ( const file of Array.from( fileList ) ) {
		if ( buf.length >= maxFiles ) {
			rejected = ( ctx.t.tooMany || 'You can upload up to %d files at once.' ).replace( '%d', String( maxFiles ) );
			break;
		}
		const err = validate( file, ctx );
		const kind = ( file.type || '' ).split( '/' )[ 0 ] || 'file';
		const item = {
			file,
			name:    file.name,
			kind,
			preview: '',
			status:  err ? 'error' : 'queued',
			error:   err,
		};
		buf.push( item );
		if ( ! err && kind === 'image' ) {
			fresh.push( item );
		}
	}
	ctx.errorMsg = rejected;
	syncStaged( ctx );

	// Generate the small thumbnails asynchronously so a large source image never
	// blocks staging or upload; the tile shows its placeholder until ready.
	fresh.forEach( ( item ) => {
		makeThumb( item.file ).then( ( url ) => {
			item.preview = url;
			syncStaged( ctx );
		} );
	} );
}

const mediaStore = store( 'buddynext/media', {
	state: {
		get privacyIsPublic() { return getContext().privacy === 'public'; },
		get canUpload() {
			const c = getContext();
			return c.hasStaged && ! c.uploading && c.staged.some( ( s ) => s.status === 'queued' );
		},
	},
	actions: {
		openPicker() {
			const input = islandEl()?.querySelector( '.bn-mu-input' );
			if ( input ) {
				input.click();
			}
		},
		onDragOver( event ) {
			event.preventDefault();
			getContext().dragOver = true;
		},
		onDragLeave() {
			getContext().dragOver = false;
		},
		onDrop( event ) {
			event.preventDefault();
			const ctx = getContext();
			ctx.dragOver = false;
			if ( event.dataTransfer && event.dataTransfer.files ) {
				addFiles( ctx, event.dataTransfer.files );
			}
		},
		onFileSelect( event ) {
			const ctx = getContext();
			if ( event.target.files && event.target.files.length ) {
				addFiles( ctx, event.target.files );
			}
			event.target.value = '';
		},
		removeStaged( event ) {
			const ctx = getContext();
			const idx = parseInt( event.target.closest( '[data-index]' )?.getAttribute( 'data-index' ) || '-1', 10 );
			const buf = buffer();
			if ( idx >= 0 && idx < buf.length ) {
				if ( buf[ idx ].preview ) {
					URL.revokeObjectURL( buf[ idx ].preview );
				}
				buf.splice( idx, 1 );
				syncStaged( ctx );
			}
		},
		setPrivacy( event ) {
			getContext().privacy = event.target.value;
		},
		clearStaged() {
			const ctx = getContext();
			buffer().forEach( ( s ) => s.preview && URL.revokeObjectURL( s.preview ) );
			buffer().length = 0;
			ctx.errorMsg = '';
			syncStaged( ctx );
		},

		/* Upload queued files sequentially via the shared REST client, then
		 * refresh the gallery from the server. Failed files are kept + marked so
		 * the member can retry; the batch never aborts on one failure. */
		async startUpload() {
			const ctx = getContext();
			if ( ctx.uploading ) {
				return;
			}
			const buf = buffer();
			if ( ! buf.some( ( s ) => s.status === 'queued' ) ) {
				return;
			}

			ctx.uploading = true;
			ctx.errorMsg = '';
			let okCount = 0;
			let dupCount = 0;

			for ( const item of buf ) {
				if ( item.status !== 'queued' ) {
					continue;
				}
				item.status = 'uploading';
				syncStaged( ctx );

				const out = await uploadMedia( item.file, {
					nonce:   ctx.restNonce,
					privacy: ctx.privacy || 'public',
				} );
				if ( out.ok ) {
					item.status = 'done';
					item.mediaId = out.mediaId;
					okCount++;
					if ( out.duplicate ) {
						dupCount++;
					}
				} else {
					item.status = 'error';
					item.error = out.message || ( ctx.t.failed || 'Upload failed.' );
				}
				syncStaged( ctx );
			}

			ctx.uploading = false;

			if ( okCount > 0 ) {
				// The whole batch becomes ONE feed post carrying all the media —
				// the mainstream-social model (uploading photos = posting them).
				// The media also surfaces in the Media tab via the author gallery.
				const postedIds = buf
					.filter( ( s ) => s.status === 'done' && s.mediaId )
					.map( ( s ) => s.mediaId );
				let posted = false;
				if ( postedIds.length ) {
					try {
						const pres = await restFetch( '/posts', {
							method:       'POST',
							nonce:        ctx.restNonce,
							body:         {
								type:      'photo',
								content:   '',
								media_ids: postedIds,
								privacy:   ctx.privacy || 'public',
							},
							toastOnError: false,
						} );
						posted = !! ( pres && pres.ok );
					} catch ( e ) {
						posted = false;
					}
				}

				await refreshGallery( ctx );
				for ( let i = buf.length - 1; i >= 0; i-- ) {
					if ( buf[ i ].status === 'done' ) {
						if ( buf[ i ].preview ) {
							URL.revokeObjectURL( buf[ i ].preview );
						}
						buf.splice( i, 1 );
					}
				}
				syncStaged( ctx );

				let msg = posted
					? ( ctx.t.shared || '%d uploaded and shared to your feed.' ).replace( '%d', String( okCount ) )
					: ( ctx.t.uploaded || '%d uploaded.' ).replace( '%d', String( okCount ) );
				if ( dupCount > 0 ) {
					msg += ' ' + ( ctx.t.dup || '%d already in your library.' ).replace( '%d', String( dupCount ) );
				}
				bnToast( msg, { tone: 'success' } );
			}

			if ( buf.some( ( s ) => s.status === 'error' ) ) {
				bnToast( ctx.t.someFailed || 'Some files could not be uploaded.', { tone: 'danger' } );
			}
		},
	},
	callbacks: {
		initComposer() {
			const ctx = getContext();
			ctx.staged = ctx.staged || [];
			ctx.hasStaged = false;
			ctx.dragOver = false;
			ctx.uploading = false;
			ctx.errorMsg = '';
			ctx.privacy = ctx.privacy || 'public';
			// Capture config for the document-delegated delete handler.
			cfg = { nonce: ctx.restNonce, owner: Number( ctx.ownerId ) || 0, t: ctx.t || {} };
			onNavReady( () => {
				enhanceOwnerTiles();
				bindRegionDelete();
			} );
		},
	},
} );

/* ── Gallery region helpers (imperative, sibling DOM outside the island) ───── */

function regionEl() {
	return document.querySelector( '[data-bn-media-region]' );
}

async function refreshGallery( ctx ) {
	const region = regionEl();
	if ( ! region ) {
		return;
	}
	const owner = Number( ctx.ownerId ) || 0;
	try {
		const res = await restFetch( '/users/' + owner + '/media?page=1&per_page=24', {
			method:       'GET',
			nonce:        ctx.restNonce,
			toastOnError: false,
		} );
		if ( res.ok && res.data ) {
			const html = res.data.html || '';
			// `html` is BuddyNext's own server-rendered, pre-escaped MediaRenderer
			// markup from our endpoint (every URL/attr escaped server-side) — the
			// same string the PHP template echoes. Not user free-text.
			region.innerHTML = html
				? '<div class="bn-card bn-profile-media-card">' + html + '</div>'
				: emptyState();
			enhanceOwnerTiles();
		}
	} catch ( e ) {
		/* Keep the existing grid on a refresh failure. */
	}
}

function emptyState() {
	const span = document.createElement( 'span' );
	span.textContent = t( 'empty', 'No media uploaded yet.' );
	return '<div class="bn-empty-state"><div class="bn-empty-title">' + span.innerHTML + '</div></div>';
}

/* Wrap each owner tile in a positioned cell with a hover delete control. A tile
 * is a <button>, so the delete control must be a SIBLING overlay, not a child. */
function enhanceOwnerTiles() {
	const region = regionEl();
	if ( ! region || region.getAttribute( 'data-bn-owner' ) !== '1' ) {
		return;
	}
	region.querySelectorAll( '.bn-media-gallery > .bn-media-tile[data-bn-media-id]' ).forEach( ( tile ) => {
		const id = tile.getAttribute( 'data-bn-media-id' );
		const cell = document.createElement( 'div' );
		cell.className = 'bn-media-cell';
		tile.parentNode.insertBefore( cell, tile );
		cell.appendChild( tile );
		const del = document.createElement( 'button' );
		del.type = 'button';
		del.className = 'bn-media-cell__delete';
		del.setAttribute( 'data-bn-media-delete', id );
		del.setAttribute( 'aria-label', t( 'remove', 'Remove media' ) );
		del.textContent = '×';
		cell.appendChild( del );
	} );
}

let regionDeleteBound = false;
function bindRegionDelete() {
	if ( regionDeleteBound ) {
		return;
	}
	regionDeleteBound = true;
	document.addEventListener( 'click', async ( e ) => {
		const btn = e.target.closest( '[data-bn-media-delete]' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		const id = parseInt( btn.getAttribute( 'data-bn-media-delete' ), 10 ) || 0;
		if ( ! id ) {
			return;
		}
		const ok = await bnConfirm( t( 'confirmDelete', 'Remove this media? This cannot be undone.' ), {
			confirmLabel: t( 'remove', 'Remove' ),
			tone:         'danger',
		} );
		if ( ! ok ) {
			return;
		}
		const removed = await deleteMedia( id, cfg.nonce );
		if ( removed ) {
			const cell = btn.closest( '.bn-media-cell' );
			if ( cell ) {
				cell.remove();
			}
			bnToast( t( 'removed', 'Media removed.' ), { tone: 'success' } );
		} else {
			bnToast( t( 'failed', 'Could not remove.' ), { tone: 'danger' } );
		}
	} );
}

export default mediaStore;
