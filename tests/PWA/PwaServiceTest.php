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

	/**
	 * The worker precaches the SHELL, not just "/".
	 *
	 * Precaching only "/" meant the single cached page rendered with 61 failed
	 * assets — a raw unstyled list of links. If this ever regresses, the offline
	 * page loses its styling and looks broken rather than deliberate.
	 *
	 * @return void
	 */
	public function test_service_worker_precaches_the_offline_shell(): void {
		$script = $this->pwa->get_service_worker_script();

		// Asserted via the service's own resolution rather than a hardcoded path:
		// rest_url() differs between pretty and plain permalinks, and pinning one
		// shape here would pass on the test site while missing the other.
		$this->assertStringContainsString( 'OFFLINE_URL', $script, 'The offline page must be precached.' );
		$this->assertMatchesRegularExpression(
			'/OFFLINE_URL = "[^"]*pwa[^"]*offline[^"]*"/',
			$script,
			'OFFLINE_URL must resolve to the offline route under either permalink style.'
		);
		$this->assertStringContainsString( 'bn-base.css', $script, 'Base tokens must be precached or the offline page is unstyled.' );
		$this->assertStringContainsString( 'bn-shell.css', $script );
	}

	/**
	 * Page HTML and REST responses are never cached.
	 *
	 * A community page is personalised. Storing rendered HTML would let one
	 * member's feed, notifications or DMs be replayed to whoever opens the browser
	 * next, and would present stale content as current. This is a privacy
	 * boundary, not a performance choice.
	 *
	 * @return void
	 */
	public function test_service_worker_never_caches_personalised_responses(): void {
		$script = $this->pwa->get_service_worker_script();

		/*
		 * Asserted through the injected path constants rather than literal
		 * `indexOf('/wp-json/')` matches, which is what this used to do.
		 *
		 * Those literals were the bug: a hardcoded leading "/wp-admin" only matches
		 * a site at the origin root, so on a subdirectory install the bail never
		 * fired and the worker cached wp-admin. Pinning the literal here meant this
		 * test PASSED against that bug and then FAILED against its fix — it was
		 * describing the implementation, not the boundary the docblock above claims.
		 *
		 * The boundary is: each of these paths is excluded, whatever the site's
		 * layout. ServiceWorkerAdminBailTest covers the subdirectory case itself.
		 */
		$this->assertStringContainsString( 'indexOf(REST_PATH)', $script, 'REST must be excluded from the worker.' );
		$this->assertStringContainsString( 'indexOf(ADMIN_PATH)', $script, 'wp-admin must never be intercepted.' );
		$this->assertStringContainsString( 'indexOf(LOGIN_PATH)', $script, 'The login screen must never be intercepted.' );
		// Not asserted as "wp-json": with plain permalinks rest_url() has no such
		// path and REST_PATH is "/", which the worker deliberately ignores in favour
		// of the rest_route query check. Asserting the literal would pin one
		// permalink setting.
		$this->assertStringContainsString( 'const REST_PATH', $script, 'REST_PATH must be injected.' );
		$this->assertStringContainsString( "REST_PATH !== '/'", $script, 'A path-less REST root must not bail on every request.' );
		$this->assertMatchesRegularExpression( '~ADMIN_PATH\s*=\s*"[^"]*wp-admin~', $script, 'ADMIN_PATH must point at the admin.' );
		$this->assertMatchesRegularExpression( '~LOGIN_PATH\s*=\s*"[^"]*wp-login~', $script, 'LOGIN_PATH must point at wp-login.php.' );

		// The navigate branch falls back to the OFFLINE page and nothing else: it
		// must not read a stored copy of the page that was requested.
		$navigate = substr( $script, (int) strpos( $script, "request.mode === 'navigate'" ) );
		$navigate = substr( $navigate, 0, (int) strpos( $navigate, 'Static sub-resources' ) );

		$this->assertStringContainsString( 'OFFLINE_URL', $navigate, 'A failed navigation must serve the offline page.' );
		$this->assertStringNotContainsString( 'cache.put', $navigate, 'A navigation response must never be written to the cache.' );
	}

	/**
	 * The redirect guards survive. They are what fixed the P1 outage.
	 *
	 * @return void
	 */
	public function test_service_worker_keeps_the_redirect_guards(): void {
		$script = $this->pwa->get_service_worker_script();

		$this->assertStringContainsString( 'bnCacheable', $script );
		$this->assertStringContainsString( 'response.redirected', $script, 'Redirected responses must still be rebuilt before caching.' );
		$this->assertStringContainsString( '.redirected', $script, 'Cached redirected responses must still be refused.' );
	}

	/**
	 * The runtime asset cache is bounded.
	 *
	 * Unbounded growth on a member's device gets the WHOLE origin evicted when
	 * storage runs low — including the shell that makes offline work at all.
	 *
	 * @return void
	 */
	public function test_asset_cache_is_capped(): void {
		$script = $this->pwa->get_service_worker_script();

		$this->assertStringContainsString( 'ASSET_CACHE_LIMIT', $script );
		$this->assertMatchesRegularExpression( '/ASSET_CACHE_LIMIT\s*=\s*\d+/', $script );
		$this->assertStringContainsString( 'bnTrim', $script );
	}

	/**
	 * Caches are version-keyed so a plugin update cannot serve stale assets.
	 *
	 * @return void
	 */
	public function test_caches_are_version_keyed_and_old_ones_purged(): void {
		$script = $this->pwa->get_service_worker_script();
		$version = defined( 'BUDDYNEXT_VERSION' ) ? BUDDYNEXT_VERSION : '1.0.0';

		$this->assertStringContainsString( 'buddynext-shell-' . $version, $script );
		$this->assertStringContainsString( 'buddynext-assets-' . $version, $script );
		$this->assertStringContainsString( 'caches.delete', $script, 'activate must purge caches from older versions.' );
	}

	/**
	 * The offline page is a real HTML document, styled, and honest about itself.
	 *
	 * @return void
	 */
	public function test_offline_page_is_a_styled_document(): void {
		$html = $this->pwa->get_offline_page();

		$this->assertStringStartsWith( '<!doctype html>', $html );
		$this->assertStringContainsString( 'bn-base.css', $html, 'Must link the precached tokens or it renders unstyled.' );
		$this->assertStringContainsString( 'data-bn-offline-retry', $html, 'Retry is wired by attribute, never inline script.' );
		$this->assertStringNotContainsString( 'onclick', $html, 'Inline handlers are a blocked pattern (ux-audit F2).' );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $html, 'The member should see whose community this is.' );
	}

	/**
	 * The offline page carries the site's colour choice.
	 *
	 * Without this a dark community flashes a white page the moment the network
	 * drops, which reads as a different site rather than the same one offline.
	 *
	 * @return void
	 */
	public function test_offline_page_follows_the_site_theme(): void {
		update_option( 'buddynext_default_theme', 'dark' );
		$this->assertStringContainsString( 'data-bn-theme="dark"', $this->pwa->get_offline_page() );

		update_option( 'buddynext_default_theme', 'auto' );
		$this->assertStringContainsString( 'data-bn-theme="auto"', $this->pwa->get_offline_page() );

		// An unknown stored value must not reach the attribute verbatim.
		update_option( 'buddynext_default_theme', 'neon' );
		$this->assertStringContainsString( 'data-bn-theme="auto"', $this->pwa->get_offline_page() );

		delete_option( 'buddynext_default_theme' );
	}

	/**
	 * The shell list is filterable, so a theme can add its own stylesheet.
	 *
	 * @return void
	 */
	public function test_shell_assets_are_filterable(): void {
		add_filter(
			'buddynext_pwa_shell_assets',
			static function ( array $shell ): array {
				$shell[] = 'https://example.test/theme.css';
				return $shell;
			}
		);

		$this->assertStringContainsString( 'theme.css', $this->pwa->get_service_worker_script() );

		remove_all_filters( 'buddynext_pwa_shell_assets' );
	}
}
