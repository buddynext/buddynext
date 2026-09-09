<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * One-time activation of the baked-in preset licence key against the store.
 *
 * Update downloads are authorised by a preset key that must be activated once
 * per site. The activation is a remote POST to wbcomdesigns.com, so it must
 * never run on the owner's admin page load: the original code fired a blocking
 * wp_remote_post( timeout: 15 ) on EVERY admin_init until it succeeded, adding
 * up to 15s to every wp-admin page forever when the store was unreachable.
 *
 * This class moves the call into a background single event, bounds the retries
 * so a firewalled host stops trying after a day, and — when it does give up —
 * shows the owner an admin notice explaining why with a Retry button, instead of
 * failing silently forever. It deliberately writes NO tracking-consent option:
 * usage tracking is the EDD SDK's own opt-in (default off, toggled by the owner
 * on the licence screen). Forcing it on here was consent the owner never gave.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Background preset-key activation with a bounded retry and an owner notice.
 */
class PresetActivation {

	/** Cron hook that runs the remote activation call. */
	public const HOOK = 'buddynext_activate_preset_key';

	/** Set to 1 once the store confirms activation. */
	public const OPT_ACTIVATED = 'buddynext_preset_activated';

	/** Running count of failed attempts (cleared on success). */
	public const OPT_ATTEMPTS = 'buddynext_preset_activation_attempts';

	/** UTC timestamp of the last failed attempt, set once we give up. */
	public const OPT_GAVE_UP = 'buddynext_preset_activation_gave_up';

	/** The admin-post action for the owner's manual retry. */
	public const RETRY_ACTION = 'buddynext_retry_preset_activation';

	/**
	 * One-shot transient carrying a manual retry's outcome ('ok'|'fail') across the
	 * post-retry redirect, so the notice reports success/failure. Without it a retry
	 * on a cron-ENABLED firewalled host was silent: run() reschedules (attempts<24)
	 * WITHOUT setting OPT_GAVE_UP, so the gave_up-gated notice vanished and the owner
	 * could not tell the retry failed (card 10264291915 RFT round 4).
	 */
	private const RETRY_RESULT_TRANSIENT = 'buddynext_preset_retry_result';

	/** Give up after this many failures (~a day at hourly backoff). */
	private const MAX_ATTEMPTS = 24;

	/** Remote timeout, seconds. Short: this is a fire-and-forget authorisation. */
	private const TIMEOUT = 5;

	/** The baked-in Free preset key. */
	private const PRESET_KEY = 'buddynext9a3c7e1d5f2b8a4c6e0d9b7f1a2c8e55';

	/** EDD item id for BuddyNext Free. */
	private const ITEM_ID = 1664401;

	/**
	 * Wire the schedule, the background runner, the failure notice and the retry.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_schedule' ) );
		// Wrap run() so the cron callback returns nothing — run() now returns bool
		// (consumed by the manual retry), and an action callback must not return.
		add_action(
			self::HOOK,
			static function (): void {
				self::run();
			}
		);
		add_action( 'admin_notices', array( self::class, 'maybe_render_notice' ) );
		add_action( 'admin_post_' . self::RETRY_ACTION, array( self::class, 'handle_retry' ) );
	}

	/**
	 * Schedule the background attempt from an admin page — but never do the work
	 * here. Returns early once activated, AND once we have given up, so a
	 * firewalled host does not re-arm a fresh event on every admin page load (the
	 * bug the bounded-retry was supposed to fix but did not: admin_init used to
	 * check only the activated flag, so giving up in the runner was undone by the
	 * very next page view). After giving up, only an explicit Retry re-arms.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( get_option( self::OPT_ACTIVATED ) || get_option( self::OPT_GAVE_UP ) ) {
			return;
		}

		// DISABLE_WP_CRON with no system cron is the card's own silent-failure case: a
		// scheduled single event would never fire, so run() would never execute,
		// OPT_GAVE_UP would never be written, and the owner would see nothing forever.
		// Drive the attempt INLINE from this admin request instead. run() sets
		// OPT_GAVE_UP on failure under disabled cron (see run()), so the next
		// admin_init early-returns above and this runs at most once — and the give-up
		// notice (admin_notices, later this same request) surfaces immediately
		// (card 10264291915).
		if ( self::cron_is_disabled() ) {
			self::run();
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}

	/**
	 * Whether WP-Cron is disabled, so a scheduled event cannot be relied on to fire.
	 *
	 * @return bool
	 */
	private static function cron_is_disabled(): bool {
		/**
		 * Whether WP-Cron cannot be relied on to fire a scheduled event.
		 *
		 * Defaults to the DISABLE_WP_CRON constant. A site that sets that constant but
		 * DOES run a real system cron can return false to keep the scheduled-event
		 * path (and its bounded hourly retry) instead of the inline give-up.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $disabled True when a scheduled event cannot be relied on.
		 */
		return (bool) apply_filters( 'buddynext_wp_cron_disabled', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * Perform the remote activation in the cron request. On success, mark
	 * activated and clear the retry state. On failure, increment the bounded
	 * counter and reschedule hourly until the ceiling, then stop and record that
	 * we gave up so the notice can surface it.
	 *
	 * @return bool True when the licence is (or is now) activated; false on a
	 *              failed attempt. Used by the owner's manual retry to report the
	 *              outcome; the cron/action callers ignore it.
	 */
	public static function run(): bool {
		if ( get_option( self::OPT_ACTIVATED ) ) {
			return true;
		}

		update_option( 'buddynext_license_key', self::PRESET_KEY, false );

		$response = wp_remote_post(
			'https://wbcomdesigns.com',
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'edd_action' => 'activate_license',
					'license'    => self::PRESET_KEY,
					'item_id'    => self::ITEM_ID,
					'url'        => home_url(),
				),
			)
		);

		$ok = false;
		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$ok   = 'valid' === ( $body['license'] ?? '' );
		}

