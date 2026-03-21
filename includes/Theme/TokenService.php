<?php
/**
 * CSS custom-property token service.
 *
 * Generates the :root { --bn-* } block that maps BuddyNext design tokens to
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
	 * Register the wp_head hook so tokens are emitted on every page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_head', array( $this, 'output_css' ) );
	}

	/**
	 * Return the default token map.
	 *
	 * Values intentionally reference wp--preset-- variables so that active
	 * block-theme palettes are respected.  Hard-coded hex fallbacks are used
	 * only for tokens that have no direct preset equivalent.
	 *
	 * @return array<string, string>
	 */
	public function get_defaults(): array {
		return array(
			// Colour tokens — delegate to block-theme colour palette.
			'--bn-color-primary'       => 'var(--wp--preset--color--primary, #0073aa)',
			'--bn-color-secondary'     => 'var(--wp--preset--color--secondary, #005f8e)',
			'--bn-color-bg'            => 'var(--wp--preset--color--background, #ffffff)',
			'--bn-color-surface'       => 'var(--wp--preset--color--tertiary, #f8f8f7)',
			'--bn-color-text'          => 'var(--wp--preset--color--foreground, #37352f)',

			// Typography tokens — use block-theme font families when available.
			'--bn-font-family'         => "var(--wp--preset--font-family--body, 'Inter', -apple-system, BlinkMacSystemFont, sans-serif)",
			'--bn-font-family-display' => "var(--wp--preset--font-family--display, 'Plus Jakarta Sans', 'Inter', sans-serif)",
			'--bn-font-size-base'      => 'var(--wp--preset--font-size--medium, 15px)',
			'--bn-font-size-sm'        => 'var(--wp--preset--font-size--small, 13px)',
			'--bn-font-size-lg'        => 'var(--wp--preset--font-size--large, 17px)',

			// Spacing tokens — 4 px grid.
			'--bn-space-xs'            => '4px',
			'--bn-space-sm'            => '8px',
			'--bn-space-md'            => '16px',
			'--bn-space-lg'            => '24px',
			'--bn-space-xl'            => '32px',

			// Border radius tokens.
			'--bn-radius-sm'           => '4px',
			'--bn-radius-md'           => '8px',
			'--bn-radius-lg'           => '12px',
			'--bn-radius-full'         => '9999px',
		);
	}

	/**
	 * Build the :root CSS block as a string.
	 *
	 * Applies the buddynext_css_vars filter so themes / bridges can override
	 * individual token values.
	 *
	 * @return string CSS string including :root { … } wrapper.
	 */
	public function build_css(): string {
		/**
		 * Filter the BuddyNext CSS custom-property token map.
		 *
		 * @param array<string, string> $vars Token name → value pairs.
		 */
		$vars = apply_filters( 'buddynext_css_vars', $this->get_defaults() );

		$declarations = '';
		foreach ( $vars as $property => $value ) {
			$declarations .= sprintf( "\t%s: %s;\n", $property, $value );
		}

		return sprintf( ":root {\n%s}\n", $declarations );
	}

	/**
	 * Emit the CSS block inside a <style> tag on wp_head.
	 *
	 * @return void
	 */
	public function output_css(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<style id="buddynext-tokens">' . $this->build_css() . '</style>' . "\n";
	}
}
