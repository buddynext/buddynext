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

/**
 * Wires email sending into the notification lifecycle.
 */
class EmailDispatchListener {

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
	public function init(): void {
		add_action( 'buddynext_notification_created', array( $this, 'on_notification_created' ), 10, 3 );
		add_action( 'init', array( $this, 'handle_unsubscribe_request' ) );
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

		$this->sender->send( $recipient_id, $type, $data );
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

		$this->pref_service->set_pref(
			$uid,
			$type,
			array(
				'on_site'    => true,
				'email_freq' => 'off',
			)
		);

		wp_safe_redirect(
			add_query_arg( 'bn_unsub_status', 'success', home_url( '/' ) )
		);
		exit;
	}
}
