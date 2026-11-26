<?php
/**
 * The free site tools install from their public GitHub release, and only from GitHub.
 *
 * @package BuddyNext\Tests\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Integrations;

use BuddyNext\Integrations\CompanionInstaller;
use BuddyNext\Integrations\CompanionRegistry;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The HTTP layer is mocked with pre_http_request, so nothing leaves the test run.
 *
 * @covers \BuddyNext\Integrations\CompanionInstaller
 * @covers \BuddyNext\Integrations\CompanionRegistry::tools
 * @covers \BuddyNext\Integrations\CompanionRegistry::manual
 * @covers \BuddyNext\Integrations\CompanionController::status
 */
class CompanionGithubInstallTest extends WP_UnitTestCase {

	/**
	 * Every URL the code asked for.
	 *
	 * @var string[]
	 */
	private array $requested = array();

	/**
	 * Assets the mocked releases API returns.
	 *
	 * @var array<int, array<string, string>>|null Null = GitHub unreachable.
	 */
	private ?array $assets = array();

	/**
	 * A dummy plugin written to disk by a test.
	 *
	 * @var string
	 */
	private string $dummy = '';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		remove_all_filters( 'file_mod_allowed' );
		if ( '' !== $this->dummy ) {
			deactivate_plugins( $this->dummy, true );
			$dir = WP_PLUGIN_DIR . '/' . dirname( $this->dummy );
			wp_delete_file( WP_PLUGIN_DIR . '/' . $this->dummy );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		parent::tear_down();
	}

	/**
	 * Answer the releases API from $this->assets; refuse every download.
	 *
	 * @param false|array<string,mixed>|\WP_Error $pre  Short-circuit value.
	 * @param array<string,mixed>                 $args Request args.
	 * @param string                              $url  Request URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function mock_http( $pre, $args, $url ) {
		$this->requested[] = $url;
		if ( str_starts_with( $url, 'https://api.github.com/repos/' ) ) {
			if ( null === $this->assets ) {
				return new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
			}
			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode( array( 'assets' => $this->assets ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		}
		return new \WP_Error( 'http_request_failed', 'blocked in tests' );
	}

	/**
	 * An asset row.
	 *
	 * @param string $name Asset file name.
	 * @param string $url  Download URL.
	 * @return array<string,string>
	 */
	private function asset( string $name, string $url ): array {
		return array(
			'name'                 => $name,
			'browser_download_url' => $url,
		);
	}

	/**
	 * Put a minimal inactive plugin on disk at a tool's basename.
	 *
	 * @param string $basename Plugin basename.
	 * @return void
	 */
	private function put_plugin( string $basename ): void {
		$this->dummy = $basename;
		wp_mkdir_p( WP_PLUGIN_DIR . '/' . dirname( $basename ) );
		file_put_contents( WP_PLUGIN_DIR . '/' . $basename, "<?php\n/**\n * Plugin Name: QA dummy\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_clean_plugins_cache( false );
	}

	public function test_the_tools_stay_out_of_the_integration_catalog(): void {
		$this->assertArrayNotHasKey( 'wbcom-demo-installer', CompanionRegistry::all() );
		$this->assertArrayNotHasKey( 'wbcom-essential', CompanionRegistry::all() );
		$this->assertNotNull( CompanionRegistry::get( 'wbcom-demo-installer' ) );
		$this->assertSame( 'not_installed', CompanionRegistry::status( 'wbcom-essential' ) );
	}

	public function test_an_unknown_slug_is_refused(): void {
		$error = CompanionInstaller::install( 'not-a-tool' );
		$this->assertWPError( $error );
		$this->assertSame( 'buddynext_unknown_companion', $error->get_error_code() );
		$this->assertSame( array(), $this->requested );
	}

	public function test_install_needs_the_install_plugins_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$error = CompanionInstaller::install( 'wbcom-essential' );
		$this->assertWPError( $error );
		$this->assertSame( 'buddynext_cap', $error->get_error_code() );
		$this->assertSame( array(), $this->requested );
	}

	public function test_the_zip_is_picked_by_prefix_and_downloaded_from_github(): void {
		$zip          = 'https://github.com/vapvarun/wbcom-essential/releases/download/v4.7.3/wbcom-essential-4.7.3.zip';
		$this->assets = array(
			$this->asset( 'wbcom-essential-4.7.3.zip.sig.json', 'https://github.com/vapvarun/wbcom-essential/releases/download/v4.7.3/wbcom-essential-4.7.3.zip.sig.json' ),
			$this->asset( 'other-plugin-1.0.zip', 'https://github.com/vapvarun/wbcom-essential/releases/download/v4.7.3/other-plugin-1.0.zip' ),
			$this->asset( 'wbcom-essential-4.7.3.zip', $zip ),
		);

		$level = ob_get_level();
		$error = CompanionInstaller::install( 'wbcom-essential' );
		// Core's Automatic_Upgrader_Skin opens a second buffer when it reports an error; close it here.
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}

