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

		// Resolve the asset URL through WordPress so it is correct on every host.
		// The previous approach derived the URL from $_SERVER['DOCUMENT_ROOT'],
		// which breaks wherever realpath() resolves a symlink or the document root
		// does not prefix-match the plugin path (many live hosts, LocalWP): the
		// str_replace strips nothing, the full filesystem path leaks into the URL,
		// and the SDK assets 404. plugins_url() handles HTTPS, host, subdirectory
		// installs, symlinks and multisite correctly.
		self::$sdk_url = trailingslashit( plugins_url( '', $file ) );
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
