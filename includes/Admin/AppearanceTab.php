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
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Appearance saved.', 'buddynext' ) . '</p></div>';
		}

		$logo   = (string) get_option( 'buddynext_logo_url', '' );
		$theme  = (string) get_option( 'buddynext_default_theme', 'auto' );
		$css    = (string) get_option( 'buddynext_custom_css', '' );
		$themes = array(
			'auto'  => __( 'Auto (follow the visitor’s device)', 'buddynext' ),
			'light' => __( 'Light', 'buddynext' ),
			'dark'  => __( 'Dark', 'buddynext' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="bn-admin-hub__form-bare">
			<input type="hidden" name="action" value="bn_appearance_save">
			<?php wp_nonce_field( 'bn_appearance_save' ); ?>

			<div class="bn-settings-section">
				<div class="bn-ss-header"><span class="bn-ss-title"><?php esc_html_e( 'Logo', 'buddynext' ); ?></span></div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc"><?php esc_html_e( 'Shown at the top of the navigation rail. A wide PNG/SVG around 160×40 works best. Leave empty to show the community name instead.', 'buddynext' ); ?></p>
					<?php if ( '' !== $logo ) : ?>
						<p><img src="<?php echo esc_url( $logo ); ?>" alt="" style="max-height:40px;max-width:240px;"></p>
						<p><label><input type="checkbox" name="bn_remove_logo" value="1"> <?php esc_html_e( 'Remove current logo', 'buddynext' ); ?></label></p>
					<?php endif; ?>
					<input type="file" name="bn_logo_file" accept="image/png,image/jpeg,image/webp,image/svg+xml">
				</div>
			</div>

			<div class="bn-settings-section">
				<div class="bn-ss-header"><span class="bn-ss-title"><?php esc_html_e( 'Default theme', 'buddynext' ); ?></span></div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc"><?php esc_html_e( 'Applies to visitors who have not picked a theme themselves.', 'buddynext' ); ?></p>
					<select name="bn_default_theme" class="bn-select">
						<?php foreach ( $themes as $tv => $tl ) : ?>
							<option value="<?php echo esc_attr( $tv ); ?>" <?php selected( $theme, $tv ); ?>><?php echo esc_html( $tl ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="bn-av-section-desc" style="margin-top:8px;">
						<?php esc_html_e( 'Accent colour is set under', 'buddynext' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=buddynext&tab=general' ) ); ?>"><?php esc_html_e( 'General → Brand Color', 'buddynext' ); ?></a>.
					</p>
				</div>
			</div>

			<div class="bn-settings-section">
				<div class="bn-ss-header"><span class="bn-ss-title"><?php esc_html_e( 'Custom CSS', 'buddynext' ); ?></span></div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc"><?php esc_html_e( 'Injected on community pages after the theme styles. Use the BuddyNext token variables (e.g. var(--bn-accent)) where you can.', 'buddynext' ); ?></p>
					<textarea name="bn_custom_css" class="bn-textarea large-text code" rows="10" spellcheck="false"><?php echo esc_textarea( $css ); ?></textarea>
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

		// Default theme.
		$theme = isset( $_POST['bn_default_theme'] ) ? sanitize_key( wp_unslash( (string) $_POST['bn_default_theme'] ) ) : 'auto';
		update_option( 'buddynext_default_theme', in_array( $theme, array( 'auto', 'light', 'dark' ), true ) ? $theme : 'auto' );

		// Custom CSS — stored verbatim (manage_options); neutralised on output.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$css = isset( $_POST['bn_custom_css'] ) ? (string) wp_unslash( $_POST['bn_custom_css'] ) : '';
		update_option( 'buddynext_custom_css', $css );

		// Logo: remove takes precedence, then a new upload.
		if ( ! empty( $_POST['bn_remove_logo'] ) ) {
			delete_option( 'buddynext_logo_url' );
		} elseif ( ! empty( $_FILES['bn_logo_file']['name'] ) ) {
			$url = $this->handle_logo_upload();
			if ( null !== $url ) {
				update_option( 'buddynext_logo_url', $url );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'buddynext',
					'tab'           => 'appearance',
					'bn_appearance' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Move the uploaded logo into the uploads dir and return its URL.
	 *
	 * A single site asset (not per-member), so a plain wp_handle_upload is the
	 * right tool — no attachment row, no ImageStorageService variations.
	 *
	 * @return string|null URL on success, null on failure.
	 */
	private function handle_logo_upload(): ?string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer() in handle_save() before this runs.
		$file = isset( $_FILES['bn_logo_file'] ) && is_array( $_FILES['bn_logo_file'] ) ? $_FILES['bn_logo_file'] : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing

		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return null;
		}
		if ( (int) ( $file['size'] ?? 0 ) > 2 * 1024 * 1024 ) {
			return null;
		}

		$check   = wp_check_filetype_and_ext( (string) ( $file['tmp_name'] ?? '' ), (string) ( $file['name'] ?? '' ) );
		$allowed = array( 'image/png', 'image/jpeg', 'image/webp', 'image/svg+xml' );
		$type    = (string) ( $check['type'] ?: ( $file['type'] ?? '' ) ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- SVG often returns empty from fileinfo.
		if ( ! in_array( $type, $allowed, true ) ) {
			return null;
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$data = array(
			'name'     => sanitize_file_name( (string) ( $file['name'] ?? '' ) ),
			'type'     => $type,
			'tmp_name' => (string) ( $file['tmp_name'] ?? '' ),
			'error'    => (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ),
			'size'     => (int) ( $file['size'] ?? 0 ),
		);

		$result = wp_handle_upload( $data, array( 'test_form' => false ) );
		return isset( $result['url'] ) && ! isset( $result['error'] ) ? esc_url_raw( (string) $result['url'] ) : null;
	}
}
