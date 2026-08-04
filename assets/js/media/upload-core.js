/**
 * Shared media-upload core — the single upload path for every BuddyNext surface
 * (profile Media tab, feed composer, and later spaces). Centralising it keeps the
 * upload flow uniform: one BuddyNext-owned, owner-gated endpoint
 * (buddynext/v1/me/media → engine services, never the engine REST directly), one
 * client-side validation rule, one fast small-thumbnail generator, one delete.
 *
 * Each composer keeps its own UI shell and orchestration (the Media tab stages
 * then uploads a batch; the feed composer eager-uploads on select) but they all
 * call these helpers so behaviour, gating and performance stay identical.
 */

import { restFetch } from '@buddynext/rest-client';

/**
 * Validate a File against the shared media limits.
 *
 * @param {File}   file File to check.
 * @param {Object} opts { maxSizeMB, badTypeMsg, tooLargeMsg }.
 * @return {string} '' when valid, else a human-readable error.
 */
export function validateMedia( file, opts = {} ) {
	const maxBytes = ( Number( opts.maxSizeMB ) || 64 ) * 1024 * 1024;
	if ( ! /^(image|video|audio)\//.test( file.type || '' ) ) {
		return opts.badTypeMsg || 'Only images, video and audio can be uploaded.';
	}
	if ( file.size > maxBytes ) {
		return opts.tooLargeMsg || 'File is larger than the allowed size.';
	}
	return '';
}

/**
 * Build a SMALL (max 256px) preview thumbnail off the critical path. Using a
 * file's full-resolution image as the preview makes the browser decode a
 * multi-megapixel bitmap (tens of MB) just to paint a tiny tile — slow and
 * memory-heavy for several files at once. createImageBitmap decodes-and-
 * downscales in one efficient step; we keep only a tiny data URL. Falls back to
 * an object URL where createImageBitmap is unavailable. Returns '' for non-images.
 *
 * @param {File} file Image file.
 * @return {Promise<string>} A small preview URL (data: or blob:), or ''.
 */
export async function makeThumb( file ) {
	if ( ! /^image\//.test( file.type || '' ) ) {
		return '';
	}
	try {
		if ( typeof createImageBitmap === 'function' ) {
			const bmp = await createImageBitmap( file, { resizeWidth: 256, resizeQuality: 'low' } );
			const canvas = document.createElement( 'canvas' );
			canvas.width = bmp.width;
			canvas.height = bmp.height;
			canvas.getContext( '2d' ).drawImage( bmp, 0, 0 );
			if ( bmp.close ) {
				bmp.close();
			}
			return canvas.toDataURL( 'image/jpeg', 0.7 );
		}
	} catch ( e ) {
		/* fall back to a full-res object URL below */
	}
	try {
		return URL.createObjectURL( file );
	} catch ( e ) {
		return '';
	}
}

/**
 * The broad media kind of a File — 'image' | 'video' | 'audio' | 'file'.
 *
 * One classifier so every surface labels and previews a file the same way,
 * instead of each composer re-deriving it from file.type inconsistently.
 *
 * @param {File} file File to classify.
 * @return {string} 'image' | 'video' | 'audio' | 'file'.
 */
export function mediaKind( file ) {
	const top = ( ( file && file.type ) || '' ).split( '/' )[ 0 ];
	return ( 'image' === top || 'video' === top || 'audio' === top ) ? top : 'file';
}

/**
 * Capture a still poster frame from a video File, client-side.
 *
 * makeThumb() only handles images; a video handed to an <img> renders as a broken
 * tile. This mirrors the proven WPMediaVerse capture: load metadata muted, seek a
 * touch past the start (first frames are often black), draw one frame to a canvas
 * and return a small JPEG data URL. Returns '' on any failure so the caller can
 * fall back to a play-icon placeholder rather than a broken image.
 *
 * @param {File} file Video file.
 * @return {Promise<string>} A data: URL poster, or '' if a frame can't be grabbed.
 */
export function makeVideoThumb( file ) {
	return new Promise( ( resolve ) => {
		if ( ! /^video\//.test( ( file && file.type ) || '' ) ) {
			resolve( '' );
			return;
		}
		let url = '';
		let done = false;
		const finish = ( value ) => {
			if ( done ) { return; }
			done = true;
			if ( url ) { try { URL.revokeObjectURL( url ); } catch ( e ) {} }
			resolve( value );
		};
		try {
			url = URL.createObjectURL( file );
		} catch ( e ) {
			finish( '' );
			return;
		}
		const video = document.createElement( 'video' );
		video.preload = 'metadata';
		video.muted = true;
		video.playsInline = true;
		video.src = url;
		// A stuck/unsupported file must not leave the tile spinning forever.
		const guard = setTimeout( () => finish( '' ), 5000 );
		video.addEventListener( 'loadeddata', () => {
			try {
				video.currentTime = Math.min( 1, ( video.duration || 0 ) * 0.1 );
			} catch ( e ) {
				clearTimeout( guard );
				finish( '' );
			}
		} );
		video.addEventListener( 'seeked', () => {
			clearTimeout( guard );
			try {
				const canvas = document.createElement( 'canvas' );
				canvas.width = video.videoWidth || 320;
				canvas.height = video.videoHeight || 180;
				canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );
				finish( canvas.toDataURL( 'image/jpeg', 0.85 ) );
			} catch ( e ) {
				finish( '' );
			}
		} );
		video.addEventListener( 'error', () => {
			clearTimeout( guard );
			finish( '' );
		} );
	} );
}

