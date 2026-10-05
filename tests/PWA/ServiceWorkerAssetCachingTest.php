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
		$this->assert_harness_passes();
	}

	/**
	 * Folder names a site may sit in.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function folder_names(): array {
		return array(
			'space'      => array( 'my site' ),
			'brackets'   => array( 'site(1)' ),
			'plus'       => array( 'client+shop' ),
			'apostrophe' => array( "o'brien" ),
			'non-ascii'  => array( 'café' ),
		);
	}

	/**
	 * A plugin path in any of these folders still matches what the browser requests.
	 *
	 * The worker compares url.pathname, whose encoding is the browser's own: "%20"
	 * for a space, but ( ) + ' @ left as they are. Encoding in PHP got one set right
	 * and the other wrong (card 10369178676, and its QA bounce), so PHP now sends the
	 * decoded path and the worker encodes it the browser's way.
	 *
	 * @dataProvider folder_names
	 *
	 * @param string $folder Folder the site is installed in.
	 * @return void
	 */
	public function test_a_plugin_path_in_any_folder_is_still_cached( string $folder ): void {
		// Move BuddyNext's files, own paths and precached shell alike, under that folder.
		$base  = home_url( '/' . $folder . '/wp-content/plugins/buddynext/' );
		$paths = static fn() => array( $base );
		$shell = static fn( array $urls ) => str_replace( BUDDYNEXT_URL, $base, $urls );
		add_filter( 'buddynext_pwa_asset_paths', $paths );
		add_filter( 'buddynext_pwa_shell_assets', $shell );

		$script = ( new PwaService() )->get_service_worker_script();
		$this->assertStringContainsString( '.map(browserPath)', $script );
		$this->assert_harness_passes();

		remove_filter( 'buddynext_pwa_asset_paths', $paths );
		remove_filter( 'buddynext_pwa_shell_assets', $shell );
	}

	/**
	 * Run the generated worker through the harness and require every check to pass.
	 *
	 * @return void
	 */
	private function assert_harness_passes(): void {
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
