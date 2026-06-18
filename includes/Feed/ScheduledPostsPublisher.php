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
	 * Max posts published per pass (keeps a burst of due posts bounded).
	 */
	private const BATCH = 100;

	/**
	 * Wire the publish worker to its hook. Called once from Plugin::init().
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'publish_due' ) );
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

				/**
				 * Fires after a scheduled post is published.
				 *
				 * @param int    $post_id Post ID.
				 * @param int    $user_id Author user ID.
				 * @param string $type    Post type slug.
				 */
				do_action( 'buddynext_post_created', $post_id, $user_id, $type );
			}
		}

		// Re-arm for the next pending post (or disarm when none remain). When
		// more than BATCH posts were due, the earliest remaining is now overdue,
		// so arm() schedules an immediate follow-up pass.
		self::arm();

		return $published;
	}
}
