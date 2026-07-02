<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WP-Cron job handler implementations for BuddyNext background tasks.
 *
 * Contains every cron callback registered via CronScheduler. Each handler is a
 * discrete public method so unit tests can invoke them directly without firing
 * a WP-Cron event. The class is wired up inside CronScheduler::init().
 *
 * Handlers implemented:
 *   handle_daily_digest          — email digest for email_freq = 'daily' users
 *   handle_weekly_digest         — email digest for email_freq = 'weekly' users
 *   handle_cleanup_tokens        — prune expired bn_verify_tokens rows
 *   handle_cleanup_notifications — prune 90-day-old read bn_notifications rows
 *   handle_cleanup_activity_log  — prune old bn_activity_log rows
 *   handle_recount_stats         — correct reaction_count + comment_count on bn_posts (daily)
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

use BuddyNext\Notifications\EmailSender;

/**
 * Implements all BuddyNext WP-Cron job callbacks.
 */
class CronService {

	/**
	 * Maximum users processed per digest run to avoid PHP timeout.
	 */
	private const DIGEST_USER_CAP = 200;

	// ── Daily digest ──────────────────────────────────────────────────────────

	/**
	 * Send daily digest emails to users with email_freq = 'daily'.
	 *
	 * Queries bn_notification_prefs for distinct user IDs with the daily
	 * frequency, capped at DIGEST_USER_CAP per run. For each user, fetches
	 * unread notifications created within the last 24 hours. Skips any user
	 * whose daily digest was already sent today (checked via bn_email_log).
	 * Sends via wp_mail using the bn.daily_digest template and logs each
	 * successful delivery to bn_email_log with digest_date = today.
	 *
	 * Self-chains via Action Scheduler when a chunk fills the cap, so every daily user
	 * is reached (the old un-cursored LIMIT starved everyone past the first ~200).
	 *
	 * @param int $after_id Keyset cursor for chained chunks (0 = first/recurring run).
	 * @return void
	 */
	public function handle_daily_digest( int $after_id = 0 ): void {
		if ( $this->digests_disabled() ) {
			return;
		}

		$template = $this->get_email_template( 'bn.daily_digest' );
		if ( null === $template || ! (bool) $template->enabled ) {
			return;
		}

		$user_ids = $this->get_digest_user_ids( 'daily', $after_id );
		if ( empty( $user_ids ) ) {
			return;
		}

		foreach ( $user_ids as $user_id ) {
			if ( $this->digest_already_sent( $user_id, 'bn.daily_digest' ) ) {
				continue;
			}

			$notifications = $this->fetch_unread_notifications( $user_id, 1 );
			if ( empty( $notifications ) ) {
				continue;
			}

			$sent = $this->send_digest_email( $user_id, $template, $notifications );
			if ( $sent ) {
				$this->log_digest( $user_id, 'bn.daily_digest' );
			}
		}

		$this->chain_next_digest_chunk( CronScheduler::JOB_DAILY_DIGEST, $user_ids );
	}

	// ── Weekly digest ─────────────────────────────────────────────────────────

	/**
	 * Send weekly digest emails to users with email_freq = 'weekly'.
	 *
	 * Identical flow to handle_daily_digest() but spans 7 days and uses the
	 * bn.weekly_digest template. The digest_already_sent check looks for a
	 * bn_email_log row with type = 'bn.weekly_digest' and a digest_date within
	 * the current ISO week so multiple cron runs do not re-send. Self-chains via
	 * Action Scheduler the same way handle_daily_digest() does.
	 *
	 * @param int $after_id Keyset cursor for chained chunks (0 = first/recurring run).
	 * @return void
	 */
	public function handle_weekly_digest( int $after_id = 0 ): void {
		if ( $this->digests_disabled() ) {
			return;
		}

		$template = $this->get_email_template( 'bn.weekly_digest' );
		if ( null === $template || ! (bool) $template->enabled ) {
			return;
		}

		$user_ids = $this->get_digest_user_ids( 'weekly', $after_id );
		if ( empty( $user_ids ) ) {
			return;
		}

		foreach ( $user_ids as $user_id ) {
			if ( $this->digest_already_sent( $user_id, 'bn.weekly_digest' ) ) {
				continue;
			}

			$notifications = $this->fetch_unread_notifications( $user_id, 7 );
			if ( empty( $notifications ) ) {
				continue;
			}

			$sent = $this->send_digest_email( $user_id, $template, $notifications );
			if ( $sent ) {
				$this->log_digest( $user_id, 'bn.weekly_digest' );
			}
		}

		$this->chain_next_digest_chunk( CronScheduler::JOB_WEEKLY_DIGEST, $user_ids );
	}

