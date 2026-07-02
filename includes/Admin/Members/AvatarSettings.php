<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Avatar & Cover Settings admin tab.
 *
 * Renders the Avatar & Cover tab inside the Members admin panel and handles
 * form submission for three site-wide avatar/cover settings:
 *
 *   bn_avatar_style       - 'initials' | 'default_image' | 'gravatar'
 *   bn_default_avatar_url - URL of the site-wide fallback avatar image
 *   bn_default_cover_url  - URL of the site-wide default cover photo
 *
 * These options are read by AvatarService::filter_avatar_data() so every
 * WordPress surface (theme, admin, REST responses) honours the site owner's
 * choices without further per-template work.
 *
 * @package BuddyNext\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Members;

/**
 * Handles the Avatar & Cover settings tab for the Members admin panel.
 */
class AvatarSettings {

	/**
	 * Allowed avatar style values.
	 *
	 * @var string[]
	 */
	private const AVATAR_STYLES = array( 'initials', 'default_image', 'gravatar' );

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register the admin-post hook for form submission.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_save_avatar_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the Avatar Settings tab JS on the Members admin page.
	 *
	 * Loads only when the active tab is "avatar-settings". Also forces the
	 * WordPress media library (wp_enqueue_media) on the same condition.
	 *
	 * @param string $hook_suffix Hook suffix for the current admin page.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'buddynext-members' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
		if ( 'avatar-settings' !== $tab ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'bn-avatar-settings',
			BUDDYNEXT_URL . 'assets/js/admin/avatar-settings.js',
			array( 'jquery', 'wp-i18n' ),
			BUDDYNEXT_VERSION,
			true
		);

		wp_set_script_translations( 'bn-avatar-settings', 'buddynext', BUDDYNEXT_DIR . 'languages' );

