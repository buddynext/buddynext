<?php
/**
 * The new-member review threshold counts PUBLISHED posts only, as its help text
 * says. A member whose posts were deleted, drafted or held did not earn trust.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Moderation\SafeguardService;

/**
 * @covers \BuddyNext\Moderation\SafeguardService
 */
class NewMemberGateCountsPublishedTest extends \WP_UnitTestCase {

	/**
	 * Insert posts for a member.
	 *
	 * @param int    $user_id Author.
	 * @param string $status  Post status.
	 * @param int    $count   How many.
	 * @return void
	 */
	private function add_posts( int $user_id, string $status, int $count ): void {
		global $wpdb;
		for ( $i = 0; $i < $count; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_posts',
				array(
					'user_id' => $user_id,
					'status'  => $status,
					'content' => 'post',
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * @return void
	 */
	public function test_only_published_posts_count_toward_the_threshold(): void {
		update_option( 'buddynext_new_member_post_threshold', 3 );
		$member = self::factory()->user->create();
		$gate   = new \ReflectionMethod( SafeguardService::class, 'check_new_member_gate' );

		$this->add_posts( $member, 'deleted', 5 );
		$this->assertWPError( $gate->invoke( new SafeguardService(), $member ), 'removed posts must not graduate a new member' );

		$this->add_posts( $member, 'published', 3 );
		$this->assertTrue( $gate->invoke( new SafeguardService(), $member ), 'three published posts graduate them' );
		delete_option( 'buddynext_new_member_post_threshold' );
	}
}
