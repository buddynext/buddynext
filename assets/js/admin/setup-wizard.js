/**
 * BuddyNext setup wizard — interactive helpers.
 *
 * Keeps each .bn-wizard__option row's visual selected-state in sync with its
 * underlying input (so unchecked rows fade and selected rows highlight),
 * wires Select all / Clear all on the profile-fields step, and updates the
 * brand-colour value chip live.
 *
 * No build step. No framework. Plain DOM, runs once on DOMContentLoaded.
 */
( function () {
	'use strict';

	function syncOption( input ) {
		var option = input.closest( '.bn-wizard__option' );
		if ( ! option ) {
			return;
		}

		if ( 'radio' === input.type ) {
			// Update every option in the same radio group, not just this one.
			var name = input.name;
			document.querySelectorAll( 'input[type="radio"][name="' + name + '"]' ).forEach( function ( other ) {
				var row = other.closest( '.bn-wizard__option' );
				if ( row ) {
					row.dataset.selected = other.checked ? 'true' : 'false';
				}
			} );
			return;
		}

		option.dataset.selected = input.checked ? 'true' : 'false';
	}

	function bindOptions() {
		document.querySelectorAll( '.bn-wizard__option-input' ).forEach( function ( input ) {
			syncOption( input );
			input.addEventListener( 'change', function () {
				syncOption( input );
			} );
		} );
	}

	function bindBulk() {
		document.querySelectorAll( '.bn-wizard__bulk-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var target = btn.dataset.bulk; // 'all' | 'none'
				var list   = btn.closest( '.bn-wizard__form' );
				if ( ! list ) {
					return;
				}
				list.querySelectorAll( '.bn-wizard__options[data-variant="check"] input[type="checkbox"]' ).forEach( function ( input ) {
					input.checked = 'all' === target;
					syncOption( input );
				} );
			} );
		} );
	}

	function bindColorPicker() {
		var picker = document.querySelector( '.bn-wizard__swatch-input' );
		if ( ! picker ) {
			return;
		}
		var value  = document.querySelector( '.bn-wizard__swatch-value' );
		var swatch = picker.closest( '.bn-wizard__swatch' );

		function paint() {
			if ( swatch ) {
				swatch.style.setProperty( '--bn-wiz-color', picker.value );
			}
			if ( value ) {
				value.textContent = picker.value.toUpperCase();
			}
		}

		picker.addEventListener( 'input', paint );
		picker.addEventListener( 'change', paint );
	}

	function init() {
		bindOptions();
		bindBulk();
		bindColorPicker();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
