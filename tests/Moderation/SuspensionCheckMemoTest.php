<?php
/**
 * is_suspended() is asked once per feed card; it must cost one query per member
 * per request and still see a suspension the moment it is written.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::is_suspended
 */
class SuspensionCheckMemoTest extends \WP_UnitTestCase {

	/**
	 * Repeated checks cost one query; suspend and lift are seen at once.
	 *
	 * @return void
	 */
	public function test_repeated_checks_cost_one_query_and_writes_are_seen(): void {
		$service = new ModerationService();
		$member  = self::factory()->user->create();
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		global $wpdb;

		$this->assertFalse( $service->is_suspended( $member ) );
		$before = $wpdb->num_queries;
		for ( $i = 0; $i < 30; $i++ ) {
			$service->is_suspended( $member );
		}
		$this->assertSame( $before, $wpdb->num_queries, '30 more checks, no more queries.' );

		$this->assertNotWPError( $service->suspend( $member, 'Spam', 3, false, $admin ) );
		$this->assertTrue( $service->is_suspended( $member ), 'A new suspension is seen in the same request.' );

		$service->unsuspend( $member );
		$this->assertFalse( $service->is_suspended( $member ), 'A lifted suspension is seen in the same request.' );
	}
}
