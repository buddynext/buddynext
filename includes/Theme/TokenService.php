<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * CSS custom-property token service.
 *
 * Generates the :root { --* } block that maps BuddyNext design tokens to
 * WordPress theme-preset variables (or hard-coded defaults when no preset
 * exists).  The buddynext_css_vars filter lets themes and child plugins
 * override individual token values at run-time.
 *
 * Usage:
 *   ( new TokenService() )->init();  // runs inside Plugin::init()
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

			// ── Typography ─────────────────────────────────────────────────────
			'--font-body'       => "var(--wp--preset--font-family--body, 'Inter', -apple-system, BlinkMacSystemFont, sans-serif)",
			'--font-display'    => "var(--wp--preset--font-family--display, 'Plus Jakarta Sans', 'Inter', sans-serif)",
			'--text-2xs'        => 'var(--wp--preset--font-size--2xs, 9px)',
			'--text-xs'         => 'var(--wp--preset--font-size--xs, 11px)',
			'--text-sm'         => 'var(--wp--preset--font-size--sm, 13px)',
			'--text-md'         => 'var(--wp--preset--font-size--md, 14px)',
			'--text-base'       => 'var(--wp--preset--font-size--medium, 15px)',
			'--text-lg'         => 'var(--wp--preset--font-size--large, 17px)',
			'--text-xl'         => 'var(--wp--preset--font-size--xl, 20px)',
			'--text-2xl'        => 'var(--wp--preset--font-size--2xl, 24px)',
			'--text-3xl'        => 'var(--wp--preset--font-size--3xl, 30px)',
			'--text-4xl'        => 'var(--wp--preset--font-size--4xl, 38px)',
			'--text-5xl'        => 'var(--wp--preset--font-size--5xl, 48px)',
			'--leading-tight'   => '1.2',
			'--leading-snug'    => '1.35',
			'--leading-normal'  => '1.5',
			'--leading-body'    => '1.7',

			// ── Font weight ────────────────────────────────────────────────────────
			'--fw-normal'       => '400',
			'--fw-medium'       => '500',
			'--fw-semibold'     => '600',
			'--fw-bold'         => '700',
			'--fw-extrabold'    => '800',

			// ── Letter spacing ─────────────────────────────────────────────────────
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

		return sprintf(
			":root {\n%s}\n\n[data-theme=\"dark\"] {\n%s}\n",
			$root_declarations,
			$dark_declarations
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
