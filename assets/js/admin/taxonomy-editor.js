/**
 * BuddyNext — shared taxonomy editor live behaviour.
 *
 * Powers templates/parts/taxonomy-editor.php for both Member Types and Space
 * Categories. Vanilla JS, no jQuery:
 *   - Auto-fills the slug from the name (only until the user edits the slug).
 *   - Live-updates the badge preview from name + colour + text colour.
 *   - When the text colour is blank, derives a readable colour (light/dark)
 *     from the background using relative luminance.
 *
 * Enqueued by AssetService on the Members + Spaces admin screens.
 *
 * @package BuddyNext
 */
( function () {
	'use strict';

	function slugify( value ) {
		return value
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-|-$/g, '' );
	}

	/**
	 * Pick black or white text for a given background hex for readable contrast.
	 *
	 * @param {string} hex Background colour (e.g. "#0073aa").
	 * @return {string} "#ffffff" or "#1a1a1a".
	 */
	function readableTextColor( hex ) {
		var normalized = hex.replace( '#', '' );
		if ( 3 === normalized.length ) {
			normalized = normalized
				.split( '' )
				.map( function ( c ) {
					return c + c;
				} )
				.join( '' );
		}
		if ( 6 !== normalized.length ) {
			return '#ffffff';
		}
		var r = parseInt( normalized.substr( 0, 2 ), 16 ) / 255;
		var g = parseInt( normalized.substr( 2, 2 ), 16 ) / 255;
		var b = parseInt( normalized.substr( 4, 2 ), 16 ) / 255;

		function channel( c ) {
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		}

		var luminance = 0.2126 * channel( r ) + 0.7152 * channel( g ) + 0.0722 * channel( b );
		return luminance > 0.5 ? '#1a1a1a' : '#ffffff';
	}

	function initEditor( form ) {
		var nameInput = form.querySelector( '[data-bn-tax-name]' );
		var slugInput = form.querySelector( '[data-bn-tax-slug]' );
		var colorInput = form.querySelector( '[data-bn-tax-color]' );
		var textColorInput = form.querySelector( '[data-bn-tax-text-color]' );
		var preview = form.querySelector( '[data-bn-tax-preview]' );
		var previewLabel = form.querySelector( '[data-bn-tax-preview-label]' );

		// Track whether the user has manually touched the slug. Pre-filled
		// (edit-mode) slugs count as "touched" so we never clobber them.
		var slugTouched = !! ( slugInput && slugInput.value );
		if ( slugInput ) {
			slugInput.addEventListener( 'input', function () {
				slugTouched = '' !== slugInput.value;
			} );
		}

		function syncSlug() {
			if ( ! slugInput || ! nameInput || slugTouched ) {
				return;
			}
			slugInput.value = slugify( nameInput.value );
		}

		function updatePreview() {
			if ( ! preview ) {
				return;
			}
			var bg = colorInput ? colorInput.value : '';
			var fg = textColorInput ? textColorInput.value.trim() : '';

			if ( bg ) {
				preview.style.background = bg;
				preview.style.color = fg !== '' ? fg : readableTextColor( bg );
			} else if ( fg !== '' ) {
				preview.style.color = fg;
			}

			if ( previewLabel && nameInput ) {
				previewLabel.textContent = nameInput.value || previewLabel.dataset.bnPreviewFallback || previewLabel.textContent;
			}
		}

		// Remember the server-rendered fallback label so an emptied name input
		// falls back to "Preview" rather than going blank.
		if ( previewLabel ) {
			previewLabel.dataset.bnPreviewFallback = previewLabel.textContent;
		}

		if ( nameInput ) {
			nameInput.addEventListener( 'input', function () {
				syncSlug();
				updatePreview();
			} );
		}
		if ( colorInput ) {
			colorInput.addEventListener( 'input', updatePreview );
		}
		if ( textColorInput ) {
			textColorInput.addEventListener( 'input', updatePreview );
		}

		updatePreview();
	}

	function init() {
		var forms = document.querySelectorAll( '[data-bn-tax-editor]' );
		Array.prototype.forEach.call( forms, initEditor );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
