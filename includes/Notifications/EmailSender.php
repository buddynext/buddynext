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

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Reply-To sender identity (Settings → Email). Applied as a header so it
		// survives any wp_mail_from override and is per-message.
		$reply_to = sanitize_email( (string) get_option( 'buddynext_email_reply_to', '' ) );
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$payload = array(
			'to'      => $user->user_email,
			'subject' => $subject,
			'body'    => $this->wrap_email_html( $body, $subject ),
			'headers' => $headers,
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

		// Sender identity (Settings → Email). Apply the configured From name and
		// From address via the wp_mail_* filters only for this dispatch, then
		// detach so we never affect unrelated mail (password resets, etc.).
		$from_name    = sanitize_text_field( (string) get_option( 'buddynext_email_from_name', '' ) );
		$from_address = sanitize_email( (string) get_option( 'buddynext_email_from_address', '' ) );

		$name_filter = null;
		if ( '' !== $from_name ) {
			$name_filter = static function () use ( $from_name ) {
				return $from_name;
			};
			add_filter( 'wp_mail_from_name', $name_filter );
		}

		$address_filter = null;
		if ( '' !== $from_address && is_email( $from_address ) ) {
			$address_filter = static function () use ( $from_address ) {
				return $from_address;
			};
			add_filter( 'wp_mail_from', $address_filter );
		}

		wp_mail(
			$payload['to'],
			$payload['subject'],
			$payload['body'],
			$payload['headers']
		);

		if ( null !== $name_filter ) {
			remove_filter( 'wp_mail_from_name', $name_filter );
		}
		if ( null !== $address_filter ) {
			remove_filter( 'wp_mail_from', $address_filter );
		}

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
	/**
	 * Wrap a rendered template body in the branded HTML email shell.
	 *
	 * send_now() previously emitted the bare `<html><body>$body</body></html>`,
	 * so notification emails arrived as unstyled plain text with no header,
	 * footer, or branding. This wraps every email in a responsive, inline-styled
	 * shell (table layout for client compatibility) carrying the site name and a
	 * footer, using the admin's brand colour for the header accent.
	 *
	 * @param string $body    Rendered (token-replaced) template body HTML.
	 * @param string $subject Rendered subject, used as the preheader/title.
	 * @return string Full branded HTML document.
	 */
	private function wrap_email_html( string $body, string $subject = '' ): string {
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url  = esc_url( home_url( '/' ) );
		$brand     = (string) get_option( 'buddynext_brand_color', '#0073aa' );
		if ( ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $brand ) ) {
			$brand = '#0073aa';
		}

		/**
		 * Filter the BuddyNext notification email shell.
		 *
		 * Return a string containing the literal token `{{email_body}}` where the
		 * rendered body should be injected to fully replace the default shell.
		 *
		 * @since 1.0.0
		 *
		 * @param string $shell   Default shell HTML (contains {{email_body}}).
		 * @param string $body    Rendered body HTML.
		 * @param string $subject Rendered subject.
		 */
		$shell = (string) apply_filters( 'buddynext_email_shell', '', $body, $subject );
		if ( '' !== $shell && false !== strpos( $shell, '{{email_body}}' ) ) {
			return str_replace( '{{email_body}}', $body, $shell );
		}

		$brand_esc = esc_attr( $brand );
		$name_esc  = esc_html( $site_name );
		$year      = esc_html( gmdate( 'Y' ) );

		// Footer text (Settings → Email). When the admin has set a custom footer
		// it replaces the default copyright line; otherwise fall back to the
		// standard "© {year} {site} ..." attribution.
		$custom_footer = trim( (string) get_option( 'buddynext_email_footer_text', '' ) );
		$footer_html   = '' !== $custom_footer
			? nl2br( esc_html( $custom_footer ) )
			: esc_html( sprintf( /* translators: 1: year, 2: site name. */ __( '© %1$s %2$s. All rights reserved.', 'buddynext' ), $year, $site_name ) );

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
			. '<title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f3f4f6;'
			. '-webkit-font-smoothing:antialiased;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
			. 'style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;'
			. 'box-shadow:0 1px 3px rgba(0,0,0,0.08);">'
			// Header.
			. '<tr><td style="background:' . $brand_esc . ';padding:20px 32px;">'
			. '<a href="' . $site_url . '" style="color:#ffffff;text-decoration:none;font-size:20px;font-weight:700;">'
			. $name_esc . '</a></td></tr>'
			// Body.
			. '<tr><td style="padding:28px 32px;color:#1f2937;font-size:15px;line-height:1.6;">'
			. $body
			. '</td></tr>'
			// Footer.
			. '<tr><td style="padding:20px 32px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;line-height:1.5;">'
			. $footer_html
			. '<br><a href="' . $site_url . '" style="color:#6b7280;">' . $site_url . '</a>'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

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
