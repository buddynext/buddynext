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
		add_action( self::HOOK, array( self::class, 'run' ) );
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
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}

	/**
	 * Perform the remote activation in the cron request. On success, mark
	 * activated and clear the retry state. On failure, increment the bounded
	 * counter and reschedule hourly until the ceiling, then stop and record that
	 * we gave up so the notice can surface it.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( get_option( self::OPT_ACTIVATED ) ) {
			return;
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
			return;
		}

		$attempts = (int) get_option( self::OPT_ATTEMPTS, 0 ) + 1;
		update_option( self::OPT_ATTEMPTS, $attempts, false );

		if ( $attempts < self::MAX_ATTEMPTS ) {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK );
			}
			return;
		}

		// Ceiling reached: stop, and record when so the owner notice can explain.
		// maybe_schedule() now sees OPT_GAVE_UP and will not silently re-arm.
		update_option( self::OPT_GAVE_UP, time(), false );
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
		$gave_up = (int) get_option( self::OPT_GAVE_UP, 0 );
		if ( get_option( self::OPT_ACTIVATED ) || $gave_up <= 0 ) {
			return;
		}

		$when = sprintf(
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			__( 'last tried %s ago', 'buddynext' ),
			human_time_diff( $gave_up, time() )
		);
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
		self::run();

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
