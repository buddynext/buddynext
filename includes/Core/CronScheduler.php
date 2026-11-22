<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Recurring background-job registration for BuddyNext.
 *
 * Recurring jobs run on Action Scheduler (group 'buddynext') when available —
 * one observable, retrying queue that can be driven off real system cron so
 * nothing executes inside a visitor request — and fall back to native WP-Cron
 * when AS is absent. All job handles are defined here so other code references
 * them by constant rather than bare string.
 *
 * Jobs defined:
 *   buddynext_daily_digest         — daily (first run at activation time, then every 24h)
 *   buddynext_weekly_digest        — weekly (first run at activation time, then every 7 days)
 *   buddynext_cleanup_tokens       — daily
 *   buddynext_cleanup_notifications— weekly (prune 90-day-old read rows)
 *   buddynext_recount_stats        — daily (counters maintained incrementally on write;
 *                                    daily run is a reconcile pass only)
 *
 * Removed recurring jobs (converted to lazy/reactive):
 *   buddynext_trending_hashtags    — dropped; HashtagService::get_trending() computes
 *                                    lazily on first read and caches via transient (~30 min).
 *   buddynext_publish_scheduled    — no longer a recurring 1-min poll; now a single
 *                                    on-demand event armed at the next due post's time by
 *                                    Feed\ScheduledPostsPublisher (Free-owned, runs without Pro).
 *   buddynext_webhook_retry        — dropped; OutboundWebhookService schedules a single
 *                                    wp_schedule_single_event per failed delivery with backoff.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Manages registration of BuddyNext WP-Cron events.
 */
class CronScheduler {

	// ── Job handle constants ──────────────────────────────────────────────────

	/**
	 * Daily digest job hook.
	 */
	public const JOB_DAILY_DIGEST = 'buddynext_daily_digest';

	/**
	 * Weekly digest job hook.
	 */
	public const JOB_WEEKLY_DIGEST = 'buddynext_weekly_digest';

	/**
	 * RETIRED in 1.3.0 — token cleanup runs inside the daily retention job
	 * (LogRetentionService::HOOK). Kept so clear_events() and the upgrade migration
	 * can unschedule it where it is still armed.
	 */
	public const JOB_CLEANUP_TOKENS = 'buddynext_cleanup_tokens';

	/**
	 * RETIRED — no longer registered or scheduled.
	 *
	 * LogRetentionService owns bn_notifications retention now (daily, batched, with a
	 * separate hard max for unread). This hook is kept ONLY so clear_events() and the
	 * upgrade migration can unschedule it on installs where it is still armed: a
	 * recurring event whose hook has no listener never fires, but it stays in the cron
	 * array forever, which is how a "retired" job quietly becomes permanent litter.
	 */
	public const JOB_CLEANUP_NOTIFICATIONS = 'buddynext_cleanup_notifications';

	/**
	 * RETIRED — no longer registered or scheduled.
	 *
	 * The bn_activity_log table was never written and is retired (Installer::LEGACY_TABLES).
	 * Kept only so clear_events() and the upgrade migration can unschedule it where
	 * it is still armed. See JOB_CLEANUP_NOTIFICATIONS above.
	 *
	 * @var string
	 */
	public const JOB_CLEANUP_ACTIVITY_LOG = 'buddynext_cleanup_activity_log';

	/**
	 * RETIRED — no longer registered or scheduled.
	 *
	 * LogRetentionService owns bn_email_log retention now. Kept only so clear_events()
	 * and the upgrade migration can unschedule it where it is still armed. See
	 * JOB_CLEANUP_NOTIFICATIONS above.
	 *
	 * @var string
	 */
	public const JOB_CLEANUP_EMAIL_LOG = 'buddynext_cleanup_email_log';

