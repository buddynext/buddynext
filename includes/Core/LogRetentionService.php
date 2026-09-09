<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Log retention — age-purge for bn_notifications, bn_email_log, the webhook
 * delivery logs (bn_outbound_webhook_log, bn_webhook_log) and stale bn_presence
 * rows.
 *
 * THE SOLE OWNER of these two tables' retention. It was not always: CronService also
 * pruned both, weekly, under the separate `buddynext_data_retention_days` option — the
 * ONLY retention setting the owner could actually see in the admin. Two systems purging
 * the same two tables on different schedules under different options, and the daily
 * 60-day sweep here always reached a row before the weekly 365-day one did.
 *
 * So the owner's visible setting was DEAD for these tables. They could set "Data
 * retention: 365 days", save it, and notifications would still vanish at 60 — governed by
 * an option with no UI at all. Those CronService handlers are now deleted, and this
 * service's own option is exposed in Settings, so the control the owner sees is the
 * control that runs.
 *
 * (This class's docblock previously claimed "Both tables were append-only. Nothing ever
 * deleted from them, so they grew forever." That was untrue on the day it was written —
 * CronService had been deleting from both for months. A file that lies about itself gets
 * believed by the next reader, and this one did.)
 *
 * The owner picks a window (30 / 60 / 90 days, default 60). Read notifications and
 * email-log rows older than the window are deleted.
 *
 * UNREAD notifications are treated differently, and deliberately:
 *
 *   Read      -> purged at the owner's window.
 *   Unread    -> kept until the HARD MAX (90 days), whatever the window is.
 *
 * The alternative — exempting unread entirely — sounds kinder and is not: the members
 * who never open the bell are exactly the members who accumulate the most rows, so the
 * table stays unbounded for precisely the accounts that make it a problem. Purging
 * unread on the SAME window as read is the other extreme: a 30-day window would then
 * silently discard a notification a member had never had a chance to see. Keeping
 * unread to the hard max is the honest middle — nothing a member has not seen is
 * dropped early, and the table is still bounded.
 *
 * Mechanism follows docs/standards/BACKGROUND-JOBS.md category 5 (genuinely always-on
 * cleanup): ONE daily recurring Action Scheduler action, self-arming, no polling. The
 * delete is BATCHED — a single unbounded `DELETE ... WHERE created_at < x` against
 * millions of rows takes a long table lock, and on shared hosting that is an outage.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Daily, batched age-purge of the two append-only log tables.
 */
class LogRetentionService {

	/**
	 * Option holding the owner's retention window, in days.
	 */
	public const OPTION = 'buddynext_log_retention_days';

	/**
	 * Windows the owner may choose. There is deliberately no "unlimited": the whole
	 * point of the setting is that these tables stay bounded, and an unlimited option
	 * is just the current bug with a checkbox in front of it.
	 */
	public const ALLOWED_WINDOWS = array( 30, 60, 90 );

	/**
	 * Default window when the owner has not chosen one.
	 */
	public const DEFAULT_WINDOW = 60;

	/**
	 * Hard ceiling for UNREAD notifications, regardless of the chosen window.
	 *
	 * An unread notification is something the member has not seen yet. It is never
	 * dropped early just because the owner picked a short window — but it is not kept
	 * forever either, or the table is unbounded for every member who ignores the bell.
	 */
	public const UNREAD_MAX_DAYS = 90;

	/**
	 * Rows deleted per statement. Small enough that the lock is never held long on a
	 * shared host; large enough that a big backlog still drains in a reasonable number
	 * of passes.
	 */
	private const BATCH = 5000;

	/**
	 * Batches per run, so one cron pass cannot run away. A backlog just takes a few
	 * days of runs to drain, which is fine for a retention sweep.
	 */
	private const MAX_BATCHES_PER_RUN = 20;

	/**
	 * Action Scheduler hook.
	 */
	public const HOOK = 'buddynext_purge_logs';

	/**
	 * Action Scheduler group.
	 */
	public const AS_GROUP = 'buddynext';

