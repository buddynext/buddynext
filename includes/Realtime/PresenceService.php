<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Online-presence heartbeat writer.
 *
 * The presence readers — BlockService::is_user_online() and the member
 * directory `online`/`most_active` query — both resolve presence from the
 * `bn_last_active` user_meta timestamp. Nothing in Free wrote that value, so
 * every member resolved to offline and the "Online now" directory filter,
 * online sort, member-card dots, and OnlineMembersWidget were all permanently
 * empty.
 *
 * This service is the missing producer. It stamps `bn_last_active` for the
 * logged-in user on front-end activity, throttled via a short transient so a
 * page-heavy session does not write on every request. It is the Free polling
 * equivalent of Pro's WebSocket heartbeat — same meta key, same 300s window
 * used by the reader, so Pro can later swap the producer without touching the
 * readers.
 *
 * @package BuddyNext\Realtime
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Realtime;

/**
 * Writes the `bn_last_active` presence timestamp on authenticated activity.
 *
 * @since 1.0.0
 */
class PresenceService {

	/**
	 * User_meta key the presence readers consume.
	 *
	 * Matches BlockService::is_user_online() and
	 * MemberDirectoryService's online/most_active JOIN.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const META_KEY = 'bn_last_active';

	/**
	 * Minimum seconds between two stamps for the same user.
	 *
	 * A transient guard keyed per user prevents a write on every page load.
	 * Kept well under the reader's 300s online window so presence never goes
	 * stale for an active user.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const THROTTLE_SECONDS = 60;

	/**
	 * Attach the front-end heartbeat hook.
	 *
	 * Called once at boot. The stamp runs on template_redirect — every
	 * front-end page view by a logged-in user — so presence works with no
	 * JavaScript at all. The REST heartbeat endpoint (RealtimeController)
	 * provides a finer-grained, JS-driven top-up on top of this baseline.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'heartbeat' ) );
	}

	/**
	 * Heartbeat handler for front-end page views.
	 *
	 * Bails on admin / REST / cron / AJAX contexts and for anonymous users;
	 * those are handled elsewhere (REST) or are not real presence signals.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function heartbeat(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$this->stamp( get_current_user_id() );
	}

	/**
	 * Stamp `bn_last_active` for a user, throttled.
	 *
	 * Safe to call from any authenticated context (template_redirect or the
	 * REST heartbeat). The transient guard collapses repeated calls within the
	 * throttle window to a single write. Returns true when a write happened,
	 * false when throttled or the user id is invalid — never throws, so an
	 * absent object cache or a guarded call site degrades gracefully.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User to stamp. Must be a real, positive user id.
	 * @return bool True when the timestamp was written, false otherwise.
	 */
	public function stamp( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$guard_key = 'bn_presence_' . $user_id;

		// Already stamped within the throttle window — nothing to do.
		if ( false !== get_transient( $guard_key ) ) {
			return false;
		}

		update_user_meta( $user_id, self::META_KEY, time() );
		set_transient( $guard_key, 1, self::THROTTLE_SECONDS );

		/**
		 * Fires after a user's presence timestamp is refreshed.
		 *
		 * Pro's WebSocket presence layer can listen here to broadcast a
		 * real-time presence event in addition to the polled meta write.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id User whose presence was just stamped.
		 */
		do_action( 'buddynext_presence_stamped', $user_id );

		return true;
	}
}
