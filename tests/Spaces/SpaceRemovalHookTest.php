<?php
/**
 * Regression: SpaceMemberService::remove() fires the canonical removal hook.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Spaces\SpaceMemberService::remove
 */
class SpaceRemovalHookTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_remove_fires_canonical_member_removed_hook(): void {
		$owner  = self::factory()->user->create();
		$member = self::factory()->user->create();

		$spaces   = new SpaceService();
		$space_id = (int) $spaces->create(
			$owner,
			array(
				'name' => 'Hook Space',
				'slug' => 'hook-space',
				'type' => 'open',
			)
		);

		$members = new SpaceMemberService();
		$members->join( $space_id, $member );

		$fired = array();
		add_action(
			'buddynext_space_member_removed',
			static function ( $sid, $uid ) use ( &$fired ) {
				$fired = array( (int) $sid, (int) $uid );
			},
			10,
			2
		);

		$this->assertTrue( $members->remove( $space_id, $member, $owner ) );
		$this->assertSame( array( $space_id, $member ), $fired );
	}
}
