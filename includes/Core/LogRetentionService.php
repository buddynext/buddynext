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
	 *
	 * @deprecated 1.3.0 Use CronScheduler::GROUP.
	 */
	public const AS_GROUP = CronScheduler::GROUP;

	/**
	 * Days a completed BuddyNext Action Scheduler row is kept (filterable, not stored).
	 */
	public const FINISHED_RETENTION_DAYS = 7;

	/**
	 * Days a failed BuddyNext Action Scheduler row is kept, for diagnosis (the family rule).
	 */
	public const FAILED_RETENTION_DAYS = 90;

	/**
	 * Scheduler rows removed per statement, the per-run cap (10,000 rows a day, the
	 * family number) and the seconds a run may spend; the rest waits for the next
	 * day, and Action Scheduler's own cleaner is the backstop beyond that.
	 */
	public const ACTION_BATCH       = 200;
	public const MAX_ACTION_BATCHES = 50;
	private const ACTION_SECONDS    = 10;

	/**
	 * Seconds this listener's whole run may spend (tables and scheduler rows). Each
	 * listener on HOOK keeps its own budget, so one slow table cannot starve another
	 * plugin's prune of the same job.
	 */
	private const RUN_SECONDS = 15;

	/**
	 * When the current run must stop (INF outside a run).
	 *
	 * @var float
	 */
	private float $deadline = INF;

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
	 * Arm the daily recurring action, exactly once (CronScheduler::ensure_recurring()).
	 *
	 * @return void
	 */
	public static function arm(): void {
		CronScheduler::ensure_recurring( self::HOOK, time() + HOUR_IN_SECONDS, DAY_IN_SECONDS );
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
	 * @return array{notifications:int,email_log:int,webhook_log:int,presence:int,invites:int,orphaned_notifications:int,scheduler_actions:int} Rows deleted, per table.
	 */
	public function purge(): array {
		global $wpdb;

		$this->deadline = microtime( true ) + self::RUN_SECONDS;

		$window        = self::window_days();
		$read_cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $window * DAY_IN_SECONDS ) );
		$unread_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::UNREAD_MAX_DAYS * DAY_IN_SECONDS ) );

		$deleted = array(
			'notifications' => 0,
			'email_log'     => 0,
			'webhook_log'   => 0,
			'presence'      => 0,
			'invites'       => 0,
		);

		// Read notifications older than the owner's window.
		$deleted['notifications'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_notifications WHERE is_read = 1 AND created_at < %s LIMIT %d",
			$read_cutoff
		);

		// Rows outside the bell can never be read, so they age out on the owner's window
		// like read ones. Every window is longer than the 7-day digest look-back.
		$deleted['notifications'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_notifications WHERE is_read = 0 AND in_app = 0 AND created_at < %s LIMIT %d",
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

		// Email invitations (bn_invites) hold an invited person's email + first
		// name. Nothing aged these out, so an invitee's PII lingered forever (GDPR
		// data-minimisation gap). Two terminal cases carry no further purpose:
		// - registered/bounced older than the window — the invite is spent, and a
		// registered invitee's email now lives on their user account instead.
		// - pending past its own expires_at — a dead, unusable token.
		// The status_expires index covers both predicates. A member's own invite
		// row (they were invited, then joined) is also cleared on GDPR erase, via
		// the email-keyed eraser in PrivacyTools.
		$deleted['invites'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_invites WHERE status IN ('registered','bounced') AND created_at < %s LIMIT %d",
			$read_cutoff
		);
		$deleted['invites'] += $this->delete_batched(
			"DELETE FROM {$wpdb->prefix}bn_invites WHERE status = 'pending' AND expires_at < %s LIMIT %d",
			gmdate( 'Y-m-d H:i:s' )
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

		// Our own finished Action Scheduler rows. Fan-out and async jobs leave many
		// completed rows; this keeps a week of them and leaves every other plugin's
		// rows (and the site-wide retention setting) alone.
		$deleted['scheduler_actions'] = $this->purge_finished_actions();

		/**
		 * Fires after a retention purge, so a site can log or monitor it.
		 *
		 * @since 1.0.8
		 *
		 * @param array{notifications:int,email_log:int,webhook_log:int,presence:int,invites:int,orphaned_notifications:int,scheduler_actions:int} $deleted Rows removed per table.
		 * @param int                                    $window  The window used, in days.
		 */
		do_action( 'buddynext_logs_purged', $deleted, $window );

		return $deleted;
	}

	/**
	 * Days BuddyNext's completed and canceled Action Scheduler rows are kept (filtered, at least 1).
	 *
	 * @since 1.3.0
	 *
	 * @return int
	 */
	public static function finished_retention_days(): int {
		/**
		 * Days to keep BuddyNext's completed and canceled Action Scheduler rows
		 * before the daily retention job deletes them (with their logs). Failed rows
		 * are kept 90 days; pending and running rows and other plugins' rows are
		 * never touched. Below 3 days, a member registered longer ago than the value
		 * gets no restored onboarding nudge after a deactivation (a sent nudge's row
		 * may already be gone, so it cannot be told from a canceled one).
		 *
		 * @since 1.3.0
		 *
		 * @param int $days Whole days. Default 7 (LogRetentionService::FINISHED_RETENTION_DAYS); values below 1 count as 1.
		 */
		return max( 1, (int) apply_filters( 'buddynext_finished_action_retention_days', self::FINISHED_RETENTION_DAYS ) );
	}

	/**
	 * Delete BuddyNext's finished Action Scheduler rows, with their logs.
	 *
	 * Completed and canceled rows go after a week (deactivation cancels a whole
	 * queue at once), failed rows after 90 days.
	 *
	 * Matched by exact group name (every BuddyNext action is scheduled in one of
	 * them; Pro adds its own through buddynext_finished_action_groups), on the same
	 * status + scheduled-date index Action Scheduler's own cleaner uses. Log rows
	 * go first so none are orphaned; 200 rows per statement keeps locks short.
	 *
	 * @return int Action rows deleted.
	 */
	private function purge_finished_actions(): int {
		global $wpdb;
		$days = self::finished_retention_days();

		/**
		 * Action Scheduler groups whose completed rows the daily retention job may
		 * delete. An add-on that runs its jobs in its own group adds it here
		 * (BuddyNext Pro adds its groups); never add another plugin's group.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $groups Group slugs. Default array( 'buddynext' ).
		 */
		$groups = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'buddynext_finished_action_groups', array( CronScheduler::GROUP ) ) ) ) );
		if ( array() === $groups ) {
			return 0;
		}

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total   = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$group_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug IN (" . implode( ',', array_fill( 0, count( $groups ), '%s' ) ) . ')',
					$groups
				)
			)
		);
		if ( array() === $group_ids ) {
			return 0;
		}
		$in = implode( ',', $group_ids );

		// One pass per status, each on the status + scheduled-date index, sharing
		// one per-run cap of batches.
		$passes   = array(
			'complete' => $cutoff,
			'canceled' => $cutoff,
			'failed'   => gmdate( 'Y-m-d H:i:s', time() - ( self::FAILED_RETENTION_DAYS * DAY_IN_SECONDS ) ),
		);
		$batches  = 0;
		$deadline = microtime( true ) + self::ACTION_SECONDS; // Its own budget, not what is left of the run.
		foreach ( $passes as $status => $before ) {
			while ( $batches < self::MAX_ACTION_BATCHES && microtime( true ) < $deadline ) {
				++$batches;
				$ids = array_map(
					'intval',
					(array) $wpdb->get_col(
						$wpdb->prepare(
							"SELECT action_id FROM {$actions} WHERE status = %s AND scheduled_date_gmt < %s AND group_id IN ({$in}) LIMIT %d",
							$status,
							$before,
							self::ACTION_BATCH
						)
					)
				);
				if ( array() === $ids ) {
					break;
				}
				$list = implode( ',', $ids );
				$wpdb->query( "DELETE FROM {$logs} WHERE action_id IN ({$list})" );
				$done   = (int) $wpdb->query( "DELETE FROM {$actions} WHERE action_id IN ({$list})" );
				$total += $done;
				if ( $done < self::ACTION_BATCH ) {
					break;
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return $total;
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
			if ( microtime( true ) >= $this->deadline ) {
				break;
			}
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
			if ( microtime( true ) >= $this->deadline ) {
				break;
			}
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
