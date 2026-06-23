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
		add_action( 'admin_post_buddynext_email_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_buddynext_email_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_buddynext_email_reset', array( $this, 'handle_reset' ) );

		// Slug 'templates' avoids collision with Settings → Email tab.
		// Label "Email Templates" disambiguates from the email-sender
		// configuration over in Settings → Email.
		AdminHub::register_tab(
			'settings',
			'templates',
			__( 'Email Templates', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'group'    => __( 'Advanced', 'buddynext' ),
				'layout'   => 'wide', // split-pane editor needs edge-to-edge room.
				'subtitle' => __( 'Edit, enable, and test the transactional emails BuddyNext sends to members.', 'buddynext' ),
			)
		);
	}

	/**
	 * Build a URL to the Email Templates editor inside the Settings hub.
	 *
	 * The editor lives as the Settings → "Email Templates" tab (registered via
	 * AdminHub::register_tab in register()), not a standalone admin page, so the
	 * rail links and post-action redirects must target the hub tab. Pointing
	 * them at a standalone page slug produced WordPress's "Sorry, you are not
	 * allowed to access this page" error because that page is never registered.
	 *
	 * @param array<string,string> $args Extra query args (e.g. template, updated).
	 * @return string Admin URL for the Email Templates tab.
	 */
	private function hub_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'buddynext-notifications',
					'tab'  => 'templates',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	// ── Template catalogue ────────────────────────────────────────────────────

	/**
	 * Return the catalogue of built-in templates grouped by category.
	 *
	 * @return array<string, array<string, array<string, string|list<string>>>>
	 *   [ 'Category' => [ 'slug' => [ 'name', 'trigger', 'tokens', 'subject', 'preview', 'body' ] ] ]
	 *   The 'tokens' key holds a list<string>; all other keys are plain strings.
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
				'bn.connection_declined'  => array(
					'name'    => __( 'Connection Declined', 'buddynext' ),
					'trigger' => __( 'When a connection request is not accepted', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}', '{{site_url}}', '{{unsubscribe_url}}' ),
					'subject' => 'An update on your connection request on {{site_name}}',
					'preview' => 'An update on your connection request.',
					'body'    => "Hi {{recipient_name}},\n\nYour recent connection request on {{site_name}} wasn't accepted. No worries — there are plenty of other members to connect with.\n\n<a href=\"{{site_url}}\">Explore the community →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a> from this type of email.",
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
				'bn.new_message'          => array(
					'name'    => __( 'New Message', 'buddynext' ),
					'trigger' => __( 'When you receive a direct message', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{sender_name}}', '{{action_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => 'New message from {{sender_name}} on {{site_name}}',
					'preview' => '{{sender_name}} sent you a message.',
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{sender_name}}</strong> sent you a direct message on {{site_name}}.\n\n<a href=\"{{action_url}}\">Read it →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
				),
				'bn.media_favorited'      => array(
					'name'    => __( 'Media Favorited', 'buddynext' ),
					'trigger' => __( 'When someone favorites your media', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{actor_name}}', '{{action_url}}', '{{site_name}}', '{{unsubscribe_url}}' ),
					'subject' => '{{actor_name}} favorited your media on {{site_name}}',
					'preview' => '{{actor_name}} favorited your media.',
					'body'    => "Hi {{recipient_name}},\n\n<strong>{{actor_name}}</strong> favorited your media on {{site_name}}.\n\n<a href=\"{{action_url}}\">View it →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a>",
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
				'bn.strike_issued'             => array(
					'name'    => __( 'Strike Issued', 'buddynext' ),
					'trigger' => __( 'When a moderation strike is issued to a member', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}' ),
					'subject' => 'A moderation action has been taken on your account — {{site_name}}',
					'preview' => 'Important account notice.',
					'body'    => "Hi {{recipient_name}},\n\nA moderation strike has been issued on your account at <strong>{{site_name}}</strong>. Please review the community guidelines to avoid further action.",
				),
				'bn.strike_warning'            => array(
					'name'    => __( 'Strike Warning', 'buddynext' ),
					'trigger' => __( 'When a member is warned (approaching the strike threshold)', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}' ),
					'subject' => 'A warning about your account on {{site_name}}',
					'preview' => 'Please review the community guidelines.',
					'body'    => "Hi {{recipient_name}},\n\nThis is a warning regarding activity on your account at <strong>{{site_name}}</strong>. Please review the community guidelines to keep your account in good standing.",
				),
				'bn.member_suspended'          => array(
					'name'    => __( 'Member Suspended', 'buddynext' ),
					'trigger' => __( 'When a member is suspended', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}' ),
					'subject' => 'Your account on {{site_name}} has been suspended',
					'preview' => 'Your account has been suspended.',
					'body'    => "Hi {{recipient_name}},\n\nYour account at <strong>{{site_name}}</strong> has been suspended following a review of community guideline violations. If you believe this was a mistake, you can submit an appeal.",
				),
				'bn.appeal_resolved'           => array(
					'name'    => __( 'Appeal Resolved', 'buddynext' ),
					'trigger' => __( 'When a member\'s appeal is decided', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}' ),
					'subject' => 'An update on your appeal — {{site_name}}',
					'preview' => 'Your appeal has been reviewed.',
					'body'    => "Hi {{recipient_name}},\n\nYour appeal on <strong>{{site_name}}</strong> has been reviewed. Check your account for the outcome and next steps.",
				),
				'bn.unsuspension_confirmation' => array(
					'name'    => __( 'Unsuspension Confirmation', 'buddynext' ),
					'trigger' => __( 'When a member\'s suspension is lifted', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}', '{{site_url}}' ),
					'subject' => 'Your account on {{site_name}} has been restored',
					'preview' => 'Welcome back — your account is active again.',
					'body'    => "Hi {{recipient_name}},\n\nGood news — your account at <strong>{{site_name}}</strong> has been restored and you can participate again.\n\n<a href=\"{{site_url}}\">Return to the community →</a>",
				),
				'bn.new_report'                => array(
					'name'    => __( 'New Report (admin)', 'buddynext' ),
					'trigger' => __( 'When content is reported (sent to moderators)', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}', '{{action_url}}' ),
					'subject' => 'New content report on {{site_name}}',
					'preview' => 'A member reported content for review.',
					'body'    => "Hi {{recipient_name}},\n\nA new report was filed on <strong>{{site_name}}</strong> and is awaiting review.\n\n<a href=\"{{action_url}}\">Open the moderation queue →</a>",
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
				'welcome'              => array(
					'name'    => __( 'Welcome Email', 'buddynext' ),
					'trigger' => __( 'Sent on registration', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{login_url}}', '{{site_name}}' ),
					'subject' => 'Welcome to {{site_name}}!',
					'preview' => "Get started with {{site_name}} — here's everything you need.",
					'body'    => "Hi {{recipient_name}},\n\nWelcome to <strong>{{site_name}}</strong>! We're excited to have you.\n\n<a href=\"{{login_url}}\">Get started →</a>",
				),
				'email_verify'         => array(
					'name'    => __( 'Email Verification', 'buddynext' ),
					'trigger' => __( 'Verify email address (OTP)', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{otp_code}}', '{{site_name}}' ),
					'subject' => '{{otp_code}} is your {{site_name}} verification code',
					'preview' => 'Enter this code to verify your email address.',
					'body'    => "Hi {{recipient_name}},\n\nYour verification code for {{site_name}} is:\n\n<strong style=\"font-size:28px;letter-spacing:4px;\">{{otp_code}}</strong>\n\nThis code expires in 15 minutes.",
				),
				'email_change_confirm' => array(
					'name'    => __( 'Email Change Confirmation', 'buddynext' ),
					'trigger' => __( 'When a member requests an email-address change', 'buddynext' ),
					'tokens'  => array( '{{user_name}}', '{{site_name}}', '{{verify_url}}' ),
					'subject' => 'Confirm your new email address on {{site_name}}',
					'preview' => 'Confirm your new email address.',
					'body'    => "Hi {{user_name}},\n\nYou asked to change the email address on your {{site_name}} account to this inbox. Confirm the change below:\n\n<a href=\"{{verify_url}}\">Confirm email change →</a>\n\nIf you didn't request this, you can ignore this email.",
				),
			),
			__( 'Digests', 'buddynext' )      => array(
				'bn.daily_digest'  => array(
					'name'    => __( 'Daily Digest', 'buddynext' ),
					'trigger' => __( 'Daily summary of community activity', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}', '{{site_url}}', '{{unsubscribe_url}}' ),
					'subject' => 'Your daily digest from {{site_name}}',
					'preview' => "Here's what happened today.",
					'body'    => "Hi {{recipient_name}},\n\nHere's a summary of recent activity on {{site_name}}.\n\n<a href=\"{{site_url}}\">Catch up on the community →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a> from digests.",
				),
				'bn.weekly_digest' => array(
					'name'    => __( 'Weekly Digest', 'buddynext' ),
					'trigger' => __( 'Weekly summary of community activity', 'buddynext' ),
					'tokens'  => array( '{{recipient_name}}', '{{site_name}}', '{{site_url}}', '{{unsubscribe_url}}' ),
					'subject' => 'Your weekly digest from {{site_name}}',
					'preview' => "Here's what happened this week.",
					'body'    => "Hi {{recipient_name}},\n\nHere's a summary of this week's activity on {{site_name}}.\n\n<a href=\"{{site_url}}\">Catch up on the community →</a>\n\n<a href=\"{{unsubscribe_url}}\">Unsubscribe</a> from digests.",
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
	 * Whether the bn_email_templates table exists.
	 *
	 * Guards the save/reset/test handlers: if activation failed, the table was
	 * dropped, or a DB restore was incomplete, the table can be missing — in
	 * which case the write operations would silently fail with no admin feedback.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Bail with a clear admin message when the templates table is missing,
	 * rather than letting a save/reset/test silently no-op.
	 *
	 * @return void
	 */
	private function ensure_table_or_die(): void {
		if ( $this->table_exists() ) {
			return;
		}
		wp_die(
			esc_html__( 'The BuddyNext email templates table is missing. Please deactivate and reactivate BuddyNext to recreate it, then try again.', 'buddynext' ),
			esc_html__( 'Database table missing', 'buddynext' ),
			array(
				'back_link' => true,
				'response'  => 500,
			)
		);
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
	 * @param string $slug      Template slug.
	 * @param string $recipient Optional recipient; falls back to the admin email.
	 * @return bool True if wp_mail() accepted the message.
	 */
	public function send_test( string $slug, string $recipient = '' ): bool {
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

		// Send to the admin's chosen test recipient when given, else the admin email.
		$recipient = sanitize_email( $recipient );
		$to        = '' !== $recipient && is_email( $recipient ) ? $recipient : (string) get_option( 'admin_email', '' );
		if ( '' === $to ) {
			return false;
		}

		// Route through the shared identity helper + branded shell so the test
		// mirrors a real send exactly: same From name/address + Reply-To
		// (Settings → Email) and the same branded wrapper, not wp_mail() defaults.
		return \BuddyNext\Notifications\EmailSender::send_with_identity(
			$to,
			'[Test] ' . $subject,
			\BuddyNext\Notifications\EmailSender::brand_wrap( nl2br( $body ), $subject ),
			\BuddyNext\Notifications\EmailSender::build_identity_headers()
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

		$this->ensure_table_or_die();

		$slug         = $this->sanitize_template_type( sanitize_text_field( wp_unslash( $_POST['template_slug'] ?? '' ) ) );
		$subject      = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$preview_text = sanitize_text_field( wp_unslash( $_POST['preview_text'] ?? '' ) );
		$body_html    = wp_kses_post( wp_unslash( $_POST['body_html'] ?? '' ) );
		$enabled      = ! empty( $_POST['enabled'] );

		$saved = $this->save( $slug, $subject, $preview_text, $body_html, $enabled );

		$redirect = $this->hub_url(
			array(
				'template' => $slug,
				'updated'  => $saved ? '1' : '0',
			)
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

		$this->ensure_table_or_die();

		$slug      = $this->sanitize_template_type( sanitize_text_field( wp_unslash( $_POST['template_slug'] ?? '' ) ) );
		$recipient = sanitize_email( wp_unslash( $_POST['bn_test_recipient'] ?? '' ) );
		$sent      = $this->send_test( $slug, $recipient );

		$redirect = $this->hub_url(
			array(
				'template' => $slug,
				'tested'   => $sent ? '1' : '0',
			)
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

		$this->ensure_table_or_die();

		global $wpdb;
		$slug  = $this->sanitize_template_type( sanitize_text_field( wp_unslash( $_POST['template_slug'] ?? '' ) ) );
		$table = $wpdb->prefix . 'bn_email_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'type' => $slug ), array( '%s' ) );

		$redirect = $this->hub_url(
			array(
				'template' => $slug,
				'reset'    => '1',
			)
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Map a category label to a `.bn-badge[data-tone]` value.
	 *
	 * @param string $category Catalogue category key (translated).
	 * @return string A v2 badge tone token.
	 */
	private function category_tone( string $category ): string {
		$map = array(
			'Social'       => 'accent',
			'Spaces'       => 'info',
			'Moderation'   => 'warn',
			'Gamification' => 'events',
			'Jetonomy'     => 'jetonomy',
			'Auth'         => 'success',
			'Onboarding'   => 'media',
		);
		foreach ( $map as $needle => $tone ) {
			if ( false !== stripos( $category, $needle ) ) {
				return $tone;
			}
		}
		return 'accent';
	}

	/**
	 * Render the full Email Template Editor admin page.
	 *
	 * Composition follows docs/v2 Plans/PLAN.md Part 3 + v2/dm-thread.html:
	 *   .adm-topbar  → title + global actions (Send test, Save)
	 *   .bn-split    → two-pane primitive
	 *     .bn-split__rail      — categorised template list (left)
	 *     .bn-split__pane      — editor surface (right)
	 *       .bn-tabs           — HTML / Plain / Preview switcher
	 *       .bn-modal-backdrop — Send-test + Reset confirms
	 *
	 * Inline styles + scripts are NOT emitted; rules live in bn-admin.css
	 * (EmailEditor section) and assets/js/admin/email-editor.js, both
	 * enqueued via Core\AssetService::enqueue_admin_assets().
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

		// Resolve the active template's category for the pane head badge.
		$active_category = '';
		foreach ( $catalogue as $cat_label => $templates ) {
			if ( isset( $templates[ $active_slug ] ) ) {
				$active_category = $cat_label;
				break;
			}
		}

		// Admin notices — null when the query param is absent (no notice
		// renders), 0 on failure, 1 on success. The previous code used
		// `absint( $_GET['x'] ?? -1 )` which returned 1 for missing params
		// (absint of -1 = 1), so every page render flashed all 3 success
		// banners. The notice-clear JS hid the symptom; this fixes the cause.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tested = isset( $_GET['tested'] ) ? absint( $_GET['tested'] ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reset = isset( $_GET['reset'] ) ? absint( $_GET['reset'] ) : null;

		$plain_body = trim( wp_strip_all_tags( $body_html ) );
		$admin_post = admin_url( 'admin-post.php' );

		?>
		<div class="bn-email-editor">

		<?php
		/*
		 * Notices opt in to auto-clear (strip the matching query-string param
		 * via history.replaceState on load) and auto-fade after 5s. Handled
		 * by bn-admin-dialogs.js — no inline JS per skill gate F2.
		 */
		?>
		<?php if ( 1 === $updated ) : ?>
			<div class="notice notice-success is-dismissible" data-bn-clear-param="updated" data-bn-auto-dismiss="5000"><p><?php esc_html_e( 'Template saved.', 'buddynext' ); ?></p></div>
		<?php elseif ( 0 === $updated ) : ?>
			<div class="notice notice-error is-dismissible" data-bn-clear-param="updated"><p><?php esc_html_e( 'Save failed. Please try again.', 'buddynext' ); ?></p></div>
		<?php endif; ?>

		<?php if ( 1 === $tested ) : ?>
			<div class="notice notice-success is-dismissible" data-bn-clear-param="tested" data-bn-auto-dismiss="5000"><p><?php esc_html_e( 'Test email sent.', 'buddynext' ); ?></p></div>
		<?php elseif ( 0 === $tested ) : ?>
			<div class="notice notice-error is-dismissible" data-bn-clear-param="tested"><p><?php esc_html_e( 'Test email failed. Check wp_mail() configuration.', 'buddynext' ); ?></p></div>
		<?php endif; ?>

		<?php if ( 1 === $reset ) : ?>
			<div class="notice notice-success is-dismissible" data-bn-clear-param="reset" data-bn-auto-dismiss="5000"><p><?php esc_html_e( 'Template reset to defaults.', 'buddynext' ); ?></p></div>
		<?php endif; ?>

		<?php
		// Save / Send-test buttons moved into the pane head so they sit next
		// to the template title + status toggle (Layer 3 polish). The empty
		// .adm-topbar that used to host them is gone — its only job was to
		// surface those actions, and they're better placed in context.
		?>
		<div class="bn-split">

			<!-- Template list rail -->
			<nav class="bn-split__rail" aria-label="<?php esc_attr_e( 'Email templates', 'buddynext' ); ?>">
				<div class="bn-email-editor__rail-search">
					<label class="screen-reader-text" for="bn-email-rail-search">
						<?php esc_html_e( 'Search templates', 'buddynext' ); ?>
					</label>
					<input
						type="search"
						id="bn-email-rail-search"
						class="bn-input"
						placeholder="<?php esc_attr_e( 'Filter templates…', 'buddynext' ); ?>"
						data-bn-rail-filter
						aria-controls="bn-email-rail-list"
					>
				</div>
				<div id="bn-email-rail-list" class="bn-email-editor__rail-list">
				<?php foreach ( $catalogue as $category => $templates ) : ?>
					<div class="bn-email-editor__rail-group"><?php echo esc_html( $category ); ?></div>
					<?php
					$category_tone = $this->category_tone( (string) $category );
					foreach ( $templates as $slug => $tpl ) :
						$row       = $this->get_saved( $slug );
						$is_on     = $row ? (bool) $row->enabled : true;
						$is_active = ( $slug === $active_slug );
						$item_url  = $this->hub_url( array( 'template' => $slug ) );
						?>
						<a
							href="<?php echo esc_url( $item_url ); ?>"
							class="bn-split__rail-item"
							<?php
							if ( $is_active ) :
								?>
								aria-current="true"<?php endif; ?>
						>
							<div class="bn-email-editor__rail-item-row">
								<span class="bn-split__rail-item-title"><?php echo esc_html( $tpl['name'] ); ?></span>
								<span
									class="bn-badge"
									data-tone="<?php echo esc_attr( $is_on ? 'success' : 'info' ); ?>"
								>
									<?php
									echo $is_on
										? esc_html__( 'Enabled', 'buddynext' )
										: esc_html__( 'Disabled', 'buddynext' );
									?>
								</span>
							</div>
							<span class="bn-split__rail-item-meta"><?php echo esc_html( $tpl['trigger'] ); ?></span>
						</a>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</div><!-- /#bn-email-rail-list -->
			</nav>

			<!-- Editor pane -->
			<section class="bn-split__pane" aria-label="<?php esc_attr_e( 'Template editor', 'buddynext' ); ?>">

				<header class="bn-split__pane-head bn-email-editor__pane-head">
					<div class="bn-email-editor__pane-title">
						<span class="bn-email-editor__pane-name"><?php echo esc_html( $active_def['name'] ); ?></span>
						<span class="bn-email-editor__pane-trigger"><?php echo esc_html( $active_def['trigger'] ); ?></span>
					</div>
					<?php if ( '' !== $active_category ) : ?>
						<span class="bn-badge" data-tone="<?php echo esc_attr( $this->category_tone( $active_category ) ); ?>">
							<?php echo esc_html( $active_category ); ?>
						</span>
					<?php endif; ?>
					<div class="bn-email-editor__pane-actions">
						<label
							class="bn-email-editor__toggle-control"
							data-bn-toggle-wrap
							aria-label="<?php esc_attr_e( 'Email enabled', 'buddynext' ); ?>"
							for="bn-email-enabled"
						>
							<input
								id="bn-email-enabled"
								type="checkbox"
								name="enabled"
								value="1"
								form="bn-email-save-form"
								<?php checked( $enabled ); ?>
							>
							<span class="bn-toggle" role="presentation" aria-hidden="true"></span>
						</label>
						<button
							type="button"
							class="bn-btn"
							data-variant="ghost"
							data-size="sm"
							data-bn-open-modal="bn-email-modal-reset"
						>
							<?php esc_html_e( 'Reset to default', 'buddynext' ); ?>
						</button>
						<button
							type="button"
							class="bn-btn"
							data-variant="ghost"
							data-size="sm"
							data-bn-open-modal="bn-email-modal-test"
						>
							<?php esc_html_e( 'Send test', 'buddynext' ); ?>
						</button>
						<button
							type="submit"
							form="bn-email-save-form"
							class="bn-btn"
							data-variant="primary"
							data-size="sm"
						>
							<?php esc_html_e( 'Save template', 'buddynext' ); ?>
						</button>
					</div>
				</header>

				<div role="tablist" class="bn-tabs" aria-label="<?php esc_attr_e( 'Editor view', 'buddynext' ); ?>">
					<button
						type="button"
						role="tab"
						class="bn-tab"
						id="bn-email-tab-html"
						aria-selected="true"
						aria-controls="bn-email-panel-html"
						data-bn-tab="html"
					>
						<?php esc_html_e( 'HTML', 'buddynext' ); ?>
					</button>
					<button
						type="button"
						role="tab"
						class="bn-tab"
						id="bn-email-tab-plain"
						aria-selected="false"
						aria-controls="bn-email-panel-plain"
						tabindex="-1"
						data-bn-tab="plain"
					>
						<?php esc_html_e( 'Plain', 'buddynext' ); ?>
					</button>
					<button
						type="button"
						role="tab"
						class="bn-tab"
						id="bn-email-tab-preview"
						aria-selected="false"
						aria-controls="bn-email-panel-preview"
						tabindex="-1"
						data-bn-tab="preview"
					>
						<?php esc_html_e( 'Preview', 'buddynext' ); ?>
					</button>
				</div>

				<div class="bn-split__pane-body">

					<form
						id="bn-email-save-form"
						method="post"
						action="<?php echo esc_url( $admin_post ); ?>"
					>
						<input type="hidden" name="action" value="buddynext_email_save">
						<input type="hidden" name="template_slug" value="<?php echo esc_attr( $active_slug ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>

						<!-- HTML panel: subject + preview text + body textarea + tokens -->
						<div
							class="bn-email-editor__panel"
							role="tabpanel"
							id="bn-email-panel-html"
							aria-labelledby="bn-email-tab-html"
							data-bn-panel="html"
						>
							<div class="bn-email-editor__field">
								<label for="bn-subject" class="bn-email-editor__label">
									<?php esc_html_e( 'Subject line', 'buddynext' ); ?>
								</label>
								<input
									id="bn-subject"
									class="bn-input"
									type="text"
									name="subject"
									value="<?php echo esc_attr( $subject ); ?>"
								>
							</div>

							<div class="bn-email-editor__field">
								<label for="bn-preview" class="bn-email-editor__label">
									<?php esc_html_e( 'Preview text', 'buddynext' ); ?>
									<span class="bn-email-editor__hint">
										<?php esc_html_e( '(shown in inbox before opening)', 'buddynext' ); ?>
									</span>
								</label>
								<input
									id="bn-preview"
									class="bn-input"
									type="text"
									name="preview_text"
									value="<?php echo esc_attr( $preview_text ); ?>"
								>
							</div>

							<div class="bn-email-editor__field">
								<label for="bn-body" class="bn-email-editor__label">
									<?php esc_html_e( 'Body (HTML)', 'buddynext' ); ?>
								</label>
								<textarea
									id="bn-body"
									class="bn-textarea"
									name="body_html"
									rows="12"
									data-bn-mono
								><?php echo esc_textarea( $body_html ); ?></textarea>
							</div>

							<div class="bn-card bn-email-editor__tokens">
								<div class="bn-email-editor__tokens-title">
									<?php esc_html_e( 'Available tokens (click to insert at caret)', 'buddynext' ); ?>
								</div>
								<div class="bn-email-editor__tokens-grid">
									<?php
									$tokens = is_array( $active_def['tokens'] ) ? $active_def['tokens'] : array();
									foreach ( $tokens as $token ) :
										$desc = str_replace( array( '{{', '}}', '_' ), array( '', '', ' ' ), $token );
										?>
										<button
											type="button"
											class="bn-email-editor__token"
											data-bn-token="<?php echo esc_attr( $token ); ?>"
										>
											<span><?php echo esc_html( $token ); ?></span>
											<span class="bn-email-editor__token-desc"><?php echo esc_html( $desc ); ?></span>
										</button>
									<?php endforeach; ?>
								</div>
							</div>

							<div class="bn-email-editor__save-bar">
								<button type="submit" class="bn-btn" data-variant="primary">
									<?php esc_html_e( 'Save template', 'buddynext' ); ?>
								</button>
								<span class="bn-email-editor__hint">
									<?php esc_html_e( 'Changes apply to outgoing email immediately.', 'buddynext' ); ?>
								</span>
							</div>
						</div>
					</form>

					<!-- Plain panel: read-only stripped text preview -->
					<div
						class="bn-email-editor__panel"
						role="tabpanel"
						id="bn-email-panel-plain"
						aria-labelledby="bn-email-tab-plain"
						data-bn-panel="plain"
						hidden
					>
						<label for="bn-plain" class="bn-email-editor__label screen-reader-text">
							<?php esc_html_e( 'Plain-text rendering of the email body.', 'buddynext' ); ?>
						</label>
						<textarea
							id="bn-plain"
							class="bn-textarea"
							readonly
							data-bn-mono
							rows="16"
						><?php echo esc_textarea( $plain_body ); ?></textarea>
					</div>

					<!-- Preview panel: rendered email frame -->
					<div
						class="bn-email-editor__panel"
						role="tabpanel"
						id="bn-email-panel-preview"
						aria-labelledby="bn-email-tab-preview"
						data-bn-panel="preview"
						hidden
					>
						<iframe
							class="bn-email-editor__preview-frame"
							title="<?php esc_attr_e( 'Email preview', 'buddynext' ); ?>"
							data-bn-preview-iframe
						></iframe>
					</div>

				</div><!-- .bn-split__pane-body -->
			</section>

		</div><!-- .bn-split -->

		<!-- Send-test confirm modal -->
		<div
			class="bn-modal-backdrop"
			id="bn-email-modal-test"
			role="dialog"
			aria-modal="true"
			aria-labelledby="bn-email-modal-test-title"
			hidden
		>
			<form
				method="post"
				action="<?php echo esc_url( $admin_post ); ?>"
				class="bn-modal__panel"
			>
				<header class="bn-modal__head">
					<h2 id="bn-email-modal-test-title" class="bn-modal__title">
						<?php esc_html_e( 'Send a test email', 'buddynext' ); ?>
					</h2>
					<button
						type="button"
						class="bn-modal__close"
						data-bn-close
						aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
					>&times;</button>
				</header>
				<div class="bn-modal__body">
					<p>
						<?php esc_html_e( 'A copy of this template, with sample data, will be sent using your configured From name, address, and Reply-To.', 'buddynext' ); ?>
					</p>
					<p class="bn-field">
						<label for="bn-test-recipient"><?php esc_html_e( 'Send to', 'buddynext' ); ?></label>
						<input
							type="email"
							id="bn-test-recipient"
							name="bn_test_recipient"
							class="regular-text"
							value="<?php echo esc_attr( (string) get_option( 'admin_email', '' ) ); ?>"
							placeholder="<?php esc_attr_e( 'you@example.com', 'buddynext' ); ?>"
						>
						<span class="bn-field-hint"><?php esc_html_e( 'Defaults to the site admin email. Change it to send the test elsewhere.', 'buddynext' ); ?></span>
					</p>
					<input type="hidden" name="action" value="buddynext_email_test">
					<input type="hidden" name="template_slug" value="<?php echo esc_attr( $active_slug ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				</div>
				<footer class="bn-modal__foot">
					<button type="button" class="bn-btn" data-variant="ghost" data-bn-close>
						<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
					</button>
					<button type="submit" class="bn-btn" data-variant="primary">
						<?php esc_html_e( 'Send test', 'buddynext' ); ?>
					</button>
				</footer>
			</form>
		</div>

		<!-- Reset confirm modal -->
		<div
			class="bn-modal-backdrop"
			id="bn-email-modal-reset"
			role="dialog"
			aria-modal="true"
			aria-labelledby="bn-email-modal-reset-title"
			hidden
		>
			<form
				method="post"
				action="<?php echo esc_url( $admin_post ); ?>"
				class="bn-modal__panel"
				data-tone="danger"
			>
				<header class="bn-modal__head">
					<h2 id="bn-email-modal-reset-title" class="bn-modal__title">
						<?php esc_html_e( 'Reset to default?', 'buddynext' ); ?>
					</h2>
					<button
						type="button"
						class="bn-modal__close"
						data-bn-close
						aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
					>&times;</button>
				</header>
				<div class="bn-modal__body">
					<p>
						<?php esc_html_e( 'This will discard your subject, preview, and body changes for this template and restore the factory defaults. This cannot be undone.', 'buddynext' ); ?>
					</p>
					<input type="hidden" name="action" value="buddynext_email_reset">
					<input type="hidden" name="template_slug" value="<?php echo esc_attr( $active_slug ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				</div>
				<footer class="bn-modal__foot">
					<button type="button" class="bn-btn" data-variant="ghost" data-bn-close>
						<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
					</button>
					<button type="submit" class="bn-btn" data-variant="danger">
						<?php esc_html_e( 'Reset template', 'buddynext' ); ?>
					</button>
				</footer>
			</form>
		</div>

		</div><!-- .wrap.bn-email-editor -->
		<?php
	}
}
