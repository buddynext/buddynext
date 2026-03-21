<?php
/**
 * BuddyNext admin settings page.
 *
 * Registers the top-level BuddyNext menu and all settings tabs:
 * General, Registration, Social, Spaces, Notifications, Moderation,
 * Integrations, Webhooks. Settings are stored in wp_options with the
 * buddynext_ prefix.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Registers and renders the BuddyNext admin settings page.
 */
class Settings {

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
	 * Add the top-level BuddyNext menu and the General sub-page.
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
	 * Register all settings, sections, and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		foreach ( self::SETTINGS_MAP as $option => $config ) {
			list( $type, $sanitize, $default ) = $config;
			register_setting(
				'buddynext_general',
				$option,
				array(
					'type'              => $type,
					'sanitize_callback' => $sanitize,
					'default'           => $default,
				)
			);
		}

		$this->add_sections_and_fields();
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active_tab = sanitize_key( $_GET['tab'] ?? 'general' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BuddyNext Settings', 'buddynext' ); ?></h1>
			<?php $this->render_tabs( $active_tab ); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'buddynext_general' );
				do_settings_sections( 'buddynext_' . $active_tab );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

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

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Register all settings sections and fields.
	 *
	 * @return void
	 */
	private function add_sections_and_fields(): void {
		// General section.
		add_settings_section(
			'buddynext_general_section',
			__( 'General', 'buddynext' ),
			'__return_null',
			'buddynext_general'
		);

		add_settings_field(
			'buddynext_site_name',
			__( 'Site Display Name', 'buddynext' ),
			array( $this, 'render_text_field' ),
			'buddynext_general',
			'buddynext_general_section',
			array( 'option' => 'buddynext_site_name' )
		);

		add_settings_field(
			'buddynext_brand_color',
			__( 'Brand Color', 'buddynext' ),
			array( $this, 'render_color_field' ),
			'buddynext_general',
			'buddynext_general_section',
			array( 'option' => 'buddynext_brand_color' )
		);

		// Registration section.
		add_settings_section(
			'buddynext_registration_section',
			__( 'Registration', 'buddynext' ),
			'__return_null',
			'buddynext_general'
		);

		add_settings_field(
			'buddynext_reg_mode',
			__( 'Registration Mode', 'buddynext' ),
			array( $this, 'render_select_field' ),
			'buddynext_general',
			'buddynext_registration_section',
			array(
				'option'  => 'buddynext_reg_mode',
				'choices' => array(
					'open'     => __( 'Open', 'buddynext' ),
					'invite'   => __( 'Invite Only', 'buddynext' ),
					'approval' => __( 'Admin Approval', 'buddynext' ),
				),
			)
		);

		add_settings_field(
			'buddynext_email_verify',
			__( 'Email Verification', 'buddynext' ),
			array( $this, 'render_checkbox_field' ),
			'buddynext_general',
			'buddynext_registration_section',
			array(
				'option' => 'buddynext_email_verify',
				'label'  => __( 'Require email verification', 'buddynext' ),
			)
		);

		// Social section.
		add_settings_section(
			'buddynext_social_section',
			__( 'Social', 'buddynext' ),
			'__return_null',
			'buddynext_general'
		);

		add_settings_field(
			'buddynext_default_post_privacy',
			__( 'Default Post Privacy', 'buddynext' ),
			array( $this, 'render_select_field' ),
			'buddynext_general',
			'buddynext_social_section',
			array(
				'option'  => 'buddynext_default_post_privacy',
				'choices' => array(
					'public'      => __( 'Public', 'buddynext' ),
					'followers'   => __( 'Followers', 'buddynext' ),
					'connections' => __( 'Connections', 'buddynext' ),
					'private'     => __( 'Only Me', 'buddynext' ),
				),
			)
		);

		add_settings_field(
			'buddynext_allow_polls',
			__( 'Allow Polls', 'buddynext' ),
			array( $this, 'render_checkbox_field' ),
			'buddynext_general',
			'buddynext_social_section',
			array(
				'option' => 'buddynext_allow_polls',
				'label'  => __( 'Members can create polls', 'buddynext' ),
			)
		);

		add_settings_field(
			'buddynext_post_edit_window',
			__( 'Post Edit Window (minutes)', 'buddynext' ),
			array( $this, 'render_number_field' ),
			'buddynext_general',
			'buddynext_social_section',
			array(
				'option' => 'buddynext_post_edit_window',
				'min'    => 0,
			)
		);

		// Spaces section.
		add_settings_section(
			'buddynext_spaces_section',
			__( 'Spaces', 'buddynext' ),
			'__return_null',
			'buddynext_general'
		);

		add_settings_field(
			'buddynext_space_creation_role',
			__( 'Who can create spaces', 'buddynext' ),
			array( $this, 'render_select_field' ),
			'buddynext_general',
			'buddynext_spaces_section',
			array(
				'option'  => 'buddynext_space_creation_role',
				'choices' => array(
					'member' => __( 'Any Member', 'buddynext' ),
					'admin'  => __( 'Admin Only', 'buddynext' ),
				),
			)
		);

		// Moderation section.
		add_settings_section(
			'buddynext_moderation_section',
			__( 'Moderation', 'buddynext' ),
			'__return_null',
			'buddynext_general'
		);

		add_settings_field(
			'buddynext_auto_hide_threshold',
			__( 'Auto-hide after N reports', 'buddynext' ),
			array( $this, 'render_number_field' ),
			'buddynext_general',
			'buddynext_moderation_section',
			array(
				'option' => 'buddynext_auto_hide_threshold',
				'min'    => 1,
			)
		);

		add_settings_field(
			'buddynext_strike_warn_threshold',
			__( 'Strikes before warning', 'buddynext' ),
			array( $this, 'render_number_field' ),
			'buddynext_general',
			'buddynext_moderation_section',
			array(
				'option' => 'buddynext_strike_warn_threshold',
				'min'    => 1,
			)
		);

		add_settings_field(
			'buddynext_strike_suspend_threshold',
			__( 'Strikes before suspension', 'buddynext' ),
			array( $this, 'render_number_field' ),
			'buddynext_general',
			'buddynext_moderation_section',
			array(
				'option' => 'buddynext_strike_suspend_threshold',
				'min'    => 1,
			)
		);

		// Webhooks section.
		add_settings_section(
			'buddynext_webhooks_section',
			__( 'Webhook Settings', 'buddynext' ),
			array( $this, 'render_webhooks_section' ),
			'buddynext_general'
		);

		add_settings_field(
			self::OPTION_WEBHOOK_SECRET,
			__( 'Webhook Secret', 'buddynext' ),
			array( $this, 'render_webhook_secret_field' ),
			'buddynext_general',
			'buddynext_webhooks_section'
		);
	}

