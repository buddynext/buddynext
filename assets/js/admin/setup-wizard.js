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

	// Companion step: every not-yet-active companion is pre-checked. Clicking
	// Continue installs + activates each checked one (sequentially, with per-row
	// progress) before advancing the wizard, so a "next-next-next" owner ends up
	// with the full community stack. Failures never block onboarding — they are
	// surfaced per row and the wizard still advances.
	function bindCompanions() {
		var list = document.querySelector( '[data-bn-companions-setup]' );
		if ( ! list ) {
			return;
		}
		var form = list.closest( 'form' );
		var btn  = form ? form.querySelector( '[name="wizard_action"][value="continue"]' ) : null;
		if ( ! form || ! btn ) {
			return;
		}
		var endpoint = list.getAttribute( 'data-rest' );
		var nonce    = list.getAttribute( 'data-nonce' );
		var i18n     = {
			installing: list.getAttribute( 'data-i18n-installing' ) || 'Installing…',
			done:       list.getAttribute( 'data-i18n-done' ) || 'Active',
			failed:     list.getAttribute( 'data-i18n-failed' ) || 'Failed'
		};
		var ran = false;

		btn.addEventListener( 'click', function ( e ) {
			if ( ran ) {
				return; // Installs finished — let the real submit advance the wizard.
			}
			var checks = Array.prototype.slice.call( list.querySelectorAll( '.bn-wizard__addon-check:checked' ) );
			if ( ! checks.length ) {
				return; // Nothing selected — submit normally.
			}
			e.preventDefault();
			btn.disabled = true;
			list.setAttribute( 'data-installing', '1' );

			var i = 0;
			function advance() {
				ran = true;
				var hidden = document.createElement( 'input' );
				hidden.type  = 'hidden';
				hidden.name  = 'wizard_action';
				hidden.value = 'continue';
				form.appendChild( hidden );
				form.submit();
			}
			function nextOne() {
				if ( i >= checks.length ) {
					advance();
					return;
				}
				var cb     = checks[ i++ ];
				var row    = cb.closest( '.bn-wizard__addon' );
				var status = row ? row.querySelector( '.bn-wizard__addon-status' ) : null;
				var msg    = row ? row.querySelector( '.bn-wizard__addon-msg' ) : null;
				cb.disabled = true;
				if ( status ) { status.textContent = i18n.installing; }
				if ( msg ) { msg.textContent = ''; }
				fetch( endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( { slug: cb.value } )
				} ).then( function ( r ) {
					return r.json().then( function ( d ) { return { ok: r.ok, d: d }; } );
				} ).then( function ( res ) {
					if ( status ) { status.textContent = res.ok ? i18n.done : i18n.failed; }
					if ( row ) { row.setAttribute( 'data-state', res.ok ? 'active' : 'failed' ); }
					if ( ! res.ok && msg ) { msg.textContent = ( res.d && res.d.message ) ? res.d.message : i18n.failed; }
					nextOne();
				} ).catch( function () {
					if ( status ) { status.textContent = i18n.failed; }
					if ( row ) { row.setAttribute( 'data-state', 'failed' ); }
					nextOne();
				} );
			}
			nextOne();
		} );
	}

	function init() {
		bindOptions();
		bindBulk();
		bindColorPicker();
		bindCompanions();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
