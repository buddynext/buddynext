<?php
/**
 * PHPStan bootstrap stub.
 *
 * Defines plugin-specific and WordPress environment constants that are
 * unavailable during static analysis because buddynext.php exits early
 * when ABSPATH is not defined.  This file is loaded via phpstan.neon
 * bootstrapFiles before analysis begins.
 *
 * @package BuddyNext
 */

// Plugin constants.
define( 'BUDDYNEXT_VERSION', '0.1.0' );
define( 'BUDDYNEXT_FILE', __DIR__ . '/buddynext.php' );
define( 'BUDDYNEXT_DIR', __DIR__ . '/' );
define( 'BUDDYNEXT_URL', 'http://example.com/wp-content/plugins/buddynext/' );
define( 'BUDDYNEXT_BASENAME', 'buddynext/buddynext.php' );

// WordPress environment constants used in PermissionService and Installer.
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'wordpress' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../' );
}

// Plugin global helpers — stubs so PHPStan can resolve calls in includes/.
if ( ! function_exists( 'buddynext_service' ) ) {
	/**
	 * Resolve a service from the BuddyNext DI container.
	 *
	 * @param string $key Service identifier.
	 * @return mixed
	 */
	function buddynext_service( string $key ): mixed {
		return null;
	}
}

if ( ! function_exists( 'buddynext_can' ) ) {
	/**
	 * Check a BuddyNext ability for a user.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $ability  Ability slug.
	 * @param array  $context  Optional context.
	 * @return bool
	 */
	function buddynext_can( int $user_id, string $ability, array $context = array() ): bool {
		return false;
	}
}

if ( ! function_exists( 'buddynext_icon' ) ) {
	/**
	 * Echo an inline SVG icon.
	 *
	 * @param string $slug Icon slug.
	 * @param string $class Optional extra CSS class.
	 * @return void
	 */
	function buddynext_icon( string $slug, string $class = '' ): void {}
}

if ( ! function_exists( 'buddynext_get_icon' ) ) {
	/**
	 * Return an inline SVG icon string.
	 *
	 * @param string $slug Icon slug.
	 * @param string $class Optional extra CSS class.
	 * @return string
	 */
	function buddynext_get_icon( string $slug, string $class = '' ): string {
		return '';
	}
}

if ( ! function_exists( 'buddynext_get_template' ) ) {
	/**
	 * Render a BuddyNext template by relative path.
	 *
	 * @param string               $relative  Relative template path (e.g. 'feed/home.php').
	 * @param array<string, mixed> $variables Variables to extract into the template scope.
	 * @return void
	 */
	function buddynext_get_template( string $relative, array $variables = array() ): void {}
}

if ( ! function_exists( 'buddynext_space_url' ) ) {
	/**
	 * Return the public URL for a single space by slug.
	 *
	 * Defined in buddynext.php (root file, outside the analysed includes/ tree).
	 *
	 * @param string $slug Space slug.
	 * @return string
	 */
	function buddynext_space_url( string $slug ): string {
		return '';
	}
}

// WP-CLI is only present in CLI context (DemoCommand). A minimal stub lets
// PHPStan resolve the static command logger calls without the wp-cli stubs
// package. Loaded only by phpstan.neon bootstrapFiles — never at runtime.
if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP-CLI stub for static analysis.
	 */
	class WP_CLI { // phpcs:ignore
		/**
		 * Register a CLI command. WP-CLI accepts a closure, a callable array,
		 * a class name, or a command-object instance.
		 *
		 * @param string              $name     Command name.
		 * @param mixed               $callable Command handler.
		 * @param array<string,mixed> $args     Optional args.
		 * @return void
		 */
		public static function add_command( string $name, $callable, array $args = array() ): void {}

		/**
		 * Print an informational line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function log( string $message ): void {}

		/**
		 * Print a success line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( string $message ): void {}

		/**
		 * Print a warning line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function warning( string $message ): void {}
	}
}
