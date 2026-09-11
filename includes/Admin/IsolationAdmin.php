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
 * owner skip any of them. Choices are stored in PluginIsolation::OPTION_STRIP and
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
		$family    = PluginIsolation::essentials();       // in-house floor, locked kept.
		$strip     = PluginIsolation::owner_strip_list();  // the owner's explicit skip choices.

		$kept     = array();
		$stripped = array();

		foreach ( $active as $basename ) {
			$basename = (string) $basename;

			// The in-house family is the mu-plugin's hard safety floor; a togglable
			// row would offer the owner a switch that does nothing, so it is not
			// listed here (it is described as always-kept in the screen copy).
			if ( in_array( $basename, $family, true ) ) {
				continue;
			}

			$row = array(
				'name'        => (string) ( $installed[ $basename ]['Name'] ?? $basename ),
				'description' => (string) ( $installed[ $basename ]['Description'] ?? '' ),
			);

			// Keep-by-default: a plugin is stripped only when the owner has ticked it.
			if ( in_array( $basename, $strip, true ) ) {
				$stripped[ $basename ] = $row;
			} else {
				$kept[ $basename ] = $row;
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
			AdminPageBase::render_notice( __( 'Could not save the isolation allow-list. Please try again.', 'buddynext' ), 'error' );
		} elseif ( '' !== $bn_flag ) {
			AdminPageBase::render_notice( __( 'Plugin isolation settings saved.', 'buddynext' ), 'success' );
		}

		$bn_groups = $this->classify();
		// Isolation ships OFF by default (opt-in). The panel copy used to assert in the
		// present tense that other plugins "are not loaded" — affirmatively FALSE while
		// the toggle is off, when every plugin IS loaded. Branch the copy on the real
		// state so it never lies (card 10284912236 item 4).
		$bn_isolation_on = PluginIsolation::is_enabled();
		?>
		<p class="bn-field-hint">
			<?php
			if ( $bn_isolation_on ) {
				esc_html_e( 'On BuddyNext pages (Activity, Members, Spaces, Messages, Notifications, Login) other plugins are not loaded. This keeps the community fast on large sites. Anything that has to change what members see on those pages — a translation or terminology override, a consent banner, a tracking script — must be kept active here, or it will simply have no effect on those pages.', 'buddynext' );
			} else {
				esc_html_e( 'Route isolation is currently OFF, so every plugin loads on BuddyNext pages (Activity, Members, Spaces, Messages, Notifications, Login) as normal. Turn it on to stop loading the plugins listed below on those pages and keep large communities fast. Anything that must change what members see there — a translation or terminology override, a consent banner, a tracking script — should be kept active here so it keeps working once isolation is on.', 'buddynext' );
			}
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-admin-hub__form-bare">
			<input type="hidden" name="action" value="bn_isolation_save">
			<?php wp_nonce_field( 'bn_isolation_save' ); ?>

			<div class="bn-settings-section">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Route isolation', 'buddynext' ); ?></span>
				</div>
				<div class="bn-ss-body">
					<div class="bn-toggle-row">
						<div class="bn-toggle-row__copy">
							<span class="bn-toggle-row__label"><?php esc_html_e( 'Enable route isolation', 'buddynext' ); ?></span>
							<p class="bn-field-hint">
								<?php
								// Branch on the real state so the hint never assumes the
								// wrong starting point. fde88ee5 branched the intro
								// paragraph but left this string present-tense-on.
								if ( $bn_isolation_on ) {
									esc_html_e( 'Route isolation is on. Turn it off only if isolation is causing a problem — large communities load faster with it on.', 'buddynext' );
								} else {
									esc_html_e( 'Route isolation is off, so every plugin loads on BuddyNext pages as normal. Turn it on to stop loading the plugins listed below there — large communities load faster with it on.', 'buddynext' );
								}
								?>
							</p>
						</div>
						<label class="bn-toggle-label">
							<input
								type="checkbox"
								name="isolation_enabled"
								value="1"
								role="switch"
								<?php checked( PluginIsolation::is_enabled() ); ?>
								aria-label="<?php esc_attr_e( 'Enable route isolation on BuddyNext pages', 'buddynext' ); ?>"
							>
							<span class="bn-toggle--inline"></span>
						</label>
					</div>
				</div>
			</div>

			<?php
			// One list of every OTHER active plugin, each with a "skip on BuddyNext
			// routes" toggle. Keep-by-default: unchecked = kept; the owner ticks the
			// heavy back-office plugins they want skipped. The in-house family is the
			// always-kept floor and is not listed. We do not classify third-party
			// plugins ourselves — a firewall/paywall/consent plugin is never skipped
			// unless the owner ticks it (card 10264291719).
			$bn_all = $bn_groups['kept'] + $bn_groups['stripped'];
			ksort( $bn_all );
			$bn_stripped = $bn_groups['stripped'];
			?>
			<div class="bn-settings-section">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Plugins on this site', 'buddynext' ); ?></span>
					<span class="bn-badge" data-tone="warn"><?php echo esc_html( (string) count( $bn_stripped ) ); ?></span>
				</div>
				<div class="bn-ss-body">
					<p class="bn-field-hint">
						<?php esc_html_e( 'BuddyNext and the Wbcom family are always kept. Every other active plugin is kept on community pages unless you switch it off here — turn off only heavy back-office plugins a community page does not need. Leave security, membership, consent and translation plugins on.', 'buddynext' ); ?>
					</p>
					<?php if ( empty( $bn_all ) ) : ?>
						<div class="bn-empty">
							<p><?php esc_html_e( 'No other plugins are active on this site, so there is nothing to skip.', 'buddynext' ); ?></p>
						</div>
					<?php else : ?>
						<?php foreach ( $bn_all as $bn_file => $bn_row ) : ?>
							<?php $bn_skipped = isset( $bn_stripped[ $bn_file ] ); ?>
							<div class="bn-toggle-row">
								<div class="bn-toggle-row__copy">
									<span class="bn-toggle-row__label"><?php echo esc_html( $bn_row['name'] ); ?></span>
									<p class="bn-field-hint">
										<?php
										echo esc_html( $bn_file );
										if ( $bn_skipped && $bn_isolation_on ) {
											echo ' — ' . esc_html__( 'skipped on BuddyNext pages', 'buddynext' );
										} elseif ( $bn_skipped ) {
											echo ' — ' . esc_html__( 'will be skipped once isolation is on', 'buddynext' );
										}
										?>
									</p>
								</div>
								<label class="bn-toggle-label">
									<input
										type="checkbox"
										name="strip[]"
										value="<?php echo esc_attr( $bn_file ); ?>"
										role="switch"
										<?php checked( $bn_skipped ); ?>
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin name. */ __( 'Skip %s on BuddyNext pages', 'buddynext' ), $bn_row['name'] ) ); ?>"
									>
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
	 * Persist the owner's skip (strip) selection.
	 *
	 * Only genuinely-installed basenames are stored, so a stale form or a
	 * hand-crafted POST cannot seed the strip list with arbitrary strings. The
	 * in-house family is dropped rather than stored — owner_strip_list() applies
	 * that safety floor at read time regardless, so it can never be stripped.
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
		$submitted = isset( $_POST['strip'] ) && is_array( $_POST['strip'] ) ? wp_unslash( $_POST['strip'] ) : array();

		$installed = get_plugins();
		$family    = PluginIsolation::essentials();
		$strip     = array();

		foreach ( $submitted as $basename ) {
			$basename = is_string( $basename ) ? trim( $basename ) : '';

			// Genuinely installed, and never the in-house family (belt-and-braces —
			// owner_strip_list() also removes the family at read time).
			if ( '' === $basename || ! isset( $installed[ $basename ] ) || in_array( $basename, $family, true ) ) {
				continue;
			}

			$strip[] = $basename;
		}

		$strip = array_values( array_unique( $strip ) );
		sort( $strip );

		$ok = update_option( PluginIsolation::OPTION_STRIP, $strip, false );

		// Master switch. Absent checkbox = off. Autoloaded (true): the mu-plugin
		// and is_enabled() read it on every front-end request, so it must ride the
		// autoloaded-options cache rather than hitting the DB each time (card
		// 10264291719).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above by check_admin_referer().
		update_option( PluginIsolation::OPTION_ENABLED, empty( $_POST['isolation_enabled'] ) ? '0' : '1', true );

		// The mirror the mu-plugin reads is rebuilt from the owner strip list, so the
		// change takes effect on the very next front-end request rather than
		// whenever the next `init` sync happens to run.
		( new PluginIsolation() )->sync_option();

		wp_safe_redirect(
			add_query_arg(
				'bn_isolation',
				$ok || PluginIsolation::owner_strip_list() === $strip ? 'saved' : 'error',
				AdminHub::tab_url( 'settings', 'plugin-isolation' )
			)
		);
		exit;
	}
}
