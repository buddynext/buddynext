/**
 * BuddyNext — shared admin media picker.
 *
 * Wires every [data-bn-media-field] row rendered by
 * AdminPageBase::render_media_row(): the Select button opens the WordPress
 * media library, the chosen image URL is written into the row's URL input
 * (the value the form posts), and the preview + Remove button follow the
 * input. Without wp.media (or without JS) the URL input still works as a
 * plain field, so the row degrades gracefully.
 *
 * Consumers: Settings → Appearance logo (free) and the Pro White-label logo —
 * both enqueue the 'bn-admin-media' handle plus wp_enqueue_media().
 *
 * @package BuddyNext
 * @since   1.0.4
 */

( function () {
	'use strict';

	var wpI18n = ( window.wp && window.wp.i18n ) || {};
	var __ = wpI18n.__ || function ( s ) { return s; };

	function initField( field ) {
		var input = field.querySelector( '.bn-media-url' );
		var preview = field.querySelector( '[data-bn-media-preview]' );
		var img = preview ? preview.querySelector( 'img' ) : null;
		var selectBtn = field.querySelector( '[data-bn-media-select]' );
		var removeBtn = field.querySelector( '[data-bn-media-remove]' );
		var frame = null;

		if ( ! input || ! selectBtn ) {
			return;
		}

		function sync() {
			var url = input.value.trim();
			if ( img ) {
				img.src = url;
			}
			if ( preview ) {
				preview.hidden = ( '' === url );
			}
			if ( removeBtn ) {
				removeBtn.hidden = ( '' === url );
			}
		}

		selectBtn.addEventListener( 'click', function () {
			// No media frame available (script blocked, subscriber screen):
			// fall back to the plain URL input.
			if ( ! window.wp || ! window.wp.media ) {
				input.focus();
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( {
					title: selectBtn.dataset.title || __( 'Select image', 'buddynext' ),
					library: { type: 'image' },
					multiple: false,
					button: { text: __( 'Use this image', 'buddynext' ) }
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first();
					if ( attachment ) {
						input.value = attachment.get( 'url' ) || '';
						sync();
					}
				} );
			}
			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				input.value = '';
				sync();
				selectBtn.focus();
			} );
		}

		input.addEventListener( 'change', sync );
		input.addEventListener( 'input', sync );
	}

	function init() {
		document.querySelectorAll( '[data-bn-media-field]' ).forEach( initField );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
