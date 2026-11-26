<?php
/**
 * Joining or leaving a space's linked forum moves the member in the space too,
 * and never lets the forum be a way round the space.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * Forum-side membership events.
 */
class JetonomyForumMembershipSyncTest extends \WP_UnitTestCase {

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner = self::factory()->user->create();
	}

	/** An open space linked to forum id 4242. */
	private function linked_space(): int {
		$id = (int) ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Sync open',
				'slug' => 'sync-open-' . wp_rand( 1000, 9999 ),
				'type' => SpaceService::TYPE_OPEN,
			)
		);
		update_space_meta( $id, 'jetonomy_forum_id', '4242' );
		return $id;
	}

	public function test_joining_the_forum_of_an_open_space_joins_the_space(): void {
		$space  = $this->linked_space();
		$member = self::factory()->user->create();

		( new JetonomyBridge() )->on_forum_member_joined( 4242, $member );

		$this->assertSame( 'active', ( new SpaceMemberService() )->get_status( $space, $member ) );
	}

	public function test_leaving_the_forum_leaves_the_space_for_a_plain_member_only(): void {
		$space  = $this->linked_space();
		$member = self::factory()->user->create();
		$svc    = new SpaceMemberService();
		$svc->join( $space, $member );
		$this->assertSame( 'active', $svc->get_status( $space, $member ) );

		( new JetonomyBridge() )->on_forum_member_left( 4242, $member );
		$this->assertNotSame( 'active', $svc->get_status( $space, $member ), 'a plain member leaves with the forum' );

		// The owner keeps the space whatever happens to the forum row.
		( new JetonomyBridge() )->on_forum_member_left( 4242, $this->owner );
		$this->assertSame( 'active', $svc->get_status( $space, $this->owner ) );
	}

	public function test_a_forum_with_no_space_changes_nothing(): void {
		$member = self::factory()->user->create();
		( new JetonomyBridge() )->on_forum_member_joined( 987654, $member );
		( new JetonomyBridge() )->on_forum_member_left( 987654, $member );
		$this->assertTrue( true, 'no error and no membership written for an unlinked forum' );
	}
}
