<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Icon service.
 *
 * Renders SVG icons from the plugin's assets/icons/ directory,
 * sanitized through wp_kses() so inline SVG output is always safe.
 *
 * Usage:
 *   // Echo directly (templates):
 *   buddynext_icon( 'bell' );
 *   buddynext_icon( 'user', 'my-css-class' );
 *
 *   // Get string (class methods):
 *   $html = buddynext_get_icon( 'star' );
 *   echo \BuddyNext\Core\IconService::render( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-sanitized via wp_kses().
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Static SVG icon renderer.
 *
 * Reads icon files from assets/icons/<name>.svg, sanitizes via wp_kses(),
 * and optionally injects a CSS class into the root <svg> element.
 */
class IconService {

	/**
	 * Base directory for icon SVG files (set at class-resolution time).
	 *
	 * Cannot be a true class constant because BUDDYNEXT_DIR is defined at
	 * plugin-load time, after the autoloader resolves the class.
	 *
	 * @var string
	 */
	private static string $icons_dir = '';

	/**
	 * Base directory for emoji SVG files (Microsoft Fluent vendor set).
	 *
	 * @var string
	 */
	private static string $emoji_dir = '';

	/**
	 * Return the absolute path to the icons directory.
	 *
	 * @return string Path with trailing slash.
	 */
	private static function icons_dir(): string {
		if ( '' === self::$icons_dir ) {
			self::$icons_dir = BUDDYNEXT_DIR . 'assets/icons/';
		}

		return self::$icons_dir;
	}

	/**
	 * Return the absolute path to the emoji directory.
	 *
	 * @return string Path with trailing slash.
	 */
	private static function emoji_dir(): string {
		if ( '' === self::$emoji_dir ) {
			self::$emoji_dir = BUDDYNEXT_DIR . 'assets/emoji/';
		}

		return self::$emoji_dir;
	}

	/**
	 * Resolve a reaction-emoji slug to its public asset URL.
	 *
	 * Reads the Microsoft Fluent Emoji SVG vendored in `assets/emoji/`
	 * matching the canonical BuddyNext reaction slug (e.g. `like`, `love`,
	 * `haha`, `wow`, `sad`, `angry`). Returns an empty string when the
	 * slug has no vendored asset so callers can omit the chip rather than
	 * emit a broken image.
	 *
	 * @param string $slug Reaction emoji slug.
	 * @return string Asset URL, or empty string when missing.
	 */
	public static function emoji_url( string $slug ): string {
		$slug = sanitize_file_name( $slug );
		if ( '' === $slug ) {
			return '';
		}
		if ( ! file_exists( self::emoji_dir() . $slug . '.svg' ) ) {
			return '';
		}
		return plugins_url( 'assets/emoji/' . $slug . '.svg', BUDDYNEXT_DIR . 'buddynext.php' );
	}

	/**
	 * Render an `<img>` tag for a reaction emoji.
	 *
	 * The image is loaded from `assets/emoji/<slug>.svg` — sourced from
	 * Microsoft Fluent Emoji (Flat). Renders as a real `<img>` element so
	 * the same emoji appears identically across every host platform
	 * (escaping the inconsistency native Unicode emoji have between
	 * macOS / Windows / Android / Linux). Returns an empty string when
	 * the slug has no vendored asset.
	 *
	 * @param string $slug      Reaction slug. See `assets/emoji/README.md`.
	 * @param string $css_class Optional CSS class appended to `bn-emoji`.
	 * @param string $alt       Optional alt text. Default is the slug as
	 *                          a human label (e.g. `like` → `Like reaction`).
	 *                          Pass `''` explicitly for a decorative image
	 *                          (an `aria-hidden="true"` empty-alt is emitted).
	 * @return string Sanitized `<img>` markup, safe to echo.
	 */
	public static function render_emoji( string $slug, string $css_class = '', ?string $alt = null ): string {
		$url = self::emoji_url( $slug );
		if ( '' === $url ) {
			return '';
		}

		$classes = 'bn-emoji' . ( '' !== $css_class ? ' ' . $css_class : '' );

		if ( null === $alt ) {
			$alt = ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) ) . ' reaction';
		}

		if ( '' === $alt ) {
			return sprintf(
				'<img src="%s" class="%s" alt="" aria-hidden="true" width="20" height="20" loading="lazy" decoding="async" />',
				esc_url( $url ),
				esc_attr( $classes )
			);
		}

		return sprintf(
			'<img src="%s" class="%s" alt="%s" width="20" height="20" loading="lazy" decoding="async" />',
			esc_url( $url ),
			esc_attr( $classes ),
			esc_attr( $alt )
		);
	}

	/**
	 * Load an SVG icon, sanitize it, and return the safe HTML string.
	 *
	 * Returns an empty string when the named icon does not exist on disk.
	 * The returned string is always safe to pass directly to echo.
	 *
	 * @param string $name      Icon slug — filename without the .svg extension.
	 *                          Examples: 'user', 'bell', 'graduation-cap'.
	 * @param string $css_class Optional CSS class(es) to inject into the <svg> element.
	 * @return string Sanitized SVG markup safe for inline output.
	 */
	public static function render( string $name, string $css_class = '' ): string {
		$path = self::icons_dir() . sanitize_file_name( $name ) . '.svg';

		if ( ! file_exists( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, not a remote HTTP request.
		$svg = file_get_contents( $path );

		if ( false === $svg || '' === trim( $svg ) ) {
			return '';
		}

		// Always inject bn-icon as base class so every icon gets the
		// sizing, stroke, and display rules from bn-base.css.
		$classes = 'bn-icon' . ( '' !== $css_class ? ' ' . $css_class : '' );
		$svg     = str_replace( '<svg ', '<svg class="' . esc_attr( $classes ) . '" ', $svg );

		return wp_kses( $svg, self::allowed_tags() );
	}

	/**
	 * WP_kses allowlist for inline SVG elements and attributes.
	 *
	 * Exposed as public so template files can pass it directly to wp_kses()
	 * when needed:  echo wp_kses( $svg_string, IconService::allowed_tags() ).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_tags(): array {
		return array(
			'svg'      => array(
				'xmlns'           => true,
				'viewbox'         => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'class'           => true,
				'aria-hidden'     => true,
				'role'            => true,
				'width'           => true,
				'height'          => true,
			),
			'path'     => array(
				'd'      => true,
				'fill'   => true,
				'stroke' => true,
				'class'  => true,
			),
			'circle'   => array(
				'cx'     => true,
				'cy'     => true,
				'r'      => true,
				'fill'   => true,
				'stroke' => true,
				'class'  => true,
			),
			'line'     => array(
				'x1'    => true,
				'x2'    => true,
				'y1'    => true,
				'y2'    => true,
				'class' => true,
			),
			'polyline' => array(
				'points' => true,
				'class'  => true,
			),
			'polygon'  => array(
				'points' => true,
				'class'  => true,
			),
			'rect'     => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
				'class'  => true,
			),
		);
	}
}