	/**
	 * RETIRED in 1.3.0 as its own job — the prune of CLOSED bn_reports rows runs on
	 * every daily run of the retention job (LogRetentionService::HOOK). Kept so
	 * clear_events() and the upgrade migration can unschedule it where it is armed.
	 *
	 * Free owns bn_reports, so Free prunes it. Pro used to DELETE from this table (and from
	 * the append-only bn_mod_log) out of its AI-moderation sweep, on every site that had Pro
	 * installed — including sites that never enabled AI moderation.
	 *
	 * @since 1.0.8
	 *
	 * @var string
	 */
	public const JOB_CLEANUP_REPORTS = 'buddynext_cleanup_reports';

	/**
	 * Counter recount job hook. Runs daily — counters are maintained
	 * incrementally on every write; this is a reconcile pass only.
	 */
	public const JOB_RECOUNT_STATS = 'buddynext_recount_stats';

	/**
	 * Action Scheduler group for every BuddyNext recurring job, so all jobs are
	 * observable together (Tools -> Scheduled Actions) and bulk-cancelable.
	 */
	public const GROUP = 'buddynext';

	/**
	 * Jobs deactivation leaves pending: each FINISHES an action a member or the
	 * owner already committed, or carries state nothing can rebuild. Everything
	 * else of ours (digests, sends, reindexes, recounts, prunes, nudges, caches)
	 * can be recomputed or is lost by nature and is canceled. Kept, and why:
	 *   buddynext_purge_member_continue    GDPR hard delete of a member's content.
	 *   buddynext_space_delete_chunk       A Space deletion already under way.
	 *   buddynext_space_drive_media_trash  That deletion's media trash chain.
	 *   buddynext_purge_field_values       Values of a profile field the owner deleted.
	 *   buddynext_finish_upgrade           The upgrade's data migrations.
	 *   buddynext_space_link_backfill_chunk Course-to-Space link backfill (migration).
	 *   buddynext_reconcile_media_privacy  Media privacy cascade after a privacy change.
	 *   buddynext_async_withdraw_forum_surfaces Forum surfaces withdrawn after a privacy change.
	 *   buddynext_jetonomy_grant_space_members / _copy_forum_members  Forum access already granted.
	 *   buddynext_async_send_invite_email  An invite the member sent (the only delivery).
	 *   buddynext_webhook_deliver / _deliver_one / _retry_single  Event payloads promised to a receiver.
	 *   buddynext_prune_replaced_image     Old file of an image a member replaced.
	 *
	 * Limit: while BuddyNext is off, another plugin's Action Scheduler still runs
	 * due rows with no listener and marks them complete, so a deactivation longer
	 * than about a minute loses them. The supported update flow (deactivate,
	 * replace files, activate) runs nothing in between and keeps them.
	 *
	 * @since 1.3.0
	 */
	public const DEACTIVATION_KEEP = array(
		'buddynext_purge_member_continue',
		'buddynext_space_delete_chunk',
		'buddynext_space_drive_media_trash',
		'buddynext_purge_field_values',
		'buddynext_finish_upgrade',
		'buddynext_space_link_backfill_chunk',
		'buddynext_reconcile_media_privacy',
		'buddynext_async_withdraw_forum_surfaces',
		'buddynext_jetonomy_grant_space_members',
		'buddynext_jetonomy_copy_forum_members',
		'buddynext_async_send_invite_email',
		'buddynext_webhook_deliver',
		'buddynext_webhook_deliver_one',
		'buddynext_webhook_retry_single',
		'buddynext_prune_replaced_image',
	);

	/**
	 * Rows one cancel statement marks; keeps each UPDATE's lock short.
	 */
	private const CANCEL_BATCH = 10000;

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Attach scheduler hooks and wire all job handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_loaded', array( $this, 'schedule_events' ) );

		// No action_scheduler_retention_period filter here: it is site-wide and
		// would shorten every plugin's history (WooCommerce included). BuddyNext's
		// own finished rows are cleared by LogRetentionService instead.

