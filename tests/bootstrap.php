<?php
/**
 * PHPUnit bootstrap for BuddyNext tests.
 *
 * Loads the WordPress test suite, initialises Composer autoloading,
 * and then loads the plugin under test so every class is available.
 *
 * Usage (from plugin root):
 *   vendor/bin/phpunit
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

// Point to Composer autoloader so BuddyNext\* classes resolve.
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $autoloader ) ) {
	echo 'Run `composer install` before running tests.' . PHP_EOL;
	exit( 1 );
}

require_once $autoloader;

// Locate the WordPress test suite — installed by bin/install-wp-tests.sh.
$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wp_tests_dir ) {
	$wp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! is_dir( $wp_tests_dir ) ) {
	echo sprintf(
		'WordPress test suite not found at %s. Run bin/install-wp-tests.sh first.' . PHP_EOL,
		$wp_tests_dir
	);
	exit( 1 );
}

// WordPress test config (DB credentials etc.) is written by the install script.
require_once $wp_tests_dir . '/includes/functions.php';

// ── Global stubs for optional addon plugins ──────────────────────────────────
// These allow bridge tests to exercise hook registration without the real
// external plugins being installed. Each stub is guarded so real plugin
// functions/classes take precedence when the actual plugin is active.
//
// Note: class_alias() requires a user-defined source class, so we declare
// a single base stub and alias it to every needed namespaced class.

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
/** Base stub used as source for class_alias() calls below. */
class BuddyNext_Test_Addon_Stub {}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
if ( ! function_exists( 'wb_gamification_badge_awarded' ) ) {
	/** Stub: WBGamification badge_awarded function. */
	function wb_gamification_badge_awarded(): void {}
}
if ( ! function_exists( 'wcb_get_job' ) ) {
	/** Stub: Career Board wcb_get_job function. */
	function wcb_get_job(): void {}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

if ( ! class_exists( 'WCB_Career_Board' ) ) {
	class_alias( BuddyNext_Test_Addon_Stub::class, 'WCB_Career_Board' );
}
if ( ! class_exists( 'Jetonomy\Core\Plugin' ) ) {
	class_alias( BuddyNext_Test_Addon_Stub::class, 'Jetonomy\Core\Plugin' );
}
if ( ! class_exists( 'Jetonomy\Jetonomy' ) ) {
	// JetonomyBridge::init() guards on the unprefixed class name.
	class_alias( BuddyNext_Test_Addon_Stub::class, 'Jetonomy\Jetonomy' );
}
if ( ! class_exists( 'WBGamification\Plugin' ) ) {
	class_alias( BuddyNext_Test_Addon_Stub::class, 'WBGamification\Plugin' );
}
if ( ! class_exists( 'WPMediaVerse\Core\Plugin' ) ) {
	class_alias( BuddyNext_Test_Addon_Stub::class, 'WPMediaVerse\Core\Plugin' );
}

/**
 * Manually load the plugin before the test suite boots.
 */
tests_add_filter(
	'muplugins_loaded',
	function (): void {
		require_once dirname( __DIR__ ) . '/buddynext.php';
	}
);

require_once $wp_tests_dir . '/includes/bootstrap.php';