		if ( $ok ) {
			update_option( self::OPT_ACTIVATED, 1, false );
			delete_option( self::OPT_ATTEMPTS );
			delete_option( self::OPT_GAVE_UP );
			return true;
		}

		$attempts = (int) get_option( self::OPT_ATTEMPTS, 0 ) + 1;
		update_option( self::OPT_ATTEMPTS, $attempts, false );

		// On a DISABLE_WP_CRON host there is no reliable auto-retry — a rescheduled
		// event would never fire — so a single failure gives up NOW rather than
		// pretending 24 hourly retries will happen. The owner sees the actionable
		// notice on this same admin load and can Retry manually (card 10264291915).
		if ( ! self::cron_is_disabled() && $attempts < self::MAX_ATTEMPTS ) {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK );
			}
			return false;
		}

		// Ceiling reached (or no cron to retry with): stop, and record when so the
		// owner notice can explain. maybe_schedule() now sees OPT_GAVE_UP and will not
		// silently re-arm.
		update_option( self::OPT_GAVE_UP, time(), false );
		return false;
	}

	/**
	 * Show the owner an actionable notice when activation has given up, so a
	 * permanent silent failure (firewalled host, DISABLE_WP_CRON with no system
	 * cron) is visible and recoverable instead of leaving the licence screen
	 * unexplained.
	 *
	 * @return void
	 */
	public static function maybe_render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// A just-completed manual retry stamps its outcome; consume it once.
		$retry_result = get_transient( self::RETRY_RESULT_TRANSIENT );
		if ( false !== $retry_result ) {
			delete_transient( self::RETRY_RESULT_TRANSIENT );
		}

		// Report a successful retry so the owner is not left guessing whether their
		// click worked (card 10264291915 RFT round 4).
		if ( 'ok' === $retry_result ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'BuddyNext is now authorised to download plugin updates.', 'buddynext' )
			);
			return;
		}

		if ( get_option( self::OPT_ACTIVATED ) ) {
			return;
		}

		$gave_up      = (int) get_option( self::OPT_GAVE_UP, 0 );
		$retry_failed = ( 'fail' === $retry_result );

		// Show the actionable notice when activation has given up OR a manual retry
		// just failed. The second case is the round-4 fix: on a cron-ENABLED
		// firewalled host a failed run() reschedules WITHOUT setting OPT_GAVE_UP, so
		// the gave_up-gated notice would vanish and the owner could not tell the
		// retry failed.
		if ( $gave_up <= 0 && ! $retry_failed ) {
			return;
		}

		$when = $gave_up > 0
			? sprintf(
				/* translators: %s: human-readable time difference, e.g. "2 hours". */
				__( 'last tried %s ago', 'buddynext' ),
				human_time_diff( $gave_up, time() )
			)
			: __( 'the retry just failed', 'buddynext' );
		$retry_url = wp_nonce_url(
			add_query_arg( 'action', self::RETRY_ACTION, admin_url( 'admin-post.php' ) ),
			self::RETRY_ACTION
		);

		printf(
			'<div class="notice notice-warning"><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: "last tried X ago". */
					__( 'BuddyNext could not reach wbcomdesigns.com to authorise plugin updates (%s). Updates will not download until this succeeds. If this host blocks outgoing connections, allow requests to wbcomdesigns.com, then retry.', 'buddynext' ),
					$when
				)
			),
			esc_url( $retry_url ),
			esc_html__( 'Retry activation now', 'buddynext' )
		);
	}

	/**
	 * Owner-triggered retry: clear the give-up + attempt state and run the
	 * activation immediately (in this request is fine — it is the owner's own
	 * click, and the 5s timeout bounds it), then redirect back.
	 *
	 * @return void
	 */
	public static function handle_retry(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'buddynext' ), 403 );
		}
		check_admin_referer( self::RETRY_ACTION );

		delete_option( self::OPT_GAVE_UP );
		delete_option( self::OPT_ATTEMPTS );
		$ok = self::run();

		// Record the outcome across the redirect so maybe_render_notice() can report
		// it — a failed retry on a cron-enabled host reschedules without OPT_GAVE_UP,
		// so the outcome would otherwise be invisible (card 10264291915 RFT round 4).
		set_transient( self::RETRY_RESULT_TRANSIENT, $ok ? 'ok' : 'fail', MINUTE_IN_SECONDS );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
