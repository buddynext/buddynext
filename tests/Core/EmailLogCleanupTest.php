<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests for the bn_email_log retention prune (change-index item S3a).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronService;
use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Email-log cleanup behaviour.
 */
class EmailLogCleanupTest extends WP_UnitTestCase {

	/**
	 * Cron handlers under test.
	 *
	 * @var CronService
	 */
	private CronService $cron;

	/**
	 * Ensure the schema + empty table.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->cron = new CronService();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bn_email_log" );
	}

	/**
	 * Insert a bn_email_log row sent $days_ago.
	 *
	 * @param int $days_ago Age in days.
	 * @return void
	 */
	private function seed_row( int $days_ago ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_email_log',
			array(
				'user_id' => 1,
				'type'    => 'digest',
				'sent_at' => gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) ),
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Count of rows in bn_email_log.
	 *
	 * @return int
	 */
	private function rows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_email_log" );
	}

	/**
	 * Rows older than the retention window are pruned; recent rows are kept.
	 *
	 * @return void
	 */
	public function test_prunes_old_keeps_recent(): void {
		update_option( 'buddynext_data_retention_days', 365 );
		$this->seed_row( 400 ); // older than 365 — pruned.
		$this->seed_row( 500 ); // older — pruned.
		$this->seed_row( 10 );  // recent — kept.

		$this->cron->handle_cleanup_email_log();

		$this->assertSame( 1, $this->rows(), 'Only the recent row should remain.' );
	}

	/**
	 * A retention setting of 0 disables pruning (keeps everything).
	 *
	 * @return void
	 */
	public function test_zero_retention_disables_pruning(): void {
		update_option( 'buddynext_data_retention_days', 0 );
		$this->seed_row( 1000 );

		$this->cron->handle_cleanup_email_log();

		$this->assertSame( 1, $this->rows(), 'Retention 0 must keep all rows.' );
	}
}
