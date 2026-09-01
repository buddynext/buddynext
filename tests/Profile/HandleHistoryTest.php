<?php
/**
 * One handle, two homes, and a memory of the ones left behind.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\Handle;

/**
 * bn_profile_slug and user_nicename were written independently, so a member with
 * a custom slug had TWO live public identities: BuddyNext showed @simmy while
 * wp-admin, the author archive and the REST slug field all still said
 * sim_member. Handle::set() writes both.
 *
 * Keeping them in step removes the accident that made renames survivable — old
 * mentions and links worked only because user_nicename happened to stay put — so
 * the handle history replaces it deliberately.
 *
 * @covers \BuddyNext\Profile\Handle::set
 * @covers \BuddyNext\Profile\Handle::history
 * @covers \BuddyNext\Profile\Handle::previous_owner
 */
class HandleHistoryTest extends \WP_UnitTestCase {

	private int $member;
	private int $other;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->member = self::factory()->user->create( array( 'user_login' => 'member_one' ) );
		$this->other  = self::factory()->user->create( array( 'user_login' => 'member_two' ) );
	}

	/**
	 * @param int $user_id User.
	 * @return string
	 */
	private function nicename( int $user_id ): string {
		clean_user_cache( $user_id );
		$user = get_userdata( $user_id );
		return $user ? (string) $user->user_nicename : '';
	}

	public function test_setting_a_handle_moves_both_homes_together(): void {
		$this->assertTrue( Handle::set( $this->member, 'first-handle' ) );

		$this->assertSame( 'first-handle', (string) get_user_meta( $this->member, 'bn_profile_slug', true ) );
		$this->assertSame( 'first-handle', $this->nicename( $this->member ), 'WordPress must agree with BuddyNext.' );
		$this->assertSame( 'first-handle', PageRouter::member_handle( $this->member ) );
	}

	public function test_the_previous_handle_is_remembered(): void {
		$original = $this->nicename( $this->member );

		Handle::set( $this->member, 'second-handle' );

		$this->assertSame( array( $original ), Handle::history( $this->member ) );
	}

	public function test_every_handle_a_member_has_used_still_resolves_to_them(): void {
		$original = $this->nicename( $this->member );
		Handle::set( $this->member, 'handle-two' );
		Handle::set( $this->member, 'handle-three' );

		foreach ( array( $original, 'handle-two', 'handle-three' ) as $handle ) {
			$resolved = Handle::resolve( $handle );
			$this->assertInstanceOf( \WP_User::class, $resolved, "@{$handle} must still reach its owner." );
			$this->assertSame( $this->member, (int) $resolved->ID );
		}
	}

	/**
	 * The impersonation case: without this, every mention of @alice written
	 * before her rename silently becomes a mention of whoever took it.
	 *
	 * @return void
	 */
	public function test_a_used_handle_cannot_be_claimed_by_someone_else(): void {
		Handle::set( $this->member, 'was-mine' );
		Handle::set( $this->member, 'now-this' );

		$this->assertFalse( PageRouter::is_slug_available( 'was-mine', $this->other ) );
	}

	/**
	 * ...but its original owner may take it back.
	 *
	 * @return void
	 */
	public function test_a_member_can_reclaim_their_own_former_handle(): void {
		Handle::set( $this->member, 'was-mine' );
		Handle::set( $this->member, 'now-this' );

		$this->assertTrue( PageRouter::is_slug_available( 'was-mine', $this->member ) );
	}

	public function test_renaming_back_does_not_record_the_current_handle(): void {
		$original = $this->nicename( $this->member );
		Handle::set( $this->member, 'temporary' );
		Handle::set( $this->member, $original );

		$this->assertNotContains( $original, Handle::history( $this->member ), 'A member must not own their current handle twice.' );
	}

	public function test_an_invalid_handle_is_refused_by_the_writer_too(): void {
		$result = Handle::set( $this->member, 'ab' );

		$this->assertWPError( $result );
		$this->assertSame( 'handle_too_short', $result->get_error_code() );
	}
}
