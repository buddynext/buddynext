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
		// Priority 22 so the accent override is appended to bn-base AFTER
		// TokenService::attach_tokens() (priority 20) — which emits the :root
		// tokens AND the host-theme adoption block. Later source order lets an
		// explicitly-set BN accent win over the active theme's palette.
		add_action( 'wp_enqueue_scripts', array( $this, 'attach_accent' ), 22 );
		add_action( 'wp_enqueue_scripts', array( $this, 'attach_container_width' ), 22 );
		add_action( 'wp_enqueue_scripts', array( $this, 'seed_default_theme' ), 21 );
		add_action( 'wp_head', array( $this, 'print_custom_css' ), 99 );

		// On BuddyNext hub pages, BN renders its own full-width shell with a left
		// rail, so suppress the Reign theme's "Left Panel" menu location to avoid
		// two overlapping left navs. Scoped to BN hub pages only — the theme panel
		// is untouched on blog, regular pages, and everything else.
		add_filter( 'theme_mod_reign_left_panel_gloabl_setting', array( $this, 'suppress_theme_left_panel_on_hub' ) );
	}

	/**
	 * Disable the Reign "Left Panel" menu location on BuddyNext hub pages —
	 * but only while BuddyNext is actually drawing its own rail there.
	 *
	 * BuddyNext's rail and Reign's left panel are two independent left-hand
	 * navigations; showing both at once overlaps them, so this suppresses the
	 * theme's panel while BN's rail owns the column. It must NOT suppress
	 * unconditionally: an owner who turns the rail off (Settings > "Show the
	 * desktop sidebar rail") loses it, and the site is left with no left
	 * navigation at all on every hub if the theme's is also off — the bug
	 * fixed here (card 10348288795). buddynext_community_rail_enabled() is
	 * the same gate hub-shell.php uses to decide whether to render the rail,
	 * so this can never disagree with what actually rendered.
	 *
	 * @param mixed $value Stored reign_left_panel_gloabl_setting value.
	 * @return mixed False on a BN hub page while the BN rail renders there, the original value otherwise.
	 */
	public function suppress_theme_left_panel_on_hub( $value ) {
		if ( '' !== (string) get_query_var( 'bn_hub' ) && function_exists( 'buddynext_community_rail_enabled' ) && buddynext_community_rail_enabled() ) {
			return false;
		}
		return $value;
	}

	/**
	 * The legacy default brand colour. A stored value equal to this is treated
	 * as "not set" — the accent stays opt-in, so saving unrelated settings (or
	 * completing setup without changing the swatch) never recolours a site that
	 * relies on its theme's palette.
	 *
	 * Public because it is a SENTINEL, not a preference: the meaning above is
	 * what makes `#0073aa` different from any other blue, and while this was
	 * private every other colour default in the product retyped the raw literal
	 * with that meaning attached to none of them. One value, one place, one
	 * explanation — change it here and every default follows.
	 */
	public const DEFAULT_BRAND = '#0073aa';

	/**
	 * Narrowest boxed width. At or below 1024px the shell already switches to two
	 * columns, so a box any narrower would only squeeze the content.
	 */
	public const CONTAINER_MIN = 1025;

	/**
	 * Widest boxed width offered.
	 */
	public const CONTAINER_MAX = 2400;

	/**
	 * Width of the community pages, from Appearance > Layout.
	 *
	 * 'full' (default, how BuddyNext has always looked) returns 0. 'theme' follows the
	 * active theme: theme.json layout.wideSize, then the `buddynext_theme_container_width`
	 * filter (for a theme that keeps its container width in its own setting), then
	 * 1200. 'custom' is the owner's number. Both boxed widths are clamped to
	 * CONTAINER_MIN..CONTAINER_MAX.
	 *
	 * @return int Max width in px, or 0 for full width.
	 */
	public static function container_width(): int {
		return self::container_width_for( (string) get_option( 'buddynext_container_width', 'full' ) );
	}

	/**
	 * Width a given layout mode resolves to (lets the settings screen show the
	 * theme's width next to "Theme default" without saving anything).
	 *
	 * @param string $mode 'full' | 'theme' | 'custom'.
	 * @return int Max width in px, or 0 for full width.
	 */
	public static function container_width_for( string $mode ): int {
		if ( 'custom' === $mode ) {
			$px = (int) get_option( 'buddynext_container_width_custom', 1280 );
		} elseif ( 'theme' === $mode ) {
			$settings = function_exists( 'wp_get_global_settings' ) ? (array) wp_get_global_settings( array( 'layout' ) ) : array();
			$px       = (int) ( $settings['wideSize'] ?? 0 ); // "1200px" -> 1200; rem/vw values read as 0 and fall back.

			/**
			 * Filters the width BuddyNext uses for "Theme default".
			 *
			 * Classic themes often keep their container width in a theme setting rather
			 * than theme.json; a theme or bridge can return it here.
			 *
			 * @since 1.2.4
			 *
			 * @param int $px Width from theme.json (0 when the theme has none).
			 */
			$px = (int) apply_filters( 'buddynext_theme_container_width', $px );
			$px = $px > 0 ? $px : 1200;
		} else {
			return 0;
		}

		return max( self::CONTAINER_MIN, min( self::CONTAINER_MAX, $px ) );
	}

	/**
	 * Print the boxed width as --bn-container-w (only when a boxed layout is chosen).
	 *
	 * @return void
	 */
	public function attach_container_width(): void {
		$px = self::container_width();
		if ( $px > 0 ) {
			wp_add_inline_style( 'bn-base', ':root{--bn-container-w:' . $px . 'px;}' );
		}
	}

	/**
	 * The accent BuddyNext actually renders when no brand colour has been picked.
	 *
	 * NOT the same thing as DEFAULT_BRAND, and the difference matters. DEFAULT_BRAND
	 * is a SENTINEL: attach_accent() sees it and returns without emitting any accent
	 * CSS, so the design tokens stand as authored. This is what those tokens then
	 * resolve to - oklch(58% 0.16 252), the indigo in bn-base.css.
	 *
	 * It exists so a screen can SHOW an owner what the product looks like today
	 * without having to store a value to do it. The setup wizard was displaying
	 * DEFAULT_BRAND in its colour picker, which meant the "Brand it" step offered
	 * WordPress admin blue - a colour the product never uses - and accepting it
	 * changed nothing, because that value is precisely the one that means "do not
	 * brand anything".
	 *
	 * @since 1.1.6
	 * @var string
	 */
	public const DEFAULT_ACCENT_HEX = '#176bc7';

	/**
	 * Recolour the accent palette from the admin's brand colour, winning over
	 * the active host theme when explicitly set (opt-in).
	 *
	 * The token system is hue-driven: the whole OKLCH accent scale derives from
	 * --bn-hue / --bn-chroma (bn-base.css — "whitelabel rebrand only flips
	 * --bn-hue"). We drive those so every shade recolours, AND pin the base
	 * --bn-accent / --bn-accent-700 / --brand family that a host-theme adoption
	 * block (e.g. Reign) re-points — emitted here AFTER that block (priority 22)
	 * so the admin's colour wins. Left empty / at the legacy default, nothing is
	 * emitted and the theme's palette stands.
	 *
	 * @return void
	 */
	public function attach_accent(): void {
		$hex = sanitize_hex_color( (string) get_option( 'buddynext_brand_color', self::DEFAULT_BRAND ) );
		// Opt-in: empty or the legacy default = inherit the theme/native palette.
		if ( ! $hex || strtolower( $hex ) === self::DEFAULT_BRAND ) {
			return;
		}

		list( $hue, $chroma ) = $this->hex_to_oklch_hc( $hex );
		$chroma               = max( 0.05, min( 0.19, $chroma ) );

		// `:root, [data-bn-theme]` matches <html> with the same (0,1,0) specificity
		// as the theme block; appended later, so it wins. Drive the hue/chroma for
		// the scale and pin the theme-remapped accent/brand tokens to the picked
		// colour (hover/light derived with color-mix, already used site-wide).
		// --bn-accent-500 is the base of the theme-mapped 50-900 ramp (and of
		// partner tokens like --wb-gam-pop-accent), so pinning it keeps the whole
		// scale on the owner's colour instead of the host theme's accent.
		$css = sprintf(
			':root,[data-bn-theme]{--bn-hue:%1$s;--bn-accent-hue:%1$s;--bn-chroma:%2$s;'
			. '--bn-accent:%3$s;--bn-accent-500:%3$s;--bn-accent-700:color-mix(in oklch,%3$s 80%%,black);'
			. '--brand:%3$s;--brand-hover:color-mix(in oklch,%3$s 86%%,black);'
			. '--brand-light:color-mix(in oklch,%3$s 14%%,white);}',
			$hue,
			$chroma,
			$hex
		);

		// Some BuddyNext components (e.g. .bn-btn--primary) read the host theme's
		// own --wp--preset--color--primary directly. Override it — but only inside
		// the .bn-app surface, so the host theme's header/footer chrome keeps its
		// palette while the community UI follows the admin accent.
		$css .= sprintf(
			'.bn-app{--wp--preset--color--primary:%1$s;'
			. '--wp--preset--color--primary-hover:color-mix(in oklch,%1$s 86%%,black);'
			. '--wp--preset--color--primary-light:color-mix(in oklch,%1$s 14%%,white);}',
			$hex
		);

		// Some host themes (e.g. Reign) restyle BuddyNext primary buttons with
		// their own hard-coded brand colour, beating the token-based rule. Reassert
		// the admin accent on BN primary buttons inside the community surface only.
		// Fill and label come from bn-base.css: --bn-accent-solid is the picked
		// colour, deepened only as far as a white label needs (--bn-accent-fg),
		// and a genuinely light colour keeps its fill with a dark label. Both
		// follow the --bn-accent pinned above, so they never disagree.
		$css .= '.bn-app .bn-btn--primary,.bn-app .bn-btn[data-variant="primary"]{'
			. 'background-color:var(--bn-accent-solid)!important;border-color:var(--bn-accent-solid)!important;color:var(--bn-accent-fg)!important;}'
			. '.bn-app .bn-btn--primary:hover,.bn-app .bn-btn[data-variant="primary"]:hover{'
			. 'background-color:color-mix(in oklch,var(--bn-accent-solid) 82%,black)!important;'
			. 'border-color:color-mix(in oklch,var(--bn-accent-solid) 82%,black)!important;}';

		wp_add_inline_style( 'bn-base', $css );
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
		$r         = $to_linear( (float) hexdec( substr( $hex, 0, 2 ) ) );
		$g         = $to_linear( (float) hexdec( substr( $hex, 2, 2 ) ) );
		$b         = $to_linear( (float) hexdec( substr( $hex, 4, 2 ) ) );

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
		// Defang every form of a </style> break-out (the only XSS vector when
		// emitting verbatim CSS in a <style> block): str_ireplace('</style')
		// missed whitespace variants like `</ style>` and `< /style>` that
		// browsers still parse as a closing tag. A regex covers them all without
		// touching valid CSS (which never contains `</style`).
		$css = (string) preg_replace( '#<\s*/\s*style#i', '', $css );
		echo "\n<style id=\"bn-custom-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS, every </style> break-out form stripped above; admin-only field.
	}
}
