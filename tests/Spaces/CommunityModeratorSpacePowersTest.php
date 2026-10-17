<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A space's membership is run by its own team and WordPress administrators.
 *
 * Community moderators work reports from every space in Community Admin; they
 * do not approve, ban or remove inside a space they are not part of, the same
 * line as who may see inside a private space (card 10322714806, phase 2).
 *
 * @package BuddyNext\Tests\Spaces
 * @since 1.2.6
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * Locks who moderates inside a space.
 */
class CommunityModeratorSpacePowersTest extends WP_UnitTestCase {

	/**
	 * A verified member holding a community role.
	 *
	 * @param string $role Community role.
	 * @param string $wp   WordPress role.
	 * @return int
	 */
	private function member( string $role = 'member', string $wp = 'subscriber' ): int {
		$id = self::factory()->user->create( array( 'role' => $wp ) );
		update_user_meta( $id, 'bn_community_role', $role );
		update_user_meta( $id, 'bn_email_verified', '1' );
		return $id;
	}

	/**
	 * The space moderator and a WordPress administrator ban; a community
	 * moderator from outside the space does not, and nobody bans the owner.
	 *
	 * @return void
	 */
	public function test_space_membership_stays_with_the_space_team(): void {
		$owner    = $this->member();
		$members  = new SpaceMemberService();
		$space_id = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Riverside Allotment Growers',
				'slug' => 'riverside-allotment-growers',
				'type' => 'private',
			)
		);
		$this->assertIsInt( $space_id );

		$space_mod = $this->member();
		$members->join( $space_id, $space_mod );
		$this->assertTrue( $members->change_role( $space_id, $space_mod, 'moderator', $owner ) );

		$spammer = $this->member();
		$members->join( $space_id, $spammer );
		$this->assertWPError( $members->ban( $space_id, $this->member( 'moderator' ), $spammer ), 'A community moderator from outside does not run the space.' );
		$this->assertTrue( $members->ban( $space_id, $space_mod, $spammer, 'Posting payday-loan links' ) );

		$second = $this->member();
		$members->join( $space_id, $second );
		$this->assertTrue( $members->ban( $space_id, $this->member( 'member', 'administrator' ), $second ) );
		$this->assertWPError( $members->ban( $space_id, $space_mod, $owner ), 'The owner is never banned.' );
	}
}
