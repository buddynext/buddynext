<?php
/**
 * The hero and directory card separate "can manage" from "is a member".
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * Who sees Leave and Invite on a space.
 */
class SpaceHeroActionsTest extends \WP_UnitTestCase {

	private int $space_id;
	private int $owner;
	private int $moderator;
	private int $member;
	private int $stranger;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner     = self::factory()->user->create();
		$this->moderator = self::factory()->user->create();
		$this->member    = self::factory()->user->create();
		$this->stranger  = self::factory()->user->create();
		$this->space_id  = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Hero Actions',
				'slug' => 'hero-actions',
				'type' => 'open',
			)
		);
		$members         = new SpaceMemberService();
		$members->join( $this->space_id, $this->member );
		$members->join( $this->space_id, $this->moderator );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'role' => 'moderator' ),
			array(
				'space_id' => $this->space_id,
				'user_id'  => $this->moderator,
			)
		);
		wp_cache_flush();
	}

	private function hero_as( int $user_id ): string {
		wp_set_current_user( $user_id );
		ob_start();
		buddynext_get_template( 'parts/space-header.php', array( 'space_id' => $this->space_id ) );
		return (string) ob_get_clean();
	}

	private function card_as( int $user_id ): string {
		wp_set_current_user( $user_id );
		$role = ( new SpaceMemberService() )->get_role( $this->space_id, $user_id );
		ob_start();
		buddynext_get_template(
			'parts/space-directory-card.php',
			array(
				'space'           => ( new SpaceService() )->get( $this->space_id ),
				'membership'      => array(
					'role'   => (string) $role,
					'status' => 'active',
				),
				'current_user_id' => $user_id,
			)
		);
		return (string) ob_get_clean();
	}

	public function test_a_moderator_can_leave_and_the_owner_cannot(): void {
		$moderator = $this->hero_as( $this->moderator );
		$this->assertStringContainsString( 'actions.leaveSpace', $moderator );
		$this->assertStringContainsString( '/settings/', $moderator );

		$owner = $this->hero_as( $this->owner );
		$this->assertStringNotContainsString( 'actions.leaveSpace', $owner );
		$this->assertStringContainsString( '/settings/', $owner );
	}

	public function test_invite_follows_can_invite_not_can_manage(): void {
		update_space_meta( $this->space_id, 'who_can_invite', 'members' );
		wp_cache_flush();
		$member = $this->hero_as( $this->member );
		$this->assertStringContainsString( 'actions.openInviteModal', $member );
		$this->assertStringContainsString( 'data-bn-modal="invite-member"', $member );
		$this->assertStringContainsString( 'actions.leaveSpace', $member );

		update_space_meta( $this->space_id, 'who_can_invite', 'mods' );
		wp_cache_flush();
		$member = $this->hero_as( $this->member );
		$this->assertStringNotContainsString( 'actions.openInviteModal', $member );
		$this->assertStringNotContainsString( 'data-bn-modal="invite-member"', $member );

		update_space_meta( $this->space_id, 'who_can_invite', 'owner' );
		wp_cache_flush();
		$moderator = $this->hero_as( $this->moderator );
		$this->assertStringNotContainsString( 'actions.openInviteModal', $moderator );
		$this->assertStringContainsString( '/settings/', $moderator );
		$this->assertStringContainsString( 'actions.openInviteModal', $this->hero_as( $this->owner ) );
	}

	public function test_a_stranger_gets_neither_leave_nor_invite(): void {
		update_space_meta( $this->space_id, 'who_can_invite', 'members' );
		wp_cache_flush();
		$stranger = $this->hero_as( $this->stranger );
		$this->assertStringNotContainsString( 'actions.leaveSpace', $stranger );
		$this->assertStringNotContainsString( 'actions.openInviteModal', $stranger );
	}

	public function test_directory_card_gives_a_moderator_manage_and_leave_and_an_owner_manage_only(): void {
		$moderator = $this->card_as( $this->moderator );
		$this->assertStringContainsString( 'data-bn-card-manage', $moderator );
		$this->assertStringContainsString( 'actions.leaveSpace', $moderator );

		$owner = $this->card_as( $this->owner );
		$this->assertStringContainsString( 'data-bn-card-manage', $owner );
		$this->assertStringNotContainsString( 'actions.leaveSpace', $owner );
	}
}
