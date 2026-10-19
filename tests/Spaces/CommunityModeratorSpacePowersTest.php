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

	/**
	 * A space moderator keeps the members below them in order but cannot ban or
	 * remove a WordPress administrator, whichever primitive the call comes through.
	 * A peer is fair game and the automated system actor (0) is exempt.
	 *
	 * @return void
	 */
	public function test_space_moderator_cannot_sanction_someone_who_outranks_them(): void {
		$owner    = $this->member();
		$members  = new SpaceMemberService();
		$space_id = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Harbour Road Swimmers',
				'slug' => 'harbour-road-swimmers',
				'type' => 'open',
			)
		);
		$this->assertIsInt( $space_id );

		$space_mod = $this->member();
		$members->join( $space_id, $space_mod );
		$this->assertTrue( $members->change_role( $space_id, $space_mod, 'moderator', $owner ) );

		$admin = $this->member( 'member', 'administrator' );
		$members->join( $space_id, $admin );

		$banned = $members->ban( $space_id, $space_mod, $admin );
		$this->assertWPError( $banned );
		$this->assertSame( 'forbidden', $banned->get_error_code() );

		$banned = $members->ban_from_space( $space_id, $admin, $space_mod );
		$this->assertWPError( $banned );
		$this->assertSame( 'forbidden', $banned->get_error_code() );
		$this->assertTrue( $members->is_member( $space_id, $admin ), 'The administrator is still in the space.' );

		$peer = $this->member();
		$members->join( $space_id, $peer );
		$this->assertTrue( $members->ban_from_space( $space_id, $peer, $space_mod ), 'A peer is not above the moderator.' );

		$other = $this->member();
		$members->join( $space_id, $other );
		$this->assertTrue( $members->ban_from_space( $space_id, $other, 0 ), 'Automated moderation has no rank to compare.' );

		wp_set_current_user( $space_mod );
		$request = new \WP_REST_Request( 'DELETE', '/buddynext/v1/spaces/' . $space_id . '/members/' . $admin );
		$request->set_param( 'id', $space_id );
		$request->set_param( 'user_id', $admin );
		$removed = ( new \BuddyNext\Spaces\SpaceController() )->remove_member( $request );
		$this->assertWPError( $removed );
		$this->assertSame( 'forbidden', $removed->get_error_code() );
		$this->assertTrue( $members->is_member( $space_id, $admin ) );
	}
}
