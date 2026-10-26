<?php
/**
 * The retired Spaces-tab "Who can create spaces" option moves into the role map.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Core\Installer
 */
class SpaceCreationRoleMigrationTest extends \WP_UnitTestCase {

	private function migrate(): void {
		( new \ReflectionMethod( Installer::class, 'maybe_migrate_space_creation_role' ) )->invoke( null );
	}

	private function rule(): array {
		return (array) get_option( 'bn_role_map_overrides', array() );
	}

	public function tear_down(): void {
		delete_option( 'buddynext_space_creation_role' );
		delete_option( 'bn_role_map_overrides' );
		parent::tear_down();
	}

	public function test_a_stricter_choice_becomes_the_roles_rule(): void {
		update_option( 'buddynext_space_creation_role', 'admin' );
		$this->migrate();
		$this->assertSame( array( 'buddynext-spaces/create' => 'admin' ), $this->rule() );
		$this->assertFalse( get_option( 'buddynext_space_creation_role' ) );

		// Idempotent: a second pass has nothing to move and changes nothing.
		$this->migrate();
		$this->assertSame( array( 'buddynext-spaces/create' => 'admin' ), $this->rule() );
	}

	public function test_an_existing_roles_row_wins(): void {
		update_option( 'bn_role_map_overrides', array( 'buddynext-spaces/create' => null, 'buddynext-spaces/join' => 'moderator' ) );
		update_option( 'buddynext_space_creation_role', 'moderator' );
		$this->migrate();
		$this->assertSame( array( 'buddynext-spaces/create' => null, 'buddynext-spaces/join' => 'moderator' ), $this->rule() );
		$this->assertFalse( get_option( 'buddynext_space_creation_role' ) );
	}

	public function test_the_member_default_adds_no_row(): void {
		update_option( 'buddynext_space_creation_role', 'member' );
		$this->migrate();
		$this->assertSame( array(), $this->rule() );
		$this->assertFalse( get_option( 'buddynext_space_creation_role' ) );
	}

	public function test_the_migrated_rule_is_enforced(): void {
		update_option( 'buddynext_space_creation_role', 'admin' );
		$this->migrate();
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$cache  = new \ReflectionProperty( \BuddyNext\Core\PermissionService::class, 'role_map_cache' );
		$cache->setValue( null, null );
		$this->assertFalse( ( new \BuddyNext\Core\PermissionService() )->can( $member, 'buddynext-spaces/create' ) );
	}
}
