<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Session tracker.
 *
 * Emits two canonical engagement events that gamification plugins
 * (wb-gamification or any equivalent) use as the streak driver and
 * presence signal:
 *
 *   buddynext_user_session_started( int $user_id )
 *     Fires at most once per sliding 30-minute window per user — the
 *     "user is active right now" signal. Idempotent across page-loads
 *     within the window so navigating doesn't generate spam events.
 *
 *   buddynext_user_daily_login( int $user_id, string $date_ymd )
 *     Fires at most once per UTC calendar day per user. The streak
 *     driver — gamification increments daily-streak counters from
 *     this signal alone, never from inferred activity. UTC-keyed so
 *     two clients in different timezones can't double-fire.
 *
 * Guard transients live in object cache when one is configured and
 * the options table otherwise. Both keys are O(1) and the entire
 * tracker bails immediately for guest viewers, so the per-request
 * overhead on logged-out traffic is one is_user_logged_in() check.
 *
 * @package BuddyNext\Engagement
 */

declare( strict_types=1 );

namespace BuddyNext\Engagement;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Fires per-session and per-day engagement events for logged-in users.
 */
class SessionTracker implements ListenerInterface {

	/**
	 * Sliding window for the session pulse, in seconds.
	 *
	 * 30 minutes mirrors the standard "session" definition used by
	 * analytics tools (GA, Plausible, Matomo) — a user is in the same
	 * session as long as page-views are < 30 min apart.
	 */
	private const SESSION_WINDOW = 30 * MINUTE_IN_SECONDS;

	/**
	 * TTL for the per-day guard, in seconds.
	 *
	 * 25 hours — slightly wider than a calendar day so timezone DST
	 * transitions or slightly-out-of-sync app servers can't briefly
	 * "un-guard" a user that already fired today.
	 */
	private const DAILY_TTL = 25 * HOUR_IN_SECONDS;

	/**
	 * Wire the wp_loaded listener.
	 *
	 * Fires on `wp_loaded:5` — after WP core is fully initialized but
	 * before `template_redirect`, so downstream gamification listeners
	 * (which hook on the events fired here) can react in the same
	 * request, and get_current_user_id() is reliable.
	 */
	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'maybe_emit_session_events' ), 5 );
	}

	/**
	 * Fire session_started + daily_login when their transient guards permit.
	 *
	 * Bails immediately for guests, AJAX/REST sub-requests (those
	 * arrive after the parent page already pulsed), and WP-CLI / cron
	 * contexts (no user "session" semantics there).
	 */
	public function maybe_emit_session_events(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		// Skip sub-requests — the parent page-load already pulsed.
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Sliding 30-min session pulse.
		$session_key = 'bn_session_' . $user_id;
		if ( false === get_transient( $session_key ) ) {
			set_transient( $session_key, 1, self::SESSION_WINDOW );

			/**
			 * Fires once per sliding 30-min window per user.
			 *
			 * "User is currently active" signal — useful as a presence
			 * proxy when Pro's WebSocket presence service isn't wired,
			 * and for gamification rules that award on engagement-bursts
			 * rather than calendar days.
			 *
			 * @param int $user_id Active user.
			 */
			do_action( 'buddynext_user_session_started', $user_id );
		}

		// Daily login pulse — UTC-keyed.
		$today     = gmdate( 'Y-m-d' );
		$daily_key = 'bn_daily_login_' . $user_id . '_' . $today;
		if ( false === get_transient( $daily_key ) ) {
			set_transient( $daily_key, 1, self::DAILY_TTL );

			/**
			 * Fires once per UTC calendar day per user.
			 *
			 * **Streak driver.** Gamification plugins increment the
			 * daily-streak counter from this event alone — they should
			 * not infer streaks from activity (posts, comments, etc.)
			 * because activity is sparse and prevents passive lurkers
			 * from earning streak badges they have legitimately earned
			 * by visiting.
			 *
			 * @param int    $user_id  Returning user.
			 * @param string $date_ymd UTC date in Y-m-d format (e.g. "2026-05-25").
			 */
			do_action( 'buddynext_user_daily_login', $user_id, $today );
		}
	}
}
