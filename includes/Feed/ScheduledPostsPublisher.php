<?php
/**
 * Scheduled-post publisher (Free).
 *
 * Owns publishing of posts created with a future `scheduled_at` (status
 * 'scheduled' in bn_posts). Scheduled posting is a FREE feature, reachable on a
 * standalone install via the REST create endpoint (the `scheduled_at` param),
 * PostService, and the profile "Scheduled" tab — so the publisher MUST live in
 * Free and run with Pro absent. Pro's ScheduledPostsService delegates its
 * writes to Free and reuses this publisher; it does not run its own.
 *
 * Minimal-cron design: instead of a perpetual poll, a single WP-Cron event is
 * armed at the exact moment the next due post is scheduled for. After each pass
 * the publisher re-arms for the next pending post (or stays disarmed when none
 * remain). Arming is driven by the write path
 * (PostService::create / set_schedule / clear_schedule), so the cron is dormant
 * whenever there are no scheduled posts.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

/**
 * Publishes due scheduled posts on demand.
 */
final class ScheduledPostsPublisher {

	/**
	 * Single-event hook fired when the earliest scheduled post comes due.
	 */
	public const HOOK = 'buddynext_publish_scheduled';

	/**
	 * Recurring hourly safety-net hook. The single-event arm() is precise but
	 * fragile — a dropped event (DB restore, cleared hook) would strand posts
	 * forever. This hourly sweep re-runs publish_due so overdue rows always go out.
	 */
	public const SWEEP_HOOK = 'buddynext_publish_scheduled_sweep';

	/**
	 * Max posts published per pass (keeps a burst of due posts bounded).
	 */
	private const BATCH = 100;

	/**
	 * Option holding per-post publish-attempt counts, so a row that repeatedly
	 * fails to publish is capped instead of retried forever. Keyed by post id.
	 */
	private const ATTEMPTS_OPTION = 'buddynext_sched_publish_attempts';

