<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Email dispatch listener for BuddyNext notifications.
 *
 * Listens for the buddynext_notification_created action and dispatches emails
 * via EmailSender. Also handles front-end unsubscribe requests by verifying
 * the HMAC signature and updating the user's preference to 'off'.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Wires email sending into the notification lifecycle.
 */
class EmailDispatchListener implements ListenerInterface {

	/**
	 * Email sender service.
	 *
	 * @var EmailSender
	 */
	private EmailSender $sender;

	/**
	 * Notification preference service.
	 *
	 * @var NotificationPrefService
	 */
	private NotificationPrefService $pref_service;

	/**
	 * Constructor.
	 *
	 * @param EmailSender             $sender       Email sender service.
	 * @param NotificationPrefService $pref_service Notification preference service.
	 */
	public function __construct( EmailSender $sender, NotificationPrefService $pref_service ) {
		$this->sender       = $sender;
		$this->pref_service = $pref_service;
	}

	/**
	 * Register action hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'buddynext_notification_created', array( $this, 'on_notification_created' ), 10, 3 );
		add_action( 'buddynext_send_notification_email', array( $this, 'on_send_notification_email' ), 10, 3 );
		add_action( 'init', array( $this, 'handle_unsubscribe_request' ) );
		add_action( 'wp_footer', array( $this, 'render_unsubscribe_status_notice' ) );
	}

	/**
	 * Handle a newly created notification by dispatching an email.
	 *
	 * @param int   $notif_id     Notification row ID.
	 * @param int   $recipient_id Recipient user ID.
	 * @param array $data         Notification data payload.
	 * @return void
	 */
	public function on_notification_created( int $notif_id, int $recipient_id, array $data ): void {
		if ( $recipient_id <= 0 ) {
			return;
		}

		$type = isset( $data['type'] ) ? (string) $data['type'] : '';

		if ( '' === $type ) {
			return;
		}

		// High-volume fan-outs (space new-post) stamp defer_email so email stays
		// off the per-row hook entirely: the record stage bulk-inserts the in-app
		// rows and hands email to a single batched AS stage instead. Without this
		// guard the hook would also enqueue a per-recipient email here, double-
		// sending. Single notifications (likes, follows, ...) never set the flag,
		// so their email path is unchanged.
		if ( ! empty( $data['defer_email'] ) ) {
			return;
		}

		$this->sender->send( $recipient_id, $type, $data );
	}

	/**
	 * Action Scheduler callback: send a notification email now.
	 *
	 * Registered as the `buddynext_send_notification_email` action callback so
	 * that immediate emails are dispatched asynchronously and do not block the
	 * originating request.
	 *
	 * @param int    $user_id           Recipient user ID.
	 * @param string $notification_type Notification type key.
	 * @param array  $data              Notification data payload.
	 * @return void
	 */
	public function on_send_notification_email( int $user_id, string $notification_type, array $data ): void {
		$this->sender->send_now( $user_id, $notification_type, $data );
	}

	/**
	 * Process an unsubscribe request from the query string.
	 *
	 * Expects GET parameters: bn_unsub=1, uid, type, sig.
	 * Verifies the HMAC signature, sets email_freq='off' for that user/type,
	 * then redirects with a success or error notice query parameter.
	 *
	 * @return void
	 */
	public function handle_unsubscribe_request(): void {
		if ( ! isset( $_GET['bn_unsub'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$uid  = isset( $_GET['uid'] ) ? (int) $_GET['uid'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sig  = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $uid <= 0 || '' === $type || '' === $sig ) {
			wp_safe_redirect(
				add_query_arg( 'bn_unsub_status', 'invalid', home_url( '/' ) )
			);
			exit;
		}

		if ( ! $this->sender->verify_unsub( $uid, $type, $sig ) ) {
			wp_safe_redirect(
				add_query_arg( 'bn_unsub_status', 'invalid', home_url( '/' ) )
			);
			exit;
		}

		// Only the email channel is being switched off — preserve the member's
		// existing on-site preference instead of force-enabling it.
		$current = $this->pref_service->get_pref( $uid, $type );

		$this->pref_service->set_pref(
			$uid,
			$type,
			array(
				'on_site'    => ! isset( $current['on_site'] ) || ! empty( $current['on_site'] ),
				'email_freq' => 'off',
			)
		);

		wp_safe_redirect(
			add_query_arg( 'bn_unsub_status', 'success', home_url( '/' ) )
		);
		exit;
	}

	/**
	 * Render a visible confirmation after an unsubscribe redirect.
	 *
	 * The handler above redirects to the home page with ?bn_unsub_status=…;
	 * without this banner the member landed on a plain page with no feedback
	 * and reasonably concluded the link did nothing (customer report,
	 * 2026-07-06). Works logged-in and logged-out; the manage link goes to the
	 * notification preferences screen (which prompts login when needed).
	 *
	 * @return void
	 */
	public function render_unsubscribe_status_notice(): void {
		$status = isset( $_GET['bn_unsub_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['bn_unsub_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect flag.

		if ( ! in_array( $status, array( 'success', 'invalid' ), true ) ) {
			return;
		}

		$is_success = 'success' === $status;
		$message    = $is_success
			? __( "You're unsubscribed - we won't email you about this anymore.", 'buddynext' )
			: __( 'This unsubscribe link is invalid or has expired.', 'buddynext' );
		?>
		<div class="bn-unsub-notice <?php echo esc_attr( $is_success ? 'bn-unsub-notice--success' : 'bn-unsub-notice--error' ); ?>" role="status">
			<span class="bn-unsub-notice__message"><?php echo esc_html( $message ); ?></span>
			<a class="bn-unsub-notice__manage" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::notification_prefs_url() ); ?>">
				<?php esc_html_e( 'Manage email preferences', 'buddynext' ); ?>
			</a>
			<a class="bn-unsub-notice__dismiss" href="<?php echo esc_url( remove_query_arg( 'bn_unsub_status' ) ); ?>" aria-label="<?php esc_attr_e( 'Dismiss', 'buddynext' ); ?>"><?php echo \BuddyNext\Core\IconService::render( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns wp_kses()-sanitized SVG. ?></a>
		</div>
		<?php
	}
}
