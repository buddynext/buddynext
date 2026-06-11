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
	 * Recolour the accent palette from the saved brand colour.
	 *
	 * The token system is hue-driven: the entire OKLCH accent scale
	 * (--bn-accent-50…900, and --bn-accent = --bn-accent-500) derives from
	 * --bn-hue / --bn-chroma (see assets/css/bn-base.css — "whitelabel rebrand
	 * only flips --bn-hue"). So we convert the picked hex to OKLCH and drive
	 * those two knobs, which recolours every shade cohesively — primary buttons,
	 * hovers and tints included — rather than just the base accent token.
	 *
	 * @param array<string,string> $vars Token map.
	 * @return array<string,string>
	 */
	public function apply_accent( array $vars ): array {
		$hex = sanitize_hex_color( (string) get_option( 'buddynext_brand_color', '' ) );
		if ( ! $hex ) {
			return $vars;
		}

		list( $hue, $chroma ) = $this->hex_to_oklch_hc( $hex );

		$vars['--bn-hue']        = (string) $hue;
		$vars['--bn-accent-hue'] = (string) $hue;
		// Keep chroma within the design's tasteful range so a very saturated pick
		// can't blow out the neutrals that also borrow --bn-hue at low chroma.
		$vars['--bn-chroma'] = (string) max( 0.05, min( 0.19, $chroma ) );

		return $vars;
	}

	/**
	 * Convert a #rrggbb colour to its OKLCH hue (degrees) and chroma.
	 *
	 * Standard sRGB → linear → OKLab → OKLCH path (Björn Ottosson's matrices).
	 *
	 * @param string $hex #rrggbb (already validated).
	 * @return array{0:float,1:float} [hue 0–360, chroma].
	 */
	private function hex_to_oklch_hc( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$to_linear = static function ( float $c ): float {
			$c /= 255;
			return $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		$r = $to_linear( (float) hexdec( substr( $hex, 0, 2 ) ) );
		$g = $to_linear( (float) hexdec( substr( $hex, 2, 2 ) ) );
		$b = $to_linear( (float) hexdec( substr( $hex, 4, 2 ) ) );

		$l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
		$m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
		$s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

		$l_ = $l ** ( 1 / 3 );
		$m_ = $m ** ( 1 / 3 );
		$s_ = $s ** ( 1 / 3 );

		$oa = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
		$ob = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

		$chroma = sqrt( $oa * $oa + $ob * $ob );
		$hue    = atan2( $ob, $oa ) * 180 / M_PI;
		if ( $hue < 0 ) {
			$hue += 360;
		}

		return array( round( $hue, 1 ), round( $chroma, 3 ) );
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
