<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * CSS custom-property token service.
 *
 * HOW THEME ADOPTION WORKS
 * ──────────────────────────
 * Every token that maps to a WordPress font-size, color, or font-family preset
 * is written as:
 *
 *   var(--wp--preset--font-size--medium, 16px)
 *
 * WordPress block themes (and child themes) that define a "medium" font-size
 * preset in their theme.json automatically override --text-base without any
 * code changes:
 *
 *   // child-theme/theme.json
 *   { "slug": "medium", "size": "18px" }   ← this becomes --text-base
 *
 * Classic themes that don't ship theme.json fall back to the hard-coded px
 * values, which are WCAG 2.1 AA compliant:
 *   - Body text (--text-base):    16px minimum
 *   - Large text (--text-lg):     18px minimum
 *   - Line height (--leading-body): 1.7 (above 1.5 WCAG minimum)
 *   - Color contrast: foreground #37352f on base #ffffff = ~10:1 ratio
 *
 * OVERRIDING AT RUNTIME
 * ──────────────────────
 * Any theme or plugin can filter the entire token map:
 *
 *   add_filter( 'buddynext_css_vars', function( $vars ) {
 *       $vars['--text-base'] = '18px';
 *       return $vars;
 *   } );
 *
 * @package BuddyNext\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Theme;

/**
 * Builds and outputs the BuddyNext CSS custom-property block.
 */
class TokenService {

	/**
	 * Register hooks so tokens are attached to the base stylesheet.
	 *
	 * Requires the target handle to be registered via wp_add_inline_style(),
	 * which happens on wp_enqueue_scripts.  We hook at priority 20 so
	 * AssetService::register_assets() (priority 10) has already run.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'attach_tokens' ), 20 );
	}

	/**
	 * Return the default light-mode token map.
	 *
	 * Values reference wp--preset-- variables so that active block-theme
	 * palettes are respected.  Hard-coded fallbacks are used only for tokens
	 * that have no direct preset equivalent.
	 *
	 * @return array<string, string>
	 */
	public function get_defaults(): array {
		// Every legacy name resolves to `var(--wp--preset--*, var(--bn-*))`:
		// the host theme's theme.json preset wins, otherwise the v2 `--bn-*`
		// source (declared canonically in assets/css/bn-base.css) is the
		// fallback. The token system is theme-agnostic — nothing here pins
		// a hex value; v2 OKLCH drives everything when no preset is set.
		return array(
			// ── Brand ──────────────────────────────────────────────────────────
			'--brand'           => 'var(--wp--preset--color--primary, var(--bn-accent))',
			'--brand-hover'     => 'var(--wp--preset--color--primary-hover, var(--bn-accent-700))',
			'--brand-light'     => 'var(--wp--preset--color--primary-light, var(--bn-accent-100))',

			// ── Backgrounds ────────────────────────────────────────────────────
			'--bg'              => 'var(--wp--preset--color--base, var(--bn-canvas))',
			'--bg-subtle'       => 'var(--wp--preset--color--base-subtle, var(--bn-sunken))',
			'--bg-hover'        => 'var(--wp--preset--color--base-hover, var(--bn-sunken))',
			'--surface'         => 'var(--wp--preset--color--base, var(--bn-surface))',

			// ── Borders ────────────────────────────────────────────────────────
			'--border'          => 'var(--wp--preset--color--border, var(--bn-line))',
			'--border-soft'     => 'var(--bn-line-faint)',

			// ── Text ───────────────────────────────────────────────────────────
			'--text-1'          => 'var(--wp--preset--color--foreground, var(--bn-ink))',
			'--text-2'          => 'var(--wp--preset--color--foreground-secondary, var(--bn-ink-2))',
			'--text-3'          => 'var(--wp--preset--color--foreground-tertiary, var(--bn-ink-3))',

			// ── Semantic ───────────────────────────────────────────────────────
			'--green'           => 'var(--wp--preset--color--success, var(--bn-success))',
			'--green-bg'        => 'var(--bn-success-bg)',
			'--amber'           => 'var(--wp--preset--color--warning, var(--bn-warn))',
			'--amber-bg'        => 'var(--bn-warn-bg)',
			'--red'             => 'var(--wp--preset--color--error, var(--bn-danger))',
			'--red-bg'          => 'var(--bn-danger-bg)',

			// ── Sibling-product accents (Jetonomy / WPMediaVerse) ──────────────
			'--jetonomy'        => 'var(--bn-jetonomy)',
			'--jetonomy-bg'     => 'var(--bn-jetonomy-bg)',
			'--jetonomy-border' => 'var(--bn-jetonomy-bg)',
			'--mvs'             => 'var(--bn-media)',
			'--mvs-bg'          => 'var(--bn-media-bg)',
			'--mvs-border'      => 'var(--bn-media-bg)',

			// ── Typography — theme.json presets win over v2 defaults ────────
			'--font-body'       => 'var(--wp--preset--font-family--body, var(--bn-font-ui))',
			'--font-display'    => 'var(--wp--preset--font-family--display, var(--bn-font-display))',
			// rem-based — scales uniformly with browser zoom and A/A+/A++ control.
			'--text-2xs'        => '0.5625rem',
			'--text-xs'         => '0.75rem',
			'--text-sm'         => '0.875rem',
			'--text-md'         => '0.9375rem',
			'--text-base'       => '1rem',
			'--text-lg'         => '1.125rem',
			'--text-xl'         => '1.25rem',
			'--text-2xl'        => '1.5rem',
			'--text-3xl'        => '1.875rem',
			'--text-4xl'        => '2.375rem',
			'--text-5xl'        => '3rem',

			// ── Line height — WCAG 1.4.12: minimum 1.5× for body text ─────────────
			'--leading-tight'   => '1.2',
			'--leading-snug'    => '1.35',
			'--leading-normal'  => '1.5',
			'--leading-body'    => '1.7',

			// ── Font weight ───────────────────────────────────────────────────────
			'--fw-normal'       => '400',
			'--fw-medium'       => '500',
			'--fw-semibold'     => '600',
			'--fw-bold'         => '700',
			'--fw-extrabold'    => '800',
			'--fw-black'        => '900',

			// ── Letter spacing — WCAG 1.4.12: minimum 0.12em ───────────────────────
			'--ls-display'      => '-0.04em',
			'--ls-tight'        => '-0.02em',
			'--ls-normal'       => '0em',
			'--ls-wide'         => '0.04em',
			'--ls-wider'        => '0.08em',

			// ── Spacing — 4 px grid ────────────────────────────────────────────
			'--s1'              => 'var(--bn-s1)',
			'--s2'              => 'var(--bn-s2)',
			'--s3'              => 'var(--bn-s3)',
			'--s4'              => 'var(--bn-s4)',
			'--s5'              => 'var(--bn-s5)',
			'--s6'              => 'var(--bn-s6)',
			'--s8'              => 'var(--bn-s8)',
			'--s10'             => 'var(--bn-s10)',
			'--s12'             => 'var(--bn-s12)',
			'--s14'             => '56px',
			'--s16'             => 'var(--bn-s16)',

			// ── Border radius ──────────────────────────────────────────────────
			'--r-sm'            => 'var(--bn-r-sm)',
			'--r-md'            => 'var(--bn-r-md)',
			'--r-lg'            => 'var(--bn-r-lg)',
			'--r-xl'            => 'var(--bn-r-xl)',
			'--r-full'          => 'var(--bn-r-full)',
		);
	}

