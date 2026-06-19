<?php
/**
 * Tests for the BuddyNext admin spaces panel.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Spaces;

/**
 * Verifies space listing, deletion, and counting logic.
 *
 * @covers \BuddyNext\Admin\Spaces
 */
class SpacesTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var Spaces
	 */
	private Spaces $spaces;

	/**
	 * Create a fresh instance and ensure the bn_spaces table exists.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->spaces = new Spaces();

		// bn_spaces (full production schema) is created once by the test bootstrap
		// (Installer::install_schema); per-test row inserts roll back with the
		// transaction, so this test no longer creates/drops its own minimal table
		// (which was missing slug/is_archived and clobbered the shared table).

		// delete_space() delegates to the spaces service, which gates on the
		// manage-space capability — act as an admin so the delete path runs.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * register() adds the admin_menu hook.
	 */
	public function test_register_adds_admin_menu_hook(): void {
		$this->spaces->register();
		// The spaces admin screen is now an AdminHub tab (spaces:directory), not a
		// direct admin_menu/add_submenu hook.
		$this->assertArrayHasKey( 'directory', \BuddyNext\Admin\AdminHub::get_tabs( 'spaces' ) );
	}

	/**
	 * list_spaces() returns an array with 'spaces' key.
	 */
	public function test_list_spaces_returns_array(): void {
		$result = $this->spaces->list_spaces( array() );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'spaces', $result );
		$this->assertArrayHasKey( 'total', $result );
	}

	/**
	 * get_space_count() returns a non-negative integer.
	 */
	public function test_get_space_count_returns_integer(): void {
		$count = $this->spaces->get_space_count();
		$this->assertIsInt( $count );
		$this->assertGreaterThanOrEqual( 0, $count );
	}

	/**
	 * get_space_count() reflects rows in bn_spaces.
	 */
	public function test_get_space_count_reflects_rows(): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'       => 'Test Space',
				'owner_id' => 1,
			)
		);
		$this->assertEquals( 1, $this->spaces->get_space_count() );
	}

	/**
	 * delete_space() removes the row from bn_spaces.
	 */
	public function test_delete_space_removes_row(): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'       => 'To Delete',
				'owner_id' => 1,
			)
		);
		$space_id = (int) $wpdb->insert_id;

		$this->spaces->delete_space( $space_id );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id )
		);
		$this->assertNull( $row );
	}

	/**
	 * delete_space() fires the buddynext_space_deleted action.
	 */
	public function test_delete_space_fires_action(): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'       => 'Action Space',
				'owner_id' => 1,
			)
		);
		$space_id = (int) $wpdb->insert_id;

		$fired = false;
		add_action(
			'buddynext_space_deleted',
			function ( $id ) use ( &$fired, $space_id ) {
				if ( $id === $space_id ) {
					$fired = true;
				}
			}
		);

		$this->spaces->delete_space( $space_id );
		$this->assertTrue( $fired );
	}

	/**
	 * list_spaces() respects 'per_page' argument.
	 */
	public function test_list_spaces_respects_per_page(): void {
		global $wpdb;
		for ( $i = 0; $i < 5; $i++ ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prefix . 'bn_spaces',
				array(
					'name'       => "Space {$i}",
					'owner_id' => 1,
				)
			);
		}
		$result = $this->spaces->list_spaces( array( 'per_page' => 2 ) );
		$this->assertLessThanOrEqual( 2, count( $result['spaces'] ) );
	}
}
