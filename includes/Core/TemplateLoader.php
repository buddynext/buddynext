<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Template resolution and rendering.
 *
 * Resolves BuddyNext PHP templates in this order:
 *   1. {active-child-theme}/buddynext/{template}.php
 *   2. {parent-theme}/buddynext/{template}.php
 *   3. {plugin}/templates/{template}.php
 *
 * Fires buddynext_before_template / buddynext_after_template hooks
 * so Pro and bridges can inject content around any template.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Locates and renders BuddyNext PHP templates.
 */
class TemplateLoader {

	/**
	 * Absolute path to the plugin's own templates directory (with trailing slash).
	 *
	 * @var string
	 */
	private string $templates_dir;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->templates_dir = BUDDYNEXT_DIR . 'templates/';
	}

	/**
	 * Locate a template file, preferring theme overrides.
	 *
	 * @param string $relative Relative path such as 'feed/home.php'.
	 * @return string|null Absolute path, or null when the template is not found.
	 */
	public function locate( string $relative ): ?string {
		$relative = ltrim( $relative, '/' );

		// 1. Child-theme override.
		$child = get_stylesheet_directory() . '/buddynext/' . $relative;
		if ( file_exists( $child ) ) {
			return $child;
		}

		// 2. Parent-theme override (only distinct when a child theme is active).
		$parent = get_template_directory() . '/buddynext/' . $relative;
		if ( $parent !== $child && file_exists( $parent ) ) {
			return $parent;
		}

		// 3. Plugin default.
		$default = $this->templates_dir . $relative;
		if ( file_exists( $default ) ) {
			return $default;
		}

		return null;
	}

	/**
	 * Render a template, passing variables into its scope.
	 *
	 * @param string               $relative  Relative template path (e.g. 'feed/home.php').
	 * @param array<string, mixed> $variables Variables to extract into the template scope.
	 * @return void
	 */
	public function render( string $relative, array $variables = array() ): void {
		$path = $this->locate( $relative );

		if ( null === $path ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<!-- BuddyNext: template not found: ' . esc_html( $relative ) . ' -->' . "\n";
			}
			return;
		}

		/**
		 * Fires before a BuddyNext template is rendered.
		 *
		 * @param string $path     Absolute path to the template file.
		 * @param string $relative Relative template identifier.
		 */
		do_action( 'buddynext_before_template', $path, $relative );

		// Bring the passed variables into the template scope WITHOUT extract().
		// Callers are internal (developer-controlled), but we still guard: only
		// string keys that are valid PHP identifiers are imported, and the
		// method's own locals ($path, $relative, $variables) cannot be shadowed.
		// This keeps templates statically analysable and rejects any stray /
		// numeric / collision-prone keys.
		$bn_reserved = array( 'path', 'relative', 'variables', 'bn_reserved', 'bn_key', 'bn_value', 'bn_filtered', 'bn_html' );
		foreach ( $variables as $bn_key => $bn_value ) {
			if ( ! is_string( $bn_key )
				|| in_array( $bn_key, $bn_reserved, true )
				|| ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $bn_key ) ) {
				continue;
			}
			$$bn_key = $bn_value;
		}
		unset( $bn_reserved, $bn_key, $bn_value );

		// Output filter, gated on has_filter() so the default render path keeps ZERO
		// overhead (no output buffering) - we only buffer when a plugin is actually
		// listening. This lets a plugin transform a template's fully-rendered HTML
		// without copying the file, complementing the before/after wrap actions.
		$bn_filtered = has_filter( 'buddynext_template_html' );
		if ( $bn_filtered ) {
			ob_start();
		}

		include $path;

		if ( $bn_filtered ) {
			$bn_html = (string) ob_get_clean();
			/**
			 * Filter a BuddyNext template's fully-rendered HTML.
			 *
			 * Fires only when a callback is attached (the render path is unbuffered
			 * otherwise). The template has already escaped its own output; a filter
			 * owns the safety of whatever markup it returns.
			 *
			 * @param string $bn_html  The rendered template output.
			 * @param string $relative Relative template identifier (e.g. 'feed/home.php').
			 * @param string $path     Absolute template path.
			 */
			echo apply_filters( 'buddynext_template_html', $bn_html, $relative, $path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template self-escapes; the filter owns its returned markup.
		}

		/**
		 * Fires after a BuddyNext template is rendered.
		 *
		 * @param string $path     Absolute path to the template file.
		 * @param string $relative Relative template identifier.
		 */
		do_action( 'buddynext_after_template', $path, $relative );
	}

	/**
	 * Capture a template's output to a string.
	 *
	 * @param string               $relative  Relative template path.
	 * @param array<string, mixed> $variables Variables to pass to the template.
	 * @return string Rendered HTML.
	 */
	public function capture( string $relative, array $variables = array() ): string {
		ob_start();
		$this->render( $relative, $variables );
		return (string) ob_get_clean();
	}
}