		wp_localize_script(
			'bn-avatar-settings',
			'bnAvatarSettingsL10n',
			array(
				'pickerTitle'  => __( 'Select Image', 'buddynext' ),
				'pickerButton' => __( 'Use this image', 'buddynext' ),
				'confirmTitle' => __( 'Remove image?', 'buddynext' ),
				'confirm'      => __( 'Remove', 'buddynext' ),
				'cancel'       => __( 'Cancel', 'buddynext' ),
			)
		);
	}

	// ── Form handler ──────────────────────────────────────────────────────────

	/**
	 * Handle admin_post_bn_save_avatar_settings form submission.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_save_avatar_settings' );

		// ── Avatar style ──────────────────────────────────────────────────────
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$style = sanitize_key( wp_unslash( $_POST['bn_avatar_style'] ?? 'initials' ) );
		if ( ! in_array( $style, self::AVATAR_STYLES, true ) ) {
			$style = 'initials';
		}
		update_option( 'bn_avatar_style', $style );

		// ── Default avatar image ──────────────────────────────────────────────
		if ( ! empty( $_FILES['bn_default_avatar_file']['name'] ) ) {
			$url = $this->handle_image_upload( 'bn_default_avatar_file', 2 );
			if ( null !== $url ) {
				update_option( 'bn_default_avatar_url', $url );
			}
		} elseif ( isset( $_POST['bn_default_avatar_url'] ) ) {
			$url = esc_url_raw( wp_unslash( $_POST['bn_default_avatar_url'] ) );
			update_option( 'bn_default_avatar_url', $url );
		}

		// ── Default cover image ───────────────────────────────────────────────
		if ( ! empty( $_FILES['bn_default_cover_file']['name'] ) ) {
			$url = $this->handle_image_upload( 'bn_default_cover_file', 5 );
			if ( null !== $url ) {
				update_option( 'bn_default_cover_url', $url );
			}
		} elseif ( isset( $_POST['bn_default_cover_url'] ) ) {
			$url = esc_url_raw( wp_unslash( $_POST['bn_default_cover_url'] ) );
			update_option( 'bn_default_cover_url', $url );
		}

		// ── Remove defaults if requested ──────────────────────────────────────
		if ( ! empty( $_POST['bn_remove_default_avatar'] ) ) {
			delete_option( 'bn_default_avatar_url' );
		}
		if ( ! empty( $_POST['bn_remove_default_cover'] ) ) {
			delete_option( 'bn_default_cover_url' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'buddynext-members',
					'tab'   => 'avatar-settings',
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Tab renderer ──────────────────────────────────────────────────────────

	/**
	 * Render the Avatar & Cover settings tab.
	 *
	 * @return void
	 */
	public function render_avatar_settings_tab(): void {
		$style      = (string) get_option( 'bn_avatar_style', 'initials' );
		$avatar_url = (string) get_option( 'bn_default_avatar_url', '' );
		$cover_url  = (string) get_option( 'bn_default_cover_url', '' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Avatar settings saved.', 'buddynext' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			enctype="multipart/form-data">
			<input type="hidden" name="action" value="bn_save_avatar_settings">
			<?php wp_nonce_field( 'bn_save_avatar_settings' ); ?>

			<?php $this->render_avatar_style_section( $style ); ?>
			<?php $this->render_default_avatar_section( $avatar_url ); ?>
			<?php $this->render_default_cover_section( $cover_url ); ?>

			<div class="bn-save-bar">
				<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save Avatar Settings', 'buddynext' ); ?></button>
			</div>
		</form>
		<?php
	}

	// ── Private section renderers ──────────────────────────────────────────────

	/**
	 * Render the default avatar style selection card group.
	 *
	 * @param string $style Currently saved style.
	 * @return void
	 */
	private function render_avatar_style_section( string $style ): void {
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Default Avatar Style', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php esc_html_e( 'Choose what to show when a member has not uploaded a custom avatar. Applies site-wide via the WordPress avatar system.', 'buddynext' ); ?>
				</p>
				<div class="bn-av-style-grid">
					<label class="bn-av-style-card">
						<input type="radio" name="bn_avatar_style" value="initials"
							<?php checked( $style, 'initials' ); ?>>
						<div class="bn-av-style-icon">
							<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<circle cx="12" cy="12" r="10"/>
								<path d="M8 12h8M12 8v8"/>
								<circle cx="12" cy="8" r="1" fill="currentColor" stroke="none"/>
								<path d="M9 16c0-1.657 1.343-3 3-3s3 1.343 3 3"/>
							</svg>
						</div>
						<div class="bn-av-style-label"><?php esc_html_e( 'Initials', 'buddynext' ); ?></div>
						<div class="bn-av-style-desc"><?php esc_html_e( 'Coloured circle with member initials. No network request.', 'buddynext' ); ?></div>
					</label>
					<label class="bn-av-style-card">
						<input type="radio" name="bn_avatar_style" value="default_image"
							<?php checked( $style, 'default_image' ); ?>>
						<div class="bn-av-style-icon">
							<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<rect x="3" y="3" width="18" height="18" rx="2"/>
								<circle cx="8.5" cy="8.5" r="1.5"/>
								<polyline points="21 15 16 10 5 21"/>
							</svg>
						</div>
						<div class="bn-av-style-label"><?php esc_html_e( 'Default Image', 'buddynext' ); ?></div>
						<div class="bn-av-style-desc"><?php esc_html_e( 'A single image you upload shown for all members without an avatar.', 'buddynext' ); ?></div>
					</label>
					<label class="bn-av-style-card">
						<input type="radio" name="bn_avatar_style" value="gravatar"
							<?php checked( $style, 'gravatar' ); ?>>
						<div class="bn-av-style-icon">
							<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<circle cx="12" cy="12" r="10"/>
								<line x1="2" y1="12" x2="22" y2="12"/>
								<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
							</svg>
						</div>
						<div class="bn-av-style-label"><?php esc_html_e( 'Gravatar', 'buddynext' ); ?></div>
						<div class="bn-av-style-desc"><?php esc_html_e( 'Use WordPress / Gravatar as fallback. Custom uploads still override.', 'buddynext' ); ?></div>
					</label>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the default avatar image picker section.
	 *
	 * @param string $current_url Currently saved URL.
	 * @return void
	 */
	private function render_default_avatar_section( string $current_url ): void {
		// Inline placeholder SVG data URI shown when no default avatar is set.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$placeholder_src = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 72 72">'
			. '<rect width="72" height="72" rx="36" fill="#e9ecef"/>'
			. '<text x="36" y="36" text-anchor="middle" dominant-baseline="central" '
			. 'font-family="Inter,sans-serif" font-size="20" font-weight="700" fill="#9ca3af">?</text>'
			. '</svg>'
		);
		$preview_src     = '' !== $current_url ? esc_attr( $current_url ) : esc_attr( $placeholder_src );
		// Dependent section: only takes effect when the avatar style is
		// "Default Image". avatar-settings.js toggles the inactive state live as
		// the style radio changes; this is the initial server-rendered state.
		$is_active = ( 'default_image' === (string) get_option( 'bn_avatar_style', 'initials' ) );
		?>
		<div class="bn-settings-section bn-av-dependent<?php echo $is_active ? '' : ' is-inactive'; ?>" data-bn-avatar-dependent>
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Default Avatar Image', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php esc_html_e( 'Used when style is set to "Default Image". Recommended size: at least 200x200 px.', 'buddynext' ); ?>
				</p>
				<p class="bn-av-dependent-note" data-bn-avatar-dependent-note>
					<?php esc_html_e( 'This image is only shown while the avatar style above is set to "Default Image".', 'buddynext' ); ?>
				</p>
				<div class="bn-image-picker">
					<img id="bn-avatar-preview"
						src="<?php echo $preview_src; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>"
						alt="<?php esc_attr_e( 'Default avatar preview', 'buddynext' ); ?>"
						class="bn-image-picker-preview"
						width="72" height="72">
					<div class="bn-image-picker-controls">
						<div class="bn-image-picker-actions">
							<button type="button" id="bn-pick-avatar" class="bn-btn" data-variant="secondary">
								<?php esc_html_e( 'Select from Media Library', 'buddynext' ); ?>
							</button>
							<?php if ( '' !== $current_url ) : ?>
								<button type="submit" name="bn_remove_default_avatar" value="1"
									class="bn-btn" data-variant="danger"
									data-bn-confirm="<?php echo esc_attr__( 'Remove the default avatar?', 'buddynext' ); ?>">
									<?php esc_html_e( 'Remove', 'buddynext' ); ?>
								</button>
							<?php endif; ?>
						</div>
						<input type="text" id="bn_default_avatar_url" name="bn_default_avatar_url"
							value="<?php echo esc_attr( $current_url ); ?>"
							placeholder="<?php esc_attr_e( 'Or paste an image URL...', 'buddynext' ); ?>">
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the default cover image picker section.
	 *
	 * @param string $current_url Currently saved URL.
	 * @return void
	 */
	private function render_default_cover_section( string $current_url ): void {
		// Wide placeholder shown when no cover is set. Rendered as the preview img
		// (not a separate empty div) so the media picker's JS — which targets
		// #bn-cover-preview — can swap the src on first selection. Mirrors the
		// avatar section's always-present-img pattern.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$placeholder_src = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 140 72">'
			. '<rect width="140" height="72" rx="6" fill="#e9ecef"/>'
			. '<text x="70" y="36" text-anchor="middle" dominant-baseline="central" '
			. 'font-family="Inter,sans-serif" font-size="11" font-weight="600" fill="#9ca3af">'
			. esc_html__( 'No cover set', 'buddynext' )
			. '</text>'
			. '</svg>'
		);
		$preview_src     = '' !== $current_url ? esc_attr( $current_url ) : esc_attr( $placeholder_src );
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Default Cover Photo', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php esc_html_e( 'Shown on profiles that have no cover photo. Recommended: 1200x280 px or wider.', 'buddynext' ); ?>
				</p>
				<div class="bn-image-picker">
					<img id="bn-cover-preview"
						src="<?php echo $preview_src; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>"
						alt="<?php esc_attr_e( 'Default cover preview', 'buddynext' ); ?>"
						class="bn-image-picker-preview bn-cover-preview"
						width="140" height="72">
					<div class="bn-image-picker-controls">
						<div class="bn-image-picker-actions">
							<button type="button" id="bn-pick-cover" class="bn-btn" data-variant="secondary">
								<?php esc_html_e( 'Select from Media Library', 'buddynext' ); ?>
							</button>
							<?php if ( '' !== $current_url ) : ?>
								<button type="submit" name="bn_remove_default_cover" value="1"
									class="bn-btn" data-variant="danger"
									data-bn-confirm="<?php echo esc_attr__( 'Remove the default cover?', 'buddynext' ); ?>">
									<?php esc_html_e( 'Remove', 'buddynext' ); ?>
								</button>
							<?php endif; ?>
						</div>
						<input type="text" id="bn_default_cover_url" name="bn_default_cover_url"
							value="<?php echo esc_attr( $current_url ); ?>"
							placeholder="<?php esc_attr_e( 'Or paste an image URL...', 'buddynext' ); ?>">
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Handle a file upload from a $_FILES key, returning the URL on success.
	 *
	 * @param string $file_key  Key in $_FILES.
	 * @param int    $max_mb    Maximum file size in megabytes.
	 * @return string|null URL on success, null on failure or no file.
	 */
	private function handle_image_upload( string $file_key, int $max_mb ): ?string {
		/*
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		 */
		if ( ! isset( $_FILES[ $file_key ] ) || ! is_array( $_FILES[ $file_key ] ) ) {
			return null;
		}
		$file = $_FILES[ $file_key ];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return null;
		}

		if ( (int) ( $file['size'] ?? 0 ) > $max_mb * 1024 * 1024 ) {
			return null;
		}

		$check   = wp_check_filetype_and_ext( (string) ( $file['tmp_name'] ?? '' ), (string) ( $file['name'] ?? '' ) );
		$allowed = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return null;
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file_data = array(
			'name'     => sanitize_file_name( (string) ( $file['name'] ?? '' ) ),
			'type'     => (string) ( $file['type'] ?? '' ),
			'tmp_name' => (string) ( $file['tmp_name'] ?? '' ),
			'error'    => (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ),
			'size'     => (int) ( $file['size'] ?? 0 ),
		);

		$result = wp_handle_upload( $file_data, array( 'test_form' => false ) );

		return isset( $result['url'] ) && ! isset( $result['error'] )
			? esc_url_raw( $result['url'] )
			: null;
	}
}
