<?php
/**
 * BuddyNext — Plugin isolation allow-list (admin tab).
 *
 * BuddyNext strips non-allow-listed plugins on its own front-end routes to save
 * 20-40MB per request. That is a real win, but it was invisible and unmanageable:
 * the ONLY way to keep a plugin alive was the developer-facing
 * `buddynext_isolation_plugins` PHP filter, so a site owner who noticed that their
 * Loco Translate terminology override, or their affiliate tracker, or their
 * consent banner simply did not apply on /spaces/ had no way to fix it without
 * writing code — and no way to even discover WHY.
 *
 * This screen shows exactly which active plugins are being stripped and lets the
 * owner keep any of them. Choices are stored in PluginIsolation::OPTION_KEEP and
 * merged into the mirror option the isolation mu-plugin already reads, so nothing
 * about the mu-plugin has to change and an older on-disk copy keeps working.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use BuddyNext\Core\PluginIsolation;

/**
 * The BuddyNext → Plugin isolation admin tab.
 */
class IsolationAdmin {

	/**
	 * Wire the save handler and register the tab. Called from Plugin::init().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_isolation_save', array( $this, 'handle_save' ) );

		AdminHub::register_tab(
			'settings',
			'plugin-isolation',
			__( 'Plugin isolation', 'buddynext' ),
			array( $this, 'render_page' ),
			array( 'group' => __( 'Integrations', 'buddynext' ) )
		);
	}

	/**
	 * Active plugins split into those isolation keeps and those it strips.
	 *
	 * BuddyNext, Pro and the in-house integration family are the allow-list floor
	 * and are shown as locked rather than hidden — an owner asking "why is this
	 * one still running?" deserves an answer on the same screen.
	 *
	 * @return array{kept: array<string, array<string, string>>, stripped: array<string, array<string, string>>}
	 */
	private function classify(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$active    = (array) get_option( 'active_plugins', array() );
		$allowed   = PluginIsolation::integration_plugins();
		$owner     = PluginIsolation::owner_keep_list();

		$kept     = array();
		$stripped = array();

		foreach ( $active as $basename ) {
			$basename = (string) $basename;

			// BuddyNext itself is the mu-plugin's hard safety floor; listing it as
			// a togglable row would offer the owner a switch that does nothing.
			if ( in_array( $basename, array( 'buddynext/buddynext.php', 'buddynext-pro/buddynext-pro.php' ), true ) ) {
				continue;
			}

			$row = array(
				'name'        => (string) ( $installed[ $basename ]['Name'] ?? $basename ),
				'description' => (string) ( $installed[ $basename ]['Description'] ?? '' ),
				'by_owner'    => in_array( $basename, $owner, true ) ? '1' : '',
			);

			if ( in_array( $basename, $allowed, true ) ) {
				$kept[ $basename ] = $row;
			} else {
				$stripped[ $basename ] = $row;
			}
		}

		ksort( $kept );
		ksort( $stripped );

		return array(
			'kept'     => $kept,
			'stripped' => $stripped,
		);
	}

