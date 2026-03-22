<?php
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
		'buddynext_site_name'                => array( 'string', 'sanitize_text_field', '' ),
		'buddynext_brand_color'              => array( 'string', 'sanitize_hex_color', '#0073aa' ),

		// Registration.
		'buddynext_reg_mode'                 => array( 'string', 'sanitize_key', 'open' ),
		'buddynext_email_verify'             => array( 'boolean', 'rest_sanitize_boolean', false ),

		// Social.
		'buddynext_default_post_privacy'     => array( 'string', 'sanitize_key', 'public' ),
		'buddynext_allow_polls'              => array( 'boolean', 'rest_sanitize_boolean', true ),
		'buddynext_post_edit_window'         => array( 'integer', 'absint', 60 ),

		// Spaces.
		'buddynext_space_creation_role'      => array( 'string', 'sanitize_key', 'member' ),

		// Moderation.
		'buddynext_auto_hide_threshold'      => array( 'integer', 'absint', 5 ),
		'buddynext_strike_warn_threshold'    => array( 'integer', 'absint', 2 ),
		'buddynext_strike_suspend_threshold' => array( 'integer', 'absint', 5 ),

		// Webhooks.
		'buddynext_webhook_secret'           => array( 'string', 'sanitize_text_field', '' ),
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
			'general'      => __( 'General', 'buddynext' ),
			'registration' => __( 'Registration', 'buddynext' ),
			'social'       => __( 'Social', 'buddynext' ),
			'spaces'       => __( 'Spaces', 'buddynext' ),
			'moderation'   => __( 'Moderation', 'buddynext' ),
			'webhooks'     => __( 'Webhooks', 'buddynext' ),
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
		$this->open_section( __( 'Community Settings', 'buddynext' ) );

		$this->render_text_row(
			'buddynext_site_name',
			__( 'Community Name', 'buddynext' ),
			(string) get_option( 'buddynext_site_name', get_bloginfo( 'name' ) )
		);

		$this->render_text_row(
			'buddynext_brand_color',
			__( 'Brand Color', 'buddynext' ),
			(string) get_option( 'buddynext_brand_color', '#0073aa' ),
			__( 'Hex color used for buttons, links, and accents throughout the community UI.', 'buddynext' ),
			140
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
