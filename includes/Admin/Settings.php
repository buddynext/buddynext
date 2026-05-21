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
		'buddynext_site_name'                  => array( 'string', 'sanitize_text_field', '' ),
		'buddynext_brand_color'                => array( 'string', 'sanitize_hex_color', '#0073aa' ),
		'buddynext_description'                => array( 'string', 'sanitize_textarea_field', '' ),
		'buddynext_public_explore'             => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_enable_dm'                  => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_default_dm_access'          => array( 'string', 'sanitize_key', 'everyone' ),
		'buddynext_show_onboarding'            => array( 'boolean', 'rest_sanitize_boolean', true ),

		// Registration.
		'buddynext_reg_mode'                   => array( 'string', 'sanitize_key', 'open' ),
		'buddynext_email_verify'               => array( 'boolean', 'rest_sanitize_boolean', false ),
		'buddynext_allowed_domains'            => array( 'string', 'sanitize_textarea_field', '' ),

		// Social.
		'buddynext_default_post_privacy'       => array( 'string', 'sanitize_key', 'public' ),
		'buddynext_allow_polls'                => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_allow_shares'               => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_allow_bookmarks'            => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_enable_link_preview'        => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_enable_emoji_picker'        => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_post_edit_window'           => array( 'integer', 'absint', 60 ),

		// Spaces.
		'buddynext_enable_spaces'              => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_space_creation_role'        => array( 'string', 'sanitize_key', 'member' ),
		'buddynext_space_max_sub_spaces'       => array( 'integer', 'absint', 0 ),

		// Moderation.
		'buddynext_auto_hide_threshold'        => array( 'integer', 'absint', 5 ),
		'buddynext_strike_warn_threshold'      => array( 'integer', 'absint', 2 ),
		'buddynext_strike_suspend_threshold'   => array( 'integer', 'absint', 5 ),
		'buddynext_strike_perma_ban_threshold' => array( 'integer', 'absint', 0 ),
		'buddynext_mod_queue_alert_threshold'  => array( 'integer', 'absint', 20 ),
		'buddynext_banned_words'               => array( 'string', 'sanitize_textarea_field', '' ),
		'buddynext_blocked_domains'            => array( 'string', 'sanitize_textarea_field', '' ),
		'buddynext_banned_hashtags'            => array( 'string', 'sanitize_textarea_field', '' ),
		'buddynext_post_rate_limit'            => array( 'integer', 'absint', 10 ),
		'buddynext_new_member_post_threshold'  => array( 'integer', 'absint', 0 ),

		// Notifications.
		'buddynext_notif_default_follow'       => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_connection'   => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_reaction'     => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_comment'      => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_mention'      => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_notif_default_space_join'   => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_digest_frequency'           => array( 'string', 'sanitize_key', 'weekly' ),
		'buddynext_admin_alert_email'          => array( 'string', 'sanitize_email', '' ),

		// Email.
		'buddynext_email_from_name'            => array( 'string', 'sanitize_text_field', '' ),
		'buddynext_email_from_address'         => array( 'string', 'sanitize_email', '' ),
		'buddynext_email_reply_to'             => array( 'string', 'sanitize_email', '' ),
		'buddynext_email_footer_text'          => array( 'string', 'sanitize_textarea_field', '' ),

		// Integrations.
		'buddynext_jetonomy_feed_sync'         => array( 'boolean', 'rest_sanitize_boolean', false ),

		// Privacy & Data.
		'buddynext_google_indexing'            => array( 'string', 'sanitize_key', 'public_posts' ),
		'buddynext_cookie_consent'             => array( 'boolean', 'rest_sanitize_boolean', false ),
		'buddynext_data_retention_days'        => array( 'integer', 'absint', 365 ),
		'buddynext_allow_data_export'          => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_allow_account_deletion'     => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_anonymize_on_delete'        => array( 'boolean', 'rest_sanitize_boolean', true ),

		// Webhooks.
		'buddynext_webhook_secret'             => array( 'string', 'sanitize_text_field', '' ),
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

		// FeatureRegistry catalog persisted as a single map of slug=>bool.
		// Mandatory features are filtered out by the registry; only
		// default_on + opt_in feature states land in the option.
		register_setting(
			'buddynext',
			'buddynext_features',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_features_option' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize callback for the buddynext_features option.
	 *
	 * Coerces the submitted POST array into a slug=>bool map and persists
	 * via FeatureRegistry so the dependency + tier rules are applied.
	 *
	 * @param mixed $value Raw input.
	 * @return array<string,bool>
	 */
	public function sanitize_features_option( $value ): array {
		$cleaned = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $slug => $on ) {
				$slug             = sanitize_key( (string) $slug );
				$cleaned[ $slug ] = ! empty( $on );
			}
		}
		// Route through FeatureRegistry::persist() so tier rules apply.
		if ( function_exists( 'buddynext_service' ) ) {
			$container = \BuddyNext\Core\Container::instance();
			if ( $container->has( 'features' ) ) {
				$container->get( 'features' )->persist( $cleaned );
				// Re-read after persist so the option reflects only
				// non-mandatory slugs that survived the registry filter.
				return (array) get_option( 'buddynext_features', array() );
			}
		}
		return $cleaned;
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
			'features'      => __( 'Features', 'buddynext' ),
			'registration'  => __( 'Registration', 'buddynext' ),
			'social'        => __( 'Social', 'buddynext' ),
			'spaces'        => __( 'Spaces', 'buddynext' ),
			'notifications' => __( 'Notifications', 'buddynext' ),
			'email'         => __( 'Email', 'buddynext' ),
			'moderation'    => __( 'Moderation', 'buddynext' ),
			'integrations'  => __( 'Integrations', 'buddynext' ),
			'privacy'       => __( 'Privacy & Data', 'buddynext' ),
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
	 * Render the Features settings tab.
	 *
	 * Site-owner control over which Layer 2 features are active. Catalogue
	 * comes from FeatureRegistry. Mandatory tier is rendered as disabled
	 * (always-on, no toggle). Default-on + opt-in tier render as live
	 * toggles backed by the buddynext_features option.
	 *
	 * @return void
	 */
	private function render_tab_features(): void {
		$container = \BuddyNext\Core\Container::instance();
		if ( ! $container->has( 'features' ) ) {
			return;
		}
		$registry = $container->get( 'features' );
		$state    = (array) get_option( 'buddynext_features', array() );
		$groups   = $registry->by_group();

		$group_labels = array(
			'core'         => __( 'Core (always on)', 'buddynext' ),
			'community'    => __( 'Community features', 'buddynext' ),
			'bridges'      => __( 'Integration bridges', 'buddynext' ),
			'integrations' => __( 'Power-user integrations', 'buddynext' ),
		);

		$this->open_section( __( 'Features', 'buddynext' ) );

		echo '<p class="bn-field-hint" style="margin-top:0">' .
			esc_html__( 'Pick which features your community uses. Core features always run. You can enable or disable everything else from this tab — changes apply immediately on save.', 'buddynext' ) .
			'</p>';

		foreach ( $groups as $group_key => $features ) {
			$group_label = $group_labels[ $group_key ] ?? ucfirst( (string) $group_key );
			echo '<h3 class="bn-feature-group">' . esc_html( $group_label ) . '</h3>';
			echo '<div class="bn-feature-grid">';

			foreach ( $features as $feature ) {
				$slug        = (string) $feature['slug'];
				$tier        = (string) $feature['tier'];
				$is_mandatory = ( \BuddyNext\Core\FeatureRegistry::TIER_MANDATORY === $tier );
				$current     = $registry->is_enabled( $slug );
				$is_locked   = $is_mandatory;

				$badge_label = $is_mandatory
					? __( 'Always on', 'buddynext' )
					: ( \BuddyNext\Core\FeatureRegistry::TIER_DEFAULT_ON === $tier
						? __( 'Default on', 'buddynext' )
						: __( 'Opt-in', 'buddynext' )
					);
				$badge_tone = $is_mandatory ? 'accent' : ( \BuddyNext\Core\FeatureRegistry::TIER_DEFAULT_ON === $tier ? 'success' : 'info' );

				?>
				<div class="bn-feature-row" data-tier="<?php echo esc_attr( $tier ); ?>">
					<div class="bn-feature-row__copy">
						<div class="bn-feature-row__head">
							<span class="bn-feature-row__label"><?php echo esc_html( $feature['label'] ); ?></span>
							<span class="bn-badge" data-tone="<?php echo esc_attr( $badge_tone ); ?>"><?php echo esc_html( $badge_label ); ?></span>
						</div>
						<p class="bn-feature-row__desc"><?php echo esc_html( $feature['description'] ); ?></p>
						<?php if ( ! empty( $feature['depends_on'] ) ) : ?>
							<p class="bn-feature-row__deps">
								<?php
								printf(
									/* translators: %s: list of dependency slugs */
									esc_html__( 'Requires: %s', 'buddynext' ),
									esc_html( implode( ', ', $feature['depends_on'] ) )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
					<div class="bn-feature-row__toggle">
						<?php if ( $is_locked ) : ?>
							<span class="bn-feature-row__locked" aria-label="<?php esc_attr_e( 'This feature is always on and cannot be disabled.', 'buddynext' ); ?>">
								<?php buddynext_icon( 'lock' ); ?>
							</span>
						<?php else : ?>
							<label class="bn-toggle-label">
								<input
									type="hidden"
									name="buddynext_features[<?php echo esc_attr( $slug ); ?>]"
									value="0">
								<input
									type="checkbox"
									name="buddynext_features[<?php echo esc_attr( $slug ); ?>]"
									value="1"
									<?php checked( $current, true ); ?>
									role="switch"
									aria-label="<?php echo esc_attr( $feature['label'] ); ?>">
								<span class="bn-toggle bn-toggle--inline"></span>
							</label>
						<?php endif; ?>
					</div>
				</div>
				<?php
			}

			echo '</div>';
		}

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

		$this->open_section( __( 'Access Restrictions', 'buddynext' ) );

		$this->render_textarea_row(
			'buddynext_allowed_domains',
			__( 'Allowed email domains', 'buddynext' ),
			(string) get_option( 'buddynext_allowed_domains', '' ),
			__( 'One domain per line (e.g. mycompany.com). When set, only addresses from these domains can register. Leave blank to allow all domains.', 'buddynext' ),
			4,
			400
		);

		$invite_url = admin_url( 'admin.php?page=buddynext-members&tab=invites' );
		?>
		<div class="bn-field">
			<span class="bn-field-label" id="bn-invite-mgmt-label">
				<?php esc_html_e( 'Invite management', 'buddynext' ); ?>
			</span>
			<a href="<?php echo esc_url( $invite_url ); ?>"
				class="bn-btn"
				data-variant="secondary"
				data-size="sm"
				aria-describedby="bn-invite-mgmt-hint"
				aria-labelledby="bn-invite-mgmt-label bn-invite-mgmt-action">
				<span id="bn-invite-mgmt-action">
					<?php esc_html_e( 'Manage invitations', 'buddynext' ); ?>
				</span>
				<?php buddynext_icon( 'external-link', 'bn-btn__icon' ); ?>
			</a>
			<span class="bn-field-hint" id="bn-invite-mgmt-hint">
				<?php esc_html_e( 'Create, resend, and revoke invitations. Active in Invite Only mode.', 'buddynext' ); ?>
			</span>
		</div>
		<?php

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

		$this->render_toggle_row(
			'buddynext_enable_link_preview',
			__( 'Enable link previews', 'buddynext' ),
			__( 'When a post contains a URL, fetch and display its Open Graph preview (title, image, description).', 'buddynext' ),
			(bool) get_option( 'buddynext_enable_link_preview', true )
		);

		$this->render_toggle_row(
			'buddynext_enable_emoji_picker',
			__( 'Enable emoji picker', 'buddynext' ),
			__( 'Show the emoji picker button in the post composer and comment editor.', 'buddynext' ),
			(bool) get_option( 'buddynext_enable_emoji_picker', true )
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

		$this->render_number_row(
			'buddynext_space_max_sub_spaces',
			__( 'Max sub-spaces per space', 'buddynext' ),
			(int) get_option( 'buddynext_space_max_sub_spaces', 0 ),
			__( 'Maximum number of sub-spaces a space owner can create inside their space. Set to 0 for no limit.', 'buddynext' ),
			0
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

		$this->render_number_row(
			'buddynext_strike_perma_ban_threshold',
			__( 'Strikes before permanent ban', 'buddynext' ),
			(int) get_option( 'buddynext_strike_perma_ban_threshold', 0 ),
			__( 'The member is permanently banned after this many lifetime strikes. Set to 0 to disable automatic permanent bans.', 'buddynext' ),
			0
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

		$this->open_section( __( 'Admin Alerts', 'buddynext' ) );

		$this->render_text_row(
			'buddynext_admin_alert_email',
			__( 'Admin alert email', 'buddynext' ),
			(string) get_option( 'buddynext_admin_alert_email', get_option( 'admin_email', '' ) ),
			__( 'Receives daily alerts when the moderation queue or pending registration count is high. Defaults to WordPress admin email.', 'buddynext' ),
			320
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
		$this->open_section( __( 'Search Engine Indexing', 'buddynext' ) );

		$this->render_select_row(
			'buddynext_google_indexing',
			__( 'Allow search engines to index', 'buddynext' ),
			(string) get_option( 'buddynext_google_indexing', 'public_posts' ),
			array(
				'all'          => __( 'Everything — public posts, profiles, and spaces', 'buddynext' ),
				'public_posts' => __( 'Public posts only', 'buddynext' ),
				'none'         => __( 'Nothing — noindex all community pages', 'buddynext' ),
			),
			__( 'Controls the robots meta tag on BuddyNext front-end pages. Profiles and spaces always respect their own privacy settings regardless of this setting.', 'buddynext' )
		);

		$this->close_section();

		$this->open_section( __( 'Cookie Consent', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_cookie_consent',
			__( 'Show cookie consent notice', 'buddynext' ),
			__( 'Display a consent banner on first visit. Required in some jurisdictions (EU/GDPR). BuddyNext itself sets only functional cookies.', 'buddynext' ),
			(bool) get_option( 'buddynext_cookie_consent', false )
		);

		$this->close_section();

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
	 * Render the Integrations settings tab.
	 *
	 * Shows the status of each addon and the cross-plugin feature toggles that
	 * are configured here (per spec 16-admin-settings.md — Integrations section).
	 *
	 * @return void
	 */
	private function render_tab_integrations(): void {
		$addons = array(
			array(
				'slug'   => 'wpmediaverse',
				'label'  => 'WPMediaVerse',
				'desc'   => __( 'Social media engine — powers direct messaging, media feeds, and avatar sync.', 'buddynext' ),
				'active' => class_exists( 'WPMediaVerse\\Core\\Plugin' ),
				'url'    => admin_url( 'plugins.php?s=wpmediaverse' ),
			),
			array(
				'slug'   => 'jetonomy',
				'label'  => 'Jetonomy',
				'desc'   => __( 'Forum platform — discussion threads and structured Q&A bridged into the activity feed.', 'buddynext' ),
				'active' => class_exists( 'Jetonomy\\Plugin' ),
				'url'    => admin_url( 'plugins.php?s=jetonomy' ),
			),
			array(
				'slug'   => 'wb-gamification',
				'label'  => 'WBGamification',
				'desc'   => __( 'Points, badges, and leaderboards synced with BuddyNext activity events.', 'buddynext' ),
				'active' => class_exists( 'WBGamification\\Plugin' ),
				'url'    => admin_url( 'plugins.php?s=wb-gamification' ),
			),
			array(
				'slug'   => 'career-board',
				'label'  => 'Career Board',
				'desc'   => __( 'Job listings surfaced as feed cards; applications notify the hiring team.', 'buddynext' ),
				'active' => class_exists( 'WP_Career_Board\\Plugin' ),
				'url'    => admin_url( 'plugins.php?s=career-board' ),
			),
		);

		$this->open_section( __( 'Addon Status', 'buddynext' ) );
		?>
		<div class="bn-addon-list">
			<?php foreach ( $addons as $addon ) : ?>
			<div class="bn-addon-row" data-status="<?php echo $addon['active'] ? 'active' : 'inactive'; ?>">
				<span class="bn-addon-row__status">
					<?php if ( $addon['active'] ) : ?>
						<span class="bn-badge" data-tone="success"><?php esc_html_e( 'Active', 'buddynext' ); ?></span>
					<?php else : ?>
						<span class="bn-badge"><?php esc_html_e( 'Inactive', 'buddynext' ); ?></span>
					<?php endif; ?>
				</span>
				<div class="bn-addon-row__meta">
					<strong class="bn-addon-row__label"><?php echo esc_html( $addon['label'] ); ?></strong>
					<p class="bn-addon-row__desc"><?php echo esc_html( $addon['desc'] ); ?></p>
				</div>
				<?php if ( ! $addon['active'] ) : ?>
				<a href="<?php echo esc_url( $addon['url'] ); ?>" class="bn-addon-row__action">
					<?php esc_html_e( 'Install', 'buddynext' ); ?>
				</a>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
		$this->close_section();

		$this->open_section( __( 'Jetonomy Settings', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_jetonomy_feed_sync',
			__( 'Surface new Jetonomy discussions in activity feed', 'buddynext' ),
			__( 'When enabled, new Jetonomy forum posts appear as feed cards in the BuddyNext activity feed. Default off. Can be overridden per space.', 'buddynext' ),
			(bool) get_option( 'buddynext_jetonomy_feed_sync', false )
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
