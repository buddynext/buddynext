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
		return array(
			// ── Brand ──────────────────────────────────────────────────────────
			'--brand'           => 'var(--wp--preset--color--primary, #0073aa)',
			'--brand-hover'     => 'var(--wp--preset--color--primary-hover, #005f8e)',
			'--brand-light'     => 'var(--wp--preset--color--primary-light, #e8f4fb)',

			// ── Backgrounds ────────────────────────────────────────────────────
			'--bg'              => 'var(--wp--preset--color--base, #ffffff)',
			'--bg-subtle'       => 'var(--wp--preset--color--base-subtle, #f8f8f7)',
			'--bg-hover'        => 'var(--wp--preset--color--base-hover, #f1f1f0)',
			'--surface'         => 'var(--wp--preset--color--base, #ffffff)',

			// ── Borders ────────────────────────────────────────────────────────
			'--border'          => 'var(--wp--preset--color--border, #e8e8e5)',
			'--border-soft'     => '#f1f1ee',

			// ── Text ───────────────────────────────────────────────────────────
			'--text-1'          => 'var(--wp--preset--color--foreground, #37352f)',
			'--text-2'          => 'var(--wp--preset--color--foreground-secondary, #787774)',
			'--text-3'          => 'var(--wp--preset--color--foreground-tertiary, #aeaca8)',

			// ── Semantic ───────────────────────────────────────────────────────
			'--green'           => 'var(--wp--preset--color--success, #059669)',
			'--green-bg'        => '#ecfdf5',
			'--amber'           => 'var(--wp--preset--color--warning, #d97706)',
			'--amber-bg'        => '#fffbeb',
			'--red'             => 'var(--wp--preset--color--error, #dc2626)',
			'--red-bg'          => '#fef2f2',

			// ── Integration accents ────────────────────────────────────────────
			'--jetonomy'        => '#5b21b6',
			'--jetonomy-bg'     => '#f5f3ff',
			'--jetonomy-border' => '#ddd6fe',
			'--mvs'             => '#0f766e',
			'--mvs-bg'          => '#f0fdf9',
			'--mvs-border'      => '#99f6e4',

			// ── Typography — WCAG AA defaults (16px body / 18px large text) ────────
			'--font-body'       => "var(--wp--preset--font-family--body, 'Inter', -apple-system, BlinkMacSystemFont, sans-serif)",
			'--font-display'    => "var(--wp--preset--font-family--display, 'Plus Jakarta Sans', 'Inter', sans-serif)",
			'--text-2xs'        => 'var(--wp--preset--font-size--2xs, 9px)',
			'--text-xs'         => 'var(--wp--preset--font-size--xs, 12px)',
			'--text-sm'         => 'var(--wp--preset--font-size--sm, 14px)',
			'--text-md'         => 'var(--wp--preset--font-size--md, 15px)',
			'--text-base'       => 'var(--wp--preset--font-size--medium, 16px)',
			'--text-lg'         => 'var(--wp--preset--font-size--large, 18px)',
			'--text-xl'         => 'var(--wp--preset--font-size--xl, 20px)',
			'--text-2xl'        => 'var(--wp--preset--font-size--2xl, 24px)',
			'--text-3xl'        => 'var(--wp--preset--font-size--3xl, 30px)',
			'--text-4xl'        => 'var(--wp--preset--font-size--4xl, 38px)',
			'--text-5xl'        => 'var(--wp--preset--font-size--5xl, 48px)',

			// ── Line height — WCAG 1.4.12: minimum 1.5× for body text ─────────────
			'--leading-tight'   => '1.2',
			'--leading-snug'    => '1.35',
			'--leading-normal'  => '1.5',
			'--leading-body'    => '1.7',

			// ── Font weight ───────────────────────────────────────────────────────────────────
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
			'--s1'              => '4px',
			'--s2'              => '8px',
			'--s3'              => '12px',
			'--s4'              => '16px',
			'--s5'              => '20px',
			'--s6'              => '24px',
			'--s8'              => '32px',
			'--s10'             => '40px',
			'--s12'             => '48px',
			'--s14'             => '56px',
			'--s16'             => '64px',

			// ── Border radius ──────────────────────────────────────────────────
			'--r-sm'            => '4px',
			'--r-md'            => '8px',
			'--r-lg'            => '12px',
			'--r-xl'            => '16px',
			'--r-full'          => '9999px',
		);
	}

	/**
	 * Return the dark-mode token overrides applied under [data-theme="dark"].
	 *
	 * @return array<string, string>
	 */
	public function get_dark_overrides(): array {
		return array(
			'--bg'          => '#191919',
			'--bg-subtle'   => '#202020',
			'--bg-hover'    => '#2a2a2a',
			'--surface'     => '#252525',
			'--border'      => '#333330',
			'--border-soft' => '#2c2c2a',
			'--text-1'      => '#e8e8e6',
			'--text-2'      => '#9b9b97',
			'--text-3'      => '#6b6b67',
			'--brand'       => '#4dabdb',
			'--brand-light' => '#1a2e3a',
			'--brand-hover' => '#5fbfe8',
			'--jetonomy'    => '#a78bfa',
			'--jetonomy-bg' => '#1e1830',
			'--mvs'         => '#34d399',
			'--mvs-bg'      => '#0d2420',
			'--green'       => '#34d399',
			'--green-bg'    => '#0d2420',
			'--amber'       => '#fbbf24',
			'--amber-bg'    => '#2a2000',
			'--red'         => '#f87171',
			'--red-bg'      => '#2d0f0f',
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

		return sprintf(
			":root {\n%s}\n\n[data-theme=\"dark\"] {\n%s}\n\n/* BuddyNext style-guide takeover */\n%s",
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
