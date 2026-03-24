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
