<?php
/**
 * One rule for putting something into a Space: a post, a photo, an album, a file.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Media\Galleries;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpacePostGuard;
use BuddyNext\Spaces\SpaceService;

/**
 * Who may contribute to a Space.
 */
class SpaceContributionRuleTest extends \WP_UnitTestCase {

	private int $owner;

	private int $member;

	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner  = self::factory()->user->create();
		$this->member = self::factory()->user->create();

		$this->space_id = (int) ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Contribution rule',
				'slug' => 'contrib-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		( new SpaceMemberService() )->join( $this->space_id, $this->member );
	}

	public function test_a_member_contributes_where_members_may_post(): void {
		$this->assertTrue( SpacePostGuard::can_contribute( $this->space_id, $this->member ) );
		$this->assertTrue( Galleries::can_create_space_album( $this->space_id, $this->member ) );
	}

	public function test_an_owner_only_space_is_closed_to_member_albums_and_uploads(): void {
		update_space_meta( $this->space_id, 'who_can_post', 'owner' );
		wp_cache_flush();

		$this->assertFalse( SpacePostGuard::can_contribute( $this->space_id, $this->member ), 'the media drive follows who can post' );
		$this->assertFalse( Galleries::can_create_space_album( $this->space_id, $this->member ) );
		$this->assertTrue( SpacePostGuard::can_contribute( $this->space_id, $this->owner ), 'the owner still may' );
	}

	public function test_an_archived_space_is_read_only_for_everyone_including_the_owner(): void {
		( new SpaceService() )->archive( $this->space_id, $this->owner );
		wp_cache_flush();

		$this->assertFalse( SpacePostGuard::can_contribute( $this->space_id, $this->member ) );
		$this->assertFalse( SpacePostGuard::can_contribute( $this->space_id, $this->owner ) );
		$this->assertFalse( Galleries::can_create_space_album( $this->space_id, $this->owner ) );
	}
}
