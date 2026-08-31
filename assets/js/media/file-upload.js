/**
 * BuddyNext Files-tab client behaviour: upload + remove.
 *
 * A small dropzone on the space / profile Files tab uploads a document straight
 * into THIS drive + folder, so a contributor can add a file from the Files tab
 * itself rather than only by attaching one to an activity post. It posts to
 * WPMediaVerse Pro's own document endpoint (mvs-pro/v1/documents/upload, the same
 * one the composer uses), passing `drive` = "type:id", the current `folder`, and
 * the drive's default `privacy`.
 *
 * Each file row the viewer may remove carries a Remove button that DELETEs the
 * document (mvs-pro/v1/documents/{id}) — the engine trashes it with a 30-day
 * restore, so this is reversible. The button is only rendered where the engine's
 * delete gate (author or documents admin) would pass, and the engine re-checks.
 *
 * On success either action reloads the tab so the server-rendered list is fresh —
 * the list has no partial-refresh route, and a reload is honest + correct for a
 * page that is server-rendered per URL.
 */

import { onNavReady } from '@buddynext/nav-init';
import { bnConfirm, bnToast } from '@buddynext/shell-dialog';

function parseStrings( root ) {
	try {
		return JSON.parse( root.getAttribute( 'data-bn-strings' ) || '{}' );
	} catch ( e ) {
		return {};
	}
}

function bind( root ) {
	if ( root._bnFileUploadBound ) {
		return;
	}
	root._bnFileUploadBound = true;

	const url     = root.getAttribute( 'data-bn-url' ) || '';
	const drive   = root.getAttribute( 'data-bn-drive' ) || '';
	const folder  = parseInt( root.getAttribute( 'data-bn-folder' ), 10 ) || 0;
	const privacy = root.getAttribute( 'data-bn-privacy' ) || '';
	const nonce   = root.getAttribute( 'data-bn-nonce' ) || '';
	const maxSize = parseInt( root.getAttribute( 'data-bn-max' ), 10 ) || 0;
	const t       = parseStrings( root );

	const input   = root.querySelector( '[data-bn-file-upload-input]' );
	const trigger = root.querySelector( '[data-bn-file-upload-trigger]' );
	const status  = root.querySelector( '[data-bn-file-upload-status]' );

	if ( ! url || ! input ) {
		return;
	}

	function setStatus( msg ) {
		if ( ! status ) {
			return;
		}
		status.textContent = msg || '';
		status.hidden = ! msg;
	}

	async function uploadOne( file ) {
		if ( maxSize > 0 && file.size > maxSize ) {
			if ( typeof bnToast === 'function' ) {
				bnToast( t.tooBig || 'That file is over the allowed size.', { tone: 'info' } );
			}
			return false;
		}
		const fd = new FormData();
		fd.append( 'file', file );
		if ( drive ) {
			fd.append( 'drive', drive );
		}
		if ( folder > 0 ) {
			fd.append( 'folder', String( folder ) );
		}
		if ( privacy ) {
			fd.append( 'privacy', privacy );
		}
		try {
			const res  = await fetch( url, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'X-WP-Nonce': nonce },
				body:        fd,
			} );
			const data = await res.json().catch( () => ( {} ) );
			return !! ( res.ok && data && data.id );
		} catch ( e ) {
			return false;
		}
	}

	async function handleFiles( files ) {
		const list = Array.prototype.slice.call( files || [] );
		if ( ! list.length ) {
			return;
		}
		root.classList.add( 'is-uploading' );
		setStatus( t.uploading || 'Uploading…' );

		let ok = 0;
		for ( let i = 0; i < list.length; i++ ) {
			// Sequential: the document endpoint is a heavy multipart write and the
			// list reloads once at the end anyway, so a burst buys nothing.
			// eslint-disable-next-line no-await-in-loop
			if ( await uploadOne( list[ i ] ) ) {
				ok++;
			}
		}

		root.classList.remove( 'is-uploading' );
		if ( ok > 0 ) {
			if ( typeof bnToast === 'function' ) {
				bnToast( t.done || 'Uploaded.', { tone: 'success' } );
			}
			window.location.reload();
		} else {
			setStatus( '' );
			if ( typeof bnToast === 'function' ) {
				bnToast( t.fail || 'That file could not be uploaded.', { tone: 'error' } );
			}
		}
	}

	if ( trigger ) {
		trigger.addEventListener( 'click', function () {
			input.click();
		} );
	}
	input.addEventListener( 'change', function () {
		handleFiles( input.files );
		input.value = '';
	} );

	[ 'dragover', 'dragenter' ].forEach( function ( ev ) {
		root.addEventListener( ev, function ( e ) {
			e.preventDefault();
			root.classList.add( 'is-drag' );
		} );
	} );
	[ 'dragleave', 'dragend' ].forEach( function ( ev ) {
		root.addEventListener( ev, function () {
			root.classList.remove( 'is-drag' );
		} );
	} );
	root.addEventListener( 'drop', function ( e ) {
		e.preventDefault();
		root.classList.remove( 'is-drag' );
		if ( e.dataTransfer ) {
			handleFiles( e.dataTransfer.files );
		}
	} );
}

/**
 * Wire the Remove buttons under one Files-tab list. The delete endpoint base,
 * nonce and copy live once on the list root; each button carries only its id.
 *
 * @param {HTMLElement} root The `[data-bn-files-actions]` list container.
 */
function bindActions( root ) {
	if ( root._bnFilesActionsBound ) {
		return;
	}
	root._bnFilesActionsBound = true;

	const base  = root.getAttribute( 'data-bn-del-base' ) || '';
	const nonce = root.getAttribute( 'data-bn-nonce' ) || '';
	const t     = parseStrings( root );
	if ( ! base ) {
		return;
	}

	root.querySelectorAll( '[data-bn-file-delete]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', async function () {
			const id = parseInt( btn.getAttribute( 'data-bn-id' ), 10 ) || 0;
			if ( ! id ) {
				return;
			}

			const ok = await bnConfirm( {
				title:        t.confirmTitle || 'Remove this file?',
				body:         t.confirmBody || '',
				confirmLabel: t.confirm || 'Remove',
				cancelLabel:  t.cancel || 'Cancel',
				tone:         'danger',
			} );
			if ( ! ok ) {
				return;
			}

			btn.disabled = true;
			try {
				const res = await fetch( base + id, {
					method:      'DELETE',
					credentials: 'same-origin',
					headers:     { 'X-WP-Nonce': nonce },
				} );
				if ( res.ok ) {
					if ( typeof bnToast === 'function' ) {
						bnToast( t.done || 'File removed.', { tone: 'success' } );
					}
					window.location.reload();
					return;
				}
			} catch ( e ) {
				// Falls through to the failure toast below.
			}

			btn.disabled = false;
			if ( typeof bnToast === 'function' ) {
				bnToast( t.fail || 'That file could not be removed.', { tone: 'error' } );
			}
		} );
	} );
}

onNavReady( function () {
	document.querySelectorAll( '[data-bn-file-upload]' ).forEach( bind );
	document.querySelectorAll( '[data-bn-files-actions]' ).forEach( bindActions );
} );
