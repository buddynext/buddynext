<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Importer seam: follows and connection requests accept a backdated created_at.
 *
 * Migrations write through the service API only, so without these seams every
 * imported follow/friendship was stamped with the migration run time
 * (cards 10124307318 / 10124307358).
 *
 * @package BuddyNext\Tests\SocialGraph
 * @since 1.1.0
 */

declare(strict_types=1);

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use WP_UnitTestCase;

/**
 * Locks created_at pass-through on follow() and send_request().
 */
class SocialGraphBackdateTest extends WP_UnitTestCase {

	/**
	 * follow() with a supplied created_at stores it; without, stamps a
	 * current timestamp (column default behaviour preserved).
	 *
	 * @return void
	 */
	public function test_follow_honours_backdated_created_at(): void {
		global $wpdb;

		$a = self::factory()->user->create();
		$b = self::factory()->user->create();
		$c = self::factory()->user->create();

		$service = new FollowService();

		$this->assertTrue( $service->follow( $a, $b, '2021-03-03 03:03:03' ) );
		$this->assertSame(
			'2021-03-03 03:03:03',
			$wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND following_id = %d", $a, $b ) )
		);

		// Without the argument the column default still applies — the seam must
		// not change existing behaviour.
		$this->assertTrue( $service->follow( $a, $c ) );
		$fresh = (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND following_id = %d", $a, $c ) );
		$this->assertNotSame( '', $fresh, 'un-backdated follow still gets a timestamp from the column default' );
		$this->assertNotSame( '2021-03-03 03:03:03', $fresh );
	}

	/**
	 * send_request() with a supplied created_at stores it on the connection row.
	 *
	 * @return void
	 */
	public function test_send_request_honours_backdated_created_at(): void {
		global $wpdb;

		$a = self::factory()->user->create();
		$b = self::factory()->user->create();

		$service = new ConnectionService();

		$this->assertTrue( $service->send_request( $a, $b, '', '2021-04-04 04:04:04' ) );
		$this->assertSame(
			'2021-04-04 04:04:04',
			$wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}bn_connections WHERE requester_id = %d AND recipient_id = %d", $a, $b ) )
		);
	}
}