	/**
	 * Render the allow-list screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_flag = isset( $_GET['bn_isolation'] ) ? sanitize_key( wp_unslash( $_GET['bn_isolation'] ) ) : '';
		if ( 'error' === $bn_flag ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not save the isolation allow-list. Please try again.', 'buddynext' ) . '</p></div>';
		} elseif ( '' !== $bn_flag ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plugin isolation settings saved.', 'buddynext' ) . '</p></div>';
		}

		$bn_groups = $this->classify();
		?>
		<p class="bn-field-hint">
			<?php esc_html_e( 'On BuddyNext pages (Activity, Members, Spaces, Messages, Notifications, Login) other plugins are not loaded. This keeps the community fast on large sites. Anything that has to change what members see on those pages — a translation or terminology override, a consent banner, a tracking script — must be kept active here, or it will simply have no effect on those pages.', 'buddynext' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-admin-hub__form-bare">
			<input type="hidden" name="action" value="bn_isolation_save">
			<?php wp_nonce_field( 'bn_isolation_save' ); ?>

			<div class="bn-settings-section">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Not loaded on BuddyNext pages', 'buddynext' ); ?></span>
					<span class="bn-badge" data-tone="warn"><?php echo esc_html( (string) count( $bn_groups['stripped'] ) ); ?></span>
				</div>
				<div class="bn-ss-body">
					<?php if ( empty( $bn_groups['stripped'] ) ) : ?>
						<div class="bn-empty">
							<p><?php esc_html_e( 'Every active plugin is currently kept on BuddyNext pages.', 'buddynext' ); ?></p>
						</div>
					<?php else : ?>
						<?php foreach ( $bn_groups['stripped'] as $bn_file => $bn_row ) : ?>
							<div class="bn-toggle-row">
								<div class="bn-toggle-row__copy">
									<span class="bn-toggle-row__label"><?php echo esc_html( $bn_row['name'] ); ?></span>
									<p class="bn-field-hint"><?php echo esc_html( $bn_file ); ?></p>
								</div>
								<label class="bn-toggle-label">
									<input
										type="checkbox"
										name="keep[]"
										value="<?php echo esc_attr( $bn_file ); ?>"
										role="switch"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin name. */ __( 'Keep %s active on BuddyNext pages', 'buddynext' ), $bn_row['name'] ) ); ?>"
									>
									<span class="bn-toggle--inline"></span>
								</label>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="bn-settings-section">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Kept on BuddyNext pages', 'buddynext' ); ?></span>
					<span class="bn-badge" data-tone="success"><?php echo esc_html( (string) count( $bn_groups['kept'] ) ); ?></span>
				</div>
				<div class="bn-ss-body">
					<?php if ( empty( $bn_groups['kept'] ) ) : ?>
						<div class="bn-empty">
							<p><?php esc_html_e( 'No other plugins are being kept yet.', 'buddynext' ); ?></p>
						</div>
					<?php else : ?>
						<?php foreach ( $bn_groups['kept'] as $bn_file => $bn_row ) : ?>
							<div class="bn-toggle-row">
								<div class="bn-toggle-row__copy">
									<span class="bn-toggle-row__label"><?php echo esc_html( $bn_row['name'] ); ?></span>
									<p class="bn-field-hint">
										<?php
										echo esc_html( $bn_file );
										if ( '' === $bn_row['by_owner'] ) {
											echo ' — ' . esc_html__( 'always kept', 'buddynext' );
										}
										?>
									</p>
								</div>
								<label class="bn-toggle-label">
									<?php if ( '' === $bn_row['by_owner'] ) : ?>
										<input type="checkbox" checked disabled role="switch" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin name. */ __( '%s is always kept active on BuddyNext pages', 'buddynext' ), $bn_row['name'] ) ); ?>">
									<?php else : ?>
										<input
											type="checkbox"
											name="keep[]"
											value="<?php echo esc_attr( $bn_file ); ?>"
											checked
											role="switch"
											aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin name. */ __( 'Keep %s active on BuddyNext pages', 'buddynext' ), $bn_row['name'] ) ); ?>"
										>
									<?php endif; ?>
									<span class="bn-toggle--inline"></span>
								</label>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="bn-save-bar">
				<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save changes', 'buddynext' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Persist the owner's keep-alive selection.
	 *
	 * Only basenames that are genuinely installed are stored, so a stale form or a
	 * hand-crafted POST cannot seed the allow-list with arbitrary strings. Entries
	 * already on the built-in floor are dropped rather than duplicated — the floor
	 * is applied at read time regardless.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_isolation_save' );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$submitted = isset( $_POST['keep'] ) && is_array( $_POST['keep'] ) ? wp_unslash( $_POST['keep'] ) : array();

		$installed = get_plugins();
		$keep      = array();

		foreach ( $submitted as $basename ) {
			$basename = is_string( $basename ) ? trim( $basename ) : '';

			if ( '' === $basename || ! isset( $installed[ $basename ] ) ) {
				continue;
			}

			$keep[] = $basename;
		}

		$keep = array_values( array_unique( $keep ) );
		sort( $keep );

		$ok = update_option( PluginIsolation::OPTION_KEEP, $keep, false );

		// The mirror the mu-plugin reads is rebuilt from the owner list, so the
		// change takes effect on the very next front-end request rather than
		// whenever the next `init` sync happens to run.
		( new PluginIsolation() )->sync_option();

		wp_safe_redirect(
			add_query_arg(
				'bn_isolation',
				$ok || PluginIsolation::owner_keep_list() === $keep ? 'saved' : 'error',
				AdminHub::tab_url( 'settings', 'plugin-isolation' )
			)
		);
		exit;
	}
}
