<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Email sender for BuddyNext notifications.
 *
 * Sends transactional notification emails respecting per-user preferences.
 * Handles immediate dispatch, queuing for digest frequencies, and generates
 * HMAC-signed unsubscribe URLs.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

/**
 * Sends notification emails and manages unsubscribe links.
 */
class EmailSender {

	/**
	 * Notification preference service.
	 *
	 * @var NotificationPrefService
	 */
	private NotificationPrefService $pref_service;

	/**
	 * Notification pref catalogue (authoritative can_email gate).
	 *
	 * @var NotificationPrefCatalogue
	 */
	private NotificationPrefCatalogue $catalogue;

	/**
	 * Constructor.
	 *
	 * @param NotificationPrefService   $pref_service Notification preference service.
	 * @param NotificationPrefCatalogue $catalogue    Pref catalogue (can_email gate).
	 */
	public function __construct( NotificationPrefService $pref_service, NotificationPrefCatalogue $catalogue ) {
		$this->pref_service = $pref_service;
		$this->catalogue    = $catalogue;
	}

	/**
	 * Send an email for a notification type to a user, respecting preferences.
	 *
	 * Checks the user's email_freq preference before dispatching:
	 * - 'off'             → no action
	 * - 'daily'|'weekly'  → fires buddynext_queue_email_digest action and returns
	 * - 'immediate'       → fetches template, renders, and sends
	 *
	 * @param int    $user_id           Recipient user ID.
	 * @param string $notification_type Notification type key.
	 * @param array  $data              Notification data payload.
	 * @return void
	 */
	public function send( int $user_id, string $notification_type, array $data ): void {
		if ( $user_id <= 0 || '' === $notification_type ) {
			return;
		}

		// Authoritative gate: never email a type the catalogue marks can_email=false.
		// Mirrored partner notifications (suite.*, jt.*) set this so BuddyNext only
		// collects/displays them — the partner plugin owns its own emails.
		if ( ! $this->catalogue->can_email( $notification_type ) ) {
			return;
		}

		$pref       = $this->pref_service->get_pref( $user_id, $notification_type );
		$email_freq = $pref['email_freq'] ?? 'immediate';

		if ( 'off' === $email_freq ) {
			return;
		}

		if ( 'daily' === $email_freq || 'weekly' === $email_freq ) {
			/**
			 * Fires when a notification should be queued for digest delivery.
			 *
			 * @param int    $user_id           Recipient user ID.
			 * @param string $notification_type Notification type key.
			 * @param array  $data              Notification data payload (includes email_freq key).
			 */
			do_action( 'buddynext_queue_email_digest', $user_id, $notification_type, array_merge( $data, array( 'email_freq' => $email_freq ) ) );
			return;
		}

		// Immediate send — dispatch asynchronously via Action Scheduler when
		// available so it does not block the current request.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				'buddynext_send_notification_email',
				array(
					'user_id'           => $user_id,
					'notification_type' => $notification_type,
					'data'              => $data,
				),
				'buddynext'
			);
			return;
		}

		// Fallback: send synchronously when Action Scheduler is not loaded.
		$this->send_now( $user_id, $notification_type, $data );
	}

	/**
	 * Perform the actual template render and wp_mail() dispatch.
	 *
	 * Called directly by the Action Scheduler callback or as a synchronous
	 * fallback when Action Scheduler is not available.
	 *
	 * @param int    $user_id           Recipient user ID.
	 * @param string $notification_type Notification type key.
	 * @param array  $data              Notification data payload.
	 * @return void
	 */
	public function send_now( int $user_id, string $notification_type, array $data ): void {
		// Defense-in-depth: honour the can_email gate here too (in case this is
		// ever invoked outside the send() path).
		if ( '' !== $notification_type && ! $this->catalogue->can_email( $notification_type ) ) {
			return;
		}

		$template = $this->get_template( $notification_type );

		// Composed-email path: campaign and drip-step senders author the subject
		// and body per message and pass them in $data. These are first-class
		// emails — the authored content IS the email — so they do not require a
		// seeded bn_email_templates row. Event emails (e.g. bn.new_follower)
		// continue to render from their template row as before.
		$inline_subject = isset( $data['subject'] ) ? (string) $data['subject'] : '';
		$inline_body    = isset( $data['body_html'] ) ? (string) $data['body_html'] : '';
		$has_inline     = '' !== $inline_subject && '' !== $inline_body;

		if ( null === $template && ! $has_inline ) {
			return;
		}

		// A disabled template row suppresses its own event emails, but never a
		// composed campaign/drip email that carries its own authored content.
		if ( null !== $template && ! $has_inline && ! (bool) $template->enabled ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( false === $user || '' === $user->user_email ) {
			return;
		}

		$subject_src = $has_inline ? $inline_subject : (string) $template->subject;
		$body_src    = $has_inline ? $inline_body : (string) $template->body_html;

		$subject = $this->render( $subject_src, $user_id, $data );
		$body    = $this->render( $body_src, $user_id, $data );

		$payload = array(
			'to'      => $user->user_email,
			'subject' => $subject,
			'body'    => '<html><body>' . $body . '</body></html>',
			'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
		);

		/**
		 * Filter the email payload immediately before wp_mail() is called.
		 *
		 * Allows Pro to modify recipients, subject, or body before dispatch.
		 * Return an array with 'send' => false to suppress the wp_mail() call —
		 * Pro uses this for broadcast campaign batching where emails are queued
		 * rather than sent inline.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $payload       Email payload: to, subject, body, headers.
		 * @param string $template_slug Notification type slug (matches bn_email_templates.type).
		 * @param array  $context       Original notification data array passed to send_now().
		 */
		$payload = (array) apply_filters( 'buddynext_email_payload', $payload, $notification_type, $data );

		if ( isset( $payload['send'] ) && false === $payload['send'] ) {
			// Pro has captured the email for batch/campaign delivery — skip wp_mail().
			return;
		}

		wp_mail(
			$payload['to'],
			$payload['subject'],
			$payload['body'],
			$payload['headers']
		);

		$this->log_sent( $user_id, $notification_type );
	}

	/**
	 * Render a template string with placeholder replacement.
	 *
	 * Replaces standard placeholders {{site_name}}, {{site_url}},
	 * {{user_name}}, {{unsubscribe_url}}, plus any key from $data
	 * formatted as {{key_name}}.
	 *
	 * @param string $template  Raw template string containing placeholders.
	 * @param int    $user_id   Recipient user ID for personalised tokens.
	 * @param array  $data      Notification data — keys become additional placeholders.
	 * @return string Rendered string with placeholders replaced.
	 */
	private function render( string $template, int $user_id, array $data ): string {
		$notification_type = (string) ( $data['type'] ?? '' );

		$actor_id   = isset( $data['sender_id'] ) ? (int) $data['sender_id'] : 0;
		$actor_name = $actor_id > 0 ? $this->get_display_name( $actor_id ) : '';

		// Deep link for the email's call-to-action ("See who it is", "View the
		// request", "See the post"). Resolved per notification type against the
		// recipient; falls back to the site home when no specific target exists
		// so the link is never empty.
		$action_url = ( new NotificationMessageService() )->email_action_url( $notification_type, $user_id, $data );
		if ( '' === $action_url ) {
			$action_url = home_url( '/' );
		}

		$tokens = array(
			'{{site_name}}'            => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{{site_url}}'             => esc_url( home_url( '/' ) ),
			'{{action_url}}'           => esc_url( $action_url ),
			'{{user_name}}'            => $this->get_display_name( $user_id ),
			'{{actor_name}}'           => $actor_name,
			'{{notification_message}}' => (string) ( $data['message'] ?? '' ),
			'{{unsubscribe_url}}'      => $this->unsubscribe_url( $user_id, $notification_type ),
		);

		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$tokens[ '{{' . $key . '}}' ] = (string) $value;
			}
		}

		return str_replace( array_keys( $tokens ), array_values( $tokens ), $template );
	}

	/**
	 * Generate an HMAC-signed unsubscribe URL for a user/type combo.
	 *
	 * URL format:
	 *   {home_url}/?bn_unsub=1&uid={user_id}&type={type}&sig={hmac}
	 *
	 * @param int    $user_id Recipient user ID.
	 * @param string $type    Notification type key.
	 * @return string Signed unsubscribe URL.
	 */
	public function unsubscribe_url( int $user_id, string $type ): string {
		$sig = hash_hmac( 'sha256', "{$user_id}:{$type}", wp_salt( 'auth' ) );

		return add_query_arg(
			array(
				'bn_unsub' => 1,
				'uid'      => $user_id,
				'type'     => rawurlencode( $type ),
				'sig'      => $sig,
			),
			home_url( '/' )
		);
	}

	/**
	 * Verify an unsubscribe HMAC signature.
	 *
	 * Uses hash_equals() for timing-safe comparison.
	 *
	 * @param int    $user_id Recipient user ID.
	 * @param string $type    Notification type key.
	 * @param string $sig     HMAC signature from the URL parameter.
	 * @return bool True when the signature is valid.
	 */
	public function verify_unsub( int $user_id, string $type, string $sig ): bool {
		$expected = hash_hmac( 'sha256', "{$user_id}:{$type}", wp_salt( 'auth' ) );
		return hash_equals( $expected, $sig );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Fetch a template row from bn_email_templates by type.
	 *
	 * Returns null when no row exists or the template is inactive.
	 *
	 * @param string $type Notification type key.
	 * @return object|null DB row object or null.
	 */
	private function get_template( string $type ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT subject, body_html, enabled FROM {$wpdb->prefix}bn_email_templates WHERE type = %s",
				$type
			)
		);

		return ( null !== $row ) ? $row : null;
	}

	/**
	 * Write a send record to bn_email_log.
	 *
	 * @param int    $user_id User who received the email.
	 * @param string $type    Notification type key.
	 * @return void
	 */
	private function log_sent( int $user_id, string $type ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_email_log',
			array(
				'user_id'     => $user_id,
				'type'        => $type,
				'digest_date' => null,
				'sent_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Return the display name for a user, falling back to login.
	 *
	 * @param int $user_id User ID.
	 * @return string Display name.
	 */
	private function get_display_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return '';
		}
		return '' !== $user->display_name ? $user->display_name : $user->user_login;
	}
}
