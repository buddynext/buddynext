<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext keeps Action Scheduler lean without touching any other plugin's history.
 *
 * The old action_scheduler_retention_period filter was site-wide: it cut every
 * plugin's history (WooCommerce included) to 14 days. BuddyNext now leaves that
 * setting alone and its daily retention pass clears only its own completed rows
 * older than a week, with their log rows.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronScheduler;
use BuddyNext\Core\LogRetentionService;
use WP_UnitTestCase;

/**
 * Scheduler retention behaviour.
 */
class CronRetentionTest extends WP_UnitTestCase {

	/**
	 * The site-wide retention setting is left exactly as the site has it.
	 *
	 * @return void
	 */
	public function test_site_wide_retention_is_not_changed(): void {
		( new CronScheduler() )->init();

		$this->assertSame( 30 * DAY_IN_SECONDS, apply_filters( 'action_scheduler_retention_period', 30 * DAY_IN_SECONDS ) );
	}

	/**
	 * Only our completed rows older than 7 days go, with their logs.
	 *
	 * @return void
	 */
	public function test_purges_only_our_completed_rows_older_than_a_week(): void {
		$old    = $this->action( 'buddynext_zz_done', 'complete', 8, 'buddynext' );
		$recent = $this->action( 'buddynext_zz_recent', 'complete', 3, 'buddynext' );
		$queued = $this->action( 'buddynext_zz_waiting', 'pending', 8, 'buddynext' );
		$failed = $this->action( 'buddynext_zz_failed', 'failed', 8, 'buddynext' );
		$other  = $this->action( 'woocommerce_zz_done', 'complete', 8, 'woocommerce' );

		$deleted = ( new LogRetentionService() )->purge();

		$this->assertSame( 1, $deleted['scheduler_actions'] );
		$this->assertFalse( $this->exists( $old ), 'Our week-old completed row goes.' );
		$this->assertSame( 0, $this->log_count( $old ), 'Its log rows go with it.' );
		$this->assertTrue( $this->exists( $recent ), 'A 3-day-old row stays.' );
		$this->assertTrue( $this->exists( $queued ), 'Pending work is never touched.' );
		$this->assertTrue( $this->exists( $failed ), 'Failed rows are left for the owner to inspect.' );
		$this->assertTrue( $this->exists( $other ), 'Another plugin\'s row stays.' );
		$this->assertSame( 1, $this->log_count( $other ) );
	}

	/**
	 * Canceled rows (a deactivation cancels a whole queue) go after a week like
	 * completed ones; failed rows are kept 90 days, then go too.
	 *
	 * @return void
	 */
	public function test_canceled_rows_go_after_a_week_and_failed_rows_after_90_days(): void {
		$canceled   = $this->action( 'buddynext_zz_canceled', 'canceled', 8, 'buddynext' );
		$old_failed = $this->action( 'buddynext_zz_failed_old', 'failed', 91, 'buddynext' );
		$new_failed = $this->action( 'buddynext_zz_failed_new', 'failed', 30, 'buddynext' );
		$other      = $this->action( 'woocommerce_zz_failed', 'failed', 91, 'woocommerce' );

		( new LogRetentionService() )->purge();

		$this->assertFalse( $this->exists( $canceled ), 'A week-old canceled row goes.' );
		$this->assertFalse( $this->exists( $old_failed ), 'A failed row older than 90 days goes.' );
		$this->assertSame( 0, $this->log_count( $old_failed ) );
		$this->assertTrue( $this->exists( $new_failed ), 'A 30-day-old failed row stays.' );
		$this->assertTrue( $this->exists( $other ), 'Another plugin\'s failed row stays.' );
	}

	/**
	 * Insert one action row (and one log row) in a group, N days old.
	 *
	 * @param string $hook   Hook.
	 * @param string $status Status.
	 * @param int    $days   Age in days.
	 * @param string $group  Group slug.
	 * @return int Action id.
	 */
	private function action( string $hook, string $status, int $days, string $group ): int {
		global $wpdb;
		$p        = $wpdb->prefix;
		$group_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$p}actionscheduler_groups WHERE slug = %s", $group ) );
		if ( 0 === $group_id ) {
			$wpdb->insert( "{$p}actionscheduler_groups", array( 'slug' => $group ) );
			$group_id = (int) $wpdb->insert_id;
		}
		$date = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->insert(
			"{$p}actionscheduler_actions",
			array(
				'hook'                 => $hook,
				'status'               => $status,
				'scheduled_date_gmt'   => $date,
				'scheduled_date_local' => $date,
				'args'                 => '[]',
				'group_id'             => $group_id,
			)
		);
		$id = (int) $wpdb->insert_id;
		$wpdb->insert(
			"{$p}actionscheduler_logs",
			array(
				'action_id'      => $id,
				'message'        => 'done',
				'log_date_gmt'   => $date,
				'log_date_local' => $date,
			)
		);
		return $id;
	}

	/**
	 * Whether an action row exists.
	 *
	 * @param int $id Action id.
	 * @return bool
	 */
	private function exists( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", $id ) );
	}

	/**
	 * Log rows for an action.
	 *
	 * @param int $id Action id.
	 * @return int
	 */
	private function log_count( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id = %d", $id ) );
	}
}