	/**
	 * Return the dark-mode token overrides applied under [data-theme="dark"].
	 *
	 * @return array<string, string>
	 */
	public function get_dark_overrides(): array {
		// The v2 --bn-* sources flip to dark in assets/css/bn-base.css under
		// [data-theme="dark"]. The legacy aliases, however, bridge through
		// `var(--wp--preset--color--*, var(--bn-*))` so that a host block
		// theme's palette wins in light mode. That bridge is the problem in
		// dark mode: when the host theme DOES define a preset (Astra and most
		// block themes define --wp--preset--color--base etc.), the preset —
		// a static LIGHT colour — resolves first and the dark --bn-* fallback
		// is never reached. The result is white cards with near-white text.
		//
		// Under dark mode BuddyNext owns the palette (consistent with the
		// "BuddyNext is the boss" takeover below), so re-pin every
		// preset-bridged alias straight at its already-dark --bn-* source,
		// dropping the light preset out of the chain. Aliases that don't
		// bridge to a preset (--border-soft, spacing, radius, type) already
		// re-resolve correctly and are intentionally omitted.
		return array(
			// Backgrounds + surfaces.
			'--bg'          => 'var(--bn-canvas)',
			'--bg-subtle'   => 'var(--bn-sunken)',
			'--bg-hover'    => 'var(--bn-sunken)',
			'--surface'     => 'var(--bn-surface)',

			// Borders.
			'--border'      => 'var(--bn-line)',

			// Text.
			'--text-1'      => 'var(--bn-ink)',
			'--text-2'      => 'var(--bn-ink-2)',
			'--text-3'      => 'var(--bn-ink-3)',

			// Brand.
			'--brand'       => 'var(--bn-accent)',
			'--brand-hover' => 'var(--bn-accent-700)',
			'--brand-light' => 'var(--bn-accent-100)',

			// Semantic.
			'--green'       => 'var(--bn-success)',
			'--amber'       => 'var(--bn-warn)',
			'--red'         => 'var(--bn-danger)',
		);
	}

