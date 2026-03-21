<?php
/**
 * BuddyNext admin settings page.
 *
 * Registers a top-level "BuddyNext" menu and a General settings sub-page that
 * currently exposes the webhook secret field used to verify inbound access
 * webhooks.  Additional settings sections will be added in later phases.
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
	 * Hook the admin menu and settings registration into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the top-level BuddyNext menu and the General sub-page.
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
			__( 'BuddyNext — General Settings', 'buddynext' ),
			__( 'General', 'buddynext' ),
			'manage_options',
			'buddynext',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_settings(): void {
		register_setting(
			'buddynext_general',
			self::OPTION_WEBHOOK_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'buddynext_webhooks',
			__( 'Webhook Settings', 'buddynext' ),
			array( $this, 'render_webhooks_section' ),
			'buddynext_general'
		);

		add_settings_field(
			self::OPTION_WEBHOOK_SECRET,
			__( 'Webhook Secret', 'buddynext' ),
			array( $this, 'render_webhook_secret_field' ),
			'buddynext_general',
			'buddynext_webhooks'
		);
	}

	/**
	 * Render the settings page wrapper.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'buddynext_general' );
				do_settings_sections( 'buddynext_general' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the webhook section description.
	 */
	public function render_webhooks_section(): void {
		echo '<p>' . esc_html__(
			'Set the shared secret used to verify inbound access webhooks. Every request to the POST buddynext/v1/webhook/access endpoint must carry a valid HMAC-SHA256 signature generated from this secret.',
			'buddynext'
		) . '</p>';
	}

	/**
	 * Render the webhook secret input field.
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
