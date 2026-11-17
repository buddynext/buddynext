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

	// What sanitize_title() folds each accented letter to on this site, printed by
	// AssetService::slug_fold_map() (the same table the @buddynext/slug module uses).
	var fold = window.bnSlugFold || {};

	function slugify( value ) {
		return value
			.replace( /[^\x00-\x7f]/g, function ( c ) { return fold[ c ] || c; } )
			.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-|-$/g, '' )
			// The slug fields' maxlength; cut at a word boundary where possible.
			.slice( 0, 64 )
			.replace( /-+$/, '' );
	}

	/**
	 * Relative luminance of a hex colour, or null when it is not one.
	 *
	 * @param {string} hex Colour (#rgb or #rrggbb).
	 * @return {?number} Luminance 0-1.
	 */
	function luminance( hex ) {
		var normalized = String( hex || '' ).trim().replace( '#', '' );
		if ( 3 === normalized.length ) {
			normalized = normalized
				.split( '' )
				.map( function ( c ) {
					return c + c;
				} )
				.join( '' );
		}
		if ( ! /^[0-9a-fA-F]{6}$/.test( normalized ) ) {
			return null;
		}

		function channel( offset ) {
			var c = parseInt( normalized.substr( offset, 2 ), 16 ) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * channel( 0 ) + 0.7152 * channel( 2 ) + 0.0722 * channel( 4 );
	}

	/**
	 * The text colour members see on a badge: the saved one when it reads well
	 * (4.5:1), else black or white. Mirrors buddynext_readable_text_color() in
	 * PHP so the admin preview matches the member card, profile and directory.
	 *
	 * @param {string} bg        Background colour.
	 * @param {string} preferred Saved text colour, or ''.
	 * @return {string} Hex text colour.
	 */
	function readableTextColor( bg, preferred ) {
		var bgLum = luminance( bg );
		if ( null === bgLum ) {
			return preferred || '#ffffff';
		}
		function ratio( a, b ) {
			return ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
		}
		var wanted = preferred ? luminance( preferred ) : null;
		if ( null !== wanted && ratio( wanted, bgLum ) >= 4.5 ) {
			return preferred;
		}
		return ratio( 1, bgLum ) >= ratio( 0, bgLum ) ? '#ffffff' : '#000000';
	}

	function initEditor( form ) {
		var nameInput = form.querySelector( '[data-bn-tax-name]' );
		var slugInput = form.querySelector( '[data-bn-tax-slug]' );
		var colorInput = form.querySelector( '[data-bn-tax-color]' );
		var textColorInput = form.querySelector( '[data-bn-tax-text-color]' );
		var textSwatch = form.querySelector( '[data-bn-tax-text-swatch]' );
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
				preview.style.color = readableTextColor( bg, fg );
			} else if ( fg !== '' ) {
				preview.style.color = fg;
			}

			// Keep the (unpersisted) swatch showing the EFFECTIVE text colour:
			// the custom value when set, else the auto-derived contrast colour.
			// A native colour input only accepts a full 6-digit hex.
			if ( textSwatch ) {
				var effective = fg !== '' ? fg : ( bg ? readableTextColor( bg, '' ) : '#ffffff' );
				if ( /^#[0-9a-fA-F]{6}$/.test( effective ) ) {
					textSwatch.value = effective;
				}
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
		// Picking from the swatch writes a custom hex into the field (the
		// persisted source of truth); the user can still clear it for auto.
		if ( textSwatch && textColorInput ) {
			textSwatch.addEventListener( 'input', function () {
				textColorInput.value = textSwatch.value;
				updatePreview();
			} );
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