	/**
	 * Build the :root CSS block as a string.
	 *
	 * Applies the buddynext_css_vars filter so themes / bridges can override
	 * individual token values.
	 *
	 * @return string CSS string including :root { … } and [data-theme="dark"] { … } wrappers.
	 */
	public function build_css(): string {
		/**
		 * Filter the BuddyNext CSS custom-property token map.
		 *
		 * @param array<string, string> $vars Token name => value pairs.
		 */
		$vars = apply_filters( 'buddynext_css_vars', $this->get_defaults() );

		$root_declarations = '';
		foreach ( $vars as $property => $value ) {
			$root_declarations .= sprintf( "\t%s: %s;\n", $property, $value );
		}

		/**
		 * Filter the BuddyNext dark-mode CSS custom-property overrides.
		 *
		 * @param array<string, string> $overrides Token name => value pairs.
		 */
		$dark = apply_filters( 'buddynext_css_vars_dark', $this->get_dark_overrides() );

		$dark_declarations = '';
		foreach ( $dark as $property => $value ) {
			$dark_declarations .= sprintf( "\t%s: %s;\n", $property, $value );
		}

		// ── Plugin style-guide takeover ─────────────────────────────────────
		// BuddyNext is the boss: when active, ALL integrated plugin surfaces
		// use BuddyNext's font stack, base size, color, and line-height.
		// Targets every possible wrapper — not just known class names.
		// When BuddyNext is deactivated this entire block disappears and each
		// plugin falls back to its own theme.json / CSS defaults.
		$takeover = '';

		// Jetonomy — .jt-app wraps every Jetonomy page.
		$takeover .= ".jt-app {\n";
		$takeover .= "\tfont-family: var(--font-body);\n";
		$takeover .= "\tfont-size: var(--text-md);\n";
		$takeover .= "\tline-height: var(--leading-body);\n";
		$takeover .= "\tcolor: var(--text-1);\n";
		$takeover .= "}\n";
		$takeover .= ".jt-app h1, .jt-app h2, .jt-app h3 {\n";
		$takeover .= "\tfont-family: var(--font-display);\n";
		$takeover .= "}\n";

		// Jetonomy sidebar — when BuddyNext is active, the sidebar partial
		// outputs bn-sidebar-card HTML structure directly (same skeleton as
		// BuddyNext's own sidebar). Only minimal bridging CSS needed here
		// for inner Jetonomy elements inside the BuddyNext card skeleton.
		$takeover .= ".jt-app .bn-sidebar-card .jt-trend,\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-leader {\n";
		$takeover .= "\tpadding: var(--s2) 0;\n";
		$takeover .= "\tborder-bottom: 1px solid var(--border-soft);\n";
		$takeover .= "}\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-trend:last-child,\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-leader:last-of-type { border-bottom: none; }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-trend-title { font-size: var(--text-sm); font-weight: 600; }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-trend-title a { color: var(--text-1); }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-trend-title a:hover { color: var(--brand); }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-trend-meta { font-size: var(--text-xs); color: var(--text-3); }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-trend-n { font-size: var(--text-xs); color: var(--text-3); min-width: 16px; }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-leader-name a { font-size: var(--text-sm); font-weight: 600; color: var(--text-1); }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-leader-name a:hover { color: var(--brand); }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-leader-pts { font-size: var(--text-xs); color: var(--text-3); }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-leader-rank { font-size: var(--text-xs); color: var(--text-3); }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-sidebar-link a { font-size: var(--text-sm); color: var(--brand); font-weight: 500; }\n";
		$takeover .= ".jt-app .bn-sidebar-card .jt-tag { font-size: var(--text-xs); }\n";
		$takeover .= ".jt-app .jt-sidebar { display: flex; flex-direction: column; gap: var(--s5); }\n";
		$takeover .= ".jt-app .jt-sidebar .bn-sidebar-card + .bn-sidebar-card { margin-top: 0; }\n";

		// WPMediaVerse — target the page-level body class instead of individual wrappers.
		$takeover .= "body.mvs-page {\n";
		$takeover .= "\tfont-family: var(--font-body);\n";
		$takeover .= "\tfont-size: var(--text-md);\n";
		$takeover .= "\tline-height: var(--leading-body);\n";
		$takeover .= "\tcolor: var(--text-1);\n";
		$takeover .= "}\n";
		$takeover .= "body.mvs-page h1, body.mvs-page h2, body.mvs-page h3 {\n";
		$takeover .= "\tfont-family: var(--font-display);\n";
		$takeover .= "}\n";

		// ── Shared animations — apply BuddyNext motion to Jetonomy + MVS ──
		// Jetonomy list/card entrances.
		$takeover .= ".jt-app .jt-topics > a,\n";
		$takeover .= ".jt-app .jt-topics > div,\n";
		$takeover .= ".jt-app .jt-space-grid > *,\n";
		$takeover .= ".jt-app section,\n";
		$takeover .= ".jt-app .jt-leader,\n";
		$takeover .= ".jt-app .jt-badge-card,\n";
		$takeover .= ".jt-app .jt-sidebar > *,\n";
		$takeover .= ".jt-app .jt-notif-item {\n";
		$takeover .= "\tanimation: bn-slide-up 0.35s cubic-bezier(0.22, 1, 0.36, 1) both;\n";
		$takeover .= "}\n";

		// Jetonomy stagger delays for topic rows.
		for ( $i = 1; $i <= 5; $i++ ) {
			$delay    = ( $i - 1 ) * 0.03;
			$takeover .= ".jt-app .jt-topics > a:nth-child({$i}) { animation-delay: {$delay}s; }\n";
		}
		$takeover .= ".jt-app .jt-topics > a:nth-child(n+6) { animation-delay: 0.12s; }\n";

		// Jetonomy headings and breadcrumbs.
		$takeover .= ".jt-app .jt-page-title,\n";
		$takeover .= ".jt-app .jt-space-head h1,\n";
		$takeover .= ".jt-app .jt-post-head h1 {\n";
		$takeover .= "\tanimation: bn-fade-title 0.5s ease both;\n";
		$takeover .= "}\n";
		$takeover .= ".jt-app .jt-crumb { animation: bn-fade-in 0.4s ease both; animation-delay: 0.05s; }\n";

		// WPMediaVerse grid/card entrances.
		$takeover .= "body.mvs-page .mvs-grid-item,\n";
		$takeover .= "body.mvs-page .mvs-album-item,\n";
		$takeover .= "body.mvs-page .mvs-collection-item,\n";
		$takeover .= "body.mvs-page .mvs-comment,\n";
		$takeover .= "body.mvs-page .mvs-notification-item {\n";
		$takeover .= "\tanimation: bn-slide-up 0.35s cubic-bezier(0.22, 1, 0.36, 1) both;\n";
		$takeover .= "}\n";

		// WPMediaVerse heading entrances.
		$takeover .= "body.mvs-page .mvs-profile-header-name,\n";
		$takeover .= "body.mvs-page .mvs-single-media h1,\n";
		$takeover .= "body.mvs-page .mvs-single-album h1 {\n";
		$takeover .= "\tanimation: bn-fade-title 0.5s ease both;\n";
		$takeover .= "}\n";

		// Respect reduced motion for all takeover animations.
		$takeover .= "@media (prefers-reduced-motion: reduce) {\n";
		$takeover .= "\t.jt-app *, body.mvs-page * { animation: none !important; }\n";
		$takeover .= "}\n";

		// Dark overrides apply under BOTH the v2-canonical [data-bn-theme="dark"]
		// and the legacy [data-theme="dark"] selectors — the same pair the
		// --bn-* dark shifts use in assets/css/bn-base.css. Keeping the
		// selectors in lock-step is what guarantees the legacy aliases re-pin
		// to dark whenever the --bn-* sources do.
		return sprintf(
			":root {\n%s}\n\n[data-bn-theme=\"dark\"],\n[data-theme=\"dark\"] {\n%s}\n\n/* BuddyNext style-guide takeover */\n%s",
			$root_declarations,
			$dark_declarations,
			$takeover
		);
	}

	/**
	 * Attach the CSS token block as inline styles on the base stylesheet handle.
	 *
	 * Using wp_add_inline_style() instead of raw echo keeps all output inside
	 * the WordPress style queue so it is correctly positioned and can be
	 * dequeued by child themes.
	 *
	 * @return void
	 */
	public function attach_tokens(): void {
		wp_add_inline_style( 'bn-base', $this->build_css() );
	}
}
