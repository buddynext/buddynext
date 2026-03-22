<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext Email Template Editor admin panel.
 *
 * Provides a WP Admin page for editing, enabling/disabling, and testing
 * the 12 built-in transactional email templates.  Templates are stored in
 * the bn_email_templates table with three editable columns:
 *   subject, preview_text, body_html
 *
 * AJAX endpoints (admin_post_):
 *   buddynext_email_save        — save subject / preview / body / enabled
 *   buddynext_email_test        — send a test message to the current admin
 *   buddynext_email_reset       — restore a template to factory defaults
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Admin page for editing BuddyNext email templates.
 */
class EmailEditor {

	// ── Constants ─────────────────────────────────────────────────────────────

	/**
	 * Admin menu slug.
	 */
	private const MENU_SLUG = 'buddynext-email-editor';

	/**
	 * Nonce action for save / test / reset operations.
	 */
	private const NONCE_ACTION = 'buddynext_email_editor';

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_post_buddynext_email_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_buddynext_email_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_buddynext_email_reset', array( $this, 'handle_reset' ) );
	}

	/**
	 * Add the Email Editor as a submenu under the main BuddyNext menu.
	 *
	 * @return void
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'buddynext',
			__( 'Email Templates', 'buddynext' ),
			__( 'Email Templates', 'buddynext' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	// ── Template catalogue ────────────────────────────────────────────────────

	/**
	 * Return the catalogue of built-in templates grouped by category.
	 *
	 * @return array<string, array<string, array<string, string>>>
	 *   [ 'Category' => [ 'slug' => [ 'name', 'trigger', 'tokens', 'subject', 'preview', 'body' ] ] ]
	 */
	public function get_catalogue(): array {
		return array(
			__( 'Social', 'buddynext' )       => array(
				'bn.new_follower'         => array(
					'name'    => __( 'New Follower', 'buddynext' ),
					'trigger' => __( 'When someone follows you', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{follower_name}}', '{{follower_bio}}', '{{profile_url}}', '{{site_name}}', '{{follow_back_url}}', '{{unsubscribe_url}}' ),
					'subject' => '{{follower_name}} started following you on {{site_name}}',
					'preview' => "You have a new follower! Check out {{follower_name}}'s profile.",
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{follower_name}}</strong> started following you on {{site_name}}!\n\n{{follower_name}} is {{follower_bio}}\n\n<a href=\"{{profile_url}}\">View their profile →</a>\n\nYou can <a href=\"{{follow_back_url}}\">follow back</a> or <a href=\"{{unsubscribe_url}}\">unsubscribe</a> from this type of email.",
				),
				'bn.connection_requested' => array(
					'name'    => __( 'Connection Requested', 'buddynext' ),
					'trigger' => __( 'When someone sends you a connection request', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{requester_name}}', '{{profile_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => '{{requester_name}} wants to connect with you on {{site_name}}',
					'preview' => 'You have a new connection request.',
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{requester_name}}</strong> sent you a connection request on {{site_name}}.\n\n<a href=\"{{profile_url}}\">View the request →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a> from this type of email.",
				),
				'bn.connection_accepted'  => array(
					'name'    => __( 'Connection Accepted', 'buddynext' ),
					'trigger' => __( 'When a connection request is accepted', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{connector_name}}', '{{profile_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => '{{connector_name}} accepted your connection on {{site_name}}',
					'preview' => "You're now connected with {{connector_name}}!",
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{connector_name}}</strong> accepted your connection request on {{site_name}}.\n\n<a href=\"{{profile_url}}\">View their profile →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a> from this type of email.",
				),
				'bn.mention'              => array(
					'name'    => __( 'Mention', 'buddynext' ),
					'trigger' => __( "When you're @mentioned", 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{mentioner_name}}', '{{context_excerpt}}', '{{post_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => '{{mentioner_name}} mentioned you on {{site_name}}',
					'preview' => '{{mentioner_name}} mentioned you in a post.',
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{mentioner_name}}</strong> mentioned you:\n\n<blockquote>{{context_excerpt}}</blockquote>\n\n<a href=\"{{post_url}}\">View post →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
				'bn.post_reacted'         => array(
					'name'    => __( 'Post Reacted', 'buddynext' ),
					'trigger' => __( 'When someone reacts to your post', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{reactor_name}}', '{{post_excerpt}}', '{{post_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => '{{reactor_name}} reacted to your post on {{site_name}}',
					'preview' => '{{reactor_name}} reacted to your post.',
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{reactor_name}}</strong> reacted to your post on {{site_name}}:\n\n<blockquote>{{post_excerpt}}</blockquote>\n\n<a href=\"{{post_url}}\">View post →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
				'bn.post_commented'       => array(
					'name'    => __( 'Post Commented', 'buddynext' ),
					'trigger' => __( 'When someone comments on your post', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{commenter_name}}', '{{comment_excerpt}}', '{{post_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => '{{commenter_name}} commented on your post',
					'preview' => '{{commenter_name}} left a comment.',
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{commenter_name}}</strong> commented on your post:\n\n<blockquote>{{comment_excerpt}}</blockquote>\n\n<a href=\"{{post_url}}\">View post →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
				'bn.post_shared'          => array(
					'name'    => __( 'Post Shared', 'buddynext' ),
					'trigger' => __( 'When someone shares your post', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{sharer_name}}', '{{post_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => '{{sharer_name}} shared your post on {{site_name}}',
					'preview' => '{{sharer_name}} shared your post.',
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{sharer_name}}</strong> shared your post on {{site_name}}.\n\n<a href=\"{{post_url}}\">View post →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
			),
			__( 'Spaces', 'buddynext' )       => array(
				'bn.space_invite'           => array(
					'name'    => __( 'Space Invite', 'buddynext' ),
					'trigger' => __( 'When invited to join a space', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{inviter_name}}', '{{space_name}}', '{{space_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => '{{inviter_name}} invited you to join {{space_name}}',
					'preview' => "You've been invited to {{space_name}}!",
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{inviter_name}}</strong> has invited you to join <strong>{{space_name}}</strong> on {{site_name}}.\n\n<a href=\"{{space_url}}\">Accept invitation →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
				'bn.space_join_requested'   => array(
					'name'    => __( 'Space Join Requested', 'buddynext' ),
					'trigger' => __( 'When a member requests to join your space', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{requester_name}}', '{{space_name}}', '{{space_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => 'New join request for {{space_name}} on {{site_name}}',
					'preview' => 'A member wants to join your space.',
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{requester_name}}</strong> has requested to join <strong>{{space_name}}</strong>.\n\n<a href=\"{{space_url}}\">Review the request →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
				'bn.space_request_approved' => array(
					'name'    => __( 'Space Request Approved', 'buddynext' ),
					'trigger' => __( 'When a space join request is approved', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{space_name}}', '{{space_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => 'Your request to join {{space_name}} was approved',
					'preview' => 'Welcome to {{space_name}}!',
					'body'    => "Hi {{recipient_name}},\n\nYour request to join <strong>{{space_name}}</strong> on {{site_name}} has been approved.\n\n<a href=\"{{space_url}}\">Visit the space →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
			),
			__( 'Moderation', 'buddynext' )   => array(
				'bn.strike_issued' => array(
					'name'    => __( 'Strike Issued', 'buddynext' ),
					'trigger' => __( 'When a moderation strike is issued to a member', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}' ),
					'subject' => 'A moderation action has been taken on your account — {{site_name}}',
					'preview' => 'Important account notice.',
					'body'    => "Hi {{recipient_name}},\n\nA moderation strike has been issued on your account at <strong>{{site_name}}</strong>. Please review the community guidelines to avoid further action.",
				),
			),
			__( 'Gamification', 'buddynext' ) => array(
				'bn.badge_awarded' => array(
					'name'    => __( 'Badge Awarded', 'buddynext' ),
					'trigger' => __( 'When a member earns a badge', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{badge_name}}', '{{site_name}}', '{{profile_url}}', '{{unsubscribe_url}}' ),
					'subject' => 'You earned a badge on {{site_name}}!',
					'preview' => 'Congratulations on your new badge.',
					'body'    => "Hi {{recipient_name}},\n\nCongratulations! You earned the <strong>{{badge_name}}</strong> badge on {{site_name}}.\n\n<a href=\"{{profile_url}}\">View your profile →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
				'bn.level_up'      => array(
					'name'    => __( 'Level Up', 'buddynext' ),
					'trigger' => __( 'When a member reaches a new level', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{new_level}}', '{{site_name}}', '{{profile_url}}', '{{unsubscribe_url}}' ),
					'subject' => 'You levelled up on {{site_name}}!',
					'preview' => 'Your community level increased.',
					'body'    => "Hi {{recipient_name}},\n\nYou have reached level <strong>{{new_level}}</strong> on {{site_name}}!\n\n<a href=\"{{profile_url}}\">See your new level →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
			),
			__( 'Jetonomy', 'buddynext' )     => array(
				'bn.jetonomy_reply' => array(
					'name'    => __( 'Forum Reply', 'buddynext' ),
					'trigger' => __( 'When someone replies to your forum discussion', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{replier_name}}', '{{discussion_title}}', '{{reply_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => 'New reply to your discussion — {{site_name}}',
					'preview' => 'Someone replied to your discussion.',
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{replier_name}}</strong> replied to your discussion: <em>{{discussion_title}}</em>.\n\n<a href=\"{{reply_url}}\">View the reply →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
			),
			__( 'Auth', 'buddynext' )         => array(
				'welcome'      => array(
					'name'    => __( 'Welcome Email', 'buddynext' ),
					'trigger' => __( 'Sent on registration', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{login_url}}', '{{site_name}}' ),
					'subject' => 'Welcome to {{site_name}}!',
					'preview' => "Get started with {{site_name}} — here's everything you need.",
					'body'    => "Hi {{recipient_name}},\n\nWelcome to <strong>{{site_name}}</strong>! We're excited to have you.\n\n<a href=\"{{login_url}}\">Get started →</a>",
				),
				'email_verify' => array(
					'name'    => __( 'Email Verification', 'buddynext' ),
					'trigger' => __( 'Verify email address (OTP)', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{otp_code}}', '{{site_name}}' ),
					'subject' => '{{otp_code}} is your {{site_name}} verification code',
					'preview' => 'Enter this code to verify your email address.',
					'body'    => "Hi {{recipient_name}},\n\nYour verification code for {{site_name}} is:\n\n<strong style=\"font-size:28px;letter-spacing:4px;\">{{otp_code}}</strong>\n\nThis code expires in 15 minutes.",
				),
			),
			__( 'Onboarding', 'buddynext' )   => array(
				'bn.bulk_invite'      => array(
					'name'    => __( 'Bulk Invite', 'buddynext' ),
					'trigger' => __( 'Sent when an admin invites a member via CSV upload', 'buddynext' ),
					'tokens'  => array( '{{first_name}}', '{{site_name}}', '{{invite_url}}' ),
					'subject' => "You've been invited to join {{site_name}}",
					'preview' => 'Accept your invitation and create your account.',
					'body'    => "Hi {{first_name}},\n\nYou've been invited to join <strong>{{site_name}}</strong>!\n\n<a href=\"{{invite_url}}\">Accept invitation →</a>\n\nThis invitation expires in 7 days.",
				),
				'bn.onboarding_nudge' => array(
					'name'    => __( 'Onboarding Nudge', 'buddynext' ),
					'trigger' => __( 'Sent 24h and 72h after registration if onboarding is incomplete', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}', '{{onboarding_url}}' ),
					'subject' => 'Finish setting up your {{site_name}} profile',
					'preview' => 'Complete your onboarding to get the most out of the community.',
					'body'    => "Hi {{recipient_name}},\n\nYou're almost there! Complete your profile setup on <strong>{{site_name}}</strong> to connect with the community.\n\n<a href=\"{{onboarding_url}}\">Complete your profile →</a>",
				),
			),
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Sanitize and validate a template type string against the catalogue.
	 *
	 * Template types may contain dots (e.g. "bn.new_follower") which
	 * sanitize_key() would strip. This method strips control characters and
	 * whitespace, then returns the value only if it exists in the catalogue.
	 * Returns an empty string when the type is not recognised.
	 *
	 * @param string $raw Raw input value.
	 * @return string Valid catalogue type, or empty string.
	 */
	private function sanitize_template_type( string $raw ): string {
		$type = preg_replace( '/[^\w.\-]/', '', wp_unslash( $raw ) );
		if ( '' === $type ) {
			return '';
		}
		foreach ( $this->get_catalogue() as $templates ) {
			if ( isset( $templates[ $type ] ) ) {
				return $type;
			}
		}
		return '';
	}

	// ── DB helpers ────────────────────────────────────────────────────────────

	/**
	 * Load a saved template row from bn_email_templates.
	 *
	 * Returns null when no override exists (caller should fall back to catalogue).
	 *
	 * @param string $type Template type identifier.
	 * @return object|null DB row or null.
	 */
	public function get_saved( string $type ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s", $type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $row ? $row : null;
	}

	/**
	 * Save template fields to bn_email_templates (upsert).
	 *
	 * @param string $type         Template type identifier.
	 * @param string $subject      Email subject line.
	 * @param string $preview_text Inbox preview text.
	 * @param string $body_html    HTML body.
	 * @param bool   $enabled      Whether the template is active.
	 * @return bool True on success.
	 */
	public function save( string $type, string $subject, string $preview_text, string $body_html, bool $enabled ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_templates';

		$existing = $this->get_saved( $type );
		$data     = array(
			'type'         => $type,
			'subject'      => $subject,
			'preview_text' => $preview_text,
			'body_html'    => $body_html,
			'enabled'      => $enabled ? 1 : 0,
		);
		$formats  = array( '%s', '%s', '%s', '%s', '%d' );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $data, array( 'type' => $type ), $formats, array( '%s' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $data, $formats );
		}

		return false !== $result;
	}

	/**
	 * Send a test email using the current template content.
	 *
	 * Replaces all tokens with placeholder values for preview purposes.
	 *
	 * @param string $slug Template slug.
	 * @return bool True if wp_mail() accepted the message.
	 */
	public function send_test( string $slug ): bool {
		$catalogue = $this->get_catalogue();
		$defaults  = null;

		foreach ( $catalogue as $templates ) {
			if ( isset( $templates[ $slug ] ) ) {
				$defaults = $templates[ $slug ];
				break;
			}
		}

		if ( null === $defaults ) {
			return false;
		}

		$saved   = $this->get_saved( $slug );
		$subject = $saved ? $saved->subject : $defaults['subject'];
		$body    = $saved ? $saved->body_html : $defaults['body'];

		$placeholders = array(
			'{{recipient_name}}'  => wp_get_current_user()->display_name,
			'{{follower_name}}'   => 'Test User',
			'{{follower_bio}}'    => 'A test member.',
			'{{connector_name}}'  => 'Test User',
			'{{liker_name}}'      => 'Test User',
			'{{post_excerpt}}'    => 'This is a sample post excerpt.',
			'{{commenter_name}}'  => 'Test User',
			'{{comment_excerpt}}' => 'This is a sample comment.',
			'{{mentioner_name}}'  => 'Test User',
			'{{context_excerpt}}' => 'Here is the context where you were mentioned.',
			'{{sender_name}}'     => 'Test User',
			'{{message_excerpt}}' => 'This is a sample message.',
			'{{inviter_name}}'    => 'Test User',
			'{{space_name}}'      => 'Test Space',
			'{{space_url}}'       => home_url( '/spaces/' ),
			'{{profile_url}}'     => home_url( '/members/' ),
			'{{thread_url}}'      => home_url( '/messages/' ),
			'{{requests_url}}'    => home_url( '/messages/requests/' ),
			'{{post_url}}'        => home_url( '/' ),
			'{{follow_back_url}}' => home_url( '/' ),
			'{{login_url}}'       => wp_login_url(),
			'{{reset_url}}'       => home_url( '/' ),
			'{{otp_code}}'        => '123456',
			'{{unsubscribe_url}}' => home_url( '/notifications/settings/' ),
			'{{site_name}}'       => get_bloginfo( 'name' ),
			'{{site_url}}'        => home_url(),
		);

		$subject = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject );
		$body    = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $body );

		$admin_email = get_option( 'admin_email', '' );
		if ( '' === $admin_email ) {
			return false;
		}

		return wp_mail(
			$admin_email,
			'[Test] ' . $subject,
			'<html><body>' . nl2br( $body ) . '</body></html>',
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * Handle the buddynext_email_save form action.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buddynext' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$slug         = $this->sanitize_template_type( sanitize_text_field( wp_unslash( $_POST['template_slug'] ?? '' ) ) );
		$subject      = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$preview_text = sanitize_text_field( wp_unslash( $_POST['preview_text'] ?? '' ) );
		$body_html    = wp_kses_post( wp_unslash( $_POST['body_html'] ?? '' ) );
		$enabled      = ! empty( $_POST['enabled'] );

		$saved = $this->save( $slug, $subject, $preview_text, $body_html, $enabled );

		$redirect = add_query_arg(
			array(
				'page'     => self::MENU_SLUG,
				'template' => $slug,
				'updated'  => $saved ? '1' : '0',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the buddynext_email_test form action.
	 *
	 * @return void
	 */
	public function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buddynext' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$slug = $this->sanitize_template_type( sanitize_text_field( wp_unslash( $_POST['template_slug'] ?? '' ) ) );
		$sent = $this->send_test( $slug );

		$redirect = add_query_arg(
			array(
				'page'     => self::MENU_SLUG,
				'template' => $slug,
				'tested'   => $sent ? '1' : '0',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the buddynext_email_reset form action.
	 *
	 * Deletes the saved override row so the catalogue defaults apply again.
	 *
	 * @return void
	 */
	public function handle_reset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buddynext' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		global $wpdb;
		$slug  = $this->sanitize_template_type( sanitize_text_field( wp_unslash( $_POST['template_slug'] ?? '' ) ) );
		$table = $wpdb->prefix . 'bn_email_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'type' => $slug ), array( '%s' ) );

		$redirect = add_query_arg(
			array(
				'page'     => self::MENU_SLUG,
				'template' => $slug,
				'reset'    => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render the full Email Template Editor admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buddynext' ) );
		}

		$catalogue   = $this->get_catalogue();
		$active_slug = $this->sanitize_template_type( sanitize_text_field( wp_unslash( $_GET['template'] ?? 'bn.new_follower' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_def  = null;

		foreach ( $catalogue as $templates ) {
			if ( isset( $templates[ $active_slug ] ) ) {
				$active_def = $templates[ $active_slug ];
				break;
			}
		}

		if ( null === $active_def ) {
			// Fallback to first template.
			$first_category = reset( $catalogue );
			$active_slug    = (string) array_key_first( $first_category );
			$active_def     = $first_category[ $active_slug ];
		}

		$saved        = $this->get_saved( $active_slug );
		$subject      = $saved ? $saved->subject : $active_def['subject'];
		$preview_text = $saved ? $saved->preview_text : $active_def['preview'];
		$body_html    = $saved ? $saved->body_html : $active_def['body'];
		$enabled      = $saved ? (bool) $saved->enabled : true;

		// Admin notices.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = absint( $_GET['updated'] ?? - 1 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tested = absint( $_GET['tested'] ?? - 1 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reset = absint( $_GET['reset'] ?? - 1 );

		?>
		<div class="wrap buddynext-email-editor">

		<?php if ( 1 === $updated ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template saved.', 'buddynext' ); ?></p></div>
		<?php elseif ( 0 === $updated ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Save failed. Please try again.', 'buddynext' ); ?></p></div>
		<?php endif; ?>

		<?php if ( 1 === $tested ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test email sent.', 'buddynext' ); ?></p></div>
		<?php elseif ( 0 === $tested ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Test email failed. Check wp_mail() configuration.', 'buddynext' ); ?></p></div>
		<?php endif; ?>

		<?php if ( 1 === $reset ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template reset to defaults.', 'buddynext' ); ?></p></div>
		<?php endif; ?>

		<style>
		.buddynext-email-editor { padding-top: 0; }
		.bn-email-shell { display: grid; grid-template-columns: 280px 1fr; min-height: calc(100vh - 80px); background: #f0f0f1; gap: 0; }
		.bn-email-list { background: #fff; border-right: 1px solid #e9ecef; display: flex; flex-direction: column; }
		.bn-email-list-header { padding: 16px; border-bottom: 1px solid #e9ecef; }
		.bn-email-list-title { font-weight: 700; font-size: 15px; margin-bottom: 10px; }
		.bn-email-section { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #9ca3af; padding: 10px 14px 4px; }
		.bn-email-item { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f9fafb; text-decoration: none; color: inherit; display: block; }
		.bn-email-item:hover { background: #f9fafb; }
		.bn-email-item.active { background: #eff6ff; border-left: 3px solid #0073aa; }
		.bn-email-item-name { font-weight: 600; font-size: 12.5px; margin-bottom: 2px; }
		.bn-email-item-trigger { font-size: 11px; color: #9ca3af; }
		.bn-email-badge { display: inline-block; font-size: 9px; padding: 1px 5px; border-radius: 6px; font-weight: 700; margin-left: 4px; }
		.bn-badge-on  { background: #d1fae5; color: #065f46; }
		.bn-badge-off { background: #f3f4f6; color: #9ca3af; }
		.bn-email-area { display: flex; flex-direction: column; }
		.bn-email-header { padding: 14px 20px; border-bottom: 1px solid #e9ecef; background: #fff; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
		.bn-email-header-title { font-size: 15px; font-weight: 700; flex: 1; min-width: 100px; }
		.bn-email-grid { display: grid; grid-template-columns: 1fr 380px; flex: 1; overflow: hidden; }
		.bn-email-form { padding: 20px; overflow-y: auto; background: #fafafa; }
		.bn-form-field { margin-bottom: 16px; }
		.bn-form-field label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 5px; color: #374151; }
		.bn-form-field .hint { font-size: 10px; color: #9ca3af; margin-top: 3px; }
		.bn-text-input { border: 1.5px solid #e9ecef; border-radius: 6px; padding: 8px 10px; font-size: 13px; width: 100%; background: #fff; }
		.bn-text-input:focus { outline: none; border-color: #0073aa; }
		textarea.bn-text-input { resize: vertical; min-height: 180px; font-family: monospace; font-size: 12px; }
		.bn-enabled-row { display: flex; align-items: center; justify-content: space-between; background: #fff; border: 1px solid #e9ecef; border-radius: 6px; padding: 12px 14px; margin-bottom: 16px; }
		.bn-token-picker { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 14px; margin-bottom: 16px; }
		.bn-token-picker-title { font-weight: 700; font-size: 12px; margin-bottom: 10px; }
		.bn-tokens-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; }
		.bn-token { background: #f3f4f6; border-radius: 4px; padding: 5px 8px; font-size: 11px; font-family: monospace; cursor: pointer; display: flex; align-items: center; justify-content: space-between; border: none; width: 100%; text-align: left; }
		.bn-token:hover { background: #dbeafe; color: #0073aa; }
		.bn-token-desc { font-size: 9px; color: #9ca3af; font-family: sans-serif; }
		.bn-preview-side { background: #e5e7eb; padding: 20px; overflow-y: auto; border-left: 1px solid #e9ecef; }
		.bn-preview-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; margin-bottom: 12px; }
		.bn-email-frame { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; }
		.bn-ef-header { background: #0073aa; padding: 24px; text-align: center; color: #fff; }
		.bn-ef-logo { font-size: 20px; font-weight: 800; margin-bottom: 4px; }
		.bn-ef-body { padding: 28px 24px; }
		.bn-ef-footer { background: #f9fafb; padding: 16px 24px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e9ecef; }
		</style>

		<div class="bn-email-shell">

			<!-- Template list -->
			<div class="bn-email-list">
				<div class="bn-email-list-header">
					<div class="bn-email-list-title"><?php esc_html_e( 'Email Templates', 'buddynext' ); ?></div>
				</div>
				<div style="overflow-y:auto;flex:1;">
				<?php foreach ( $catalogue as $category => $templates ) : ?>
					<div class="bn-email-section"><?php echo esc_html( $category ); ?></div>
					<?php
					foreach ( $templates as $slug => $tpl ) :
						$row       = $this->get_saved( $slug );
						$is_on     = $row ? (bool) $row->enabled : true;
						$is_active = ( $slug === $active_slug );
						$item_url  = add_query_arg(
							array(
								'page'     => self::MENU_SLUG,
								'template' => $slug,
							),
							admin_url( 'admin.php' )
						);
						?>
					<a href="<?php echo esc_url( $item_url ); ?>" class="bn-email-item<?php echo $is_active ? ' active' : ''; ?>">
						<div class="bn-email-item-name">
							<?php echo esc_html( $tpl['name'] ); ?>
							<span class="bn-email-badge <?php echo $is_on ? 'bn-badge-on' : 'bn-badge-off'; ?>">
								<?php echo $is_on ? esc_html__( 'On', 'buddynext' ) : esc_html__( 'Off', 'buddynext' ); ?>
							</span>
						</div>
						<div class="bn-email-item-trigger"><?php echo esc_html( $tpl['trigger'] ); ?></div>
					</a>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</div>
			</div>

			<!-- Editor area -->
			<div class="bn-email-area">
				<div class="bn-email-header">
					<div class="bn-email-header-title"><?php echo esc_html( $active_def['name'] ); ?></div>
					<!-- Reset form -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="buddynext_email_reset">
						<input type="hidden" name="template_slug" value="<?php echo esc_attr( $active_slug ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<button type="submit" class="button"><?php esc_html_e( '↺ Reset to default', 'buddynext' ); ?></button>
					</form>
					<!-- Test email form -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="buddynext_email_test">
						<input type="hidden" name="template_slug" value="<?php echo esc_attr( $active_slug ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Send test email', 'buddynext' ); ?></button>
					</form>
				</div>

				<div class="bn-email-grid">
					<!-- Form side -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-email-form">
						<input type="hidden" name="action" value="buddynext_email_save">
						<input type="hidden" name="template_slug" value="<?php echo esc_attr( $active_slug ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>

						<div class="bn-enabled-row">
							<div>
								<strong><?php esc_html_e( 'Email enabled', 'buddynext' ); ?></strong>
								<p style="font-size:11px;color:#6b7280;margin-top:2px;">
									<?php esc_html_e( 'Users can override this in their notification settings.', 'buddynext' ); ?>
								</p>
							</div>
							<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
								<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>>
								<?php esc_html_e( 'Enabled', 'buddynext' ); ?>
							</label>
						</div>

						<div class="bn-form-field">
							<label for="bn-subject"><?php esc_html_e( 'Subject Line', 'buddynext' ); ?></label>
							<input id="bn-subject" class="bn-text-input" type="text" name="subject" value="<?php echo esc_attr( $subject ); ?>">
						</div>

						<div class="bn-form-field">
							<label for="bn-preview">
								<?php esc_html_e( 'Preview text', 'buddynext' ); ?>
								<span style="font-weight:400;color:#9ca3af;"><?php esc_html_e( '(shown in inbox before opening)', 'buddynext' ); ?></span>
							</label>
							<input id="bn-preview" class="bn-text-input" type="text" name="preview_text" value="<?php echo esc_attr( $preview_text ); ?>">
						</div>

						<div class="bn-form-field">
							<label for="bn-body"><?php esc_html_e( 'Body (HTML)', 'buddynext' ); ?></label>
							<textarea id="bn-body" class="bn-text-input" name="body_html" rows="12"><?php echo esc_textarea( $body_html ); ?></textarea>
						</div>

						<div class="bn-token-picker">
							<div class="bn-token-picker-title"><?php esc_html_e( 'Available Tokens (click to insert)', 'buddynext' ); ?></div>
							<div class="bn-tokens-grid">
								<?php
								foreach ( $active_def['tokens'] as $token ) :
									$desc = str_replace( array( '{{', '}}', '_' ), array( '', '', ' ' ), $token );
									?>
								<button type="button" class="bn-token" onclick="document.getElementById('bn-body').value += '<?php echo esc_js( $token ); ?>';">
									<?php echo esc_html( $token ); ?>
									<span class="bn-token-desc"><?php echo esc_html( ucfirst( $desc ) ); ?></span>
								</button>
								<?php endforeach; ?>
							</div>
						</div>

						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Template', 'buddynext' ); ?></button>
					</form>

					<!-- Preview side -->
					<div class="bn-preview-side">
						<div class="bn-preview-label"><?php esc_html_e( 'Preview', 'buddynext' ); ?></div>
						<div class="bn-email-frame">
							<div class="bn-ef-header">
								<div class="bn-ef-logo"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
							</div>
							<div class="bn-ef-body">
								<div style="font-size:15px;font-weight:700;margin-bottom:12px;">
									<?php echo esc_html( $active_def['name'] ); ?>
								</div>
								<div style="font-size:13px;color:#374151;line-height:1.7;white-space:pre-wrap;">
									<?php echo wp_kses_post( $body_html ); ?>
								</div>
							</div>
							<div class="bn-ef-footer">
								<?php
								printf(
									/* translators: %s: site name */
									esc_html__( 'Sent by %s · Powered by BuddyNext', 'buddynext' ),
									esc_html( get_bloginfo( 'name' ) )
								);
								?>
							</div>
						</div>
					</div>
				</div>
			</div><!-- .bn-email-area -->

		</div><!-- .bn-email-shell -->
		</div><!-- .wrap -->
		<?php
	}
}
