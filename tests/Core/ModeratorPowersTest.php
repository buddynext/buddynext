<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Moderation powers follow the community role, not manage_options.
 *
 * A site could not make someone a moderator without handing them full WordPress
 * admin: comment removal, appeals, open-ended suspensions and role changes all
 * checked manage_options directly (card 10322714806).
 *
 * @package BuddyNext\Tests\Core
 * @since 1.2.6
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use BuddyNext\Moderation\ModerationService;
use WP_UnitTestCase;

/**
 * Locks each moderation power to its ability.
 */
class ModeratorPowersTest extends WP_UnitTestCase {

	/**
	 * A member holding a community role (no WordPress admin rights).
	 *
	 * @param string $role Community role.
	 * @return int
	 */
	private function member( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $id, 'bn_community_role', $role );
		update_user_meta( $id, 'bn_email_verified', '1' );
		return $id;
	}

	/**
	 * A moderator removes another member's comment but cannot rewrite it; a
	 * community admin can do both.
	 *
	 * @return void
	 */
	public function test_comment_delete_is_a_moderator_power_and_edit_an_admin_one(): void {
		$author    = $this->member( 'member' );
		$moderator = $this->member( 'moderator' );
		$admin     = $this->member( 'admin' );
		$comments  = buddynext_service( 'comments' );
		$post      = ( new \BuddyNext\Feed\PostService() )->create( $author, array( 'type' => 'text', 'content' => 'Lost a blue water bottle at the Sunday ride.' ) );
		$post_id   = is_array( $post ) ? (int) $post['id'] : (int) $post;

		$first = $comments->create( $author, 'post', $post_id, 'Check the cafe at the finish line.' );
		$this->assertIsInt( $first );
		$this->assertWPError( $comments->update( $first, $moderator, 'rewritten' ), 'A moderator cannot rewrite a member.' );
		$this->assertTrue( $comments->update( $first, $admin, 'Check the cafe at the finish line, by the racks.' ) );
		$this->assertTrue( $comments->delete( $first, $moderator ) );

		$this->assertWPError( $comments->delete( (int) $comments->create( $author, 'post', $post_id, 'Found it, thanks!' ), $this->member( 'member' ) ), 'An ordinary member cannot remove it.' );
	}

	/**
	 * Moderators review shadow-banned members' posts; everyone else does not see them.
	 *
	 * @return void
	 */
	public function test_moderators_see_hidden_authors(): void {
		$service = new ModerationService();
		$this->assertSame( '', $service->hidden_authors_where( 'user_id', $this->member( 'moderator' ) ) );
		$this->assertNotSame( '', $service->hidden_authors_where( 'user_id', $this->member( 'member' ) ) );
	}

	/**
	 * Admin-level powers: open-ended suspension, appeals and role changes.
	 *
	 * @return void
	 */
	public function test_admin_level_powers_follow_the_role_map(): void {
		$moderator = $this->member( 'moderator' );
		$admin     = $this->member( 'admin' );
		$target    = $this->member( 'member' );

		$this->assertWPError( buddynext_service( 'moderation' )->suspend_user( $target, $moderator, 'Repeated spam links' ), 'A moderator suspends for a set time only.' );
		$this->assertIsInt( buddynext_service( 'moderation' )->suspend_user( $target, $admin, 'Repeated spam links' ) );

		$this->assertFalse( ModerationService::can_resolve_appeals( $moderator ) );
		$this->assertTrue( ModerationService::can_resolve_appeals( $admin ) );
		$this->assertFalse( buddynext_can( $moderator, 'buddynext-community/assign-roles' ) );
		$this->assertTrue( buddynext_can( $admin, 'buddynext-community/assign-roles' ) );
	}

	/**
	 * Site-wide announcements are a community admin power; moderators and
	 * members are refused.
	 *
	 * @return void
	 */
	public function test_site_wide_announcements_follow_the_announce_ability(): void {
		$posts = new \BuddyNext\Feed\PostService();
		$data  = array(
			'type'    => 'announcement',
			'content' => 'The community garden opens for spring planting this Saturday at 9am.',
		);
		$this->assertWPError( $posts->create( $this->member( 'moderator' ), $data ) );
		$this->assertWPError( $posts->create( $this->member( 'member' ), $data ) );
		$this->assertNotWPError( $posts->create( $this->member( 'admin' ), $data ) );
	}

	/**
	 * An owner can hand appeals to moderators through the role map.
	 *
	 * @return void
	 */
	public function test_owner_can_delegate_appeals(): void {
		$remap = static function ( array $map ): array {
			$map['buddynext-moderation/resolve-appeals'] = 'moderator';
			return $map;
		};
		add_filter( 'buddynext_role_map', $remap );
		$this->reset_role_map();
		$this->assertTrue( ModerationService::can_resolve_appeals( $this->member( 'moderator' ) ) );
		remove_filter( 'buddynext_role_map', $remap );
		$this->reset_role_map();
	}

	/**
	 * Clear the per-request role map memo.
	 *
	 * @return void
	 */
	private function reset_role_map(): void {
		$memo = new \ReflectionProperty( \BuddyNext\Core\PermissionService::class, 'role_map_cache' );
		$memo->setAccessible( true );
		$memo->setValue( null, null );
	}
}
