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

		$headers = self::build_identity_headers();

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

		self::send_with_identity(
			$payload['to'],
			$payload['subject'],
			$payload['body'],
			(array) $payload['headers']
		);

		$this->log_sent( $user_id, $notification_type );
	}

	/**
	 * Resolve the effective From name for every BuddyNext email.
	 *
	 * Falls back to the site name when Settings → Email leaves it blank, so the
	 * sender is always branded (never the bare WordPress default) and the admin
	 * field can surface the same effective value instead of an empty box.
	 *
	 * @return string
	 */
	public static function from_name(): string {
		$name = sanitize_text_field( (string) get_option( 'buddynext_email_from_name', '' ) );
		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		// Let the admin type {{site_name}} etc. so the From name can track the site.
		return self::apply_global_tokens( $name );
	}

	/**
	 * Resolve the effective From address for every BuddyNext email.
	 *
	 * Falls back to the site admin email when Settings → Email leaves it blank.
	 *
	 * @return string
	 */
	public static function from_address(): string {
		$address = sanitize_email( (string) get_option( 'buddynext_email_from_address', '' ) );
		if ( '' === $address || ! is_email( $address ) ) {
			$address = sanitize_email( (string) get_option( 'admin_email', '' ) );
		}
		return $address;
	}

	/**
	 * Resolve site-wide merge tags in admin-authored email chrome (footer text,
	 * From name). These are the placeholders that don't depend on a recipient, so
	 * they can be filled anywhere — including settings the admin types by hand.
	 *
	 * Supported: {{site_name}}, {{site_url}}, {{current_year}}.
	 *
	 * @param string $text Text possibly containing site-wide tokens.
	 * @return string
	 */
	public static function apply_global_tokens( string $text ): string {
		if ( false === strpos( $text, '{{' ) ) {
			return $text;
		}
		return strtr(
			$text,
			array(
				'{{site_name}}'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'{{site_url}}'     => home_url( '/' ),
				'{{current_year}}' => gmdate( 'Y' ),
			)
		);
	}

	/**
	 * Build the BuddyNext email headers (Content-Type + Reply-To identity).
	 *
	 * The configured Reply-To (Settings → Email) is applied as a header so it
	 * survives any wp_mail_from override and is per-message. Shared by every
	 * BuddyNext outbound email — template notifications and the digest cron —
	 * so the sender identity stays consistent across all of them.
	 *
	 * @param array<int, string> $extra_headers Optional additional headers.
	 * @return array<int, string> Header lines for wp_mail().
	 */
	public static function build_identity_headers( array $extra_headers = array() ): array {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$reply_to = sanitize_email( (string) get_option( 'buddynext_email_reply_to', '' ) );
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		foreach ( $extra_headers as $header ) {
			if ( is_string( $header ) && '' !== $header ) {
				$headers[] = $header;
			}
		}

		return $headers;
	}

	/**
	 * Dispatch an email through wp_mail() with the BuddyNext sender identity.
	 *
	 * Applies the configured From name and From address (Settings → Email) via
	 * the wp_mail_* filters for the duration of this single dispatch only, then
	 * detaches them so unrelated mail (password resets, etc.) is never affected.
	 * Every BuddyNext outbound email routes through here so the From/Reply-To
	 * identity is identical for template notifications and the digest cron.
	 *
	 * @param string             $to      Recipient email address.
	 * @param string             $subject Email subject.
	 * @param string             $body    Email body (HTML).
	 * @param array<int, string> $headers Header lines (use build_identity_headers()).
	 * @return bool True when wp_mail() reports success.
	 */
	public static function send_with_identity( string $to, string $subject, string $body, array $headers ): bool {
		// Always branded: resolvers fall back to the site name / admin email when
		// the owner hasn't set a custom identity, so no BuddyNext email ever sends
		// as the bare WordPress default.
		$from_name    = self::from_name();
		$from_address = self::from_address();

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

		// Make this the authoritative identity path: ensure the HTML Content-Type
		// and the configured Reply-To are present on EVERY send, even when a caller
		// passed only a bare Content-Type header (so the Reply-To setting is wired
		// into all email, not just the ones that call build_identity_headers()).
		$has_ctype    = false;
		$has_reply_to = false;
		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) ) {
				continue;
			}
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				$has_ctype = true;
			}
			if ( 0 === stripos( $header, 'reply-to:' ) ) {
				$has_reply_to = true;
			}
		}
		if ( ! $has_ctype ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}
		if ( ! $has_reply_to ) {
			$reply_to = sanitize_email( (string) get_option( 'buddynext_email_reply_to', '' ) );
			if ( '' !== $reply_to && is_email( $reply_to ) ) {
				$headers[] = 'Reply-To: ' . $reply_to;
			}
		}

		$sent = wp_mail( $to, $subject, $body, $headers );

		if ( null !== $name_filter ) {
			remove_filter( 'wp_mail_from_name', $name_filter );
		}
		if ( null !== $address_filter ) {
			remove_filter( 'wp_mail_from', $address_filter );
		}

		return $sent;
	}

	/**
	 * Wrap a rendered body in the single, canonical branded email shell.
	 *
	 * THE one wrapper every BuddyNext email passes through — notifications,
	 * digests, auth (verify/registration/2FA), invites, campaigns, drip, and the
	 * admin test send — so all outbound mail is 100% visually consistent. Owners
	 * author only the content; this supplies the chrome (logo/site header, padded
	 * body, footer) using the Appearance logo + brand colour + Settings → Email
	 * footer text.
	 *
	 * Public + static so any send path can call it without an EmailSender
	 * instance.
	 *
	 * Developer hooks:
	 *  - buddynext_email_shell  — replace the WHOLE shell (return HTML with the
	 *    {{email_body}} token where the body goes).
	 *  - buddynext_email_header_html / buddynext_email_footer_html — replace just
	 *    the header or footer block (each gets the subject for context).
	 *
	 * @param string $body    Rendered (token-replaced) body HTML.
	 * @param string $subject Rendered subject, used as the title/preheader.
	 * @return string Full branded HTML document.
	 */
	public static function brand_wrap( string $body, string $subject = '' ): string {
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url  = esc_url( home_url( '/' ) );
		$brand     = (string) get_option( 'buddynext_brand_color', '#0073aa' );
		if ( ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $brand ) ) {
			$brand = '#0073aa';
		}

		/**
		 * Filter the BuddyNext email shell. Return HTML containing the literal
		 * token `{{email_body}}` to fully replace the default shell.
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
		$year      = esc_html( gmdate( 'Y' ) );

		// Header: the Appearance logo when set (Settings → Appearance), else the
		// site name as a text wordmark. Both link home.
		$logo_url = (string) get_option( 'buddynext_logo_url', '' );
		if ( '' !== $logo_url ) {
			$header_inner = '<a href="' . $site_url . '" style="display:inline-block;text-decoration:none;">'
				. '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $site_name ) . '" '
				. 'style="max-height:40px;max-width:240px;height:auto;border:0;display:block;"></a>';
		} else {
			// No logo: wordmark in the brand colour (readable on the light header).
			$header_inner = '<a href="' . $site_url . '" style="color:' . $brand_esc . ';text-decoration:none;font-size:20px;font-weight:700;">'
				. esc_html( $site_name ) . '</a>';
		}

		/**
		 * Filter the email header block (inside the coloured header cell).
		 *
		 * @param string $header_inner Default header HTML (logo or wordmark link).
		 * @param string $subject      Rendered subject.
		 */
		$header_inner = (string) apply_filters( 'buddynext_email_header_html', $header_inner, $subject );

		// Footer: custom footer text (Settings → Email) or the default © line.
		$custom_footer = trim( (string) get_option( 'buddynext_email_footer_text', '' ) );
		$custom_footer = self::apply_global_tokens( $custom_footer );
		$footer_html   = '' !== $custom_footer
			? nl2br( esc_html( $custom_footer ) )
			: esc_html( sprintf( /* translators: 1: year, 2: site name. */ __( '© %1$s %2$s. All rights reserved.', 'buddynext' ), $year, $site_name ) );
		$footer_html  .= '<br><a href="' . $site_url . '" style="color:#6b7280;">' . $site_url . '</a>';

		/**
		 * Filter the email footer block.
		 *
		 * @param string $footer_html Default footer HTML.
		 * @param string $subject     Rendered subject.
		 */
		$footer_html = (string) apply_filters( 'buddynext_email_footer_html', $footer_html, $subject );

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
			. '<title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f3f4f6;'
			. '-webkit-font-smoothing:antialiased;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
			. 'style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;'
			. 'box-shadow:0 1px 3px rgba(0,0,0,0.08);border-top:3px solid ' . $brand_esc . ';">'
			// Header — light background so any logo (light, dark, or transparent)
			// reads well; a thin brand-colour strip on the card top keeps a touch
			// of branding without a heavy coloured block behind the logo.
			. '<tr><td style="background:#ffffff;padding:24px 32px;border-bottom:1px solid #e5e7eb;">'
			. $header_inner . '</td></tr>'
			// Body.
			. '<tr><td style="padding:28px 32px;color:#1f2937;font-size:15px;line-height:1.6;">'
			. $body
			. '</td></tr>'
			// Footer.
			. '<tr><td style="padding:20px 32px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;line-height:1.5;">'
			. $footer_html
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Back-compat instance alias for brand_wrap().
	 *
	 * @param string $body    Rendered body HTML.
	 * @param string $subject Rendered subject.
	 * @return string
	 */
	private function wrap_email_html( string $body, string $subject = '' ): string {
		return self::brand_wrap( $body, $subject );
	}

	/**
	 * Render a template string with placeholder replacement.
	 *
	 * Replaces standard placeholders {{site_name}}, {{site_url}},
	 * {{user_name}}, {{unsubscribe_url}}, plus any key from $data
	 * formatted as {{key_name}}.
	 *
	 * @param string $template Raw template string containing placeholders.
	 * @param int    $user_id  Recipient user ID for personalised tokens.
	 * @param array  $data     Notification data — keys become additional placeholders.
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

		// Personalisation tokens. first_name/user_email come from the recipient
		// account; login_url/current_year are site-wide. These join the existing
		// set so the email composer can offer a consistent CMS-like token list
		// that actually resolves at send time.
		$recipient   = get_userdata( $user_id );
		$display_name = $this->get_display_name( $user_id );
		$first_name  = '';
		if ( $recipient ) {
			$first_name = (string) get_user_meta( $user_id, 'first_name', true );
			if ( '' === $first_name ) {
				$first_name = trim( (string) strtok( $display_name, ' ' ) );
			}
		}

		$tokens = array(
			'{{site_name}}'            => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{{site_url}}'             => esc_url( home_url( '/' ) ),
			'{{login_url}}'            => esc_url( \BuddyNext\Core\PageRouter::auth_url() ),
			'{{action_url}}'           => esc_url( $action_url ),
			'{{user_name}}'            => $display_name,
			'{{first_name}}'           => '' !== $first_name ? $first_name : $display_name,
			'{{user_email}}'           => $recipient ? (string) $recipient->user_email : '',
			'{{actor_name}}'           => $actor_name,
			'{{notification_message}}' => (string) ( $data['message'] ?? '' ),
			'{{unsubscribe_url}}'      => $this->unsubscribe_url( $user_id, $notification_type ),
			'{{current_year}}'         => gmdate( 'Y' ),
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
