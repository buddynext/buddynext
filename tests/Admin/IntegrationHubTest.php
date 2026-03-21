<?php
/**
 * Tests for the BuddyNext admin integration hub.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\IntegrationHub;

/**
 * Verifies addon detection and status reporting.
 *
 * @covers \BuddyNext\Admin\IntegrationHub
 */
class IntegrationHubTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var IntegrationHub
	 */
	private IntegrationHub $hub;

	/**
	 * Create a fresh instance before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->hub = new IntegrationHub();
	}

	/**
	 * register() adds the admin_menu hook.
	 */
	public function test_register_adds_admin_menu_hook(): void {
		$this->hub->register();
		$this->assertNotFalse(
			has_action( 'admin_menu', array( $this->hub, 'add_submenu' ) )
		);
	}

	/**
	 * get_addons() returns an array.
	 */
	public function test_get_addons_returns_array(): void {
		$addons = $this->hub->get_addons();
		$this->assertIsArray( $addons );
	}

	/**
	 * get_addons() returns entries with 'id' key.
	 */
	public function test_get_addons_entries_have_id(): void {
		foreach ( $this->hub->get_addons() as $addon ) {
			$this->assertArrayHasKey( 'id', $addon );
		}
	}

	/**
	 * get_addons() returns entries with 'label' key.
	 */
	public function test_get_addons_entries_have_label(): void {
		foreach ( $this->hub->get_addons() as $addon ) {
			$this->assertArrayHasKey( 'label', $addon );
		}
	}

	/**
	 * get_addons() returns entries with 'active' boolean key.
	 */
	public function test_get_addons_entries_have_active_bool(): void {
		foreach ( $this->hub->get_addons() as $addon ) {
			$this->assertArrayHasKey( 'active', $addon );
			$this->assertIsBool( $addon['active'] );
		}
	}

	/**
	 * get_addon_status() returns false for an unknown addon ID.
	 */
	public function test_get_addon_status_false_for_unknown(): void {
		$this->assertFalse( $this->hub->get_addon_status( 'nonexistent-addon-xyz' ) );
	}

	/**
	 * buddynext_register_addon filter can add a custom addon entry.
	 */
	public function test_filter_can_register_addon(): void {
		add_filter(
			'buddynext_register_addons',
			function ( array $addons ): array {
				$addons[] = array(
					'id'           => 'my-test-addon',
					'label'        => 'My Test Addon',
					'plugin_file'  => 'nonexistent/nonexistent.php',
					'description'  => 'A test addon.',
				);
				return $addons;
			}
		);

		$ids = array_column( $this->hub->get_addons(), 'id' );
		$this->assertContains( 'my-test-addon', $ids );

		remove_all_filters( 'buddynext_register_addons' );
	}

	/**
	 * get_addon_status() returns false when the plugin file is not active.
	 */
	public function test_get_addon_status_false_when_plugin_inactive(): void {
		add_filter(
			'buddynext_register_addons',
			function ( array $addons ): array {
				$addons[] = array(
					'id'          => 'inactive-addon',
					'label'       => 'Inactive',
					'plugin_file' => 'inactive-plugin/inactive-plugin.php',
					'description' => '',
				);
				return $addons;
			}
		);

		$this->assertFalse( $this->hub->get_addon_status( 'inactive-addon' ) );

		remove_all_filters( 'buddynext_register_addons' );
	}
}
