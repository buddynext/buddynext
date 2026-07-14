<?php
/**
 * Tests that exactly ONE system prunes bn_notifications and bn_email_log.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronScheduler;
use BuddyNext\Core\CronService;
use BuddyNext\Core\Installer;
use BuddyNext\Core\LogRetentionService;

/**
 * Regression cover for the duplicated log-retention systems.
 *
 * TWO systems pruned the SAME two tables. CronService did it weekly under
 * `buddynext_data_retention_days` (default 365) — the only retention setting exposed in
 * the admin. LogRetentionService did it daily under `buddynext_log_retention_days`
 * (default 60) — an option with NO UI at all.
 *
 * The daily 60-day sweep always reached a row before the weekly 365-day one. So the
 * owner's visible setting was DEAD for these tables: they could set "Data retention: 365
 * days", save it, and notifications would still vanish at 60, governed by a value they
 * could not see or change.
 *
 * LogRetentionService is the better implementation and is now the sole owner — daily
 * rather than weekly, batched rather than one unbounded DELETE, and it keeps UNREAD
 * notifications to a separate hard max. Its option is now exposed in Settings.
 *
 * @covers \BuddyNext\Core\CronService
 * @covers \BuddyNext\Core\LogRetentionService
 */
class LogRetentionOwnershipTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * CronService must no longer prune the two log tables at all.
	 *
	 * @return void
	 */
	public function test_cron_service_no_longer_owns_the_log_tables(): void {
		$this->assertFalse(
			method_exists( CronService::class, 'handle_cleanup_notifications' ),
			'CronService still prunes bn_notifications. Two systems purging the same table on different windows means the owner\'s setting is a lie.'
		);
		$this->assertFalse(
			method_exists( CronService::class, 'handle_cleanup_email_log' ),
			'CronService still prunes bn_email_log.'
		);
	}

	/**
	 * The retired hooks must not be registered any more.
	 *
	 * A hook with no listener does not error — it fires into nothing. So a stale
	 * registration is invisible, which is exactly how a duplicate survives.
	 *
	 * @return void
	 */
	public function test_the_retired_cron_hooks_have_no_listeners(): void {
		( new CronScheduler() )->init();

		$this->assertFalse(
			has_action( CronScheduler::JOB_CLEANUP_NOTIFICATIONS ),
			'The retired notifications-cleanup hook still has a listener.'
		);
		$this->assertFalse(
			has_action( CronScheduler::JOB_CLEANUP_EMAIL_LOG ),
			'The retired email-log-cleanup hook still has a listener.'
		);
	}

	/**
	 * The surviving owner still prunes read notifications past its window.
	 *
	 * Deleting the duplicate must not delete the behaviour.
	 *
	 * @return void
	 */
	public function test_the_surviving_owner_still_prunes_read_notifications(): void {
		global $wpdb;

		$old = gmdate( 'Y-m-d H:i:s', time() - ( 200 * DAY_IN_SECONDS ) );

		$wpdb->insert(
			$wpdb->prefix . 'bn_notifications',
			array(
				'recipient_id' => 1,
				'type'         => 'bn.test',
				'is_read'      => 1,
				'created_at'   => $old,
			)
		);

		$count = fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE type = 'bn.test'" );
		$this->assertSame( 1, $count(), 'precondition: an old READ notification exists' );

		( new LogRetentionService() )->purge();

		$this->assertSame( 0, $count(), 'The surviving retention owner no longer prunes read notifications.' );
	}

	/**
	 * An UNREAD notification is NOT dropped on the read window.
	 *
	 * This is the behaviour that makes a short window acceptable: nothing a member has
	 * not seen is deleted early. Losing it while consolidating would be a silent
	 * regression — the member simply never learns what they missed.
	 *
	 * @return void
	 */
	public function test_an_unread_notification_survives_the_read_window(): void {
		global $wpdb;

		// Older than the 60-day read window, younger than the 90-day unread hard max.
		$between = gmdate( 'Y-m-d H:i:s', time() - ( 70 * DAY_IN_SECONDS ) );

		$wpdb->insert(
			$wpdb->prefix . 'bn_notifications',
			array(
				'recipient_id' => 1,
				'type'         => 'bn.unread',
				'is_read'      => 0,
				'created_at'   => $between,
			)
		);

		( new LogRetentionService() )->purge();

		$survivors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE type = 'bn.unread'" );

		$this->assertSame(
			1,
			$survivors,
			'An UNREAD notification was deleted on the READ window. A member is now missing a notification they never had a chance to see.'
		);
	}
}
