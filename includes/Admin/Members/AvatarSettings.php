<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Avatar & Cover Settings admin tab.
 *
 * Renders the Avatar & Cover tab inside the Members admin panel and handles
 * form submission for three site-wide avatar/cover settings:
 *
 *   bn_avatar_style       — 'initials' | 'default_image' | 'gravatar'
 *   bn_default_avatar_url — URL of the site-wide fallback avatar image
 *   bn_default_cover_url  — URL of the site-wide default cover photo
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

		wp_enqueue_media();
		?>
		<script>
		jQuery(function($){
			function openMediaPicker(inputId, previewId) {
				var frame = wp.media({
					title: '<?php echo esc_js( __( 'Select Image', 'buddynext' ) ); ?>',
					button: { text: '<?php echo esc_js( __( 'Use this image', 'buddynext' ) ); ?>' },
					multiple: false,
					library: { type: 'image' }
				});
				frame.on('select', function(){
					var att = frame.state().get('selection').first().toJSON();
					$('#' + inputId).val(att.url);
					$('#' + previewId).attr('src', att.url).show();
				});
				frame.open();
			}
			$('#bn-pick-avatar').on('click', function(e){
				e.preventDefault();
				openMediaPicker('bn_default_avatar_url', 'bn-avatar-preview');
			});
			$('#bn-pick-cover').on('click', function(e){
				e.preventDefault();
				openMediaPicker('bn_default_cover_url', 'bn-cover-preview');
			});
		});

		// Delegated confirm handler — replaces inline confirm dialogs (F2 compliance).
		document.addEventListener('click', function (e) {
			var t = e.target.closest('[data-bn-confirm]');
			if (!t) return;
			if (!window.confirm(t.dataset.bnConfirm)) {
				e.preventDefault();
				e.stopImmediatePropagation();
			}
		}, true);
		</script>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			enctype="multipart/form-data">
			<input type="hidden" name="action" value="bn_save_avatar_settings">
			<?php wp_nonce_field( 'bn_save_avatar_settings' ); ?>

			<?php $this->render_avatar_style_section( $style ); ?>
			<?php $this->render_default_avatar_section( $avatar_url ); ?>
			<?php $this->render_default_cover_section( $cover_url ); ?>

			<div class="bn-save-bar">
				<?php submit_button( __( 'Save Avatar Settings', 'buddynext' ), 'primary bn-btn-save', 'submit', false ); ?>
			</div>
		</form>

		<style>
		.bn-av-style-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 4px; }
		.bn-av-style-card { border: 2px solid #e9ecef; border-radius: 8px; padding: 16px 12px; cursor: pointer; text-align: center; transition: border-color .15s, background .15s; }
		.bn-av-style-card:has(input:checked) { border-color: #0073aa; background: #f0f7fb; }
		.bn-av-style-card input { position: absolute; opacity: 0; pointer-events: none; }
		.bn-av-style-icon { display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
		.bn-av-style-icon svg { color: #6b7280; }
		.bn-av-style-card:has(input:checked) .bn-av-style-icon svg { color: #0073aa; }
		.bn-av-style-label { font-size: 13px; font-weight: 600; color: #374151; }
		.bn-av-style-desc { font-size: 11px; color: #9ca3af; margin-top: 2px; }
		.bn-image-picker { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
		.bn-image-picker-preview { width: 72px; height: 72px; object-fit: cover; border-radius: 8px; border: 1px solid #e9ecef; background: #f9fafb; flex-shrink: 0; }
		.bn-image-picker-preview.bn-cover-preview { width: 140px; height: 72px; border-radius: 6px; }
		.bn-image-picker-controls { display: flex; flex-direction: column; gap: 8px; }
		.bn-image-picker-controls input[type="text"] { border: 1px solid #ddd; border-radius: 4px; padding: 7px 10px; font-size: 13px; width: 280px; }
		.bn-image-picker-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
		</style>
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
				<p style="font-size:13px;color:#6b7280;margin:0 0 16px;">
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
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Default Avatar Image', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p style="font-size:13px;color:#6b7280;margin:0 0 16px;">
					<?php esc_html_e( 'Used when style is set to "Default Image". Recommended size: at least 200×200 px.', 'buddynext' ); ?>
				</p>
				<div class="bn-image-picker">
					<img id="bn-avatar-preview"
						src="<?php echo $preview_src; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>"
						alt="<?php esc_attr_e( 'Default avatar preview', 'buddynext' ); ?>"
						class="bn-image-picker-preview"
						width="72" height="72">
					<div class="bn-image-picker-controls">
						<div class="bn-image-picker-actions">
							<button type="button" id="bn-pick-avatar" class="button">
								<?php esc_html_e( 'Select from Media Library', 'buddynext' ); ?>
							</button>
							<?php if ( '' !== $current_url ) : ?>
								<button type="submit" name="bn_remove_default_avatar" value="1"
									class="button bn-btn-danger"
									data-bn-confirm="<?php echo esc_attr( __( 'Remove the default avatar?', 'buddynext' ) ); ?>">
									<?php esc_html_e( 'Remove', 'buddynext' ); ?>
								</button>
							<?php endif; ?>
						</div>
						<input type="text" id="bn_default_avatar_url" name="bn_default_avatar_url"
							value="<?php echo esc_attr( $current_url ); ?>"
							placeholder="<?php esc_attr_e( 'Or paste an image URL…', 'buddynext' ); ?>">
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
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Default Cover Photo', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p style="font-size:13px;color:#6b7280;margin:0 0 16px;">
					<?php esc_html_e( 'Shown on profiles that have no cover photo. Recommended: 1200×280 px or wider.', 'buddynext' ); ?>
				</p>
				<div class="bn-image-picker">
					<?php if ( '' !== $current_url ) : ?>
						<img id="bn-cover-preview"
							src="<?php echo esc_attr( $current_url ); ?>"
							alt="<?php esc_attr_e( 'Default cover preview', 'buddynext' ); ?>"
							class="bn-image-picker-preview bn-cover-preview"
							width="140" height="72">
					<?php else : ?>
						<div style="width:140px;height:72px;border:1px solid #e9ecef;border-radius:6px;background:#f9fafb;display:flex;align-items:center;justify-content:center;">
							<span style="font-size:11px;color:#9ca3af;"><?php esc_html_e( 'No cover set', 'buddynext' ); ?></span>
						</div>
					<?php endif; ?>
					<div class="bn-image-picker-controls">
						<div class="bn-image-picker-actions">
							<button type="button" id="bn-pick-cover" class="button">
								<?php esc_html_e( 'Select from Media Library', 'buddynext' ); ?>
							</button>
							<?php if ( '' !== $current_url ) : ?>
								<button type="submit" name="bn_remove_default_cover" value="1"
									class="button bn-btn-danger"
									data-bn-confirm="<?php echo esc_attr( __( 'Remove the default cover?', 'buddynext' ) ); ?>">
									<?php esc_html_e( 'Remove', 'buddynext' ); ?>
								</button>
							<?php endif; ?>
						</div>
						<input type="text" id="bn_default_cover_url" name="bn_default_cover_url"
							value="<?php echo esc_attr( $current_url ); ?>"
							placeholder="<?php esc_attr_e( 'Or paste an image URL…', 'buddynext' ); ?>">
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