/**
 * A small preview poster for any media File: an image thumbnail, a captured video
 * frame, or '' for audio/other (the caller renders a kind icon for those).
 *
 * The one entry point every upload surface should call so images and videos both
 * get a real visual preview and nothing ever renders a broken <img>.
 *
 * @param {File} file File to preview.
 * @return {Promise<string>} data:/blob: URL, or '' when there is no visual frame.
 */
export async function mediaPreview( file ) {
	const kind = mediaKind( file );
	if ( 'image' === kind ) {
		return makeThumb( file );
	}
	if ( 'video' === kind ) {
		return makeVideoThumb( file );
	}
	return '';
}

/**
 * Convert a `data:` URL (e.g. a captured video frame) into a Blob for upload.
 *
 * @param {string} dataUrl A data: URL.
 * @return {Blob|null} The decoded Blob, or null when the input isn't a data URL.
 */
function dataUrlToBlob( dataUrl ) {
	if ( ! dataUrl || 0 !== String( dataUrl ).indexOf( 'data:' ) ) {
		return null;
	}
	try {
		const parts     = String( dataUrl ).split( ',' );
		const mimeMatch = parts[ 0 ].match( /:(.*?);/ );
		const mime      = mimeMatch ? mimeMatch[ 1 ] : 'image/jpeg';
		const binary    = atob( parts[ 1 ] );
		const bytes     = new Uint8Array( binary.length );
		for ( let i = 0; i < binary.length; i++ ) {
			bytes[ i ] = binary.charCodeAt( i );
		}
		return new Blob( [ bytes ], { type: mime } );
	} catch ( e ) {
		return null;
	}
}

/**
 * Upload one file through the BuddyNext endpoint (engine services server-side).
 * Always posts as the current member's own media; owner-gated server-side.
 *
 * @param {File}   file File to upload.
 * @param {Object} opts { nonce, privacy, title }.
 * @return {Promise<Object>} { ok, mediaId, thumb, url, type, duplicate, status, message }.
 */
export async function uploadMedia( file, opts = {} ) {
	const fd = new FormData();
	fd.append( 'file', file, file.name );
	if ( opts.privacy ) {
		fd.append( 'privacy', opts.privacy );
	}
	if ( opts.title ) {
		fd.append( 'title', opts.title );
	}
	// A client-captured video poster (data URL) rides along as a `thumbnail` file so
	// the server can stage it for videos the engine can't extract an embedded frame
	// from — otherwise the feed shows the default poster while the composer showed
	// the real frame. Only for video; images/audio never send one.
	if ( opts.thumbnail && /^video\//.test( file.type || '' ) ) {
		const posterBlob = dataUrlToBlob( opts.thumbnail );
		if ( posterBlob ) {
			fd.append( 'thumbnail', posterBlob, 'poster.jpg' );
		}
	}

	let res;
	try {
		res = await restFetch( '/me/media', {
			method:       'POST',
			nonce:        opts.nonce,
			body:         fd,
			toastOnError: false,
		} );
	} catch ( e ) {
		return { ok: false, status: 0, message: '' };
	}

	const media = res && res.ok && res.data ? res.data.media : null;
	if ( media ) {
		return {
			ok:        true,
			mediaId:   Number( media.id ) || 0,
			thumb:     media.thumb || '',
			url:       media.url || '',
			type:      media.type || '',
			duplicate: !! ( res.data && res.data.duplicate_warning ),
		};
	}
	return {
		ok:      false,
		status:  res ? res.status : 0,
		message: res && res.data ? res.data.message : '',
	};
}

/**
 * Best-effort delete of an uploaded media item (e.g. a staged-but-unposted file
 * removed from a composer). Owner-gated server-side; failures never block the UI.
 *
 * @param {number} mediaId Media id.
 * @param {string} nonce   REST nonce.
 * @return {Promise<boolean>} True when the server confirmed removal.
 */
export async function deleteMedia( mediaId, nonce ) {
	if ( ! mediaId ) {
		return false;
	}
	try {
		const res = await restFetch( '/me/media/' + mediaId, {
			method:       'DELETE',
			nonce,
			toastOnError: false,
		} );
		return !! ( res && res.ok );
	} catch ( e ) {
		return false;
	}
}
