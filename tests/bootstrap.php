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

// WB Gamification read/submit API stubs. The GamificationBridge + Achievements
// tab guard on these; tests drive data through the $GLOBALS['wb_gam_test'] store.
// Each is guarded so the real plugin's functions win when it is active.
$GLOBALS['wb_gam_test'] = array(
	'actions' => array(),
	'points'  => array(),
	'level'   => array(),
	'badges'  => array(),
	'streak'  => array(),
);
if ( ! function_exists( 'wb_gam_submit_event' ) ) {
	/**
	 * Stub: mirrors the real submit by firing wb_gamification_event with the slug.
	 *
	 * @param int    $user_id   User.
	 * @param string $action_id Action slug.
	 * @param array  $context   Context.
	 * @return bool
	 */
	function wb_gam_submit_event( $user_id, $action_id, $context = array() ) {
		do_action( 'wb_gamification_event', $action_id, (int) $user_id, (array) $context );
		return true;
	}
}
if ( ! function_exists( 'wb_gam_register_action' ) ) {
	/**
	 * Stub: record a registered action.
	 *
	 * @param array $args Action definition (needs 'id').
	 * @return void
	 */
	function wb_gam_register_action( array $args ) {
		if ( isset( $args['id'] ) ) {
			$GLOBALS['wb_gam_test']['actions'][ $args['id'] ] = $args;
		}
	}
}
if ( ! function_exists( 'wb_gam_get_actions' ) ) {
	/**
	 * Stub: registered actions.
	 *
	 * @return array
	 */
	function wb_gam_get_actions() {
		return $GLOBALS['wb_gam_test']['actions'];
	}
}
if ( ! function_exists( 'wb_gam_get_user_points' ) ) {
	/**
	 * Stub: user points.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	function wb_gam_get_user_points( $user_id ) {
		return (int) ( $GLOBALS['wb_gam_test']['points'][ $user_id ] ?? 0 );
	}
}
if ( ! function_exists( 'wb_gam_get_user_level' ) ) {
	/**
	 * Stub: user level row or null.
	 *
	 * @param int $user_id User.
	 * @return array|null
	 */
	function wb_gam_get_user_level( $user_id ) {
		return $GLOBALS['wb_gam_test']['level'][ $user_id ] ?? null;
	}
}
if ( ! function_exists( 'wb_gam_get_user_badges' ) ) {
	/**
	 * Stub: earned badges list.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	function wb_gam_get_user_badges( $user_id ) {
		return (array) ( $GLOBALS['wb_gam_test']['badges'][ $user_id ] ?? array() );
	}
}
if ( ! function_exists( 'wb_gam_get_user_streak' ) ) {
	/**
	 * Stub: streak data.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	function wb_gam_get_user_streak( $user_id ) {
		return $GLOBALS['wb_gam_test']['streak'][ $user_id ] ?? array(
			'current_streak' => 0,
			'longest_streak' => 0,
			'last_active'    => '',
		);
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

// Career Board stubs removed — CareerBoardBridge moved to BuddyNext Pro.
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