	/**
	 * Hook the worker and arm the daily schedule.
	 *
	 * @return void
	 */
	public function register(): void {
		// Wrapped, not passed directly: purge() returns a per-table count (useful to a
		// caller, and to the tests), and an action callback must return nothing.
		add_action(
			self::HOOK,
			function (): void {
				$this->purge();
			}
		);
		add_action( 'init', array( __CLASS__, 'arm' ) );
	}

	/**
	 * Arm the daily recurring action, exactly once.
	 *
	 * The as_has_scheduled_action guard stops it re-arming on every request — without
	 * it, every page view would enqueue another copy.
	 *
	 * @return void
	 */
	public static function arm(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::HOOK, array(), self::AS_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + HOUR_IN_SECONDS,
			DAY_IN_SECONDS,
			self::HOOK,
			array(),
			self::AS_GROUP
		);
	}

	/**
	 * The owner's retention window, in days.
	 *
	 * Anything not in ALLOWED_WINDOWS falls back to the default — a hand-edited option
	 * (or a filter) must not be able to set a 3650-day window and quietly reintroduce
	 * the unbounded growth this exists to stop.
	 *
	 * @return int
	 */
	public static function window_days(): int {
		$days = (int) get_option( self::OPTION, self::DEFAULT_WINDOW );

		return in_array( $days, self::ALLOWED_WINDOWS, true ) ? $days : self::DEFAULT_WINDOW;
	}

	/**
	 * Delete aged rows from both log tables, in batches.
	 *
	 * @return array{notifications:int,email_log:int,webhook_log:int,presence:int} Rows deleted, per table.
	 */
	public function purge(): array {
		global $wpdb;

		$window        = self::window_days();
		$read_cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $window * DAY_IN_SECONDS ) );
		$unread_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::UNREAD_MAX_DAYS * DAY_IN_SECONDS ) );

		$deleted = array(
			'notifications' => 0,
			'email_log'     => 0,
			'webhook_log'   => 0,
			'presence'      => 0,
		);

		// Read notifications older than the owner's window.
		$deleted['notifications'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_notifications WHERE is_read = 1 AND created_at < %s LIMIT %d",
			$read_cutoff
		);

		// Unread notifications older than the HARD MAX — never the owner's window.
		$deleted['notifications'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_notifications WHERE is_read = 0 AND created_at < %s LIMIT %d",
			$unread_cutoff
		);

		// The email log has no read/unread distinction — it is a record of what we sent.
		$deleted['email_log'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_email_log WHERE sent_at < %s LIMIT %d",
			$read_cutoff
		);

		// Webhook delivery logs (outbound calls + inbound access-webhook receipts)
		// are append-only and otherwise grow forever. Same class of unbounded log
		// as the email log, so they follow the same window.
		$deleted['webhook_log'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_outbound_webhook_log WHERE created_at < %s LIMIT %d",
			$read_cutoff
		);
		$deleted['webhook_log'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_webhook_log WHERE created_at < %s LIMIT %d",
			$read_cutoff
		);

		// Presence rows for members who have not been seen within the window. One
		// row per user, replaced on their next visit — purging a stale row only
		// drops a "last seen months ago" marker, never anything a member relies on.
		// last_active is an INT unix timestamp, so the cutoff is bound with %d.
		$deleted['presence'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_presence WHERE last_active < %d LIMIT %d",
			(string) ( time() - ( $window * DAY_IN_SECONDS ) )
		);

		// Orphaned notifications — rows whose target object is GONE. NotificationService
		// deletes these at the moment of deletion (on buddynext_post_deleted /
		// _comment_deleted, and SpaceService inline), so this daily pass only drains
		// legacy rows and any created by a path those hooks do not cover (a direct DB
		// delete, a failed cascade). It is the WRITE-side companion to the read-side
		// filter_resolvable(): the read gate hides a dead row from a page, but the row
		// stays in the table and every COUNT(*)/pager keeps counting it (card
		// 10264293036). Set-based and batched, over the types BuddyNext can prove gone
		// (post/comment/space); partner types it cannot check are left alone, exactly
		// as the read gate keeps a "cannot tell" row. Counts self-heal on the 30s cache
		// TTL after this off-request pass — no per-user bust needed here.
		$deleted['orphaned_notifications']  = $this->delete_orphans_batched( 'post', 'bn_posts' );
		$deleted['orphaned_notifications'] += $this->delete_orphans_batched( 'comment', 'bn_comments' );
		$deleted['orphaned_notifications'] += $this->delete_orphans_batched( 'space', 'bn_spaces' );
		// 'user'-keyed rows (follow/connection notifications naming a member) whose
		// member is gone. wp_delete_user -> MemberCleanupService already removes these
		// at delete time; this is the backstop for a raw DB delete that fired no hook
		// (card 10264293036). The owner table is WordPress core wp_users, whose PK is
		// ID — MySQL column names are case-insensitive, so the join's o.id resolves.
		$deleted['orphaned_notifications'] += $this->delete_orphans_batched( 'user', 'users' );

		/**
		 * Fires after a retention purge, so a site can log or monitor it.
		 *
		 * @since 1.0.8
		 *
		 * @param array{notifications:int,email_log:int,webhook_log:int,presence:int,orphaned_notifications:int} $deleted Rows removed per table.
		 * @param int                                    $window  The window used, in days.
		 */
		do_action( 'buddynext_logs_purged', $deleted, $window );

		return $deleted;
	}

	/**
	 * Batch-delete notifications of one type whose target row no longer exists.
	 *
	 * A LEFT JOIN … IS NULL anti-join (not NOT IN, which materialises the whole id
	 * set), bounded by LIMIT and the same per-run batch cap as delete_batched(). The
	 * $table is a hardcoded, code-derived table slug — never user input.
	 *
	 * @param string $object_type Notification object_type slug (e.g. 'post').
	 * @param string $table       Owning table WITHOUT prefix (e.g. 'bn_posts').
	 * @return int Rows deleted.
	 */
	private function delete_orphans_batched( string $object_type, string $table ): int {
		global $wpdb;

		$owner  = $wpdb->prefix . preg_replace( '/[^a-z0-9_]/', '', $table );
		$notifs = $wpdb->prefix . 'bn_notifications';
		$total  = 0;

		// SELECT the orphan ids (LEFT JOIN anti-join, bounded by LIMIT) then delete them
		// by id. A multi-table DELETE cannot take a LIMIT in MySQL, so batching is done
		// on the SELECT and the delete is a plain single-table IN() — index-driven on
		// the PRIMARY key.
		// $owner/$notifs are code-derived table names; $object_type and the id list are
		// bound. The interpolated names defeat the per-line sniff on a multi-line
		// prepare(), so the block is disabled and re-enabled around the two queries.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		for ( $i = 0; $i < self::MAX_BATCHES_PER_RUN; $i++ ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT n.id FROM {$notifs} n
					 LEFT JOIN {$owner} o ON o.id = n.object_id
					 WHERE n.object_type = %s AND o.id IS NULL
					 LIMIT %d",
					$object_type,
					self::BATCH
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			$ids          = array_map( 'intval', $ids );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$total       += (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$notifs} WHERE id IN ( {$placeholders} )", $ids )
			);

			if ( count( $ids ) < self::BATCH ) {
				break;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return $total;
	}

	/**
	 * Run one batched DELETE until it stops matching, or the per-run cap is hit.
	 *
	 * Batching is the whole point. A single `DELETE ... WHERE created_at < x` over
	 * millions of rows holds a table lock for the duration; on shared hosting that is
	 * an outage, not a slow query. Deleting in bounded chunks keeps every statement
	 * short, and a backlog simply takes a few nightly runs to drain.
	 *
	 * @param string $sql    Prepared-style SQL with a %s cutoff and a %d limit.
	 * @param string $cutoff Datetime cutoff (UTC).
	 * @return int Rows deleted.
	 */
	private function delete_batched( string $sql, string $cutoff ): int {
		global $wpdb;

		$total = 0;

		// $sql is a caller-supplied literal with a %s cutoff + %d limit; both are bound.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		for ( $i = 0; $i < self::MAX_BATCHES_PER_RUN; $i++ ) {
			$rows = (int) $wpdb->query( $wpdb->prepare( $sql, $cutoff, self::BATCH ) );

			$total += $rows;

			// Fewer than a full batch means we have reached the end of the aged rows.
			if ( $rows < self::BATCH ) {
				break;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return $total;
	}
}
