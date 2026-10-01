<?php
/**
 * Behaviour test for the generated service worker's static-asset caching.
 *
 * @package BuddyNext\Tests\PWA
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\PWA;

use BuddyNext\PWA\PwaService;

/**
 * Runs the real generated worker through tests/PWA/sw-harness.mjs (an in-memory
 * Cache API and a scripted network) and asserts the caching contract:
 * - only BuddyNext's own files are intercepted; the theme, other plugins and
 *   uploads are left to the browser;
 * - the first view after a ?ver= bump gets the NEW file (Zoho #41951);
 * - offline, a versioned shell file still resolves to its precached copy.
 *
 * Skipped when Node is not on PATH (the harness needs Node 18+ for Request,
 * Response and URL globals).
 *
 * @covers \BuddyNext\PWA\PwaService::get_service_worker_script
 */
class ServiceWorkerAssetCachingTest extends \WP_UnitTestCase {

	/**
	 * The worker passes every caching check in the harness.
	 *
	 * @return void
	 */
	public function test_worker_caches_only_own_assets_and_never_serves_a_stale_version(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'Node is not available.' );
		}

		$script = ( new PwaService() )->get_service_worker_script();
		$this->assertStringContainsString( 'const OWN_ASSET_PATHS = ', $script );

		$file = wp_tempnam( 'bn-sw' );
		file_put_contents( $file, $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp fixture.

		$origin = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$output = array();
		$status = 0;
		exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/sw-harness.mjs' ) . ' ' . escapeshellarg( $file ) . ' ' . escapeshellarg( $origin ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- runs the JS harness.
		wp_delete_file( $file );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}
}
