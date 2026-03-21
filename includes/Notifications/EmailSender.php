<?php
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
	 * Constructor.
	 *
	 * @param NotificationPrefService $pref_service Notification preference service.
	 */
	public function __construct( NotificationPrefService $pref_service ) {
		$this->pref_service = $pref_service;
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
			 * @param array  $data              Notification data payload.
			 */
			do_action( 'buddynext_queue_email_digest', $user_id, $notification_type, $data );
			return;
		}

		// Immediate send — fetch and render the template.
		$template = $this->get_template( $notification_type );
		if ( null === $template ) {
			return;
		}

		if ( ! (bool) $template->is_active ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( false === $user || '' === $user->user_email ) {
			return;
		}

		$subject = $this->render( (string) $template->subject, $user_id, $data );
		$body    = $this->render( (string) $template->body, $user_id, $data );

		wp_mail(
			$user->user_email,
			$subject,
			'<html><body>' . $body . '</body></html>',
			array( 'Content-Type: text/html; charset=UTF-8' )
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

		$tokens = array(
			'{{site_name}}'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{{site_url}}'        => esc_url( home_url( '/' ) ),
			'{{user_name}}'       => $this->get_display_name( $user_id ),
			'{{unsubscribe_url}}' => $this->unsubscribe_url( $user_id, $notification_type ),
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
				"SELECT subject, body, is_active FROM {$wpdb->prefix}bn_email_templates WHERE type = %s",
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
