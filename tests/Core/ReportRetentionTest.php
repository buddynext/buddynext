<?php
/**
 * Closed reports are kept for the retention window counted from when they closed.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronService;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Core\CronService::handle_cleanup_reports
 */
class ReportRetentionTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		update_option( 'buddynext_data_retention_days', 30 );
	}

	private function report( int $object_id, int $filed_days_ago, ?int $closed_days_ago ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_reports',
			array(
				'reporter_id' => 1,
				'object_type' => 'post',
				'object_id'   => $object_id,
				'reason'      => 'spam',
				'status'      => 'resolved',
				'created_at'  => gmdate( 'Y-m-d H:i:s', time() - $filed_days_ago * DAY_IN_SECONDS ),
				'resolved_at' => null === $closed_days_ago ? null : gmdate( 'Y-m-d H:i:s', time() - $closed_days_ago * DAY_IN_SECONDS ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function exists( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_reports WHERE id = %d", $id ) );
	}

	public function test_an_old_report_closed_yesterday_is_kept_and_one_closed_long_ago_goes(): void {
		$recent = $this->report( 9001, 40, 1 );
		$old    = $this->report( 9002, 60, 45 );
		$legacy = $this->report( 9003, 60, null ); // Closed before resolved_at was recorded.

		( new CronService() )->handle_cleanup_reports();

		$this->assertTrue( $this->exists( $recent ), 'A report closed yesterday was deleted because it was filed 40 days ago.' );
		$this->assertFalse( $this->exists( $old ) );
		$this->assertFalse( $this->exists( $legacy ) );
	}
}
