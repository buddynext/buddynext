<?php
/**
 * Plugin Name: EDD SL SDK
 * Plugin URI: https://easydigitaldownloads.com
 * Description: The Software Licensing SDK for plugins and themes using Software Licensing.
 * Version: 1.0.4.3
 * Author: Easy Digital Downloads
 * Author URI: https://easydigitaldownloads.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: edd-sl-sdk
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

// Only set up hooks if WordPress functions are available (not during composer autoload initialization).
if ( ! function_exists( 'edd_sl_sdk_register_1_0_4_3' ) && function_exists( 'add_action' ) ) { // WRCS: DEFINED_VERSION.

	// Wbcom: many plugins bundle a copy of this SDK. Upstream includes the Composer autoloader of whichever copy
	// loads first, and every copy shares one Composer class name, so that first copy supplies every class even
	// when a newer copy wins the version contest. Instead, this copy loads only the version-contest class now
	// (stable across versions), queues its own loader as a fallback, and moves it to the front of the queue
	// only if it wins (see edd_sl_sdk_initialize_1_0_4_3()), so the chosen copy's own code is what runs.
	/**
	 * Loads this copy's SDK classes (PSR-4 under src/).
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	function edd_sl_sdk_autoload_1_0_4_3( $class_name ) { // WRCS: DEFINED_VERSION.
		$prefix = 'EasyDigitalDownloads\\Updater\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	if ( ! class_exists( '\\EasyDigitalDownloads\\Updater\\Versions' ) ) {
		require_once __DIR__ . '/src/Versions.php';
	}
	spl_autoload_register( 'edd_sl_sdk_autoload_1_0_4_3' ); // WRCS: DEFINED_VERSION.

	add_action( 'after_setup_theme', array( '\\EasyDigitalDownloads\\Updater\\Versions', 'initialize_latest_version' ), 1, 0 );
	add_action( 'after_setup_theme', 'edd_sl_sdk_register_1_0_4_3', 0, 0 ); // WRCS: DEFINED_VERSION.

	/**
	 * Registers this version of the SDK.
	 */
	function edd_sl_sdk_register_1_0_4_3() {
		$version  = '1.0.4.3';
		$versions = EasyDigitalDownloads\Updater\Versions::instance();
		$versions->register( $version, 'edd_sl_sdk_initialize_1_0_4_3' ); // WRCS: DEFINED_VERSION.
	}

	// phpcs:disable Generic.Functions.OpeningFunctionBraceKernighanRitchie.ContentAfterBrace
	/**
	 * Initializes this version of the SDK.
	 */
	function edd_sl_sdk_initialize_1_0_4_3() {
		// This copy won: its loader goes to the front so its classes load before any other copy's.
		spl_autoload_unregister( 'edd_sl_sdk_autoload_1_0_4_3' ); // WRCS: DEFINED_VERSION.
		spl_autoload_register( 'edd_sl_sdk_autoload_1_0_4_3', true, true ); // WRCS: DEFINED_VERSION.

		// Set up the SDK paths and version using the Path utility.
		EasyDigitalDownloads\Updater\Utilities\Path::set( __FILE__, '1.0.4.3' );
		do_action( 'edd_sl_sdk_registry', EasyDigitalDownloads\Updater\Registry::instance() );
	}

	// Support usage in themes or when included directly - initialize this version if no plugin has loaded a version yet.
	if ( did_action( 'after_setup_theme' ) && ! doing_action( 'after_setup_theme' ) && ! class_exists( '\\EasyDigitalDownloads\\Updater\\Registry', false ) ) {
		edd_sl_sdk_initialize_1_0_4_3(); // WRCS: DEFINED_VERSION.
		EasyDigitalDownloads\Updater\Versions::initialize_latest_version();
	}
}
