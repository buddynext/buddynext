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
 *   handle_trending_hashtags     — refresh bn_hashtags.post_count from bn_post_hashtags
 *   handle_recount_stats         — correct reaction_count + comment_count on bn_posts
 *   handle_publish_scheduled     — publish due scheduled posts
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Implements all BuddyNext WP-Cron job callbacks.
 */
class CronService {

	/**
	 * Maximum users processed per digest run to avoid PHP timeout.
	 */
	private const DIGEST_USER_CAP = 200;

	/**
	 * Maximum scheduled posts published per run.
	 */
	private const PUBLISH_BATCH = 100;

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
	 * @return void
	 */
	public function handle_daily_digest(): void {
		if ( $this->digests_disabled() ) {
			return;
		}

		$template = $this->get_email_template( 'bn.daily_digest' );
		if ( null === $template || ! (bool) $template->enabled ) {
			return;
		}

		$user_ids = $this->get_digest_user_ids( 'daily' );
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
	}

	// ── Weekly digest ─────────────────────────────────────────────────────────

	/**
	 * Send weekly digest emails to users with email_freq = 'weekly'.
	 *
	 * Identical flow to handle_daily_digest() but spans 7 days and uses the
	 * bn.weekly_digest template. The digest_already_sent check looks for a
	 * bn_email_log row with type = 'bn.weekly_digest' and a digest_date within
	 * the current ISO week so multiple cron runs do not re-send.
	 *
	 * @return void
	 */
	public function handle_weekly_digest(): void {
		if ( $this->digests_disabled() ) {
			return;
		}

		$template = $this->get_email_template( 'bn.weekly_digest' );
		if ( null === $template || ! (bool) $template->enabled ) {
			return;
		}

		$user_ids = $this->get_digest_user_ids( 'weekly' );
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_verify_tokens WHERE expires_at < NOW()" );
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

	// ── Trending hashtags ─────────────────────────────────────────────────────

	/**
	 * Refresh post_count on every bn_hashtags row from actual bn_post_hashtags data.
	 *
	 * Uses an UPDATE … INNER JOIN aggregate to recount in a single statement.
	 * Hashtags with zero posts are set to 0 via a separate UPDATE for rows
	 * that have no matching bn_post_hashtags entry.
	 *
	 * Runs every 30 minutes.
	 *
	 * @return void
	 */
	public function handle_trending_hashtags(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_hashtags h
			 INNER JOIN (
			     SELECT hashtag_id, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_post_hashtags
			      GROUP BY hashtag_id
			 ) c ON c.hashtag_id = h.id
			 SET h.post_count = c.cnt"
		);

		// Zero out tags that have no posts (left-join approach).
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_hashtags h
			  LEFT JOIN (
			      SELECT hashtag_id
			        FROM {$wpdb->prefix}bn_post_hashtags
			       GROUP BY hashtag_id
			  ) c ON c.hashtag_id = h.id
			   SET h.post_count = 0
			 WHERE c.hashtag_id IS NULL"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── Stats recount ─────────────────────────────────────────────────────────

	/**
	 * Correct reaction_count and comment_count on bn_posts from actual data.
	 *
	 * Uses UPDATE … INNER JOIN aggregates to fix any counter drift caused by
	 * failed decrements (e.g. from force-deleted comments or reactions).
	 * Runs every 5 minutes.
	 *
	 * @return void
	 */
	public function handle_recount_stats(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// bn_reactions uses object_type + object_id (not post_id).
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_posts p
			 INNER JOIN (
			     SELECT object_id, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_reactions
			      WHERE object_type = 'post'
			      GROUP BY object_id
			 ) r ON r.object_id = p.id
			 SET p.reaction_count = r.cnt"
		);

		// bn_comments uses object_type + object_id (not post_id).
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_posts p
			 INNER JOIN (
			     SELECT object_id, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_comments
			      WHERE is_deleted = 0
			        AND object_type = 'post'
			      GROUP BY object_id
			 ) c ON c.object_id = p.id
			 SET p.comment_count = c.cnt"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── Scheduled post publisher ──────────────────────────────────────────────

	/**
	 * Publish all bn_posts rows with status = 'scheduled' whose scheduled_at
	 * is in the past.
	 *
	 * Processes up to PUBLISH_BATCH posts per run. For each published post,
	 * fires the buddynext_post_created action so downstream listeners
	 * (search indexing, notifications, webhooks) are triggered.
	 *
	 * Runs every minute via the buddynext_1min WP-Cron schedule.
	 *
	 * @return void
	 */
	public function handle_publish_scheduled(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$due_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, type
				   FROM {$wpdb->prefix}bn_posts
				  WHERE status = 'scheduled'
				    AND scheduled_at <= NOW()
				  LIMIT %d",
				self::PUBLISH_BATCH
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $due_posts ) ) {
			return;
		}

		foreach ( $due_posts as $post ) {
			$post_id = (int) $post['id'];
			$user_id = (int) $post['user_id'];
			$type    = (string) $post['type'];

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$wpdb->prefix . 'bn_posts',
				array(
					'status'     => 'published',
					'created_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $post_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( false !== $updated && $updated > 0 ) {
				/**
				 * Fires after a scheduled post is published by the cron handler.
				 *
				 * @since 1.0.0
				 *
				 * @param int    $post_id Post ID.
				 * @param int    $user_id Author user ID.
				 * @param string $type    Post type slug.
				 */
				do_action( 'buddynext_post_created', $post_id, $user_id, $type );
			}
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Return distinct user IDs that have a global email_freq preference of $freq.
	 *
	 * Only returns a global preference row (type = 'global') to avoid sending
	 * separate digests for every individual notification type. Capped at
	 * DIGEST_USER_CAP to prevent PHP timeout on large sites.
	 *
	 * @param string $freq 'daily' or 'weekly'.
	 * @return int[]
	 */
	private function get_digest_user_ids( string $freq ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id
				   FROM {$wpdb->prefix}bn_notification_prefs
				  WHERE email_freq = %s
				  LIMIT %d",
				$freq,
				self::DIGEST_USER_CAP
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $raw );
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

		return wp_mail(
			$user->user_email,
			$subject,
			'<html><body>' . $body . '</body></html>',
			array( 'Content-Type: text/html; charset=UTF-8' )
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
