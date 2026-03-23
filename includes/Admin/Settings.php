<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext admin settings page.
 *
 * Registers the top-level BuddyNext menu and all settings tabs:
 * General, Registration, Social, Spaces, Moderation, Webhooks.
 * Settings are stored in wp_options with the buddynext_ prefix.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Registers and renders the BuddyNext admin settings page.
 */
class Settings extends AdminPageBase {

	/**
	 * Option name for the webhook shared secret.
	 */
	private const OPTION_WEBHOOK_SECRET = 'buddynext_webhook_secret';

	/**
	 * All settings registered by this class.
	 * Format: option_name => [ type, sanitize_callback, default ].
	 *
	 * @var array<string, array{string, callable|string, mixed}>
	 */
	private const SETTINGS_MAP = array(
		// General.
		'buddynext_site_name'                 => array( 'string', 'sanitize_text_field', '' ),
		'buddynext_brand_color'               => array( 'string', 'sanitize_hex_color', '#0073aa' ),
		'buddynext_description'               => array( 'string', 'sanitize_textarea_field', '' ),
		'buddynext_public_explore'            => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_enable_dm'                 => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_default_dm_access'         => array( 'string', 'sanitize_key', 'everyone' ),
		'buddynext_show_onboarding'           => array( 'boolean', 'rest_sanitize_boolean', true ),

		// Registration.
		'buddynext_reg_mode'                  => array( 'string', 'sanitize_key', 'open' ),
		'buddynext_email_verify'              => array( 'boolean', 'rest_sanitize_boolean', false ),

		// Social.
		'buddynext_default_post_privacy'      => array( 'string', 'sanitize_key', 'public' ),
		'buddynext_allow_polls'               => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_allow_shares'              => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_allow_bookmarks'           => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_post_edit_window'          => array( 'integer', 'absint', 60 ),

		// Spaces.
		'buddynext_enable_spaces'             => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_space_creation_role'       => array( 'string', 'sanitize_key', 'member' ),

		// Moderation.
		'buddynext_auto_hide_threshold'       => array( 'integer', 'absint', 5 ),
		'buddynext_strike_warn_threshold'     => array( 'integer', 'absint', 2 ),
		'buddynext_strike_suspend_threshold'  => array( 'integer', 'absint', 5 ),
		'buddynext_mod_queue_alert_threshold' => array( 'integer', 'absint', 20 ),
		'buddynext_banned_words'              => array( 'string', 'sanitize_textarea_field', '' ),
		'buddynext_blocked_domains'           => array( 'string', 'sanitize_textarea_field', '' ),
		'buddynext_banned_hashtags'           => array( 'string', 'sanitize_textarea_field', '' ),
		'buddynext_post_rate_limit'           => array( 'integer', 'absint', 10 ),
		'buddynext_new_member_post_threshold' => array( 'integer', 'absint', 0 ),

		// Notifications.
		'buddynext_notif_default_follow'      => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_connection'  => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_reaction'    => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_comment'     => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_mention'     => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_space_join'  => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_digest_frequency'          => array( 'string', 'sanitize_key', 'weekly' ),

		// Email.
		'buddynext_email_from_name'           => array( 'string', 'sanitize_text_field', '' ),
		'buddynext_email_from_address'        => array( 'string', 'sanitize_email', '' ),
		'buddynext_email_reply_to'            => array( 'string', 'sanitize_email', '' ),
		'buddynext_email_footer_text'         => array( 'string', 'sanitize_textarea_field', '' ),

		// Privacy & Data.
		'buddynext_data_retention_days'       => array( 'integer', 'absint', 365 ),
		'buddynext_allow_data_export'         => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_allow_account_deletion'    => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_anonymize_on_delete'       => array( 'boolean', 'rest_sanitize_boolean', true ),

		// Navigation slugs.
		'buddynext_slug_activity'             => array( 'string', 'sanitize_title', 'activity' ),
		'buddynext_slug_people'               => array( 'string', 'sanitize_title', 'members' ),
		'buddynext_slug_spaces'               => array( 'string', 'sanitize_title', 'spaces' ),
		'buddynext_slug_messages'             => array( 'string', 'sanitize_title', 'messages' ),
		'buddynext_slug_notifications'        => array( 'string', 'sanitize_title', 'notifications' ),
		'buddynext_slug_auth'                 => array( 'string', 'sanitize_title', 'login' ),

		// Webhooks.
		'buddynext_webhook_secret'            => array( 'string', 'sanitize_text_field', '' ),
	);

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Hook the admin menu and settings registration into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Flush rewrite rules whenever any hub slug is changed.
		foreach ( array( 'activity', 'people', 'spaces', 'messages', 'notifications', 'auth' ) as $hub ) {
			add_action( "update_option_buddynext_slug_{$hub}", 'flush_rewrite_rules' );
		}
	}

	/**
	 * Add the top-level BuddyNext menu and the Settings sub-page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'BuddyNext', 'buddynext' ),
			__( 'BuddyNext', 'buddynext' ),
			'manage_options',
			'buddynext',
			array( $this, 'render_page' ),
			'dashicons-groups',
			30
		);

		add_submenu_page(
			'buddynext',
			__( 'BuddyNext — Settings', 'buddynext' ),
			__( 'Settings', 'buddynext' ),
			'manage_options',
			'buddynext',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register all settings with the WordPress Settings API.
	 *
	 * Registering options here ensures sanitize_callback is applied on save
	 * even though rendering is handled manually via render_content().
	 *
	 * @return void
	 */
	public function register_settings(): void {
		foreach ( self::SETTINGS_MAP as $option => $config ) {
			list( $type, $sanitize, $default ) = $config;
			register_setting(
				'buddynext',
				$option,
				array(
					'type'              => $type,
					'sanitize_callback' => $sanitize,
					'default'           => $default,
				)
			);
		}
	}

	// ── Static helper ─────────────────────────────────────────────────────────

	/**
	 * Get a BuddyNext setting value.
	 *
	 * @param string $key      Setting key without the buddynext_ prefix.
	 * @param mixed  $fallback Default value if the option is not set.
	 * @return mixed
	 */
	public static function get_setting( string $key, mixed $fallback = '' ): mixed {
		return get_option( 'buddynext_' . $key, $fallback );
	}

	// ── AdminPageBase interface ────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'BuddyNext Settings', 'buddynext' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return __( 'Configure your community platform', 'buddynext' );
	}

	/**
	 * Render the settings page content: tab bar + form with section cards.
	 *
	 * @return void
	 */
	protected function render_content(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'general' ) );
		$base_url   = admin_url( 'admin.php?page=buddynext' );

		$tabs = array(
			'general'       => __( 'General', 'buddynext' ),
			'registration'  => __( 'Registration', 'buddynext' ),
			'social'        => __( 'Social', 'buddynext' ),
			'spaces'        => __( 'Spaces', 'buddynext' ),
			'notifications' => __( 'Notifications', 'buddynext' ),
			'email'         => __( 'Email', 'buddynext' ),
			'moderation'    => __( 'Moderation', 'buddynext' ),
			'privacy'       => __( 'Privacy & Data', 'buddynext' ),
			'navigation'    => __( 'Navigation', 'buddynext' ),
			'webhooks'      => __( 'Webhooks', 'buddynext' ),
		);

		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'general';
		}

		$this->render_tab_bar( $tabs, $active_tab, $base_url );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'buddynext' ); ?>
			<?php $this->{'render_tab_' . $active_tab}(); ?>
			<?php $this->render_save_bar(); ?>
		</form>
		<?php
	}

	// ── Tab renderers ─────────────────────────────────────────────────────────

	/**
	 * Render the General settings tab.
	 *
	 * @return void
	 */
	private function render_tab_general(): void {
		$this->open_section( __( 'Community Identity', 'buddynext' ) );

		$this->render_text_row(
			'buddynext_site_name',
			__( 'Community Name', 'buddynext' ),
			(string) get_option( 'buddynext_site_name', get_bloginfo( 'name' ) ),
			__( 'Displayed in the site header, emails, and browser title.', 'buddynext' ),
			360
		);

		$this->render_text_row(
			'buddynext_brand_color',
			__( 'Brand Color', 'buddynext' ),
			(string) get_option( 'buddynext_brand_color', '#0073aa' ),
			__( 'Hex color used for buttons, links, and accents throughout the community UI.', 'buddynext' ),
			140
		);

		$this->render_textarea_row(
			'buddynext_description',
			__( 'Community Description', 'buddynext' ),
			(string) get_option( 'buddynext_description', '' ),
			__( 'Short description shown on the community landing page and in meta tags.', 'buddynext' ),
			3,
			540
		);

		$this->close_section();

		$this->open_section( __( 'Discovery', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_public_explore',
			__( 'Public explore feed', 'buddynext' ),
			__( 'Allow guests to browse the explore feed without logging in.', 'buddynext' ),
			(bool) get_option( 'buddynext_public_explore', true )
		);

		$this->close_section();

		$this->open_section( __( 'Direct Messaging', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_enable_dm',
			__( 'Enable direct messaging', 'buddynext' ),
			__( 'Allow members to send private messages. Requires the WPMediaVerse plugin.', 'buddynext' ),
			(bool) get_option( 'buddynext_enable_dm', true )
		);

		$this->render_select_row(
			'buddynext_default_dm_access',
			__( 'Who can DM me (default)', 'buddynext' ),
			(string) get_option( 'buddynext_default_dm_access', 'everyone' ),
			array(
				'everyone'    => __( 'Everyone', 'buddynext' ),
				'connections' => __( 'Connections only', 'buddynext' ),
				'followers'   => __( 'Followers only', 'buddynext' ),
			),
			__( 'Default setting applied to new accounts. Members can override this in their own privacy settings.', 'buddynext' )
		);

		$this->close_section();

		$this->open_section( __( 'Onboarding', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_show_onboarding',
			__( 'Show onboarding wizard to new members', 'buddynext' ),
			__( 'Guides new members through setting up their profile, following people, and joining spaces after first login.', 'buddynext' ),
			(bool) get_option( 'buddynext_show_onboarding', true )
		);

		$this->close_section();
	}

	/**
	 * Render the Registration settings tab.
	 *
	 * @return void
	 */
	private function render_tab_registration(): void {
		$this->open_section( __( 'Registration Settings', 'buddynext' ) );

		$this->render_select_row(
			'buddynext_reg_mode',
			__( 'Registration Mode', 'buddynext' ),
			(string) get_option( 'buddynext_reg_mode', 'open' ),
			array(
				'open'     => __( 'Open — anyone can register', 'buddynext' ),
				'invite'   => __( 'Invite Only — requires an invitation', 'buddynext' ),
				'approval' => __( 'Admin Approval — admin reviews each request', 'buddynext' ),
			),
			__( 'Controls who can create a new account on your community.', 'buddynext' )
		);

		$this->render_toggle_row(
			'buddynext_email_verify',
			__( 'Require email verification', 'buddynext' ),
			__( 'New registrations must verify their email before accessing the community.', 'buddynext' ),
			(bool) get_option( 'buddynext_email_verify', false )
		);

		$this->close_section();
	}

	/**
	 * Render the Social settings tab.
	 *
	 * @return void
	 */
	private function render_tab_social(): void {
		$this->open_section( __( 'Activity Feed', 'buddynext' ) );

		$this->render_select_row(
			'buddynext_default_post_privacy',
			__( 'Default post visibility', 'buddynext' ),
			(string) get_option( 'buddynext_default_post_privacy', 'public' ),
			array(
				'public'      => __( 'Public', 'buddynext' ),
				'followers'   => __( 'Followers only', 'buddynext' ),
				'connections' => __( 'Connections only', 'buddynext' ),
				'private'     => __( 'Only me', 'buddynext' ),
			),
			__( 'Members can override this in their own post composer.', 'buddynext' )
		);

		$this->render_toggle_row(
			'buddynext_allow_polls',
			__( 'Allow polls', 'buddynext' ),
			__( 'Members can attach a poll to their posts.', 'buddynext' ),
			(bool) get_option( 'buddynext_allow_polls', true )
		);

		$this->render_toggle_row(
			'buddynext_allow_shares',
			__( 'Allow re-shares', 'buddynext' ),
			__( 'Members can share other members\' posts to their own feed.', 'buddynext' ),
			(bool) get_option( 'buddynext_allow_shares', true )
		);

		$this->render_toggle_row(
			'buddynext_allow_bookmarks',
			__( 'Allow bookmarks', 'buddynext' ),
			__( 'Members can save posts to a private bookmarks list.', 'buddynext' ),
			(bool) get_option( 'buddynext_allow_bookmarks', true )
		);

		$this->render_number_row(
			'buddynext_post_edit_window',
			__( 'Post edit window (minutes)', 'buddynext' ),
			(int) get_option( 'buddynext_post_edit_window', 60 ),
			__( 'How many minutes after posting a member can edit their post. Set to 0 for no limit.', 'buddynext' ),
			0
		);

		$this->close_section();
	}

	/**
	 * Render the Spaces settings tab.
	 *
	 * @return void
	 */
	private function render_tab_spaces(): void {
		$this->open_section( __( 'Space Settings', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_enable_spaces',
			__( 'Enable Spaces', 'buddynext' ),
			__( 'Spaces let members form communities within your community. Disable to hide the Spaces hub entirely.', 'buddynext' ),
			(bool) get_option( 'buddynext_enable_spaces', true )
		);

		$this->render_select_row(
			'buddynext_space_creation_role',
			__( 'Who can create spaces', 'buddynext' ),
			(string) get_option( 'buddynext_space_creation_role', 'member' ),
			array(
				'member' => __( 'Any member', 'buddynext' ),
				'admin'  => __( 'Admins only', 'buddynext' ),
			),
			__( 'Restricting to admins prevents members from creating unmoderated spaces.', 'buddynext' )
		);

		$this->close_section();
	}

	/**
	 * Render the Moderation settings tab.
	 *
	 * @return void
	 */
	private function render_tab_moderation(): void {
		$this->open_section( __( 'Auto-Moderation Thresholds', 'buddynext' ) );

		$this->render_number_row(
			'buddynext_auto_hide_threshold',
			__( 'Auto-hide after N reports', 'buddynext' ),
			(int) get_option( 'buddynext_auto_hide_threshold', 5 ),
			__( 'Content is hidden automatically once it reaches this number of reports. Reviewable in the moderation queue.', 'buddynext' ),
			1
		);

		$this->render_number_row(
			'buddynext_mod_queue_alert_threshold',
			__( 'Queue alert threshold', 'buddynext' ),
			(int) get_option( 'buddynext_mod_queue_alert_threshold', 20 ),
			__( 'Send a daily email to admins when the moderation queue exceeds this many unreviewed items. Set to 0 to disable.', 'buddynext' ),
			0
		);

		$this->close_section();

		$this->open_section( __( 'Strike System', 'buddynext' ) );

		$this->render_number_row(
			'buddynext_strike_warn_threshold',
			__( 'Strikes before warning', 'buddynext' ),
			(int) get_option( 'buddynext_strike_warn_threshold', 2 ),
			__( 'A warning email is sent to the member after this many active strikes.', 'buddynext' ),
			1
		);

		$this->render_number_row(
			'buddynext_strike_suspend_threshold',
			__( 'Strikes before suspension', 'buddynext' ),
			(int) get_option( 'buddynext_strike_suspend_threshold', 5 ),
			__( 'The member is automatically suspended after this many active strikes.', 'buddynext' ),
			1
		);

		$this->close_section();

		$this->open_section( __( 'Content Safeguards', 'buddynext' ) );

		$this->render_textarea_row(
			'buddynext_banned_words',
			__( 'Banned words', 'buddynext' ),
			(string) get_option( 'buddynext_banned_words', '' ),
			__( 'One word or phrase per line. Posts containing any of these are rejected. Case-insensitive substring match.', 'buddynext' ),
			5,
			480
		);

		$this->render_textarea_row(
			'buddynext_banned_hashtags',
			__( 'Banned hashtags', 'buddynext' ),
			(string) get_option( 'buddynext_banned_hashtags', '' ),
			__( 'One hashtag per line (without the # sign). Posts using these tags are rejected.', 'buddynext' ),
			4,
			480
		);

		$this->render_textarea_row(
			'buddynext_blocked_domains',
			__( 'Blocked link domains', 'buddynext' ),
			(string) get_option( 'buddynext_blocked_domains', '' ),
			__( 'One domain per line (e.g. spam.example.com). Posts linking to these domains are rejected.', 'buddynext' ),
			4,
			480
		);

		$this->render_number_row(
			'buddynext_post_rate_limit',
			__( 'Post rate limit (per minute)', 'buddynext' ),
			(int) get_option( 'buddynext_post_rate_limit', 10 ),
			__( 'Maximum number of posts a member can create per minute. Set to 0 to disable rate limiting.', 'buddynext' ),
			0
		);

		$this->render_number_row(
			'buddynext_new_member_post_threshold',
			__( 'New member review threshold', 'buddynext' ),
			(int) get_option( 'buddynext_new_member_post_threshold', 0 ),
			__( 'Posts by members with fewer than this many published posts are held for review. Set to 0 to disable.', 'buddynext' ),
			0
		);

		$this->close_section();
	}

	/**
	 * Render the Navigation settings tab.
	 *
	 * Shows slug inputs for all 5 community hubs. Saving any slug triggers a
	 * rewrite flush via the update_option_buddynext_slug_* hooks registered
	 * in register(). Hub pages are auto-created by the installer; admins only
	 * need to set the slug (e.g. change "members" to "players").
	 *
	 * @return void
	 */
	private function render_tab_navigation(): void {
		$site_url = trailingslashit( home_url() );

		$hubs = array(
			'activity'      => array(
				'label'   => __( 'Activity Feed slug', 'buddynext' ),
				'default' => 'activity',
				'hint'    => __( 'Main activity feed and explore pages.', 'buddynext' ),
			),
			'people'        => array(
				'label'   => __( 'Members slug', 'buddynext' ),
				'default' => 'members',
				'hint'    => __( 'Member directory and individual profile pages. Change to "players", "students", "athletes", etc.', 'buddynext' ),
			),
			'spaces'        => array(
				'label'   => __( 'Spaces slug', 'buddynext' ),
				'default' => 'spaces',
				'hint'    => __( 'Spaces directory and individual space pages. Change to "groups", "clubs", "channels", etc.', 'buddynext' ),
			),
			'messages'      => array(
				'label'   => __( 'Messages slug', 'buddynext' ),
				'default' => 'messages',
				'hint'    => __( 'Direct messaging inbox and conversation threads.', 'buddynext' ),
			),
			'notifications' => array(
				'label'   => __( 'Notifications slug', 'buddynext' ),
				'default' => 'notifications',
				'hint'    => __( 'In-app notification centre.', 'buddynext' ),
			),
			'auth'          => array(
				'label'   => __( 'Login / Register slug', 'buddynext' ),
				'default' => 'login',
				'hint'    => __( 'Login and registration page. Logged-in visitors are redirected to the activity feed automatically.', 'buddynext' ),
			),
		);

		$this->open_section( __( 'Hub URL Slugs', 'buddynext' ) );
		?>
		<p style="font-size:13px;color:#6b7280;margin:0 0 20px;">
			<?php esc_html_e( 'Each hub is a single WordPress page auto-created on activation. Change the slug here to rename the URL across your entire community — no page editing required. Saving flushes rewrite rules automatically.', 'buddynext' ); ?>
		</p>
		<?php

		foreach ( $hubs as $key => $config ) {
			$option  = "buddynext_slug_{$key}";
			$value   = (string) get_option( $option, $config['default'] );
			$preview = $site_url . trailingslashit( $value );
			$hint    = $config['hint'] . ' — <strong>' . esc_url( $preview ) . '</strong>';

			$input_id = 'bn-field-' . sanitize_key( $option );
			?>
			<div class="bn-field">
				<label for="<?php echo esc_attr( $input_id ); ?>">
					<?php echo esc_html( $config['label'] ); ?>
				</label>
				<input type="text"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $option ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						class="bn-text-input regular-text"
						style="max-width:240px"
						pattern="[a-z0-9\-]+"
						title="<?php esc_attr_e( 'Lowercase letters, numbers, and hyphens only.', 'buddynext' ); ?>">
				<span class="bn-field-hint">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo wp_kses( $hint, array( 'strong' => array() ) );
					?>
				</span>
			</div>
			<?php
		}

		$this->close_section();
	}

	/**
	 * Render the Notifications settings tab.
	 *
	 * Default notification preferences applied to new user accounts.
	 * Individual users can override these from their own notification settings.
	 *
	 * @return void
	 */
	private function render_tab_notifications(): void {
		$this->open_section( __( 'Default Notification Preferences', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_notif_default_follow',
			__( 'New follower', 'buddynext' ),
			__( 'Notify users by default when someone follows them.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_follow', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_connection',
			__( 'Connection request', 'buddynext' ),
			__( 'Notify users by default when they receive a connection request.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_connection', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_reaction',
			__( 'Reaction on post', 'buddynext' ),
			__( 'Notify users by default when someone reacts to their post.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_reaction', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_comment',
			__( 'Comment on post', 'buddynext' ),
			__( 'Notify users by default when someone comments on their post.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_comment', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_mention',
			__( '@mention in post or comment', 'buddynext' ),
			__( 'Notify users by default when they are mentioned.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_mention', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_space_join',
			__( 'New space member', 'buddynext' ),
			__( 'Notify space owners by default when someone joins their space.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_space_join', true )
		);

		$this->close_section();

		$this->open_section( __( 'Email Digest', 'buddynext' ) );

		$this->render_select_row(
			'buddynext_digest_frequency',
			__( 'Digest frequency', 'buddynext' ),
			(string) get_option( 'buddynext_digest_frequency', 'weekly' ),
			array(
				'never'  => __( 'Disabled — no digest emails', 'buddynext' ),
				'daily'  => __( 'Daily', 'buddynext' ),
				'weekly' => __( 'Weekly (Sunday)', 'buddynext' ),
			),
			__( 'How often BuddyNext sends a digest of unread notifications. Individual users can opt out.', 'buddynext' )
		);

		$this->close_section();
	}

	/**
	 * Render the Email settings tab.
	 *
	 * Controls the sender identity and footer for all BuddyNext system emails.
	 *
	 * @return void
	 */
	private function render_tab_email(): void {
		$this->open_section( __( 'Sender Identity', 'buddynext' ) );

		$this->render_text_row(
			'buddynext_email_from_name',
			__( 'From name', 'buddynext' ),
			(string) get_option( 'buddynext_email_from_name', get_bloginfo( 'name' ) ),
			__( 'Display name shown in the "From:" field of all community emails.', 'buddynext' ),
			300
		);

		$this->render_text_row(
			'buddynext_email_from_address',
			__( 'From address', 'buddynext' ),
			(string) get_option( 'buddynext_email_from_address', get_option( 'admin_email', '' ) ),
			__( 'Sending address for all BuddyNext system emails. Must be a verified domain.', 'buddynext' ),
			300
		);

		$this->render_text_row(
			'buddynext_email_reply_to',
			__( 'Reply-To address', 'buddynext' ),
			(string) get_option( 'buddynext_email_reply_to', '' ),
			__( 'Optional. If set, replies to community emails go here instead of the From address.', 'buddynext' ),
			300
		);

		$this->close_section();

		$this->open_section( __( 'Email Footer', 'buddynext' ) );

		$this->render_textarea_row(
			'buddynext_email_footer_text',
			__( 'Footer text', 'buddynext' ),
			(string) get_option( 'buddynext_email_footer_text', '' ),
			__( 'Appended to the bottom of every BuddyNext email. Supports plain text only.', 'buddynext' ),
			3,
			540
		);

		$this->close_section();
	}

	/**
	 * Render the Privacy & Data settings tab.
	 *
	 * Controls data retention, member-initiated data export, and account deletion behaviour.
	 *
	 * @return void
	 */
	private function render_tab_privacy(): void {
		$this->open_section( __( 'Data Retention', 'buddynext' ) );

		$this->render_number_row(
			'buddynext_data_retention_days',
			__( 'Activity log retention (days)', 'buddynext' ),
			(int) get_option( 'buddynext_data_retention_days', 365 ),
			__( 'BuddyNext activity log entries older than this are purged automatically. Set to 0 to retain indefinitely.', 'buddynext' ),
			0,
			3650
		);

		$this->close_section();

		$this->open_section( __( 'Member Rights', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_allow_data_export',
			__( 'Allow members to export their data', 'buddynext' ),
			__( 'Adds a "Download my data" option on member profile settings. Generates a JSON archive of posts, reactions, and profile fields.', 'buddynext' ),
			(bool) get_option( 'buddynext_allow_data_export', true )
		);

		$this->render_toggle_row(
			'buddynext_allow_account_deletion',
			__( 'Allow members to delete their account', 'buddynext' ),
			__( 'Adds a "Delete account" option on member profile settings. Admins can always delete accounts regardless of this setting.', 'buddynext' ),
			(bool) get_option( 'buddynext_allow_account_deletion', true )
		);

		$this->render_toggle_row(
			'buddynext_anonymize_on_delete',
			__( 'Anonymise posts on account deletion', 'buddynext' ),
			__( 'When enabled, posts by deleted members are reassigned to an anonymous author rather than hard-deleted. Preserves community discussion threads.', 'buddynext' ),
			(bool) get_option( 'buddynext_anonymize_on_delete', true )
		);

		$this->close_section();
	}

	/**
	 * Render the Webhooks settings tab.
	 *
	 * @return void
	 */
	private function render_tab_webhooks(): void {
		$this->open_section( __( 'Webhook Secret', 'buddynext' ) );
		?>
		<div class="bn-field">
			<label for="bn-webhook-secret"><?php esc_html_e( 'Shared Secret', 'buddynext' ); ?></label>
			<input type="password"
					id="bn-webhook-secret"
					name="<?php echo esc_attr( self::OPTION_WEBHOOK_SECRET ); ?>"
					value="<?php echo esc_attr( (string) get_option( self::OPTION_WEBHOOK_SECRET, '' ) ); ?>"
					class="bn-text-input regular-text"
					autocomplete="new-password">
			<span class="bn-field-hint">
				<?php esc_html_e( 'Used to sign outgoing webhooks (HMAC-SHA256) and to verify inbound access requests at POST buddynext/v1/webhook/access. Leave blank to disable signature verification.', 'buddynext' ); ?>
			</span>
		</div>
		<?php
		$this->close_section();
	}
}