	/**
	 * Give up (and mark the row failed) after this many failed publish attempts.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Wire the publish worker to its hooks and ensure the hourly safety-net sweep
	 * is scheduled. Called once from Plugin::init().
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'publish_due' ) );
		add_action( self::SWEEP_HOOK, array( self::class, 'publish_due' ) );

		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::SWEEP_HOOK );
		}
	}

	/**
	 * Arm a single cron event at the earliest pending scheduled_at.
	 *
	 * Idempotent: clears any existing arm and reschedules to the current
	 * earliest due time. Stays disarmed when no scheduled posts remain. Safe to
	 * call from every write path that creates, reschedules, or cancels a
	 * scheduled post.
	 *
	 * @return void
	 */
	public static function arm(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$next = $wpdb->get_var(
			"SELECT scheduled_at FROM {$wpdb->prefix}bn_posts
			 WHERE status = 'scheduled' AND scheduled_at IS NOT NULL
			 ORDER BY scheduled_at ASC LIMIT 1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Drop any existing arm so duplicate events never stack.
		wp_clear_scheduled_hook( self::HOOK );

		if ( null === $next ) {
			return; // No scheduled posts — stay disarmed.
		}

		// scheduled_at is stored in UTC; strtotime() treats a bare datetime as UTC.
		$timestamp = strtotime( (string) $next . ' UTC' );
		if ( false === $timestamp ) {
			return;
		}

		// An already-overdue post runs on the next cron sweep.
		$timestamp = max( $timestamp, time() );

		wp_schedule_single_event( $timestamp, self::HOOK );
	}

	/**
	 * Publish every scheduled post whose time has passed, then re-arm.
	 *
	 * Each due row is set to status='published' (via Free's PostService so the
	 * per-post cache is busted and the publish timestamp is bumped) and
	 * `buddynext_post_created` is re-fired so the notification, hashtag-index,
	 * and analytics listeners run as a fresh publish. Re-arms for the next
	 * pending post when the pass completes.
	 *
	 * @return int Number of posts published.
	 */
	public static function publish_due(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$due = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, type FROM {$wpdb->prefix}bn_posts
				 WHERE status = 'scheduled'
				   AND scheduled_at IS NOT NULL
				   AND scheduled_at <= UTC_TIMESTAMP()
				 ORDER BY scheduled_at ASC
				 LIMIT %d",
				self::BATCH
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$published    = 0;
		$post_service = buddynext_service( 'post_service' );

		foreach ( (array) $due as $row ) {
			$post_id = (int) $row['id'];
			$user_id = (int) $row['user_id'];
			$type    = (string) $row['type'];

			if ( $post_service->mark_published( $post_id ) ) {
				++$published;
				self::clear_attempts( $post_id );

				// Run the SAME at-go-live effects the immediate publish path runs —
				// buddynext_post_created fan-out (search / hashtags / webhooks / space
				// notifications), @mention notifications, and auto-moderation. This
				// fires buddynext_post_created itself, so we no longer fire it here (a
				// scheduled @mention was previously never delivered and auto-mod never
				// evaluated at go-live).
				$post_service->run_published_effects( $post_id );
			} else {
				// mark_published() failed. Previously ignored, so the row stayed
				// scheduled forever with no error surfaced. Count the attempt and,
				// after MAX_ATTEMPTS, mark the row failed + fire an action so admins
				// can surface it — instead of retrying every sweep indefinitely.
				self::record_failure( $post_id );
			}
		}

		// Re-arm for the next pending post (or disarm when none remain). When
		// more than BATCH posts were due, the earliest remaining is now overdue,
		// so arm() schedules an immediate follow-up pass.
		self::arm();

		return $published;
	}

	/**
	 * Record a failed publish attempt for a post. After MAX_ATTEMPTS the row is
	 * returned to 'draft' with its schedule cleared — so it leaves the scheduled
	 * queue instead of being retried every sweep forever — and
	 * buddynext_scheduled_publish_failed fires so the failure can be surfaced.
	 *
	 * ('draft' is used rather than a dedicated 'failed' status because the status
	 * ENUM has no 'failed' value and adding one needs a schema migration, which is
	 * disallowed pre-release. The fired action carries the failure for surfacing.)
	 *
	 * @param int $post_id Post whose publish attempt failed.
	 * @return void
	 */
	private static function record_failure( int $post_id ): void {
		$attempts = (array) get_option( self::ATTEMPTS_OPTION, array() );
		$count    = (int) ( $attempts[ $post_id ] ?? 0 ) + 1;

		if ( $count >= self::MAX_ATTEMPTS ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_posts',
				array(
					'status'       => 'draft',
					'scheduled_at' => null,
				),
				array( 'id' => $post_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_cache_delete( "post_{$post_id}", 'buddynext_posts' );
			unset( $attempts[ $post_id ] );
			update_option( self::ATTEMPTS_OPTION, $attempts, false );

			/**
			 * Fires when a scheduled post is abandoned after repeated publish
			 * failures (returned to draft). Admin-notice / logging consumers can
			 * hook it to surface the failure to the owner.
			 *
			 * @param int $post_id  Post that could not be published.
			 * @param int $attempts Number of attempts made.
			 */
			do_action( 'buddynext_scheduled_publish_failed', $post_id, $count );
			return;
		}

		$attempts[ $post_id ] = $count;
		update_option( self::ATTEMPTS_OPTION, $attempts, false );
	}

	/**
	 * Clear the failed-attempt counter for a post that published successfully.
	 *
	 * @param int $post_id Post that published.
	 * @return void
	 */
	private static function clear_attempts( int $post_id ): void {
		$attempts = (array) get_option( self::ATTEMPTS_OPTION, array() );
		if ( isset( $attempts[ $post_id ] ) ) {
			unset( $attempts[ $post_id ] );
			update_option( self::ATTEMPTS_OPTION, $attempts, false );
		}
	}
}
