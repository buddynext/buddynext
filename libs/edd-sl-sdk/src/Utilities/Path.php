<?php
/**
 * Path utility class.
 *
 * @package EasyDigitalDownloads\Updater
 * @since 1.0.1
 */

namespace EasyDigitalDownloads\Updater\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

/**
 * Path utility class for managing SDK paths and URLs.
 */
class Path {

	/**
	 * Stores the current SDK directory.
	 *
	 * @var string
	 */
	private static $sdk_dir = '';

	/**
	 * Stores the current SDK URL.
	 *
	 * @var string
	 */
	private static $sdk_url = '';

	/**
	 * Stores the current SDK version.
	 *
	 * @var string
	 */
	private static $sdk_version = '';

	/**
	 * Sets the SDK paths and version based on a file location.
	 *
	 * @since 1.0.1
	 * @param string $file    The __FILE__ constant from the SDK main plugin file.
	 * @param string $version The version number.
	 * @return void
	 */
	public static function set( $file, $version = '1.0.0' ) {
		self::$sdk_dir     = dirname( $file );
		self::$sdk_version = $version;

		self::$sdk_url = trailingslashit( self::url_for( self::$sdk_dir, $file ) );
	}

	/**
	 * Works out the public URL of the SDK folder wherever it is bundled.
	 *
	 * Wbcom: upstream only strips WP_CONTENT_DIR, which breaks when the plugin or theme folder is a symlink
	 * (__FILE__ is then the real path outside wp-content, and the asset URL 404s).
	 *
	 * @param string $dir  The SDK folder.
	 * @param string $file The SDK main file.
	 * @return string
	 */
	private static function url_for( $dir, $file ) {
		$dir     = wp_normalize_path( $dir );
		$content = wp_normalize_path( WP_CONTENT_DIR );

		// Plugin or theme inside wp-content: the common case.
		if ( 0 === strpos( $dir, trailingslashit( $content ) ) ) {
			return content_url( substr( $dir, strlen( $content ) ) );
		}

		// Theme folder that is a symlink: match the real path of the active theme and its parent.
		$themes = array(
			array( get_stylesheet_directory(), get_stylesheet_directory_uri() ),
			array( get_template_directory(), get_template_directory_uri() ),
		);
		foreach ( $themes as $theme ) {
			$real = realpath( $theme[0] );
			if ( $real && 0 === strpos( $dir, trailingslashit( wp_normalize_path( $real ) ) ) ) {
				return $theme[1] . substr( $dir, strlen( wp_normalize_path( $real ) ) );
			}
		}

		// Plugin folder that is a symlink: WordPress maps the real path back to the plugin URL.
		return plugins_url( '', $file );
	}

	/**
	 * Gets the SDK directory.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public static function get_dir() {
		return self::$sdk_dir;
	}

	/**
	 * Gets the SDK URL.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public static function get_url() {
		return self::$sdk_url;
	}

	/**
	 * Gets the SDK version.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public static function get_version() {
		return self::$sdk_version;
	}
}
