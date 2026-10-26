<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The @handle and the profile URL come from one slug chain (card 10435747502):
 * bn_profile_slug, then user_nicename, then user-{ID}. A member with no nicename
 * showed a bare "@" while their profile link used user-{ID}.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;

/**
 * @covers \BuddyNext\Core\PageRouter::member_handle
 * @covers \BuddyNext\Core\PageRouter::profile_url
 */
class MemberHandleTest extends \WP_UnitTestCase {

	private function url_slug( int $user_id ): string {
		return basename( untrailingslashit( PageRouter::profile_url( $user_id ) ) );
	}

	public function test_no_nicename_falls_back_to_user_id_in_both(): void {
		// Core caches users by nicename and notices the empty key this fixture creates.
		$this->setExpectedIncorrectUsage( 'WP_Object_Cache::add' );
		$id = self::factory()->user->create();
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_nicename' => '' ), array( 'ID' => $id ) );
		clean_user_cache( $id );

		$this->assertSame( 'user-' . $id, PageRouter::member_handle( $id ) );
		$this->assertSame( PageRouter::member_handle( $id ), $this->url_slug( $id ) );
	}

	public function test_custom_slug_wins_in_both(): void {
		$id = self::factory()->user->create( array( 'user_nicename' => 'plain-nicename' ) );
		update_user_meta( $id, 'bn_profile_slug', 'chosen-handle' );

		$this->assertSame( 'chosen-handle', PageRouter::member_handle( $id ) );
		$this->assertSame( 'chosen-handle', $this->url_slug( $id ) );
	}

	public function test_unknown_user_has_no_handle(): void {
		$this->assertSame( '', PageRouter::member_handle( 999999 ) );
	}
}
