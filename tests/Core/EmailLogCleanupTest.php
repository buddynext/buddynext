<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests for the bn_email_log retention prune.
 *
 * These tests used to drive CronService::handle_cleanup_email_log(). That handler is
 * gone: it was the SECOND system pruning bn_email_log, and it always lost. See the note
 * on test_zero_retention_no_longer_disables_pruning() — one of the assertions here was
 * passing while the behaviour it claimed to protect was already dead in production.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Core\LogRetentionService;
use WP_UnitTestCase;

/**
 * Email-log cleanup behaviour, against its sole owner.
 */
class EmailLogCleanupTest extends WP_UnitTestCase {

	/**
	 * The retention owner under test.
	 *
	 * @var LogRetentionService
	 */
	private LogRetentionService $retention;

	/**
	 * Ensure the schema + empty table.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->retention = new LogRetentionService();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_email_log" );
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
		update_option( LogRetentionService::OPTION, 60 );
		$this->seed_row( 400 ); // Older than 60 — pruned.
		$this->seed_row( 90 );  // Older than 60 — pruned.
		$this->seed_row( 10 );  // Recent — kept.

		$this->retention->purge();

		$this->assertSame( 1, $this->rows(), 'Only the recent row should remain.' );
	}

	/**
	 * The owner's chosen window is the window that actually runs.
	 *
	 * This is the whole point of the consolidation. Before it, a row's fate was decided by
	 * whichever of the two retention systems reached it first, not by the setting.
	 *
	 * @return void
	 */
	public function test_the_chosen_window_is_the_window_applied(): void {
		update_option( LogRetentionService::OPTION, 90 );
		$this->seed_row( 70 ); // Inside a 90-day window; would have died under the 60-day default.

		$this->retention->purge();

		$this->assertSame( 1, $this->rows(), 'A row inside the owner\'s chosen 90-day window was deleted anyway.' );
	}

	/**
	 * An out-of-range window (including the old 0 = "never prune") falls back to default.
	 *
	 * The previous version of this file asserted the opposite: that
	 * `buddynext_data_retention_days = 0` disabled pruning and kept every row forever.
	 * That assertion passed for months while being FALSE on every real site —
	 * LogRetentionService was already sweeping bn_email_log daily at 60 days no matter what
	 * that option said. The test only went green because it called the CronService handler
	 * directly and never exercised the daily sweep that actually ran.
	 *
	 * So this is not a behaviour being removed here; it is a behaviour that never existed
	 * being written down honestly. There is deliberately no "unlimited" window — an
	 * unbounded log table is the bug this service exists to prevent.
	 *
	 * @return void
	 */
	public function test_zero_retention_no_longer_disables_pruning(): void {
		update_option( LogRetentionService::OPTION, 0 );
		$this->seed_row( 1000 );

		$this->assertSame(
			LogRetentionService::DEFAULT_WINDOW,
			LogRetentionService::window_days(),
			'An out-of-range window must fall back to the default, not disable pruning.'
		);

		$this->retention->purge();

		$this->assertSame( 0, $this->rows(), 'A 1000-day-old row survived. The log table is unbounded again.' );
	}
}
