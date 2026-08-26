<?php
/**
 * Only a space owner or moderator may share a space document — the same write
 * authority MediaVerse enforces server-side. A plain member reads the drive but
 * cannot grant its files to others.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WPMediaVerseBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Bridges\WPMediaVerseBridge::space_drive_can_share
 */
class SpaceDriveShareAuthorityTest extends \WP_UnitTestCase {

	private SpaceService $spaces;
	private SpaceMemberService $members;
	private int $owner    = 0;
	private int $mod      = 0;
	private int $member   = 0;
	private int $outsider = 0;
	private int $space_id = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->spaces   = new SpaceService();
		$this->members  = new SpaceMemberService();
		$this->owner    = (int) self::factory()->user->create();
		$this->mod      = (int) self::factory()->user->create();
		$this->member   = (int) self::factory()->user->create();
		$this->outsider = (int) self::factory()->user->create();

		$this->space_id = (int) $this->spaces->create(
			$this->owner,
			array(
				'name' => 'Drive Share Space',
				'slug' => 'drive-share-space',
				'type' => 'open',
			)
		);
		$this->members->join( $this->space_id, $this->mod );
		$this->members->join( $this->space_id, $this->member );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'role' => 'moderator' ),
			array(
				'space_id' => $this->space_id,
				'user_id'  => $this->mod,
			)
		);
		wp_cache_flush();
	}

	public function test_owner_and_moderator_may_share(): void {
		$this->assertTrue( WPMediaVerseBridge::space_drive_can_share( $this->space_id, $this->owner ) );
		$this->assertTrue( WPMediaVerseBridge::space_drive_can_share( $this->space_id, $this->mod ) );
	}

	public function test_plain_member_and_outsider_may_not_share(): void {
		$this->assertFalse( WPMediaVerseBridge::space_drive_can_share( $this->space_id, $this->member ) );
		$this->assertFalse( WPMediaVerseBridge::space_drive_can_share( $this->space_id, $this->outsider ) );
	}

	public function test_zero_ids_are_refused(): void {
		$this->assertFalse( WPMediaVerseBridge::space_drive_can_share( 0, $this->owner ) );
		$this->assertFalse( WPMediaVerseBridge::space_drive_can_share( $this->space_id, 0 ) );
	}
}
