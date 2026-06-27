<?php
/**
 * BuddyNext — Integration display & activity controls (admin tab).
 *
 * Renders one card per registered integration (from the open `buddynext_integrations`
 * registry) letting the owner toggle, per integration: whether it shows its nav
 * tab/sub-tab and whether it posts to the activity feed — plus a per-sub-tab toggle
 * for aggregating surfaces (the Portfolio). 100% registry-driven: a new in-house or
 * third-party integration appears here automatically with no change to this class.
 *
 * Defaults are ON (an absent option = enabled), so this screen only ever records an
 * owner's opt-OUT. All reads/writes go through the option keys the shared
 * `buddynext_integration_enabled()` helper consumes, so keys never drift.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * The BuddyNext → Integration Display admin tab.
 */
class IntegrationControlsAdmin {

	/**
	 * Wire the save handler and register the tab. Called from Plugin::init().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_integration_controls_save', array( $this, 'handle_save' ) );

		\BuddyNext\Admin\AdminHub::register_tab(
			'settings',
			'integration-controls',
			__( 'Integration Display', 'buddynext' ),
			array( $this, 'render_page' ),
			array( 'group' => __( 'Integrations', 'buddynext' ) )
		);
	}

	/**
	 * Render the per-integration toggle matrix.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_flag = isset( $_GET['bn_intctl'] ) ? sanitize_key( wp_unslash( $_GET['bn_intctl'] ) ) : '';
		if ( 'error' === $bn_flag ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not save integration settings. Please try again.', 'buddynext' ) . '</p></div>';
		} elseif ( '' !== $bn_flag ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Integration settings saved.', 'buddynext' ) . '</p></div>';
		}

		$integrations = buddynext_integrations();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-admin-hub__form-bare">
			<input type="hidden" name="action" value="bn_integration_controls_save">
			<?php wp_nonce_field( 'bn_integration_controls_save' ); ?>

			<div class="bn-settings-section">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Integration Display & Activity', 'buddynext' ); ?></span>
				</div>
				<div class="bn-ss-body">
					<p class="bn-av-section-desc">
						<?php esc_html_e( 'For each active integration, choose whether it shows its tab in member navigation and whether its events post to the activity feed. Everything is on by default — turn off only what your community does not need.', 'buddynext' ); ?>
					</p>

					<?php if ( empty( $integrations ) ) : ?>
						<p class="bn-empty"><?php esc_html_e( 'No controllable integrations are active yet. Activate an integration (such as Career Board, Listora, Learnomy, Jetonomy, or Gamification) and it will appear here.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php foreach ( $integrations as $key => $entry ) : ?>
							<?php
							$bn_label   = (string) ( $entry['label'] ?? $key );
							$bn_subtabs = (array) ( $entry['subtabs'] ?? array() );
							?>
							<fieldset class="bn-int-controls">
								<legend class="bn-roles-group"><?php echo esc_html( $bn_label ); ?></legend>
								<?php if ( ! empty( $entry['has_nav'] ) ) : ?>
									<p>
										<label>
											<input type="checkbox" name="nav[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( buddynext_integration_enabled( $key, 'nav' ) ); ?>>
											<?php esc_html_e( 'Show in navigation', 'buddynext' ); ?>
										</label>
									</p>
								<?php endif; ?>
								<?php if ( ! empty( $entry['has_feed'] ) ) : ?>
									<p>
										<label>
											<input type="checkbox" name="feed[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( buddynext_integration_enabled( $key, 'feed' ) ); ?>>
											<?php esc_html_e( 'Post to the activity feed', 'buddynext' ); ?>
										</label>
									</p>
								<?php endif; ?>
								<?php if ( ! empty( $bn_subtabs ) ) : ?>
									<fieldset class="bn-int-subtabs">
										<legend class="bn-av-section-desc"><?php esc_html_e( 'Sub-tabs', 'buddynext' ); ?></legend>
										<?php foreach ( $bn_subtabs as $bn_sub => $bn_sub_label ) : ?>
											<p>
												<label>
													<input type="checkbox" name="subtab[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $bn_sub ); ?>]" value="1" <?php checked( buddynext_integration_enabled( $key, 'nav', (string) $bn_sub ) ); ?>>
													<?php echo esc_html( (string) $bn_sub_label ); ?>
												</label>
											</p>
										<?php endforeach; ?>
									</fieldset>
								<?php endif; ?>
							</fieldset>
						<?php endforeach; ?>

						<p>
							<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save changes', 'buddynext' ); ?></button>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Persist the submitted toggles. Iterates the registry (the source of truth) so
	 * a missing checkbox is recorded as an explicit opt-out, never an unknown key.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_integration_controls_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$nav = isset( $_POST['nav'] ) && is_array( $_POST['nav'] ) ? wp_unslash( $_POST['nav'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$feed = isset( $_POST['feed'] ) && is_array( $_POST['feed'] ) ? wp_unslash( $_POST['feed'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$subtab = isset( $_POST['subtab'] ) && is_array( $_POST['subtab'] ) ? wp_unslash( $_POST['subtab'] ) : array();

		$ok = true;
		foreach ( buddynext_integrations() as $key => $entry ) {
			$key = sanitize_key( (string) $key );

			if ( ! empty( $entry['has_nav'] ) ) {
				$ok = $this->write_flag( "buddynext_integration_{$key}_nav", ! empty( $nav[ $key ] ) ) && $ok;
			}
			if ( ! empty( $entry['has_feed'] ) ) {
				$ok = $this->write_flag( "buddynext_integration_{$key}_feed", ! empty( $feed[ $key ] ) ) && $ok;
			}
			foreach ( (array) ( $entry['subtabs'] ?? array() ) as $sub => $unused_label ) {
				$sub = sanitize_key( (string) $sub );
				$on  = isset( $subtab[ $key ] ) && is_array( $subtab[ $key ] ) && ! empty( $subtab[ $key ][ $sub ] );
				$ok  = $this->write_flag( "buddynext_integration_{$key}_subtab_{$sub}", $on ) && $ok;
			}
		}

		wp_safe_redirect( \BuddyNext\Admin\AdminHub::tab_url( 'settings', 'integration-controls', array( 'bn_intctl' => $ok ? '1' : 'error' ) ) );
		exit;
	}

	/**
	 * Store a single on/off flag option, treating an unchanged value as success.
	 *
	 * @param string $option Option name.
	 * @param bool   $on     Enabled state.
	 * @return bool True when the option holds the intended value.
	 */
	private function write_flag( string $option, bool $on ): bool {
		$value   = $on ? '1' : '0';
		$current = (string) get_option( $option, '1' );
		return ( $current === $value ) || update_option( $option, $value, false );
	}
}