	/**
	 * Render the settings tab navigation.
	 *
	 * @param string $active_tab Currently active tab slug.
	 * @return void
	 */
	private function render_tabs( string $active_tab ): void {
		$tabs = array(
			'general'      => __( 'General', 'buddynext' ),
			'registration' => __( 'Registration', 'buddynext' ),
			'social'       => __( 'Social', 'buddynext' ),
			'spaces'       => __( 'Spaces', 'buddynext' ),
			'moderation'   => __( 'Moderation', 'buddynext' ),
			'webhooks'     => __( 'Webhooks', 'buddynext' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$class = ( $slug === $active_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( add_query_arg( 'tab', $slug ) ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Render a text input field.
	 *
	 * @param array<string, mixed> $args Field arguments including 'option' key.
	 * @return void
	 */
	public function render_text_field( array $args ): void {
		$option = (string) $args['option'];
		$value  = (string) get_option( $option, '' );
		printf(
			'<input type="text" id="%s" name="%s" value="%s" class="regular-text" />',
			esc_attr( $option ),
			esc_attr( $option ),
			esc_attr( $value )
		);
	}

	/**
	 * Render a color input field.
	 *
	 * @param array<string, mixed> $args Field arguments including 'option' key.
	 * @return void
	 */
	public function render_color_field( array $args ): void {
		$option = (string) $args['option'];
		$value  = (string) get_option( $option, '#0073aa' );
		printf(
			'<input type="color" id="%s" name="%s" value="%s" />',
			esc_attr( $option ),
			esc_attr( $option ),
			esc_attr( $value )
		);
	}

	/**
	 * Render a select field.
	 *
	 * @param array<string, mixed> $args Field arguments including 'option' and 'choices'.
	 * @return void
	 */
	public function render_select_field( array $args ): void {
		$option  = (string) $args['option'];
		$choices = (array) ( $args['choices'] ?? array() );
		$value   = (string) get_option( $option, '' );
		printf( '<select id="%s" name="%s">', esc_attr( $option ), esc_attr( $option ) );
		foreach ( $choices as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $key ),
				selected( $value, (string) $key, false ),
				esc_html( (string) $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array<string, mixed> $args Field arguments including 'option' and 'label'.
	 * @return void
	 */
	public function render_checkbox_field( array $args ): void {
		$option = (string) $args['option'];
		$label  = (string) ( $args['label'] ?? '' );
		$value  = (bool) get_option( $option, false );
		printf(
			'<label><input type="checkbox" id="%s" name="%s" value="1"%s /> %s</label>',
			esc_attr( $option ),
			esc_attr( $option ),
			checked( $value, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render a number input field.
	 *
	 * @param array<string, mixed> $args Field arguments including 'option' and optional 'min'.
	 * @return void
	 */
	public function render_number_field( array $args ): void {
		$option = (string) $args['option'];
		$min    = isset( $args['min'] ) ? (int) $args['min'] : 0;
		$value  = (int) get_option( $option, 0 );
		printf( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'<input type="number" id="%s" name="%s" value="%d" min="%d" class="small-text" />',
			esc_attr( $option ),
			esc_attr( $option ),
			(int) $value,
			(int) $min
		);
	}

	/**
	 * Render the webhook section description.
	 *
	 * @return void
	 */
	public function render_webhooks_section(): void {
		echo '<p>' . esc_html__(
			'Set the shared secret used to verify inbound access webhooks. Every request to the POST buddynext/v1/webhook/access endpoint must carry a valid HMAC-SHA256 signature generated from this secret.',
			'buddynext'
		) . '</p>';
	}

	/**
	 * Render the webhook secret input field.
	 *
	 * @return void
	 */
	public function render_webhook_secret_field(): void {
		$value = (string) get_option( self::OPTION_WEBHOOK_SECRET, '' );
		?>
		<input
			type="password"
			id="<?php echo esc_attr( self::OPTION_WEBHOOK_SECRET ); ?>"
			name="<?php echo esc_attr( self::OPTION_WEBHOOK_SECRET ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="new-password"
		/>
		<p class="description">
			<?php esc_html_e( 'Generate a strong random string and share it with your webhook sender. Leave blank to disable signature verification.', 'buddynext' ); ?>
		</p>
		<?php
	}
}
