<?php
/**
 * The week-over-week figure on the notifications "This week" card.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Core\Installer;
use BuddyNext\Sidebar\WidgetService;

/**
 * Weekly stats delta tests.
 *
 * @covers \BuddyNext\Sidebar\WidgetService::weekly_stats
 */
class WeeklyStatsDeltaTest extends \WP_UnitTestCase {

	/**
	 * Member whose stats are read.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->member = self::factory()->user->create();
	}

	/**
	 * Add notifications for the member, a number of days ago.
	 *
	 * @param int $count    How many.
	 * @param int $days_ago Age in days.
	 * @return void
	 */
	private function notify( int $count, int $days_ago ): void {
		global $wpdb;
		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_notifications',
				array(
					'recipient_id' => $this->member,
					'type'         => 'bn.new_follower',
					'created_at'   => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ),
				)
			);
		}
	}

	/**
	 * A quiet week after a busy one shows no percentage.
	 *
	 * @return void
	 */
	public function test_quiet_week_shows_no_percentage(): void {
		$this->notify( 4, 10 );

		$stats = ( new WidgetService( new \BuddyNext\Sidebar\WidgetCache() ) )->weekly_stats( $this->member );

		$this->assertSame( 0, $stats['notifications'] );
		$this->assertSame( '', $stats['wow_delta_label'] );
		$this->assertSame( 'flat', $stats['wow_trend'] );
	}

	/**
	 * A real drop still shows as a negative percentage.
	 *
	 * @return void
	 */
	public function test_real_drop_still_shows(): void {
		$this->notify( 10, 10 );
		$this->notify( 3, 1 );

		$stats = ( new WidgetService( new \BuddyNext\Sidebar\WidgetCache() ) )->weekly_stats( $this->member );

		$this->assertSame( '-70%', $stats['wow_delta_label'] );
		$this->assertSame( 'down', $stats['wow_trend'] );
	}
}
