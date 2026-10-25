<?php
/**
 * The wordpress.org source of CompanionInstaller.
 *
 * @package BuddyNext\Tests\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Integrations;

use BuddyNext\Integrations\CompanionInstaller;
use WP_UnitTestCase;

/**
 * A wporg slug installs only from downloads.wordpress.org; a store entry is untouched.
 */
class CompanionInstallerWporgTest extends WP_UnitTestCase {

	/**
	 * Register a wordpress.org companion and an admin.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter(
			'buddynext_companions',
			static function ( array $all ): array {
				$all['wporgkit'] = array(
					'label'  => 'Wporg kit',
					'detect' => static fn() => false,
					'free'   => array(
						'wporg'    => 'wporg-kit',
						'basename' => 'wporg-kit/wporg-kit.php',
					),
				);
				return $all;
			}
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
	}

	/**
	 * Remove the filters.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_companions' );
		remove_all_filters( 'plugins_api' );
		parent::tear_down();
	}

	/**
	 * Answer plugins_api() with a chosen download link.
	 *
	 * @param string $link Download link.
	 * @return void
	 */
	private function api_returns( string $link ): void {
		add_filter( 'plugins_api', static fn() => (object) array( 'download_link' => $link ), 10, 0 );
	}

	/**
	 * An off-domain package is refused before the upgrader runs.
	 *
	 * @return void
	 */
	public function test_off_domain_package_is_refused(): void {
		$this->api_returns( 'https://evil.example/wporg-kit.zip' );

		$r = CompanionInstaller::install( 'wporgkit' );

		$this->assertWPError( $r );
		$this->assertSame( 'buddynext_wporg_host', $r->get_error_code() );
	}

	/**
	 * A look-alike host and a plain http link are refused too.
	 *
	 * @return void
	 */
	public function test_lookalike_host_and_http_are_refused(): void {
		foreach ( array( 'https://downloads.wordpress.org.evil.example/a.zip', 'http://downloads.wordpress.org/a.zip' ) as $link ) {
			remove_all_filters( 'plugins_api' );
			$this->api_returns( $link );
			$this->assertSame( 'buddynext_wporg_host', CompanionInstaller::install( 'wporgkit' )->get_error_code(), $link );
		}
	}

	/**
	 * A failed lookup is a clear error, not a fatal.
	 *
	 * @return void
	 */
	public function test_lookup_failure_is_an_error(): void {
		add_filter( 'plugins_api', static fn() => new \WP_Error( 'down' ), 10, 0 );

		$this->assertSame( 'buddynext_wporg_lookup', CompanionInstaller::install( 'wporgkit' )->get_error_code() );
	}

	/**
	 * A member without install_plugins cannot use the wordpress.org source.
	 *
	 * @return void
	 */
	public function test_requires_install_plugins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->api_returns( 'https://downloads.wordpress.org/plugin/wporg-kit.zip' );

		$this->assertSame( 'buddynext_cap', CompanionInstaller::install( 'wporgkit' )->get_error_code() );
	}

	/**
	 * An entry with neither a store item nor a wordpress.org slug still reports not installable.
	 *
	 * @return void
	 */
	public function test_entry_without_any_source_is_not_installable(): void {
		add_filter(
			'buddynext_companions',
			static function ( array $all ): array {
				$all['nosrc'] = array(
					'label'  => 'None',
					'detect' => static fn() => false,
					'free'   => array(),
				);
				return $all;
			}
		);

		$this->assertSame( 'buddynext_no_item', CompanionInstaller::install( 'nosrc' )->get_error_code() );
	}
}