	/**
	 * Whether digest emails are switched off site-wide.
	 *
	 * The Settings → Notifications → "Digest frequency" master switch
	 * (buddynext_digest_frequency). 'never' disables every digest run; any other
	 * value leaves digests on, with each user's own email_freq deciding whether
	 * they receive the daily or weekly digest.
	 *
	 * @return bool
	 */
	private function digests_disabled(): bool {
		return 'never' === (string) get_option( 'buddynext_digest_frequency', 'weekly' );
	}

	// ── Token cleanup ─────────────────────────────────────────────────────────

	/**
	 * Delete all expired rows from bn_verify_tokens.
	 *
	 * Runs daily. Tokens expire based on the expires_at column set when the
	 * token was created (24 hours by default for email verification).
	 *
	 * @return void
	 */
	public function handle_cleanup_tokens(): void {
		global $wpdb;

		// expires_at is written in UTC (VerificationService uses gmdate()), so the
		// cleanup must compare against UTC_TIMESTAMP(), not the server-local NOW()
		// — on a non-UTC MySQL server NOW() would delete tokens early or late.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_verify_tokens WHERE expires_at < UTC_TIMESTAMP()" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// ── Notification pruning ──────────────────────────────────────────────────

	/**
	 * Delete read notifications older than the configured data-retention window.
	 *
	 * Runs weekly. The window is the Privacy → "Data Retention Days" setting
	 * (buddynext_data_retention_days, default 365). A value of 0 (or less)
	 * disables pruning so read notifications are kept indefinitely. Only rows
	 * where is_read = 1 are removed; unread notifications are preserved
	 * regardless of age.
	 *
	 * @return void
	 */
	public function handle_cleanup_notifications(): void {
		$retention_days = (int) get_option( 'buddynext_data_retention_days', 365 );
		if ( $retention_days <= 0 ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}bn_notifications
				  WHERE is_read = 1
				    AND created_at < DATE_SUB( NOW(), INTERVAL %d DAY )",
				$retention_days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// ── Activity-log pruning ──────────────────────────────────────────────────

	/**
	 * Delete activity-log rows older than the configured data-retention window.
	 *
	 * Runs weekly. Driven by the Privacy → "Activity log retention (days)"
	 * setting (buddynext_data_retention_days, default 365); 0 (or less) disables
	 * pruning so the log is kept indefinitely. Deletes in batches of 1,000 with a
	 * per-run cap so a large bn_activity_log never locks the table or times the
	 * cron out — any remainder is cleared on the next weekly run.
	 *
	 * @return void
	 */
	public function handle_cleanup_activity_log(): void {
		$retention_days = (int) get_option( 'buddynext_data_retention_days', 365 );
		if ( $retention_days <= 0 ) {
			return;
		}

		global $wpdb;

		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$max_batches = 50; // up to 50k rows per weekly run.

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}bn_activity_log WHERE created_at < %s LIMIT 1000",
					$cutoff
				)
			);
			--$max_batches;
		} while ( $deleted > 0 && $max_batches > 0 );
	}

	/**
	 * Prune old bn_email_log rows (weekly).
	 *
	 * The bn_email_log table grows one row per email sent (digests + identity
	 * sends) and had no retention — the fastest-growing table at scale. Mirrors
	 * the activity-log
	 * prune: honours buddynext_data_retention_days (default 365; 0 disables),
	 * batched 1,000/iteration up to 50k/run, keyed on sent_at.
	 *
	 * @return void
	 */
	public function handle_cleanup_email_log(): void {
		$retention_days = (int) get_option( 'buddynext_data_retention_days', 365 );
		if ( $retention_days <= 0 ) {
			return;
		}

		global $wpdb;

		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$max_batches = 50; // up to 50k rows per weekly run.

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}bn_email_log WHERE sent_at < %s LIMIT 1000",
					$cutoff
				)
			);
			--$max_batches;
		} while ( $deleted > 0 && $max_batches > 0 );
	}

	// ── Stats recount ─────────────────────────────────────────────────────────

	/**
	 * Reconcile the denormalized engagement counters from actual data.
	 *
	 * Reconciles bn_posts (reaction/comment/share) via PostService::recount_counters
	 * and — added in S2(c) — bn_spaces.member_count + bn_hashtags post/follower
	 * counts via CounterService's set-based bulk recounts, so every hot per-event
	 * counter has the same nightly drift self-heal (previously member_count and
	 * the hashtag counters only reconciled via a manual admin button). Counters
	 * are maintained incrementally on every write, so this daily pass is a cheap
	 * drift-guarded safety net only.
	 *
	 * @return void
	 */
	public function handle_recount_stats(): void {
		buddynext_service( 'post_service' )->recount_counters();

		$counters = new CounterService();
		$counters->recount_all_space_members();
		$counters->recount_all_hashtag_counts();
		$counters->recount_all_follow_counts();
		$counters->recount_all_connection_counts();
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Page through the distinct user IDs whose email_freq preference is $freq.
	 *
	 * DISTINCT user_id collapses a member's multiple notification-pref rows to a
	 * single digest. Keyset-paginated by user_id (cursor $after_id) and capped at
	 * DIGEST_USER_CAP per chunk, so the handler chains through the whole base via
	 * Action Scheduler without any single unbounded run.
	 *
	 * @param string $freq     'daily' or 'weekly'.
	 * @param int    $after_id Keyset cursor — return only users with a greater user_id.
	 * @return int[]
	 */
	private function get_digest_user_ids( string $freq, int $after_id = 0 ): array {
		global $wpdb;

		// Keyset cursor on user_id (not a bare LIMIT) so successive runs page through
		// EVERY digest-frequency user. The old `LIMIT 200` with no cursor returned the
		// same first ~200 users every run and starved everyone past them; the caller
		// chains the next chunk via Action Scheduler keyed on the last user_id here.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id
				   FROM {$wpdb->prefix}bn_notification_prefs
				  WHERE email_freq = %s AND user_id > %d
				  ORDER BY user_id ASC
				  LIMIT %d",
				$freq,
				$after_id,
				self::DIGEST_USER_CAP
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $raw );
	}

	/**
	 * Chain the next digest chunk via Action Scheduler when this run filled the cap.
	 *
	 * A full chunk (== DIGEST_USER_CAP rows) means more digest-frequency users remain
	 * past the cursor, so we enqueue a one-off run of the SAME recurring hook keyed on
	 * the last user_id. The chain converges (the cursor only advances) and terminates
	 * the first time a chunk returns fewer than the cap, so a 50k-user base is fully
	 * processed in one cadence cycle while each run stays bounded.
	 *
	 * @param string $hook     The digest job hook (JOB_DAILY_DIGEST / JOB_WEEKLY_DIGEST).
	 * @param int[]  $user_ids The user IDs this run processed (ascending).
	 * @return void
	 */
	private function chain_next_digest_chunk( string $hook, array $user_ids ): void {
		if ( count( $user_ids ) < self::DIGEST_USER_CAP ) {
			return; // Last chunk (an empty chunk is also < cap) — all users paged through.
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return; // No Action Scheduler — degrade to the next recurring run.
		}
		as_enqueue_async_action( $hook, array( (int) max( $user_ids ) ), CronScheduler::GROUP );
	}

	/**
	 * Return unread notification rows for a user within the last $days days.
	 *
	 * @param int $user_id Recipient user ID.
	 * @param int $days    Look-back window in days.
	 * @return array<int, array{type: string, group_count: int}>
	 */
	private function fetch_unread_notifications( int $user_id, int $days ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, COALESCE( group_count, 1 ) AS group_count
				   FROM {$wpdb->prefix}bn_notifications
				  WHERE recipient_id = %d
				    AND is_read      = 0
				    AND created_at  >= DATE_SUB( NOW(), INTERVAL %d DAY )
				  ORDER BY created_at DESC",
				$user_id,
				$days
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $rows ) {
			return array();
		}

		return array_map(
			static fn( array $r ) => array(
				'type'        => (string) $r['type'],
				'group_count' => (int) $r['group_count'],
			),
			$rows
		);
	}

	/**
	 * Return true if a digest email of $type has already been sent to $user_id
	 * within the current day (daily) or current ISO week (weekly).
	 *
	 * @param int    $user_id Recipient user ID.
	 * @param string $type    Email type: 'bn.daily_digest' or 'bn.weekly_digest'.
	 * @return bool
	 */
	private function digest_already_sent( int $user_id, string $type ): bool {
		global $wpdb;

		$date_cond = ( 'bn.daily_digest' === $type )
			? 'digest_date = CURDATE()'
			: 'YEARWEEK( digest_date, 1 ) = YEARWEEK( CURDATE(), 1 )';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->prefix}bn_email_log
				  WHERE user_id = %d
				    AND type    = %s
				    AND {$date_cond}",
				$user_id,
				$type
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count > 0;
	}

	/**
	 * Fetch an email template row from bn_email_templates by type.
	 *
	 * Returns null when no row exists.
	 *
	 * @param string $type Email template type key.
	 * @return object|null DB row with subject, body_html, enabled fields.
	 */
	private function get_email_template( string $type ): ?object {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT subject, body_html, enabled
				   FROM {$wpdb->prefix}bn_email_templates
				  WHERE type = %s",
				$type
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return ( null !== $row ) ? $row : null;
	}

	/**
	 * Build an HTML list of notification summaries for digest email bodies.
	 *
	 * Converts the notification type slug to a human-readable label using a
	 * static map, then appends a count suffix when group_count > 1.
	 *
	 * @param array<int, array{type: string, group_count: int}> $notifications Notification rows.
	 * @return string HTML unordered list.
	 */
	private function build_notification_list_html( array $notifications ): string {
		$labels = array(
			'bn.new_follower'           => 'Someone followed you',
			'bn.connection_requested'   => 'New connection request',
			'bn.connection_accepted'    => 'A connection was accepted',
			'bn.mention'                => 'You were mentioned in a post',
			'bn.post_reacted'           => 'Someone reacted to your post',
			'bn.post_commented'         => 'New comment on your post',
			'bn.post_shared'            => 'Your post was shared',
			'bn.space_invite'           => 'You were invited to a space',
			'bn.space_join_requested'   => 'A member wants to join your space',
			'bn.space_request_approved' => 'Your space join request was approved',
			'bn.strike_issued'          => 'A moderation action was taken on your account',
			'bn.badge_awarded'          => 'You earned a new badge',
			'bn.level_up'               => 'You reached a new community level',
			'bn.jetonomy_reply'         => 'New reply to your discussion',
		);

		$items = '';
		foreach ( $notifications as $n ) {
			$label = $labels[ $n['type'] ] ?? ucwords( str_replace( array( 'bn.', '_' ), array( '', ' ' ), $n['type'] ) );
			if ( $n['group_count'] > 1 ) {
				$label .= sprintf( ' (%d)', $n['group_count'] );
			}
			$items .= '<li>' . esc_html( $label ) . '</li>';
		}

		return '<ul>' . $items . '</ul>';
	}

	/**
	 * Send a digest email to a user.
	 *
	 * Replaces standard placeholders {{site_name}}, {{site_url}},
	 * {{user_name}}, {{notification_list}}, and {{unsubscribe_url}} in the
	 * template subject and body_html before dispatching via wp_mail().
	 *
	 * @param int                                               $user_id       Recipient user ID.
	 * @param object                                            $template      Template row with subject, body_html.
	 * @param array<int, array{type: string, group_count: int}> $notifications Notification rows for list.
	 * @return bool True when wp_mail() reports success.
	 */
	private function send_digest_email( int $user_id, object $template, array $notifications ): bool {
		$user = get_userdata( $user_id );
		if ( false === $user || '' === $user->user_email ) {
			return false;
		}

		$notif_list = $this->build_notification_list_html( $notifications );
		$unsub_sig  = hash_hmac( 'sha256', "{$user_id}:digest", wp_salt( 'auth' ) );
		$unsub_url  = add_query_arg(
			array(
				'bn_unsub' => 1,
				'uid'      => $user_id,
				'type'     => 'digest',
				'sig'      => $unsub_sig,
			),
			home_url( '/' )
		);

		$tokens = array(
			'{{site_name}}'         => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{{site_url}}'          => esc_url( home_url( '/' ) ),
			'{{user_name}}'         => $user->display_name,
			'{{notification_list}}' => $notif_list,
			'{{unsubscribe_url}}'   => esc_url( $unsub_url ),
		);

		$subject = str_replace( array_keys( $tokens ), array_values( $tokens ), (string) $template->subject );
		$body    = str_replace( array_keys( $tokens ), array_values( $tokens ), (string) $template->body_html );

		// Route through EmailSender's shared identity helper so the digest
		// carries the same From name/address + Reply-To as every other
		// BuddyNext email (Settings → Email), instead of wp_mail()'s defaults.
		return EmailSender::send_with_identity(
			$user->user_email,
			$subject,
			EmailSender::brand_wrap( $body, $subject ),
			EmailSender::build_identity_headers()
		);
	}

	/**
	 * Write a digest send record to bn_email_log.
	 *
	 * Uses today's date for digest_date so the daily/weekly duplicate-send
	 * guard can find the record on subsequent cron runs.
	 *
	 * @param int    $user_id Recipient user ID.
	 * @param string $type    Email template type key.
	 * @return void
	 */
	private function log_digest( int $user_id, string $type ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_email_log',
			array(
				'user_id'     => $user_id,
				'type'        => $type,
				'digest_date' => gmdate( 'Y-m-d' ),
			),
			array( '%d', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
