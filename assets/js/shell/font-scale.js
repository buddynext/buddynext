/**
 * BuddyNext — Shell font-scale + theme bootstrap.
 *
 * Reads saved preferences from localStorage and stamps the corresponding
 * data attributes on <html> before the rail renders so the v2 token
 * system has the right scale/theme on first paint. The BN-owned topbar
 * (which previously rendered the A / A+ / A++ buttons and the theme
 * toggle) was removed when the active theme's get_header() became the
 * canonical top navigation. The bootstrap remains because the data
 * attributes still drive the token system; the UI for changing these
 * preferences is expected to live in the theme's header / user menu and
 * can dispatch via the documented data attributes below.
 *
 * Storage keys:
 *   bn_font_scale  '100' | '110' | '120'
 *   bn_theme       'light' | 'dark'
 *
 * Data attributes consumed by the v2 token system:
 *   data-bn-font-scale
 *   data-bn-theme
 *
 * Optional theme-provided UI hooks (still wired by the delegated click
 * handler below — themes can drop these into their own header):
 *   <button data-bn-action="set-font-scale" data-scale="110">A+</button>
 *   <button data-bn-action="toggle-theme">…</button>
 *
 * @package BuddyNext
 */

( function () {
	'use strict';

	var SCALES = [ '100', '110', '120' ];

	function readScale() {
		try {
			var s = window.localStorage.getItem( 'bn_font_scale' ) || '100';
			return SCALES.indexOf( s ) !== -1 ? s : '100';
		} catch ( e ) {
			return '100';
		}
	}

	function writeScale( s ) {
		try {
			window.localStorage.setItem( 'bn_font_scale', s );
		} catch ( e ) {
			/* storage unavailable — ignore */
		}
	}

	function applyScale( s ) {
		document.documentElement.setAttribute( 'data-bn-font-scale', s );
	}

	function readTheme() {
		try {
			return window.localStorage.getItem( 'bn_theme' ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function writeTheme( t ) {
		try {
			window.localStorage.setItem( 'bn_theme', t );
		} catch ( e ) {
			/* storage unavailable — ignore */
		}
	}

	function applyTheme( t ) {
		if ( 'dark' === t ) {
			document.documentElement.setAttribute( 'data-bn-theme', 'dark' );
			document.documentElement.setAttribute( 'data-theme', 'dark' );
		} else {
			document.documentElement.setAttribute( 'data-bn-theme', 'light' );
			document.documentElement.removeAttribute( 'data-theme' );
		}
	}

	// Bootstrap: apply saved scale + theme before paint.
	var initialScale = readScale();
	applyScale( initialScale );

	var savedTheme = readTheme();
	var prefersDark = false;
	try {
		prefersDark = window.matchMedia( '(prefers-color-scheme: dark)' ).matches;
	} catch ( e ) {
		prefersDark = false;
	}
	var effectiveTheme = savedTheme || ( prefersDark ? 'dark' : 'light' );
	applyTheme( effectiveTheme );

	// Delegated click handler — themes can ship their own font-scale / theme
	// controls and trigger them via the documented data attributes.
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target || ! e.target.closest ) {
			return;
		}

		var scaleBtn = e.target.closest( '[data-bn-action="set-font-scale"]' );
		if ( scaleBtn && scaleBtn.dataset.scale ) {
			var s = scaleBtn.dataset.scale;
			if ( SCALES.indexOf( s ) !== -1 ) {
				writeScale( s );
				applyScale( s );
			}
			return;
		}

		var themeBtn = e.target.closest( '[data-bn-action="toggle-theme"]' );
		if ( themeBtn ) {
			var current = document.documentElement.getAttribute( 'data-bn-theme' ) || 'light';
			var next = 'dark' === current ? 'light' : 'dark';
			writeTheme( next );
			applyTheme( next );
		}
	} );
}() );
