<?php
/**
 * The generated isolation mu-plugin must detect a BuddyNext route on a
 * SUBDIRECTORY WordPress install, not just at the domain root (card 10317871293).
 *
 * The route matcher lives in the generated mu-plugin (it runs before the plugin
 * boots), so the test extracts that exact function from Installer::mu_plugin_content()
 * and drives it - the generated code is what ships, so the generated code is what
 * is asserted.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\Installer::mu_plugin_content
 */
class IsolationSubdirRouteTest extends WP_UnitTestCase {

	private static string $fn_body = '';
	private static int $counter    = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$ref = new \ReflectionMethod( Installer::class, 'mu_plugin_content' );
		$ref->setAccessible( true );
		$content = (string) $ref->invoke( null );

		// Extract the matcher function verbatim, from its declaration to the top-level
		// call site that follows it.
		$start = strpos( $content, 'function buddynext_mu_is_bn_request()' );
		$end   = strpos( $content, "\nif ( buddynext_mu_is_bn_request() )" );
		self::$fn_body = (string) substr( $content, $start, $end - $start );
	}

	public function tear_down(): void {
		delete_option( 'home' );
		parent::tear_down();
	}

	/**
	 * Compile a fresh copy of the matcher (unique name = fresh `static $result`)
	 * and run it for one request.
	 *
	 * @param string $home        The `home` option value.
	 * @param string $request_uri The REQUEST_URI to test.
	 * @return bool
	 */
	private function run_matcher( string $home, string $request_uri ): bool {
		update_option( 'home', $home );
		$_SERVER['REQUEST_URI'] = $request_uri;

		$name = 'bn_mu_is_bn_request_probe_' . ( ++self::$counter );
		$src  = str_replace( 'function buddynext_mu_is_bn_request()', 'function ' . $name . '()', self::$fn_body );
		eval( $src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- compiling the generated matcher under test.

		return (bool) $name();
	}

	public function test_subdirectory_install_detects_a_hub_route(): void {
		$this->assertTrue(
			$this->run_matcher( 'http://example.test/community', '/community/activity/' ),
			'a hub route under a subdirectory install must be detected'
		);
	}

	public function test_subdirectory_install_ignores_a_non_hub_route(): void {
		$this->assertFalse(
			$this->run_matcher( 'http://example.test/community', '/community/shop/' ),
			'a non-hub route under a subdirectory must NOT be isolated'
		);
	}

	public function test_subdirectory_bare_home_is_not_a_hub(): void {
		$this->assertFalse( $this->run_matcher( 'http://example.test/community', '/community/' ) );
	}

	public function test_root_install_still_detects_a_hub_route(): void {
		$this->assertTrue(
			$this->run_matcher( 'http://example.test', '/activity/' ),
			'the root-install path must keep working'
		);
	}

	public function test_root_install_ignores_a_non_hub_route(): void {
		$this->assertFalse( $this->run_matcher( 'http://example.test', '/shop/' ) );
	}

	/**
	 * A percent-encoded hub segment still matches — the matcher decodes the path
	 * before comparing, so /%61ctivity/ (activity) is detected as a hub route
	 * instead of silently skipping isolation (card 10317871293, second path).
	 *
	 * @return void
	 */
	public function test_percent_encoded_hub_segment_is_detected(): void {
		$this->assertTrue(
			$this->run_matcher( 'http://example.test', '/%61ctivity/' ),
			'a percent-encoded hub route must decode and match'
		);
	}

	/**
	 * The same, under a subdirectory install: both the base strip and the slug
	 * match run on the decoded path.
	 *
	 * @return void
	 */
	public function test_percent_encoded_hub_segment_is_detected_under_subdirectory(): void {
		$this->assertTrue(
			$this->run_matcher( 'http://example.test/community', '/community/%61ctivity/' ),
			'a percent-encoded hub route under a subdirectory must decode and match'
		);
	}
}
