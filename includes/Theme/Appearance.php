<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Front-end application of the admin Appearance / Branding settings.
 *
 * The admin tab (AppearanceTab) only stores options; this class applies them on
 * the front-end, so it must register everywhere (not just wp-admin):
 *
 *   Accent colour  — overrides the accent/brand tokens through the native
 *                    `buddynext_css_vars` filter, deriving hover/light shades
 *                    with CSS color-mix() so the whole UI recolours cohesively.
 *                    (Previously buddynext_brand_color was stored but never read.)
 *   Default theme  — seeds window.bnThemeDefault so the shell bootstrap picks
 *                    the admin's light/dark/auto default for first-time visitors.
 *   Custom CSS     — printed in wp_head, after the token block, last word wins.
 *
 * The community logo is rendered directly by the rail template from
 * `buddynext_logo_url`; no hook needed here.
 *
 * @package BuddyNext\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Theme;

/**
 * Applies branding options to the front-end.
 */
class Appearance {

	/**
	 * Register the front-end hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_css_vars', array( $this, 'apply_accent' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'seed_default_theme' ), 21 );
		add_action( 'wp_head', array( $this, 'print_custom_css' ), 99 );
	}

	/**
	 * Override the accent / brand tokens from the saved colour.
	 *
	 * Only the accent + brand family is touched; the rest of the OKLCH scale is
	 * left intact. Hover/light shades are derived with color-mix() (already used
	 * across the BuddyNext stylesheets) so they track the chosen hue.
	 *
	 * @param array<string,string> $vars Token map.
	 * @return array<string,string>
	 */
	public function apply_accent( array $vars ): array {
		$hex = sanitize_hex_color( (string) get_option( 'buddynext_brand_color', '' ) );
		if ( ! $hex ) {
			return $vars;
		}

		$vars['--bn-accent']   = $hex;
		$vars['--brand']       = $hex;
		$vars['--brand-hover'] = sprintf( 'color-mix(in oklch, %s 88%%, black)', $hex );
		$vars['--brand-light'] = sprintf( 'color-mix(in oklch, %s 15%%, white)', $hex );

		return $vars;
	}

	/**
	 * Expose the admin's default theme to the shell bootstrap so it applies to
	 * visitors who have not yet chosen one (localStorage empty). 'auto' is the
	 * built-in fallback, so only a light/dark default needs seeding.
	 *
	 * @return void
	 */
	public function seed_default_theme(): void {
		$theme = (string) get_option( 'buddynext_default_theme', 'auto' );
		if ( ! in_array( $theme, array( 'light', 'dark' ), true ) ) {
			return;
		}
		wp_add_inline_script(
			'bn-shell-font-scale',
			'window.bnThemeDefault=' . wp_json_encode( $theme ) . ';',
			'before'
		);
	}

	/**
	 * Print admin custom CSS in the document head (last, so it can override).
	 *
	 * The field is manage_options-gated; we still neutralise any `</style>`
	 * break-out attempt on output.
	 *
	 * @return void
	 */
	public function print_custom_css(): void {
		$css = (string) get_option( 'buddynext_custom_css', '' );
		if ( '' === trim( $css ) ) {
			return;
		}
		// Defang a stray closing tag; everything else is CSS, output verbatim.
		$css = str_ireplace( '</style', '', $css );
		echo "\n<style id=\"bn-custom-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS, closing tag stripped above; admin-only field.
	}
}
