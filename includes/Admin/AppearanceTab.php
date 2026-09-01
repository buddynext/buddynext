<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Settings → Appearance.
 *
 * Branding controls beyond the accent colour (which lives in General → Brand
 * Color and is applied by Theme\Appearance):
 *
 *   Logo          — shown at the top of the navigation rail; links home.
 *   Default theme — light / dark / auto for first-time visitors.
 *   Custom CSS    — injected on the front-end after the token block.
 *
 * The options are stored here; Theme\Appearance applies the front-end ones.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Renders the Appearance tab and saves its options.
 */
class AppearanceTab {

	/**
	 * Register hooks + the tab.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_appearance_save', array( $this, 'handle_save' ) );

		AdminHub::register_tab(
			'settings',
			'appearance',
			__( 'Appearance', 'buddynext' ),
			array( $this, 'render_page' ),
			array( 'position' => 15 )
		);
	}

	/**
	 * Render the Appearance tab.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['bn_appearance'] ) ) {
			AdminPageBase::render_notice( __( 'Appearance saved.', 'buddynext' ), 'success', false, array( 'data-bn-clear-param' => 'bn_appearance bn_error' ) );
		}
		$bn_logo_err = isset( $_GET['bn_error'] ) ? sanitize_key( wp_unslash( $_GET['bn_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'logo_url' === $bn_logo_err ) {
			AdminPageBase::render_notice( __( 'Logo not saved: select an image from the media library or enter a valid image URL.', 'buddynext' ), 'error', false, array( 'data-bn-clear-param' => 'bn_appearance bn_error' ) );
		}

		$logo   = (string) get_option( 'buddynext_logo_url', '' );
		$theme  = (string) get_option( 'buddynext_default_theme', 'auto' );
		$css    = (string) get_option( 'buddynext_custom_css', '' );
		$brand  = (string) get_option( 'buddynext_brand_color', \BuddyNext\Theme\Appearance::DEFAULT_BRAND );
		$themes = array(
			'auto'  => __( 'Auto (follow the visitor’s device)', 'buddynext' ),
			'light' => __( 'Light', 'buddynext' ),
			'dark'  => __( 'Dark', 'buddynext' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-admin-hub__form-bare bn-settings-content">
			<input type="hidden" name="action" value="bn_appearance_save">
			<?php wp_nonce_field( 'bn_appearance_save' ); ?>

			<div class="bn-settings-section">
				<div class="bn-ss-header"><span class="bn-ss-title"><?php esc_html_e( 'Logo', 'buddynext' ); ?></span></div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc"><?php esc_html_e( 'Shown at the top of the navigation rail. A wide PNG/SVG around 160×40 works best. Leave empty to show the community name instead.', 'buddynext' ); ?></p>
					<?php
					AdminPageBase::render_media_row(
						'bn_logo_url',
						__( 'Logo image', 'buddynext' ),
						$logo,
						__( 'Pick an image from the media library, or paste an image URL directly.', 'buddynext' ),
						__( 'Select logo', 'buddynext' )
					);
					?>
				</div>
			</div>

			<div class="bn-settings-section">
				<div class="bn-ss-header"><span class="bn-ss-title"><?php esc_html_e( 'Brand color', 'buddynext' ); ?></span></div>
				<div class="bn-ss-body">
					<?php
					// The community accent. Moved here from Settings > General so all
					// look-and-feel lives on one tab; the value is still stored in the
					// same buddynext_brand_color option that Appearance reads, so nothing
					// downstream changes.
					AdminPageBase::render_color_row(
						'bn_brand_color',
						__( 'Brand color', 'buddynext' ),
						$brand,
						__( 'Your community accent — used for buttons, links, active tabs, and badges across every member-facing screen. Click the swatch to pick, or paste a hex code.', 'buddynext' )
					);
					?>
				</div>
			</div>

			<div class="bn-settings-section">
				<div class="bn-ss-header"><span class="bn-ss-title"><?php esc_html_e( 'Default theme', 'buddynext' ); ?></span></div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc"><?php esc_html_e( 'Applies to visitors who have not picked a theme themselves.', 'buddynext' ); ?></p>
					<?php // The section heading is a <span>, not a label, so this select had no name. ?>
					<select name="bn_default_theme" class="bn-select" aria-label="<?php esc_attr_e( 'Default theme', 'buddynext' ); ?>">
						<?php foreach ( $themes as $tv => $tl ) : ?>
							<option value="<?php echo esc_attr( $tv ); ?>" <?php selected( $theme, $tv ); ?>><?php echo esc_html( $tl ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="bn-settings-section">
				<div class="bn-ss-header"><span class="bn-ss-title"><?php esc_html_e( 'Custom CSS', 'buddynext' ); ?></span></div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc"><?php esc_html_e( 'Injected on community pages after the theme styles. Use the BuddyNext token variables (e.g. var(--bn-accent)) where you can.', 'buddynext' ); ?></p>
					<?php // #bn-custom-css is upgraded to the core code editor (CodeMirror) by AssetService when the tab is active; without it this stays a plain textarea. ?>
					<textarea id="bn-custom-css" name="bn_custom_css" class="bn-textarea large-text code" rows="10" spellcheck="false"><?php echo esc_textarea( $css ); ?></textarea>
				</div>
			</div>

			<p><button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save appearance', 'buddynext' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Persist the Appearance options.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_appearance_save' );

		// Brand colour — moved here from Settings > General. Reuse the shared
		// sanitiser, which falls back to the documented default rather than
		// persisting an empty option (an empty value would wipe the accent, since
		// read sites only default when the option is ABSENT, not when it is '').
		$brand = isset( $_POST['bn_brand_color'] )
			? Settings::sanitize_brand_color( wp_unslash( (string) $_POST['bn_brand_color'] ) )
			: \BuddyNext\Theme\Appearance::DEFAULT_BRAND;
		update_option( 'buddynext_brand_color', $brand );

		// Default theme.
		$theme = isset( $_POST['bn_default_theme'] ) ? sanitize_key( wp_unslash( (string) $_POST['bn_default_theme'] ) ) : 'auto';
		update_option( 'buddynext_default_theme', in_array( $theme, array( 'auto', 'light', 'dark' ), true ) ? $theme : 'auto' );

		// Custom CSS — stored verbatim (manage_options); neutralised on output.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$css = isset( $_POST['bn_custom_css'] ) ? (string) wp_unslash( $_POST['bn_custom_css'] ) : '';
		// autoload=false: the CSS blob is only read on wp_head, not every request.
		update_option( 'buddynext_custom_css', $css, false );

		// Logo: the media row posts a URL (media-library pick or a pasted one).
		// Empty clears the logo; a value that fails sanitisation is rejected with
		// an error instead of silently wiping the stored logo (the other settings
		// above already saved, so keep bn_appearance too).
		$logo_error = '';
		$raw_logo   = isset( $_POST['bn_logo_url'] ) ? trim( (string) wp_unslash( $_POST['bn_logo_url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised via esc_url_raw() on the next line.
		$logo_url   = esc_url_raw( $raw_logo );
		if ( '' === $raw_logo ) {
			delete_option( 'buddynext_logo_url' );
		} elseif ( '' === $logo_url ) {
			$logo_error = 'logo_url';
		} else {
			update_option( 'buddynext_logo_url', $logo_url );
		}

		$redirect_extra = array( 'bn_appearance' => '1' );
		if ( '' !== $logo_error ) {
			$redirect_extra['bn_error'] = $logo_error;
		}

		wp_safe_redirect( AdminHub::tab_url( 'settings', 'appearance', $redirect_extra ) );
		exit;
	}
}
