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

import { restFetch } from '../shell/rest-client.js';

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
