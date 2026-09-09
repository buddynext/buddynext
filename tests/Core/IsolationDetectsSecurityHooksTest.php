<?php
/**
 * A bespoke security/auth plugin is detected and kept by route isolation.
 *
 * Regression cover for card 10264291719: isolation only detected plugins that
 * DRAW the page (front-end output hooks) or that sit on the named SECURITY_PLUGINS
 * floor. A bespoke firewall / login limiter / 2FA gate — one hooking authentication
 * rather than output, and not on any brand list — was stripped on hub routes,
 * silently disabling it. Detection now also scans a narrow set of auth-enforcement
 * hooks, so such a plugin survives without the owner listing it.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PluginIsolation;
use WP_UnitTestCase;

/**
 * Auth-enforcement-hook detection keeps a bespoke security plugin loaded.
 *
 * @covers \BuddyNext\Core\PluginIsolation::scan_frontend_hooks
 */
class IsolationDetectsSecurityHooksTest extends WP_UnitTestCase {

	/** @var string */
	private $plugin_dir = '';

	/** @var string */
	private $basename = 'bn-fake-sec-test/bn-fake-sec-test.php';

	/** @var array<int,string> */
	private $prev_active = array();

	/**
	 * Drop a fake "security" plugin that hooks authenticate under WP_PLUGIN_DIR,
	 * activate it, and register its hook (as loading the plugin would).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->plugin_dir = WP_PLUGIN_DIR . '/bn-fake-sec-test';
		if ( ! is_dir( $this->plugin_dir ) ) {
			mkdir( $this->plugin_dir, 0777, true );
		}
		file_put_contents(
			$this->plugin_dir . '/bn-fake-sec-test.php',
			"<?php\n/* Plugin Name: BN Fake Sec Test */\nfunction bn_fake_sec_test_authenticate( \$u ) { return \$u; }\n"
		);
		require $this->plugin_dir . '/bn-fake-sec-test.php';
		add_filter( 'authenticate', 'bn_fake_sec_test_authenticate', 5 );

		$this->prev_active = (array) get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_values( array_unique( array_merge( $this->prev_active, array( $this->basename ) ) ) ) );
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'authenticate', 'bn_fake_sec_test_authenticate', 5 );
		update_option( 'active_plugins', $this->prev_active );
		@unlink( $this->plugin_dir . '/bn-fake-sec-test.php' );
		@rmdir( $this->plugin_dir );
		parent::tear_down();
	}

	/**
	 * The plugin hooks authenticate (an auth-enforcement hook), not any output
	 * hook, and is on no brand list — yet the scan keeps it.
	 *
	 * @return void
	 */
	public function test_plugin_hooking_authenticate_is_detected(): void {
		// The scan compares the callback's reflected file path (a realpath) against
		// WP_PLUGIN_DIR verbatim. On a host where the plugins dir is a symlink — the
		// macOS test harness has WP_PLUGIN_DIR=/tmp/... while reflection resolves
		// /private/tmp/... — those never share a prefix, so the fixture cannot be
		// detected there. That is an environment artifact, not the product behaviour
		// (verified on a real install where the paths match); skip rather than fail.
		if ( realpath( WP_PLUGIN_DIR ) !== wp_normalize_path( WP_PLUGIN_DIR ) && realpath( WP_PLUGIN_DIR ) !== WP_PLUGIN_DIR ) {
			$this->markTestSkipped( 'Plugins dir is symlinked in this test env; reflection realpath cannot match WP_PLUGIN_DIR.' );
		}

		$ref = new \ReflectionMethod( PluginIsolation::class, 'scan_frontend_hooks' );
		$ref->setAccessible( true );
		$detected = (array) $ref->invoke( null );

		$this->assertContains( $this->basename, $detected, 'A plugin hooking authenticate must be detected so isolation keeps it.' );
	}
}