		// The download is refused by the mock, so the install fails at the download with the asset it picked.
		$this->assertWPError( $error );
		$this->assertSame( 'https://api.github.com/repos/vapvarun/wbcom-essential/releases/latest', $this->requested[0] );
		$this->assertContains( $zip, $this->requested );
		$this->assertSame( $zip, $error->get_error_data()['manual']['zip_url'] );
		$this->assertSame( 502, $error->get_error_data()['status'] );
	}

	/**
	 * @dataProvider untrusted_urls
	 *
	 * @param string $url Asset download URL the API claims.
	 */
	public function test_a_download_off_https_github_is_refused( string $url ): void {
		$this->assets = array( $this->asset( 'wbcom-essential-4.7.3.zip', $url ) );

		$error = CompanionInstaller::install( 'wbcom-essential' );

		$this->assertWPError( $error );
		$this->assertSame( 'buddynext_bad_package_host', $error->get_error_code() );
		$this->assertNotContains( $url, $this->requested, 'The untrusted URL was never fetched.' );
		$this->assertSame( 'https://github.com/vapvarun/wbcom-essential/releases/latest', $error->get_error_data()['manual']['zip_url'] );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function untrusted_urls(): array {
		return array(
			'plain http'         => array( 'http://github.com/vapvarun/wbcom-essential/releases/download/v1/wbcom-essential-1.zip' ),
			'another host'       => array( 'https://evil.example/wbcom-essential-1.zip' ),
			'look-alike host'    => array( 'https://github.com.evil.example/wbcom-essential-1.zip' ),
			'suffix-only host'   => array( 'https://notgithub.com/wbcom-essential-1.zip' ),
			'no scheme or host'  => array( '/wbcom-essential-1.zip' ),
		);
	}

	public function test_github_subdomains_are_trusted(): void {
		$this->assertTrue( CompanionInstaller::is_github_url( 'https://github.com/a/b/releases/download/v1/x.zip' ) );
		$this->assertTrue( CompanionInstaller::is_github_url( 'https://objects.github.com/x.zip' ) );
		$this->assertFalse( CompanionInstaller::is_github_url( 'http://github.com/x.zip' ) );
	}

	public function test_a_release_without_the_zip_says_so(): void {
		$this->assets = array( $this->asset( 'wbcom-essential-4.7.3.zip.sig.json', 'https://github.com/x/y.sig.json' ) );
		$error        = CompanionInstaller::install( 'wbcom-essential' );
		$this->assertSame( 'buddynext_github_no_asset', $error->get_error_code() );
		$this->assertArrayHasKey( 'manual', $error->get_error_data() );
	}

	public function test_an_unreachable_github_returns_the_manual_payload(): void {
		$this->assets = null;
		$error        = CompanionInstaller::install( 'wbcom-demo-installer' );
		$this->assertSame( 'buddynext_github_unreachable', $error->get_error_code() );
		$manual = $error->get_error_data()['manual'];
		$this->assertSame( 'https://github.com/buddynext/wbcom-demo-installer/releases/latest', $manual['zip_url'] );
		$this->assertStringContainsString( 'plugin-install.php?tab=upload', $manual['upload_url'] );
	}

	public function test_disabled_file_mods_return_the_manual_payload_without_a_request(): void {
		// Only our install context: a site-wide DISALLOW_FILE_MODS also removes install_plugins.
		add_filter( 'file_mod_allowed', static fn( $allowed, $context ) => 'buddynext_companion_install' === $context ? false : $allowed, 10, 2 );

		$error = CompanionInstaller::install( 'wbcom-demo-installer' );

		$this->assertSame( 'buddynext_install_disabled', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame( 'https://github.com/buddynext/wbcom-demo-installer/releases/latest', $error->get_error_data()['manual']['zip_url'] );
		$this->assertSame( array(), $this->requested );
	}

	public function test_an_installed_but_inactive_tool_is_only_activated(): void {
		$this->put_plugin( 'wbcom-essential/loader.php' );

		$this->assertSame( 'inactive', CompanionRegistry::status( 'wbcom-essential' ) );
		$this->assertTrue( CompanionInstaller::install( 'wbcom-essential' ) );
		$this->assertTrue( is_plugin_active( 'wbcom-essential/loader.php' ) );
		$this->assertSame( array(), $this->requested, 'Nothing was downloaded.' );
	}

	public function test_the_rest_error_carries_the_manual_payload_and_status_rereads_state(): void {
		$this->assets = null;

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/companions/install' );
		$request->set_param( 'slug', 'wbcom-essential' );
		$response = rest_do_request( $request );
		$this->assertSame( 502, $response->get_status() );
		$this->assertArrayHasKey( 'manual', $response->get_data()['data'] );

		$status = new WP_REST_Request( 'GET', '/buddynext/v1/companions/status' );
		$status->set_param( 'slug', 'wbcom-essential' );
		$this->assertSame( 'not_installed', rest_do_request( $status )->get_data()['status'] );

		$this->put_plugin( 'wbcom-essential/loader.php' );
		$this->assertSame( 'inactive', rest_do_request( $status )->get_data()['status'] );

		$unknown = new WP_REST_Request( 'GET', '/buddynext/v1/companions/status' );
		$unknown->set_param( 'slug', 'nope' );
		$this->assertSame( 404, rest_do_request( $unknown )->get_status() );
	}
}
