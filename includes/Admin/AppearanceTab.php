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
		$bn_logo_err = isset( $_GET['bn_error'] ) ? sanitize_key( wp_unslash( $_GET['bn_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'logo_size' === $bn_logo_err ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Logo not saved: file exceeds the 2MB limit.', 'buddynext' ) . '</p></div>';
		} elseif ( 'logo_type' === $bn_logo_err ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Logo not saved: only PNG, JPEG, WebP, or SVG files are allowed.', 'buddynext' ) . '</p></div>';
		} elseif ( 'logo_upload' === $bn_logo_err ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Logo not saved: the upload failed. Please try again.', 'buddynext' ) . '</p></div>';
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
						<p><img src="<?php echo esc_url( $logo ); ?>" alt="" class="bn-a-logo-preview"></p>
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
					<p class="bn-av-section-desc bn-a-gap-top-sm">
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
		// autoload=false: the CSS blob is only read on wp_head, not every request.
		update_option( 'buddynext_custom_css', $css, false );

		// Logo: remove takes precedence, then a new upload. A failed upload must NOT
		// report success — carry its error code so the page shows why it was rejected
		// (the other settings above already saved, so keep bn_appearance too).
		$logo_error = '';
		if ( ! empty( $_POST['bn_remove_logo'] ) ) {
			delete_option( 'buddynext_logo_url' );
		} elseif ( ! empty( $_FILES['bn_logo_file']['name'] ) ) {
			$url = $this->handle_logo_upload();
			if ( is_wp_error( $url ) ) {
				$logo_error = $url->get_error_code();
			} else {
				update_option( 'buddynext_logo_url', $url );
			}
		}

		$redirect_args = array(
			'page'          => 'buddynext',
			'tab'           => 'appearance',
			'bn_appearance' => '1',
		);
		if ( '' !== $logo_error ) {
			$redirect_args['bn_error'] = $logo_error;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Move the uploaded logo into the uploads dir and return its URL.
	 *
	 * A single site asset (not per-member), so a plain wp_handle_upload is the
	 * right tool — no attachment row, no ImageStorageService variations.
	 *
	 * @return string|\WP_Error URL on success, WP_Error (code logo_size|logo_type|logo_upload) on failure.
	 */
	private function handle_logo_upload() {
		// Delegates to the shared uploader so Appearance and Pro White-label use
		// one identical flow, limits, and error codes.
		return \BuddyNext\Core\Plugin::handle_logo_upload( 'bn_logo_file' );
	}
}
