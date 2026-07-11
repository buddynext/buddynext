<?php
/**
 * Tests for automatic space-ownership succession.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceSuccession;

/**
 * Covers succession: a space must never be left owned by a deleted user.
 *
 * @covers \BuddyNext\Spaces\SpaceSuccession
 */
class SpaceSuccessionTest extends \WP_UnitTestCase {

	/**
	 * Space service.
	 *
	 * @var SpaceService
	 */
	private SpaceService $spaces;

	/**
	 * Membership service, used to seed member rows.
	 *
	 * @var SpaceMemberService
	 */
	private SpaceMemberService $members;

	/**
	 * The space's original owner (the user we delete).
	 *
	 * @var int
	 */
	private int $owner_id;

	/**
	 * The space under test.
	 *
	 * @var int
	 */
	private int $space_id;

	/**
	 * Seed a space owned by a fresh user, with succession listening.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		( new SpaceSuccession() )->register();
		$this->spaces   = new SpaceService();
		$this->members  = new SpaceMemberService();
		$this->owner_id = self::factory()->user->create();
		$this->space_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Succession Space',
				'slug' => 'succession-space',
				'type' => 'open',
			)
		);
	}

	/**
	 * A moderator is the heir — and the space is never left ownerless.
	 *
	 * @return void
	 */
	public function test_moderator_inherits_on_owner_delete(): void {
		$mod = self::factory()->user->create();
		$this->members->join( $this->space_id, $mod );
		$this->members->change_role( $this->space_id, $mod, 'moderator', $this->owner_id );

		wp_delete_user( $this->owner_id );

		$space = $this->spaces->get( $this->space_id );
		$this->assertSame( $mod, (int) $space['owner_id'] );
		$this->assertSame( 'owner', $this->members->get_role( $this->space_id, $mod ) );
	}

	/**
	 * The longest-serving moderator wins, deterministically.
	 *
	 * @return void
	 */
	public function test_longest_serving_moderator_wins(): void {
		global $wpdb;
		$first  = self::factory()->user->create();
		$second = self::factory()->user->create();

		foreach ( array( $first, $second ) as $uid ) {
			$this->members->join( $this->space_id, $uid );
			$this->members->change_role( $this->space_id, $uid, 'moderator', $this->owner_id );
		}

		// Make $first demonstrably older.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_space_members SET joined_at = %s WHERE space_id = %d AND user_id = %d",
				'2020-01-01 00:00:00',
				$this->space_id,
				$first
			)
		);

		wp_delete_user( $this->owner_id );

		$this->assertSame( $first, (int) $this->spaces->get( $this->space_id )['owner_id'] );
	}

	/**
	 * A plain member is NEVER auto-promoted — an owner cannot leave, so we would
	 * trap someone who never accepted the role. Falls back to the site admin.
	 *
	 * @return void
	 */
	public function test_plain_member_is_not_promoted_admin_inherits(): void {
		$member = self::factory()->user->create();
		$this->members->join( $this->space_id, $member );
		self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_delete_user( $this->owner_id );

		$new_owner = (int) $this->spaces->get( $this->space_id )['owner_id'];
		$this->assertNotSame( $member, $new_owner, 'a passive member must not be trapped as owner' );
		$this->assertTrue( user_can( $new_owner, 'manage_options' ), 'fallback owner must be a site admin' );
	}

	/**
	 * Banned members are never eligible.
	 *
	 * @return void
	 */
	public function test_banned_moderator_is_not_eligible(): void {
		$mod = self::factory()->user->create();
		$this->members->join( $this->space_id, $mod );
		$this->members->change_role( $this->space_id, $mod, 'moderator', $this->owner_id );
		$this->members->ban( $this->space_id, $this->owner_id, $mod );
		self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_delete_user( $this->owner_id );

		$this->assertNotSame( $mod, (int) $this->spaces->get( $this->space_id )['owner_id'] );
	}

	/**
	 * The GDPR eraser path must be covered too — not just wp_delete_user().
	 *
	 * @return void
	 */
	public function test_gdpr_erase_path_also_reassigns(): void {
		$mod = self::factory()->user->create();
		$this->members->join( $this->space_id, $mod );
		$this->members->change_role( $this->space_id, $mod, 'moderator', $this->owner_id );

		( new \BuddyNext\Profile\MemberCleanupService() )->purge_user_relations( $this->owner_id, 'gdpr-erase' );

		$this->assertSame( $mod, (int) $this->spaces->get( $this->space_id )['owner_id'] );
	}

	/**
	 * No space may be left pointing at a user that no longer exists.
	 *
	 * @return void
	 */
	public function test_no_dangling_owner_id_remains(): void {
		$mod = self::factory()->user->create();
		$this->members->join( $this->space_id, $mod );
		$this->members->change_role( $this->space_id, $mod, 'moderator', $this->owner_id );

		wp_delete_user( $this->owner_id );

		$owner_id = (int) $this->spaces->get( $this->space_id )['owner_id'];
		$this->assertNotNull( get_userdata( $owner_id ), 'owner_id must point at a real user' );
	}

	/**
	 * One user owning several spaces: every one is reassigned.
	 *
	 * @return void
	 */
	public function test_all_spaces_owned_by_the_user_are_reassigned(): void {
		$second = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Second Space',
				'slug' => 'succession-space-two',
				'type' => 'open',
			)
		);
		$mod = self::factory()->user->create();
		foreach ( array( $this->space_id, $second ) as $sid ) {
			$this->members->join( $sid, $mod );
			$this->members->change_role( $sid, $mod, 'moderator', $this->owner_id );
		}

		wp_delete_user( $this->owner_id );

		$this->assertSame( $mod, (int) $this->spaces->get( $this->space_id )['owner_id'] );
		$this->assertSame( $mod, (int) $this->spaces->get( $second )['owner_id'] );
	}
}
