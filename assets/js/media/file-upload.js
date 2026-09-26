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
 * Each file row the viewer may remove carries a Remove button whose meaning
 * follows the drive: on a SPACE drive it UNLINKS the file (POST to BuddyNext's
 * .../media/{id}/unlink — the file leaves the space and returns to its owner's
 * own Files, not deleted), and on the owner's PERSONAL drive it DELETEs the
 * document (mvs-pro/v1/documents/{id} — trashed with a 30-day restore). The
 * button is only drawn where that action's gate would pass, and the server
 * re-checks. The action + endpoint are declared once on the list root.
 *
 * On success either action reloads the tab so the server-rendered list is fresh —
 * the list has no partial-refresh route, and a reload is honest + correct for a
 * page that is server-rendered per URL.
 */

import { onNavReady } from '@buddynext/nav-init';
import { bnConfirm, bnPrompt, bnReloadWithToast, bnToast } from '@buddynext/shell-dialog';

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
			bnReloadWithToast( t.done || 'Uploaded.', { tone: 'success' } );
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

	// One action per tab: 'unlink' on a space drive (the file leaves the space and
	// returns to its owner's Files — a POST to .../{id}/unlink), or 'delete' on the
	// owner's own drive (trash the document — a DELETE to .../{id}).
	const rootAction = root.getAttribute( 'data-bn-action' ) || 'delete';
	const endpoint   = root.getAttribute( 'data-bn-endpoint' ) || '';
	const nonce      = root.getAttribute( 'data-bn-nonce' ) || '';
	const rootT      = parseStrings( root );
	if ( ! endpoint ) {
		return;
	}

	root.querySelectorAll( '[data-bn-file-remove]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', async function () {
			const id = parseInt( btn.getAttribute( 'data-bn-id' ), 10 ) || 0;
			if ( ! id ) {
				return;
			}

			// A LINKED file overrides the tab-wide action on its own button: it is
			// removed by dropping the space link (DELETE its `data-bn-detach` URL),
			// not by re-homing. Everything else falls back to the tab default.
			const action = btn.getAttribute( 'data-bn-action' ) || rootAction;
			const t      = btn.getAttribute( 'data-bn-strings' ) ? parseStrings( btn ) : rootT;

			let url;
			let method;
			if ( 'unlink-space' === action ) {
				url    = btn.getAttribute( 'data-bn-detach' ) || '';
				method = 'DELETE';
			} else if ( 'unlink' === action ) {
				url    = endpoint + id + '/unlink';
				method = 'POST';
			} else {
				url    = endpoint + id;
				method = 'DELETE';
			}
			if ( ! url ) {
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
				const res = await fetch( url, {
					method,
					credentials: 'same-origin',
					headers:     { 'X-WP-Nonce': nonce },
				} );
				if ( res.ok ) {
					bnReloadWithToast( t.done || 'File removed.', { tone: 'success' } );
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

/**
 * Wire the "Link a file" control: paste a file link, POST it to the ref-based
 * attach route, reload on success. Any member who may contribute to the space
 * sees this; the server decides whether this particular file may be linked and
 * returns a specific message when it may not.
 *
 * @param {HTMLElement} box The `[data-bn-file-link]` popover container.
 */
function bindLink( box ) {
	if ( box._bnFileLinkBound ) {
		return;
	}
	box._bnFileLinkBound = true;

	const url     = box.getAttribute( 'data-bn-url' ) || '';
	const spaceId = parseInt( box.getAttribute( 'data-bn-space' ), 10 ) || 0;
	const nonce   = box.getAttribute( 'data-bn-nonce' ) || '';
	const t       = parseStrings( box );
	const input   = box.querySelector( '[data-bn-file-link-input]' );
	const submit  = box.querySelector( '[data-bn-file-link-submit]' );
	const status  = box.querySelector( '[data-bn-file-link-status]' );
	if ( ! url || ! spaceId || ! input || ! submit ) {
		return;
	}

	const say = function ( msg, isError ) {
		if ( ! status ) {
			return;
		}
		status.textContent = msg;
		status.hidden = ! msg;
		status.classList.toggle( 'is-error', !! isError );
	};

	const run = async function () {
		let ref = ( input.value || '' ).trim();
		// A member copies the address BuddyNext shows them - a Files page
		// (/files/{id}/) or an embed (?bn_doc={id}) - which MediaVerse does not
		// know. Hand it the id instead.
		const own = ref.match( /\/files\/(\d+)\/?(?:[?#].*)?$/ ) || ref.match( /[?&]bn_doc=(\d+)/ );
		if ( own ) {
			ref = own[ 1 ];
		}
		if ( ! ref ) {
			say( t.empty || 'Paste a file link first.', true );
			return;
		}

		submit.disabled = true;
		say( t.linking || 'Linking…', false );
		try {
			const res = await fetch( url, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body:        JSON.stringify( { ref, space_id: spaceId } ),
			} );
			if ( res.ok ) {
				bnReloadWithToast( t.done || 'File linked to this space.', { tone: 'success' } );
				return;
			}
			// A wrong or unreachable file gets the server's specific reason
			// (not this file, not yours, already here), so the member can fix it.
			let msg = t.fail || 'That file could not be linked.';
			try {
				const data = await res.json();
				if ( data && data.message ) {
					msg = data.message;
				}
			} catch ( e ) {}
			say( msg, true );
		} catch ( e ) {
			say( t.fail || 'That file could not be linked.', true );
		}
		submit.disabled = false;
	};

	submit.addEventListener( 'click', run );
	input.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			run();
		}
	} );

	// The panel is a <details> popover over the file list. Like any dropdown it
	// closes on Escape (focus back to its toggle) and on a click outside it,
	// otherwise it sits over the rows and swallows their Rename / Trash clicks.
	const details = box.closest( 'details' );
	if ( details ) {
		details.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && details.open ) {
				details.open = false;
				details.querySelector( 'summary' )?.focus();
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( details.open && ! details.contains( e.target ) ) {
				details.open = false;
			}
		} );
	}
}

/**
 * Wire the folder controls a drive's managers see: New folder, Rename and Move to
 * trash on the Files list, Restore in its Trash view. All call MediaVerse's folder
 * REST routes; the list reloads on success. Endpoint, drive, parent folder, nonce
 * and copy live once on the `[data-bn-folder-manage]` root.
 *
 * @param {HTMLElement} root The Files root.
 */
function bindFolders( root ) {
	if ( root._bnFoldersBound ) {
		return;
	}
	root._bnFoldersBound = true;

	const base  = ( root.getAttribute( 'data-bn-folder-endpoint' ) || '' ).replace( /\/$/, '' );
	const nonce = root.getAttribute( 'data-bn-nonce' ) || '';
	let t       = {};
	try {
		t = JSON.parse( root.getAttribute( 'data-bn-folder-strings' ) || '{}' );
	} catch ( e ) {
		t = {};
	}
	const fmt = ( tpl, ...vals ) => { let i = 0; return String( tpl || '' ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( vals[ pos ? pos - 1 : i++ ] ?? '' ) ); };

	async function call( path, method, body, done, btn ) {
		if ( btn ) { btn.disabled = true; }
		try {
			const res = await fetch( base + path, {
				method,
				credentials: 'same-origin',
				headers:     Object.assign( { 'X-WP-Nonce': nonce }, body ? { 'Content-Type': 'application/json' } : {} ),
				body:        body ? JSON.stringify( body ) : undefined,
			} );
			if ( res.ok ) {
				bnReloadWithToast( done, { tone: 'success' } );
				return;
			}
			const data = await res.json().catch( () => ( {} ) );
			bnToast( data.message || t.failed || 'That did not work.', { tone: 'error' } );
		} catch ( e ) {
			bnToast( t.failed || 'That did not work.', { tone: 'error' } );
		}
		if ( btn ) { btn.disabled = false; }
	}

	async function askName( title, confirmLabel, current ) {
		const name = await bnPrompt( {
			title,
			confirmLabel,
			cancelLabel:  t.cancel,
			inputType:    'text',
			placeholder:  t.placeholder,
			defaultValue: current || '',
			validate:     ( value ) => ( '' === value.trim() ? ( t.nameRequired || 'Enter a folder name.' ) : '' ),
		} );
		return null === name ? null : name.trim();
	}

	root.querySelectorAll( '[data-bn-folder-new]' ).forEach( ( btn ) => btn.addEventListener( 'click', async () => {
		const name = await askName( t.newTitle, t.newConfirm );
		if ( name ) {
			const parent = parseInt( root.getAttribute( 'data-bn-parent' ) || '0', 10 ) || 0;
			call( '', 'POST', { name, drive: root.getAttribute( 'data-bn-drive' ), parent }, t.created, btn );
		}
	} ) );

	root.querySelectorAll( '[data-bn-folder-rename]' ).forEach( ( btn ) => btn.addEventListener( 'click', async () => {
		const current = btn.getAttribute( 'data-bn-name' ) || '';
		const name    = await askName( t.renameTitle, t.renameOk, current );
		if ( name && name !== current ) {
			call( '/' + btn.getAttribute( 'data-bn-id' ), 'PATCH', { name }, t.renamed, btn );
		}
	} ) );

	root.querySelectorAll( '[data-bn-folder-delete]' ).forEach( ( btn ) => btn.addEventListener( 'click', async () => {
		const files   = parseInt( btn.getAttribute( 'data-bn-files' ) || '0', 10 ) || 0;
		const folders = parseInt( btn.getAttribute( 'data-bn-folders' ) || '0', 10 ) || 0;
		const parts   = [];
		if ( files ) { parts.push( fmt( 1 === files ? t.fileOne : t.fileMany, files ) ); }
		if ( folders ) { parts.push( fmt( 1 === folders ? t.folderOne : t.folderMany, folders ) ); }
		const holds = 2 === parts.length ? fmt( t.andJoin, parts[ 0 ], parts[ 1 ] ) : ( parts[ 0 ] || '' );
		const ok    = await bnConfirm( {
			title:        fmt( t.trashTitle, btn.getAttribute( 'data-bn-name' ) || '' ),
			body:         holds ? fmt( t.trashBody, holds ) : t.trashEmpty,
			confirmLabel: t.trashConfirm,
			cancelLabel:  t.cancel,
			tone:         'danger',
		} );
		if ( ok ) {
			call( '/' + btn.getAttribute( 'data-bn-id' ), 'DELETE', null, t.trashed, btn );
		}
	} ) );

	root.querySelectorAll( '[data-bn-folder-restore]' ).forEach( ( btn ) => btn.addEventListener( 'click', () => {
		call( '/' + btn.getAttribute( 'data-bn-id' ) + '/restore', 'POST', null, t.restored, btn );
	} ) );
}

onNavReady( function () {
	document.querySelectorAll( '[data-bn-folder-manage]' ).forEach( bindFolders );
	document.querySelectorAll( '[data-bn-file-upload]' ).forEach( bind );
	document.querySelectorAll( '[data-bn-files-actions]' ).forEach( bindActions );
	document.querySelectorAll( '[data-bn-file-link]' ).forEach( bindLink );
} );
