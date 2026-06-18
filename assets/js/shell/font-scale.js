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

	// Inlined nav-init (once) — this file is a classic IIFE, not an ES module,
	// so it cannot import shell/nav-init.js. Chrome/global setup persists across
	// client-side navigations, so it binds on initial load only (no
	// buddynext:navigated binding) — equivalent to onNavReady( init, { once: true } ).
	function onNavReadyOnce( init ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	}

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

	var THEMES = [ 'light', 'dark', 'auto' ];

	function readThemePref() {
		// Site default (set in Settings → Appearance) applies until the visitor
		// makes their own choice; falls back to 'auto' when unset.
		var fallback = ( 'light' === window.bnThemeDefault || 'dark' === window.bnThemeDefault )
			? window.bnThemeDefault
			: 'auto';
		try {
			var t = window.localStorage.getItem( 'bn_theme' ) || fallback;
			return THEMES.indexOf( t ) !== -1 ? t : 'auto';
		} catch ( e ) {
			return fallback;
		}
	}

	function writeThemePref( t ) {
		try {
			window.localStorage.setItem( 'bn_theme', t );
		} catch ( e ) {
			/* storage unavailable — ignore */
		}
	}

	function effectiveFromPref( pref ) {
		if ( 'light' === pref || 'dark' === pref ) {
			return pref;
		}
		try {
			return window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light';
		} catch ( e ) {
			return 'light';
		}
	}

	function applyTheme( effective ) {
		if ( 'dark' === effective ) {
			document.documentElement.setAttribute( 'data-bn-theme', 'dark' );
			document.documentElement.setAttribute( 'data-theme', 'dark' );
		} else {
			document.documentElement.setAttribute( 'data-bn-theme', 'light' );
			document.documentElement.removeAttribute( 'data-theme' );
		}
	}

	// When the active theme drives colour mode (WBcom themes such as Reign set
	// <html data-bx-mode="light|dark|auto"> and fire `bx:color-mode-change`),
	// BuddyNext follows it so a single theme toggle switches both the theme and
	// the BN surface. Falls back to BN's own bn_theme preference when no theme
	// colour-mode system is present.
	function themeColorMode() {
		var m = document.documentElement.getAttribute( 'data-bx-mode' );
		// Only an EXPLICIT host light/dark mode overrides BN's own preference and
		// the admin Default Theme. The host's 'auto' means "no explicit choice"
		// (follow the system), so it must not win over the Default Theme setting —
		// otherwise that setting never applies on a host theme that ships
		// data-bx-mode="auto" (e.g. Reign/BuddyX defaults).
		return ( 'light' === m || 'dark' === m ) ? m : null;
	}
	function currentPref() {
		return themeColorMode() || readThemePref();
	}

	// ── Rail collapse ──────────────────────────────────────────────────────
	// The left nav rail can collapse to an icon-only panel. The choice is saved
	// in localStorage and stamped on <html data-bn-rail> here (before paint) so
	// the rail never flashes wide-then-narrow on load.
	function readRailCollapsed() {
		try {
			return '1' === window.localStorage.getItem( 'bn_rail_collapsed' );
		} catch ( e ) {
			return false;
		}
	}
	function applyRail( collapsed ) {
		if ( collapsed ) {
			document.documentElement.setAttribute( 'data-bn-rail', 'collapsed' );
		} else {
			document.documentElement.removeAttribute( 'data-bn-rail' );
		}
		// Keep the toggle buttons' accessible state + label in sync.
		var toggles = document.querySelectorAll( '[data-bn-action="toggle-rail"]' );
		for ( var i = 0; i < toggles.length; i++ ) {
			toggles[ i ].setAttribute( 'aria-pressed', collapsed ? 'true' : 'false' );
			var label = collapsed ? 'Expand navigation' : 'Collapse navigation';
			toggles[ i ].setAttribute( 'aria-label', label );
			toggles[ i ].setAttribute( 'title', label );
		}
	}

	// Bootstrap: apply saved scale + theme + rail state before paint.
	var initialScale = readScale();
	applyScale( initialScale );

	applyTheme( effectiveFromPref( currentPref() ) );

	applyRail( readRailCollapsed() );

	// Mark the active segmented-control button. Re-runs on every change so
	// the Appearance card mirrors current state without explicit binding.
	function syncPressed() {
		var scale = document.documentElement.getAttribute( 'data-bn-font-scale' ) || '100';
		var pref  = readThemePref();
		var nodes = document.querySelectorAll(
			'[data-bn-action="set-font-scale"],[data-bn-action="set-theme"]'
		);
		for ( var i = 0; i < nodes.length; i++ ) {
			var n      = nodes[ i ];
			var match  = ( n.dataset.scale && n.dataset.scale === scale )
				|| ( n.dataset.theme && n.dataset.theme === pref );
			n.setAttribute( 'aria-pressed', match ? 'true' : 'false' );
		}
	}
	onNavReadyOnce( syncPressed );

	// Follow the theme's colour-mode toggle (Reign + sibling WBcom themes) so
	// the BN surface flips with it — one control, both layers.
	document.addEventListener( 'bx:color-mode-change', function ( e ) {
		var mode = e && e.detail ? e.detail.mode : null;
		// Mirror themeColorMode(): only an explicit host light/dark drives the BN
		// surface. The host's 'auto' falls through to currentPref(), so the admin
		// Default Theme (or the visitor's own choice) still wins.
		var resolved = ( 'light' === mode || 'dark' === mode ) ? mode : currentPref();
		applyTheme( effectiveFromPref( resolved ) );
		syncPressed();
	} );

	// Auto pref tracks the OS — re-apply when system flips dark/light.
	try {
		var mql = window.matchMedia( '(prefers-color-scheme: dark)' );
		var onSystemChange = function () {
			if ( 'auto' === currentPref() ) {
				applyTheme( effectiveFromPref( 'auto' ) );
			}
		};
		if ( mql.addEventListener ) {
			mql.addEventListener( 'change', onSystemChange );
		} else if ( mql.addListener ) {
			mql.addListener( onSystemChange );
		}
	} catch ( e ) {
		/* matchMedia unavailable — ignore */
	}

	// Delegated click handler — themes / settings UI ship controls and
	// trigger updates via the documented data attributes.
	//   [data-bn-action="set-font-scale"][data-scale="100|110|120"]
	//   [data-bn-action="set-theme"][data-theme="light|dark|auto"]
	//   [data-bn-action="toggle-theme"]   ← binary flip (light ↔ dark)
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
				syncPressed();
			}
			return;
		}

		var setThemeBtn = e.target.closest( '[data-bn-action="set-theme"]' );
		if ( setThemeBtn && setThemeBtn.dataset.theme ) {
			var t = setThemeBtn.dataset.theme;
			if ( THEMES.indexOf( t ) !== -1 ) {
				writeThemePref( t );
				applyTheme( effectiveFromPref( t ) );
				syncPressed();
			}
			return;
		}

		var themeBtn = e.target.closest( '[data-bn-action="toggle-theme"]' );
		if ( themeBtn ) {
			var current = document.documentElement.getAttribute( 'data-bn-theme' ) || 'light';
			var next = 'dark' === current ? 'light' : 'dark';
			writeThemePref( next );
			applyTheme( next );
			syncPressed();
			return;
		}

		var railBtn = e.target.closest( '[data-bn-action="toggle-rail"]' );
		if ( railBtn ) {
			var nowCollapsed = 'collapsed' !== document.documentElement.getAttribute( 'data-bn-rail' );
			try {
				window.localStorage.setItem( 'bn_rail_collapsed', nowCollapsed ? '1' : '0' );
			} catch ( err ) {
				/* storage unavailable — ignore */
			}
			applyRail( nowCollapsed );
		}
	} );

	// Re-sync the rail toggle's accessible state once the rail markup exists
	// (the before-paint bootstrap above runs before the rail button renders).
	onNavReadyOnce( function () {
		applyRail( readRailCollapsed() );
	} );
}() );
