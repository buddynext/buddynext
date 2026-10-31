<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A locked profile offers the way in, and nothing more than the viewer may learn.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * The page used to stop at "This profile is private." for everyone.
 */
class LockedProfileActionsTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, clean cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
		wp_set_current_user( 0 );
	}

	/**
	 * A member with onboarding done.
	 *
	 * @return int
	 */
	private function member(): int {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $uid, 'bn_onboarding_complete', 1 );
		return $uid;
	}

	/**
	 * A private account, with the viewer's request waiting on it.
	 *
	 * @param int $viewer Viewer.
	 * @return int
	 */
	private function private_with_request( int $viewer ): int {
		$uid = $this->member();
		update_user_meta( $uid, \BuddyNext\SocialGraph\FollowService::PRIVATE_META, 1 );
		$this->assertTrue( buddynext_service( 'follows' )->follow( $viewer, $uid ) );
		return $uid;
	}

	/**
	 * A locked profile page.
	 *
	 * @param int $owner  Profile owner.
	 * @param int $viewer Viewer.
	 * @return string
	 */
	private function locked_profile( int $owner, int $viewer ): string {
		wp_set_current_user( $viewer );
		ob_start();
		buddynext_get_template( 'profile/view.php', array( 'user_id' => $owner ) );
		return (string) ob_get_clean();
	}

	/**
	 * A follower-only profile offers Follow to someone who may follow, and says why.
	 *
	 * @return void
	 */
	public function test_locked_profile_offers_what_unlocks_it(): void {
		$privacy = buddynext_service( 'privacy' );
		$viewer  = $this->member();

		$followers = $this->member();
		$privacy->set_preference( $followers, 'profile_visibility', 'followers' );
		$html = $this->locked_profile( $followers, $viewer );
		$this->assertStringContainsString( 'Only followers can see this profile.', $html );
		$this->assertStringContainsString( 'bn-follow-btn', $html );

		$connections = $this->member();
		$privacy->set_preference( $connections, 'profile_visibility', 'connections' );
		$html = $this->locked_profile( $connections, $viewer );
		$this->assertStringContainsString( 'Only connections can see this profile.', $html );
		$this->assertStringContainsString( 'bn-connection-btn-wrap', $html );
		$this->assertStringNotContainsString( 'bn-follow-btn', $html );

		$requested = $this->private_with_request( $viewer );
		$privacy->set_preference( $requested, 'profile_visibility', 'followers' );
		wp_cache_flush();
		$this->assertStringContainsString( '&quot;isPending&quot;:true', $this->locked_profile( $requested, $viewer ) );
	}

	/**
	 * What the locked page must not offer or say.
	 *
	 * @return void
	 */
	public function test_locked_profile_reveals_nothing_to_those_it_cannot_help(): void {
		$privacy = buddynext_service( 'privacy' );
		$viewer  = $this->member();

		// Nobody may follow them: the audience is true, the button would be a lie.
		$closed = $this->member();
		$privacy->set_preference( $closed, 'profile_visibility', 'followers' );
		$privacy->set_preference( $closed, 'who_can_follow', 'nobody' );
		$this->assertStringNotContainsString( 'bn-follow-btn', $this->locked_profile( $closed, $viewer ) );

		// Only-me: no control, no audience.
		$only = $this->member();
		$privacy->set_preference( $only, 'profile_visibility', 'private' );
		$html = $this->locked_profile( $only, $viewer );
		$this->assertStringContainsString( 'This profile is private.', $html );
		$this->assertStringNotContainsString( 'bn-profile-private__actions', $html );

		// A blocked viewer learns no more than before.
		$blocker = $this->member();
		$privacy->set_preference( $blocker, 'profile_visibility', 'followers' );
		buddynext_service( 'blocks' )->block( $blocker, $viewer );
		$html = $this->locked_profile( $blocker, $viewer );
		$this->assertStringContainsString( 'This profile is private.', $html );
		$this->assertStringNotContainsString( 'Only followers', $html );
		$this->assertStringNotContainsString( 'bn-follow-btn', $html );

		// A guest gets the login link back here, and not the audience.
		$followers = $this->member();
		$privacy->set_preference( $followers, 'profile_visibility', 'followers' );
		$html = $this->locked_profile( $followers, 0 );
		$this->assertStringContainsString( 'This profile is private.', $html );
		$this->assertStringNotContainsString( 'Only followers', $html );
		$this->assertStringContainsString( 'redirect_to=' . rawurlencode( \BuddyNext\Core\PageRouter::profile_url( $followers ) ), $html );
		$this->assertStringNotContainsString( 'bn-follow-btn', $html );
	}
}