		// Wire cron job handlers — one action per job defined above.
		$handlers = new CronService();
		add_action( self::JOB_DAILY_DIGEST, array( $handlers, 'handle_daily_digest' ) );
		add_action( self::JOB_WEEKLY_DIGEST, array( $handlers, 'handle_weekly_digest' ) );
		// Token and report cleanup ride the one daily retention job (LogRetentionService)
		// instead of two recurring actions of their own. Reports were weekly; daily
		// costs one index-bounded DELETE and cannot skip a week when a run is missed.
		add_action( LogRetentionService::HOOK, array( $handlers, 'handle_cleanup_tokens' ), 20 );
		add_action( LogRetentionService::HOOK, array( $handlers, 'handle_cleanup_reports' ), 20 );
		add_action( self::JOB_RECOUNT_STATS, array( $handlers, 'handle_recount_stats' ) );

		add_filter( 'site_status_tests', array( self::class, 'register_site_health' ) );
	}

	/**
	 * Add the "background tasks are running" Site Health test.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public static function register_site_health( array $tests ): array {
		$tests['direct']['buddynext_background_tasks'] = array(
			'label' => __( 'BuddyNext background tasks', 'buddynext' ),
			'test'  => array( self::class, 'site_health_queue_test' ),
		);
		return $tests;
	}

	/**
	 * Site Health: warn when BuddyNext's background tasks have stopped running.
	 *
	 * Signal: a task in the 'buddynext' Action Scheduler group still waiting an
	 * hour after it was due. A working site runs them within minutes, so an hour
	 * means nothing is driving the queue (WP-Cron disabled with no server cron, a
	 * blocked loopback request, a host that stops background requests). Search,
	 * email digests and bulk updates wait on it. One capped query, no stored state.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, mixed>
	 */
	public static function site_health_queue_test(): array {
		$badge   = array(
			'label' => __( 'BuddyNext', 'buddynext' ),
			'color' => 'blue',
		);
		$overdue = 0;
		if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_get_datetime_object' ) ) {
			$ids     = as_get_scheduled_actions(
				array(
					'group'        => self::GROUP,
					'status'       => 'pending',
					'date'         => as_get_datetime_object( '-1 hour' ),
					'date_compare' => '<=',
					'per_page'     => 1,
				),
				'ids'
			);
			$overdue = is_array( $ids ) ? count( $ids ) : 0;
		}

		if ( 0 === $overdue ) {
			return array(
				'label'       => __( 'BuddyNext background tasks are running', 'buddynext' ),
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'No BuddyNext background task is waiting past its time.', 'buddynext' ) . '</p>',
				'test'        => 'buddynext_background_tasks',
			);
		}

		$badge['color'] = 'orange';
		return array(
			'label'       => __( 'BuddyNext background tasks are not running', 'buddynext' ),
			'status'      => 'recommended',
			'badge'       => $badge,
			'description' => '<p>' . esc_html__( 'Some BuddyNext background tasks have been waiting for more than an hour. A working site runs them within minutes. Until they run, email digests are not sent and large updates (re-indexing search, changes to a whole space) are not finished. New posts are still searchable straight away.', 'buddynext' ) . '</p>'
				. '<p>' . esc_html__( 'The usual cause is that WordPress cannot start its own background requests: WP-Cron is turned off without a server cron job, or the host or a firewall blocks the site from calling itself. Ask your host to add a server cron job that runs wp-cron.php every minute, and check the loopback request result on this screen.', 'buddynext' ) . '</p>',
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=action-scheduler&status=past-due' ) ),
				esc_html__( 'See the waiting tasks', 'buddynext' )
			),
			'test'        => 'buddynext_background_tasks',
		);
	}


	/**
	 * Is a PENDING action queued for this hook (and args, when given)?
	 *
	 * The dedupe check for jobs that queue their own next run. as_has_scheduled_action()
	 * and as_next_scheduled_action() also count the action running right now, so from
	 * inside that action they always say yes and the chain stops after one pass.
	 *
	 * @param string     $hook  Action hook.
	 * @param array|null $args  Exact args to match, or null for any.
	 * @param string     $group Action group.
	 * @return bool
	 */
	public static function has_pending( string $hook, ?array $args = null, string $group = self::GROUP ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		$query = array(
			'hook'     => $hook,
			'group'    => $group,
			'status'   => 'pending', // ActionScheduler_Store::STATUS_PENDING.
			'per_page' => 1,
		);
		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return array() !== as_get_scheduled_actions( $query, 'ids' );
	}

	/**
	 * Queue an async job unless an identical one (same hook, args and group) is already pending.
	 *
	 * For per-event jobs whose worker reads current state when it runs: a second
	 * identical job before the first has started would only repeat the same work.
	 * A job already running does not count, so an edit made while it runs is
	 * still picked up by a fresh pass. Never pass 'unique' to Action Scheduler with
	 * args (3.9 ignores args for uniqueness); use this instead.
	 *
	 * @since 1.3.0
	 *
	 * @param string $hook  Action hook.
	 * @param array  $args  Job args (ids, kept under 191 characters).
	 * @param string $group Action group.
	 * @return bool True when a job was queued, false when an identical one was already pending.
	 */
	public static function enqueue_once( string $hook, array $args, string $group = self::GROUP ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) || self::has_pending( $hook, $args, $group ) ) {
			return false;
		}
		as_enqueue_async_action( $hook, $args, $group );

		return true;
	}

	/**
	 * Cancel every PENDING action in the given groups, and of the given hooks, set-based.
	 *
	 * For deactivation. Action Scheduler's own group cancel is one UPDATE per 1,000
	 * rows only while its tables store is active; AS clears its "migration complete"
	 * flag on every plugin deactivation, so in that request it often runs the hybrid
	 * store, which cancels one row at a time (three queries a row: 100,000 pending
	 * rows took minutes and timed the deactivation out). This marks our own pending
	 * rows canceled directly, CANCEL_BATCH at a time, on the group_id and status
	 * indexes. Running, failed, complete and other plugins' rows are never touched;
	 * no "action canceled" log row is written (the finished-row purge clears ours).
	 *
	 * @since 1.3.0
	 *
	 * @param string[] $groups        Our group slugs.
	 * @param string[] $hooks         Our hooks to cancel in any group (jobs queued without our group).
	 * @param string[] $keep_prefixes Hook prefixes left alone inside $groups (Free passes Pro's,
	 *                                whose hooks run in the 'buddynext' group).
	 * @param string[] $keep_hooks    Exact hooks left alone inside $groups (DEACTIVATION_KEEP).
	 * @return int Rows canceled.
	 */
	public static function cancel_pending( array $groups, array $hooks = array(), array $keep_prefixes = array(), array $keep_hooks = array() ): int {
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = array_values( array_filter( array_map( 'strval', $groups ) ) );
		$hooks   = array_values( array_filter( array_map( 'strval', $hooks ) ) );
		$total   = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) ) ) {
			return 0; // Action Scheduler never installed its tables here.
		}

		$wheres = array();
		if ( array() !== $groups ) {
			$group_ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug IN (" . implode( ',', array_fill( 0, count( $groups ), '%s' ) ) . ')',
						$groups
					)
				)
			);
			if ( array() !== $group_ids ) {
				$where = 'group_id IN (' . implode( ',', $group_ids ) . ')';
				foreach ( $keep_prefixes as $prefix ) {
					$where .= $wpdb->prepare( ' AND hook NOT LIKE %s', $wpdb->esc_like( (string) $prefix ) . '%' );
				}
				if ( array() !== $keep_hooks ) {
					$where .= $wpdb->prepare( ' AND hook NOT IN (' . implode( ',', array_fill( 0, count( $keep_hooks ), '%s' ) ) . ')', array_values( $keep_hooks ) );
				}
				$wheres[] = $where;
			}
		}
		if ( array() !== $hooks ) {
			$wheres[] = $wpdb->prepare( 'hook IN (' . implode( ',', array_fill( 0, count( $hooks ), '%s' ) ) . ')', $hooks );
		}

		foreach ( $wheres as $where ) {
			do {
				$done   = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$actions} SET status = 'canceled' WHERE status = 'pending' AND {$where} LIMIT %d", self::CANCEL_BATCH ) );
				$total += $done;
			} while ( $done >= self::CANCEL_BATCH );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return $total;
	}

	/**
	 * Run a callback now if Action Scheduler's data store is ready, else when it is.
	 *
	 * An as_*() call made before action_scheduler_init logs "called before the
	 * data store was initialized" and does nothing. The one place this check lives.
	 *
	 * @since 1.3.0
	 *
	 * @param callable $callback Work that calls Action Scheduler.
	 * @return void
	 */
	public static function when_ready( callable $callback ): void {
		if ( did_action( 'action_scheduler_init' ) ) {
			$callback();
			return;
		}
		add_action( 'action_scheduler_init', $callback );
	}

	/**
	 * Keep one recurring job armed: the one check every recurring job in Free and Pro uses.
	 *
	 * Gated by is_scheduling_request(); a no-op when the job is already queued.
	 * Action Scheduler is asked with unique, so two requests arming at once still
	 * leave one row; a legacy WP-Cron event of the same hook is cleared so it never
	 * double-runs. Without Action Scheduler it falls back to WP-Cron, using the
	 * registered recurrence whose interval matches (daily when none does).
	 *
	 * @since 1.3.0
	 *
	 * @param string $hook     Action hook.
	 * @param int    $interval Seconds between runs.
	 * @param int    $first    First run (Unix time); 0 for now.
	 * @param string $group    Action group (an add-on passes its own).
	 * @return int The new action id; 0 when nothing was scheduled (already armed,
	 *             not a scheduling request, a concurrent arm won, or WP-Cron used).
	 */
	public static function ensure_recurring( string $hook, int $interval, int $first = 0, string $group = self::GROUP ): int {
		if ( ! self::is_scheduling_request() ) {
			return 0;
		}
		$first = $first > 0 ? $first : time();
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( false !== as_next_scheduled_action( $hook, array(), $group ) ) {
				return 0;
			}
			wp_clear_scheduled_hook( $hook );
			return (int) as_schedule_recurring_action( $first, $interval, $hook, array(), $group, true );
		}
		if ( ! wp_next_scheduled( $hook ) ) {
			$recurrence = 'daily';
			foreach ( wp_get_schedules() as $name => $schedule ) {
				if ( (int) $schedule['interval'] === $interval ) {
					$recurrence = (string) $name;
					break;
				}
			}
			wp_schedule_event( $first, $recurrence, $hook );
		}
		return 0;
	}

	/**
	 * Is this a request where recurring schedules should be checked and armed?
	 *
	 * Checking a schedule is a database lookup per job (as_next_scheduled_action),
	 * and doing it on every page, REST call, image and heartbeat made it the
	 * largest part of every request's query floor. A recurring job only needs to
	 * be checked where it is cheap and still reliable: the cron runner (which runs
	 * on any live site and re-arms a lost job within one tick), wp-admin page
	 * loads and WP-CLI. Never front-end, REST or AJAX. ensure_recurring() asks
	 * this first.
	 *
	 * @return bool
	 */
	public static function is_scheduling_request(): bool {
		return wp_doing_cron()
			|| ( is_admin() && ! wp_doing_ajax() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Ensure every recurring event is scheduled.
	 *
	 * Called on wp_loaded — safe to call on every request because
	 * wp_schedule_event is a no-op when the event is already scheduled.
	 *
	 * @return void
	 */
	public function schedule_events(): void {
		if ( ! self::is_scheduling_request() ) {
			return;
		}

		self::ensure_recurring( self::JOB_DAILY_DIGEST, DAY_IN_SECONDS );
		self::ensure_recurring( self::JOB_WEEKLY_DIGEST, WEEK_IN_SECONDS );
		self::ensure_recurring( self::JOB_RECOUNT_STATS, DAY_IN_SECONDS );
	}

	/**
	 * Unschedule all BuddyNext cron events (called on plugin deactivation).
	 *
	 * Clears both the Action Scheduler actions (current) and any native WP-Cron
	 * events (legacy installs / AS-absent fallback) so nothing is left armed.
	 *
	 * @return void
	 */
	public function clear_events(): void {
		$jobs = array(
			self::JOB_DAILY_DIGEST,
			self::JOB_WEEKLY_DIGEST,
			self::JOB_CLEANUP_TOKENS,
			self::JOB_CLEANUP_NOTIFICATIONS,
			self::JOB_CLEANUP_ACTIVITY_LOG,
			self::JOB_CLEANUP_EMAIL_LOG,
			self::JOB_CLEANUP_REPORTS,
			self::JOB_RECOUNT_STATS,
			// Recurring jobs armed outside this class, same 'buddynext' group.
			\BuddyNext\Moderation\ModerationListener::QUEUE_CHECK_HOOK,
			LogRetentionService::HOOK,
			// Legacy hooks — may still be scheduled on older installs; clear them too.
			'buddynext_trending_hashtags',
			'buddynext_publish_scheduled',
			'buddynext_webhook_retry',
		);

		// WP-Cron copies (AS-absent fallback, legacy installs). Their Action
		// Scheduler copies are in our group and go with the cancel below.
		foreach ( $jobs as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Everything else still pending of ours that can be recomputed or is lost by
		// nature (fan-out pages, reindexes, backfills of caches, the per-member
		// onboarding nudges a busy community holds by the thousand). Set-based, so a
		// large queue cannot time the deactivation out (see cancel_pending()). Jobs
		// that finish a committed action stay pending (DEACTIVATION_KEEP), as in
		// 1.2.9. Pro's hooks in the 'buddynext' group are Pro's: deactivating Free
		// leaves them (Pro stands down without Free; its own deactivation clears
		// them), as uninstall does.
		self::cancel_pending( array( self::GROUP ), array( 'bn_onboarding_nudge_24h', 'bn_onboarding_nudge_72h' ), array( 'buddynextpro_', 'buddynext_pro_' ), self::DEACTIVATION_KEEP );
	}

	/**
	 * One-time idempotent migration for existing installs.
	 *
	 * Removes events that were replaced or dropped in the cron-minimisation pass
	 * and reschedules recount_stats to 'daily' when it is still running on the
	 * old buddynext_5min recurrence. Safe to call repeatedly — each step is
	 * guarded by a condition that is false once the migration has been applied.
	 *
	 * Called from Installer::maybe_upgrade() when SCHEMA_VERSION advances.
	 *
	 * @return void
	 */
	public static function run_cron_migration(): void {
		// 1. Drop the legacy recurring 1-min scheduled-post publisher, then hand
		// off to the on-demand publisher: it re-arms a single event at the next
		// due post's time (or stays disarmed when none are pending). Free owns
		// this so existing scheduled posts keep publishing with Pro absent.
		wp_clear_scheduled_hook( 'buddynext_publish_scheduled' );
		\BuddyNext\Feed\ScheduledPostsPublisher::arm();

		// 2. Remove the 30-min trending hashtags recount (lazy on read now).
		wp_clear_scheduled_hook( 'buddynext_trending_hashtags' );

		// 3. Remove the recurring 5-min webhook retry poll (single-event now).
		wp_clear_scheduled_hook( 'buddynext_webhook_retry' );

		// 4. Drop any legacy native WP-Cron recount_stats event (older installs ran
		// it on a sub-hour custom recurrence). schedule_events() -> ensure_recurring()
		// re-registers it on Action Scheduler ('daily') on the next wp_loaded;
		// clearing it here makes the migration self-contained rather than relying on
		// a later request to undo the stale recurrence.
		if ( false !== wp_next_scheduled( self::JOB_RECOUNT_STATS ) ) {
			wp_clear_scheduled_hook( self::JOB_RECOUNT_STATS );
		}

		// 5. Retire the two duplicate log-retention jobs.
		//
		// CronService used to prune bn_notifications and bn_email_log weekly, while
		// LogRetentionService pruned the SAME two tables daily — two systems, two
		// options, one set of rows, and the daily sweep always won. Those handlers are
		// gone and the hooks are no longer registered.
		//
		// Unregistering is not enough on an EXISTING install. The events are already
		// armed there, and a recurring event whose hook has no listener does not error —
		// it simply fires into nothing, forever, staying in the cron array (and in
		// Action Scheduler's tables) as permanent litter that every future debugger has
		// to explain. Clear both explicitly, on both schedulers.
		//
		// 1.3.0: token and report cleanup run inside the daily retention job
		// (LogRetentionService::HOOK), so their own recurring actions are retired here too.
		foreach ( array( self::JOB_CLEANUP_NOTIFICATIONS, self::JOB_CLEANUP_EMAIL_LOG, self::JOB_CLEANUP_ACTIVITY_LOG, self::JOB_CLEANUP_TOKENS, self::JOB_CLEANUP_REPORTS ) as $bn_retired ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $bn_retired, array(), self::GROUP );
			}
			wp_clear_scheduled_hook( $bn_retired );
		}

		// 6. The remaining recurring jobs migrate from native WP-Cron to Action
		// Scheduler automatically: schedule_events() -> ensure_recurring() clears each
		// legacy WP-Cron event and registers the AS action on the next wp_loaded.

		// 7. Onboarding nudges now schedule per-user Action Scheduler single actions at
		// registration (bn_onboarding_nudge_24h/_72h with the user id as an arg). Clear
		// the two litter sources this replaces, both idempotent no-ops once gone. First,
		// the pre-1.2.0 native WP-Cron single events for the same hooks — the original
		// unbounded-cron-option bug (305 KB after 1,500 signups). Second, the brief
		// recurring buddynext_onboarding_nudge_sweep AS action from the queue-table
		// approach that this supersedes — a recurring action whose listener is gone
		// would otherwise fire into nothing forever, exactly the permanent litter step 5
		// clears. New members are covered from their next registration onward (the AS
		// actions schedule on user_register), which matches the retired baseline
		// behaviour of not retroactively nudging the existing community, so no baseline
		// stamp is needed.
		wp_unschedule_hook( 'bn_onboarding_nudge_24h' );
		wp_unschedule_hook( 'bn_onboarding_nudge_72h' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'buddynext_onboarding_nudge_sweep', array(), self::GROUP );
		}

		// Drop the short-lived queue table (empty on any install that carried it — it
		// only ever held in-flight rows the sweep drained). dbDelta never drops, so the
		// removal lives here. IF EXISTS keeps it a no-op on installs that never had it.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bn_onboarding_nudges" );
	}

	/**
	 * Report background-task health for the Tools diagnostics surface.
	 *
	 * Background jobs run automatically on a normal WordPress install (WP-Cron
	 * fires the Action Scheduler runner on page loads). They only stall when the
	 * site has WP-Cron disabled (`DISABLE_WP_CRON`) without a system cron wired
	 * to drive it — then scheduled actions pile up overdue and nothing processes
	 * them. This detects that case so the admin can be told to add a server cron.
	 *
	 * @return array{wp_cron_disabled: bool, as_available: bool, overdue: int, stalled: bool, loopback_ok: bool, pending: int, failed: int}
	 */
	public static function health(): array {
		$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$as_available     = function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_get_datetime_object' );
		$overdue          = 0;
		$pending          = 0;
		$failed           = 0;

		if ( $as_available && class_exists( '\ActionScheduler_Store' ) ) {
			// Whole-queue counts (all groups, not just ours) — the panel answers
			// "is the background queue backed up?", and a pile of stuck core/email
			// actions is exactly what the owner needs to see. COUNT queries, not
			// row fetches, so they stay cheap regardless of queue size.
			$store   = \ActionScheduler::store();
			$pending = (int) $store->query_actions( array( 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'count' );
			$failed  = (int) $store->query_actions( array( 'status' => \ActionScheduler_Store::STATUS_FAILED ), 'count' );
		}

		if ( $as_available ) {
			// Pending actions whose scheduled time passed over an hour ago — a
			// reliable "the runner is not firing" signal (a healthy site clears
			// these within minutes). Capped: we only need "are tasks piling up?".
			$ids = as_get_scheduled_actions(
				array(
					'status'       => 'pending',
					'date'         => as_get_datetime_object( '-1 hour' ),
					'date_compare' => '<=',
					'per_page'     => 50,
				),
				'ids'
			);

			$overdue = is_array( $ids ) ? count( $ids ) : 0;
		}

		$loopback_ok = self::loopback_ok();

		return array(
			'wp_cron_disabled' => $wp_cron_disabled,
			'as_available'     => $as_available,
			'overdue'          => $overdue,
			'pending'          => $pending,
			'failed'           => $failed,
			'loopback_ok'      => $loopback_ok,
			// Stalled when nothing can drive the queue AND work is piling up.
			// Two ways nothing drives it: WP-Cron explicitly disabled, or the
			// loopback request the async runner depends on cannot reach the site
			// (firewall, localhost DNS, HTTP auth) — the latter is invisible to a
			// DISABLE_WP_CRON check, which is exactly the blind spot this reports.
			'stalled'          => ( $wp_cron_disabled || ! $loopback_ok ) && $overdue > 0,
		);
	}

	/**
	 * Whether the site can make a loopback HTTP request to itself.
	 *
	 * The WP-Cron and Action Scheduler async runners both fire a non-blocking
	 * loopback request to spawn the background process. When loopback is broken
	 * (a firewall blocking self-requests, localhost not resolving, basic-auth on
	 * staging) the runner never starts and jobs pile up silently — with
	 * DISABLE_WP_CRON still false, so the usual check says all is well.
	 *
	 * Mirrors WP core's Site Health loopback test (POST to admin-ajax.php with a
	 * no-op action). Cached for 15 minutes because it is a blocking round-trip we
	 * do not want on every admin render. ponytail: transient cache, 15-min TTL.
	 *
	 * @return bool True if the loopback succeeded (or could not be tested).
	 */
	private static function loopback_ok(): bool {
		$cached = get_transient( 'bn_cron_loopback_ok' );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ), // wp-frontend-rest-only-allow: server-side loopback probe mirroring core Site Health, not a frontend call.
			array(
				'timeout'   => 10,
				'blocking'  => true,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array( 'action' => 'nopriv_bn_loopback_noop' ),
				// $_COOKIE is always a defined array; forward the current request's
				// cookies to the loopback exactly as WP core's cron spawner does.
				'cookies'   => $_COOKIE,
			)
		);

		// admin-ajax returns 400 ("0") for an unknown action — that is a *reached*
		// site, which is all we are testing. Only a WP_Error (connection refused,
		// timeout, DNS failure) means loopback is genuinely broken.
		$ok = ! is_wp_error( $response );
		set_transient( 'bn_cron_loopback_ok', $ok ? '1' : '0', 15 * MINUTE_IN_SECONDS );

		return $ok;
	}
}
