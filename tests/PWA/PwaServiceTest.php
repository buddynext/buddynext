<?php
/**
 * Tests for the BuddyNext PWA service.
 *
 * @package BuddyNext\Tests\PWA
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\PWA;

use BuddyNext\PWA\PwaService;

/**
 * Verifies manifest output and service worker registration.
 *
 * @covers \BuddyNext\PWA\PwaService
 */
class PwaServiceTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var PwaService
	 */
	private PwaService $pwa;

	/**
	 * Create a fresh instance before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->pwa = new PwaService();
	}

	/**
	 * init() attaches the wp_head hook.
	 */
	public function test_init_adds_wp_head_hook(): void {
		$this->pwa->init();
		$this->assertNotFalse(
			has_action( 'wp_head', array( $this->pwa, 'output_manifest_link' ) )
		);
	}

	/**
	 * init() registers the manifest REST route.
	 */
	public function test_init_adds_rest_api_init_hook(): void {
		$this->pwa->init();
		$this->assertNotFalse(
			has_action( 'rest_api_init', array( $this->pwa, 'register_routes' ) )
		);
	}

	/**
	 * get_manifest() returns an array.
	 */
	public function test_get_manifest_returns_array(): void {
		$manifest = $this->pwa->get_manifest();
		$this->assertIsArray( $manifest );
	}

	/**
	 * get_manifest() includes required PWA keys.
	 */
	public function test_get_manifest_has_required_keys(): void {
		$manifest = $this->pwa->get_manifest();
		foreach ( array( 'name', 'short_name', 'start_url', 'display', 'icons' ) as $key ) {
			$this->assertArrayHasKey( $key, $manifest );
		}
	}

	/**
	 * get_manifest() display is one of the standard values.
	 */
	public function test_get_manifest_display_is_valid(): void {
		$manifest = $this->pwa->get_manifest();
		$this->assertContains(
			$manifest['display'],
			array( 'standalone', 'fullscreen', 'minimal-ui', 'browser' )
		);
	}

	/**
	 * buddynext_pwa_manifest filter can override manifest values.
	 */
	public function test_filter_can_override_manifest(): void {
		add_filter(
			'buddynext_pwa_manifest',
			function ( array $manifest ): array {
				$manifest['name'] = 'My Custom App';
				return $manifest;
			}
		);

		$manifest = $this->pwa->get_manifest();
		$this->assertEquals( 'My Custom App', $manifest['name'] );

		remove_all_filters( 'buddynext_pwa_manifest' );
	}

	/**
	 * output_manifest_link() emits a <link> tag.
	 */
	public function test_output_manifest_link_emits_link_tag(): void {
		ob_start();
		$this->pwa->output_manifest_link();
		$output = ob_get_clean();
		$this->assertStringContainsString( '<link rel="manifest"', $output );
	}

	/**
	 * get_service_worker_script() returns a non-empty string.
	 */
	public function test_get_service_worker_script_returns_string(): void {
		$script = $this->pwa->get_service_worker_script();
		$this->assertIsString( $script );
		$this->assertNotEmpty( $script );
	}
}
