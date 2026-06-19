<?php
/**
 * Tests for the BuddyNext admin members panel.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Members;
use BuddyNext\Core\Installer;

/**
 * Verifies member listing, suspension, and export logic.
 *
 * @covers \BuddyNext\Admin\Members
 */
class MembersTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var Members
	 */
	private Members $members;

	/**
	 * Create fresh instance before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->members = new Members();
	}

	/**
	 * register() adds the submenu page hook.
	 */
	public function test_register_adds_admin_menu_hook(): void {
		$this->members->register();
		// The admin screen is now contributed as an AdminHub tab (members:directory),
		// not a direct admin_menu/add_submenu hook.
		$this->assertArrayHasKey( 'directory', \BuddyNext\Admin\AdminHub::get_tabs( 'members' ) );
	}

	/**
	 * list_members() returns an array.
	 */
	public function test_list_members_returns_array(): void {
		$result = $this->members->list_members( array() );
		$this->assertIsArray( $result );
	}

	/**
	 * list_members() respects 'per_page' argument.
	 */
	public function test_list_members_respects_per_page(): void {
		// Create 5 users.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->factory->user->create();
		}
		$result = $this->members->list_members( array( 'per_page' => 2 ) );
		$this->assertLessThanOrEqual( 2, count( $result['members'] ) );
	}

	/**
	 * get_member_count() returns a non-negative integer.
	 */
	public function test_get_member_count_returns_integer(): void {
		$count = $this->members->get_member_count();
		$this->assertIsInt( $count );
		$this->assertGreaterThanOrEqual( 0, $count );
	}

	/**
	 * suspend_member() sets the suspended usermeta flag.
	 */
	public function test_suspend_member_sets_usermeta(): void {
		$user_id = $this->factory->user->create();
		$this->members->suspend_member( $user_id );
		$this->assertEquals( '1', get_user_meta( $user_id, 'bn_suspended', true ) );
	}

	/**
	 * unsuspend_member() removes the suspended flag.
	 */
	public function test_unsuspend_member_removes_usermeta(): void {
		$user_id = $this->factory->user->create();
		update_user_meta( $user_id, 'bn_suspended', '1' );
		$this->members->unsuspend_member( $user_id );
		$this->assertEmpty( get_user_meta( $user_id, 'bn_suspended', true ) );
	}

	/**
	 * suspend_member() fires the buddynext_member_suspended action.
	 */
	public function test_suspend_fires_action(): void {
		$fired    = false;
		$user_id  = $this->factory->user->create();
		add_action(
			'buddynext_member_suspended',
			function ( $id ) use ( &$fired, $user_id ) {
				if ( $id === $user_id ) {
					$fired = true;
				}
			}
		);
		$this->members->suspend_member( $user_id );
		$this->assertTrue( $fired );
	}

	/**
	 * unsuspend_member() fires the buddynext_member_unsuspended action.
	 */
	public function test_unsuspend_fires_action(): void {
		$fired   = false;
		$user_id = $this->factory->user->create();
		add_action(
			'buddynext_member_unsuspended',
			function ( $id ) use ( &$fired, $user_id ) {
				if ( $id === $user_id ) {
					$fired = true;
				}
			}
		);
		$this->members->unsuspend_member( $user_id );
		$this->assertTrue( $fired );
	}

	/**
	 * export_members_csv() returns a non-empty string.
	 */
	public function test_export_members_csv_returns_string(): void {
		$this->factory->user->create( array( 'user_email' => 'export@test.local' ) );
		$csv = $this->members->export_members_csv();
		$this->assertIsString( $csv );
		$this->assertStringContainsString( 'ID,Login,Email,Registered', $csv );
	}

	/**
	 * export_members_csv() includes user email in output.
	 */
	public function test_export_members_csv_includes_user_data(): void {
		$this->factory->user->create( array( 'user_email' => 'csvuser@test.local' ) );
		$csv = $this->members->export_members_csv();
		$this->assertStringContainsString( 'csvuser@test.local', $csv );
	}
}
