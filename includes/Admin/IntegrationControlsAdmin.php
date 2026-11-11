<?php
/**
 * BuddyNext — Integration display & activity controls (admin tab).
 *
 * Renders one table row per registered integration (from the open
 * `buddynext_integrations` registry) with a switch per surface: navigation, the
 * activity feed and search, with its navigation sub-tabs folded under the row.
 * Settings an integration adds of its own get their own section below the table. 100% registry-driven: a new in-house or
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
			__( 'Integration Settings', 'buddynext' ),
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
			AdminPageBase::render_notice( __( 'Could not save integration settings. Try again.', 'buddynext' ), 'error' );
		} elseif ( '' !== $bn_flag ) {
			AdminPageBase::render_notice( __( 'Integration settings saved.', 'buddynext' ), 'success' );
		}

		$integrations = buddynext_integrations();
		$bn_waiting   = \BuddyNext\Integrations\CompanionRegistry::waiting_for_pro();
		$bn_pro       = \BuddyNext\Integrations\CompanionRegistry::pro_action();

		echo '<p class="bn-field-hint">';
		printf(
			/* translators: %s: link to the Add-ons tab. */
			esc_html__( 'Choose where each installed integration appears. Install them under %s.', 'buddynext' ),
			'<a href="' . esc_url( \BuddyNext\Admin\AdminHub::tab_url( 'settings', 'integrations' ) ) . '">' . esc_html__( 'Add-ons', 'buddynext' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href is esc_url'd and the link text is esc_html'd.
		);
		echo '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-admin-hub__form-bare">
			<input type="hidden" name="action" value="bn_integration_controls_save">
			<?php wp_nonce_field( 'bn_integration_controls_save' ); ?>

			<?php
			// Active partners whose bridge ships in BuddyNext Pro: say they are active,
			// what Pro adds with each, and the one action that connects them all -
			// never "activate an integration" for a plugin that is already active.
			if ( ! empty( $bn_waiting ) ) :
				?>
				<div class="bn-settings-section">
					<div class="bn-ss-header">
						<div class="bn-ss-heading">
							<span class="bn-ss-title"><?php esc_html_e( 'Active, waiting for BuddyNext Pro', 'buddynext' ); ?></span>
						</div>
					</div>
					<div class="bn-ss-body">
						<ul>
							<?php foreach ( $bn_waiting as $bn_w ) : ?>
								<li>
									<?php
									/* translators: 1: partner plugin name, 2: what BuddyNext Pro adds with it. */
									echo esc_html( sprintf( __( '%1$s is active. With BuddyNext Pro: %2$s', 'buddynext' ), (string) ( $bn_w['label'] ?? '' ), (string) ( $bn_w['unlocks'] ?? '' ) ) );
									?>
								</li>
							<?php endforeach; ?>
						</ul>
						<?php if ( null !== $bn_pro ) : ?>
							<a class="bn-btn" data-variant="primary" data-size="sm" href="<?php echo esc_url( $bn_pro['url'] ); ?>"<?php echo $bn_pro['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo esc_html( $bn_pro['label'] ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( empty( $integrations ) && empty( $bn_waiting ) ) : ?>
				<div class="bn-settings-section">
					<div class="bn-ss-body">
						<div class="bn-empty">
							<p><?php esc_html_e( 'No controllable integrations are active yet. Activate an integration (such as WP Career Board, WB Listora, Learnomy, Jetonomy, or WB Gamification) and it will appear here.', 'buddynext' ); ?></p>
						</div>
					</div>
				</div>
			<?php else : ?>
				<?php $bn_extras = array(); ?>
				<div class="bn-settings-section">
					<div class="bn-ss-header">
						<div class="bn-ss-heading">
							<span class="bn-ss-title"><?php esc_html_e( 'Where each integration appears', 'buddynext' ); ?></span>
							<span class="bn-ss-sub"><?php esc_html_e( 'Navigation adds its tab to member menus. Feed posts its new activity to the community feed. Search lets members find its content; switching search off also removes what was already indexed.', 'buddynext' ); ?></span>
						</div>
					</div>
					<div class="bn-int-matrix-wrap">
						<table class="bn-table bn-int-matrix">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Integration', 'buddynext' ); ?></th>
									<th scope="col" data-align="center"><?php esc_html_e( 'Navigation', 'buddynext' ); ?></th>
									<th scope="col" data-align="center"><?php esc_html_e( 'Feed', 'buddynext' ); ?></th>
									<th scope="col" data-align="center"><?php esc_html_e( 'Search', 'buddynext' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $integrations as $key => $entry ) : ?>
								<?php
								$key        = (string) $key;
								$bn_label   = (string) ( $entry['label'] ?? $key );
								$bn_subtabs = (array) ( $entry['subtabs'] ?? array() );
								$bn_version = (string) ( $entry['version'] ?? '' );
								// Below the floor the bridge's seams no-op ("Update needed"); above the
								// tested version the partner is ahead of the bridge ("Newer partner": the
								// bridge still works and is due a refresh, the owner has nothing to update).
								$bn_floor     = (string) ( $entry['min_version'] ?? '' );
								$bn_tested    = (string) ( $entry['tested_version'] ?? '' );
								$bn_stale     = ( '' !== $bn_floor && '' !== $bn_version && version_compare( $bn_version, $bn_floor, '<' ) );
								$bn_behind    = ( ! $bn_stale && '' !== $bn_tested && '' !== $bn_version && version_compare( $bn_version, $bn_tested, '>' ) );
								$bn_companion = \BuddyNext\Integrations\CompanionRegistry::for_integration( $key );
								$bn_product   = (string) ( $bn_companion['label'] ?? $bn_label );

								ob_start();
								( new \BuddyNext\Addons\AddonSettingsForm() )->render( $key );
								$bn_extra = trim( (string) ob_get_clean() );
								if ( '' !== $bn_extra ) {
									$bn_extras[] = array( $bn_product, $bn_extra );
								}

								$bn_meta = array();
								if ( $bn_product !== $bn_label ) {
									$bn_meta[] = $bn_label;
								}
								if ( '' !== $bn_version ) {
									/* translators: %s: installed partner version. */
									$bn_meta[] = sprintf( __( 'Version %s', 'buddynext' ), $bn_version );
								}
								?>
								<tr>
									<th scope="row" class="bn-int-matrix__name">
										<span class="bn-int-matrix__title"><?php echo esc_html( $bn_product ); ?></span>
										<?php if ( $bn_stale ) : ?>
											<span class="bn-badge" data-tone="warn"><?php esc_html_e( 'Update needed', 'buddynext' ); ?></span>
										<?php elseif ( $bn_behind ) : ?>
											<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Newer partner', 'buddynext' ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( $bn_meta ) ) : ?>
											<span class="bn-int-matrix__meta"><?php echo esc_html( implode( ' · ', $bn_meta ) ); ?></span>
										<?php endif; ?>
										<?php if ( $bn_stale ) : ?>
											<span class="bn-field-hint">
												<?php
												/* translators: %s: required minimum version. */
												printf( esc_html__( 'Update to %s or newer to enable every feature this integration offers.', 'buddynext' ), esc_html( $bn_floor ) );
												?>
											</span>
										<?php elseif ( $bn_behind ) : ?>
											<span class="bn-field-hint">
												<?php
												/* translators: %s: version the bridge was built for. */
												printf( esc_html__( 'Built for %s. Its newest features may not be wired yet.', 'buddynext' ), esc_html( $bn_tested ) );
												?>
											</span>
										<?php endif; ?>
										<?php foreach ( \BuddyNext\Integrations\IntegrationRegistry::features_needing_update( $entry ) as $bn_feature => $bn_needs ) : ?>
											<span class="bn-field-hint">
												<?php
												/* translators: 1: feature name (may be plural), 2: partner version it needs. */
												printf( esc_html__( '%1$s: available from version %2$s. Everything else works now.', 'buddynext' ), esc_html( $bn_feature ), esc_html( $bn_needs ) );
												?>
											</span>
										<?php endforeach; ?>
									</th>
									<?php
									$bn_nav_label = (string) ( $entry['nav_label'] ?? '' );
									$this->toggle_cell(
										! empty( $entry['has_nav'] ),
										"nav[{$key}]",
										buddynext_integration_enabled( $key, 'nav' ),
										'' !== $bn_nav_label
											/* translators: 1: name of the tab, e.g. Discussions, 2: integration name. */
											? sprintf( __( 'Show the %1$s tab (%2$s) in navigation', 'buddynext' ), $bn_nav_label, $bn_label )
											/* translators: %s: integration name. */
											: sprintf( __( 'Show %s in navigation', 'buddynext' ), $bn_label )
									);
									/* translators: %s: integration name. */
									$this->toggle_cell( ! empty( $entry['has_feed'] ), "feed[{$key}]", buddynext_integration_enabled( $key, 'feed' ), sprintf( __( 'Post %s events to the activity feed', 'buddynext' ), $bn_label ) );
									/* translators: %s: integration name. */
									$this->toggle_cell( ! empty( $entry['has_search'] ), "search[{$key}]", buddynext_integration_enabled( $key, 'search' ), sprintf( __( 'Include %s content in community search', 'buddynext' ), $bn_label ) );
									?>
								</tr>
								<?php if ( ! empty( $bn_subtabs ) ) : ?>
									<tr class="bn-int-matrix__subrow">
										<td colspan="4">
											<span class="bn-int-subtab-group__kicker">
												<?php
												/* translators: %s: integration name. */
												echo esc_html( sprintf( __( '%s navigation sub-tabs', 'buddynext' ), $bn_product ) );
												?>
											</span>
											<div class="bn-int-matrix__subtabs">
												<?php foreach ( $bn_subtabs as $bn_sub => $bn_sub_label ) : ?>
													<?php $bn_sub_off = $entry['subtabs_unavailable'][ $bn_sub ] ?? null; ?>
													<div class="bn-int-matrix__subtab">
														<label class="bn-toggle-label">
															<?php // Raw sub flag, NOT the parent-AND-sub helper: with the parent nav off the helper reads false and the next save would write explicit 0s. ?>
															<input type="checkbox" name="subtab[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $bn_sub ); ?>]" value="1" <?php checked( null === $bn_sub_off && '0' !== (string) get_option( "buddynext_integration_{$key}_subtab_{$bn_sub}", '1' ) ); ?> <?php disabled( null !== $bn_sub_off ); ?> role="switch">
															<span class="bn-toggle--inline"></span>
															<span class="bn-int-matrix__subtab-label"><?php echo esc_html( (string) $bn_sub_label ); ?></span>
														</label>
														<?php if ( null !== $bn_sub_off ) : ?>
															<span class="bn-field-hint"><?php echo esc_html( $bn_sub_off['note'] ); ?></span>
															<?php if ( '' !== $bn_sub_off['action_url'] && '' !== $bn_sub_off['action_label'] ) : ?>
																<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( $bn_sub_off['action_url'] ); ?>"><?php echo esc_html( $bn_sub_off['action_label'] ); ?></a>
															<?php endif; ?>
														<?php endif; ?>
													</div>
												<?php endforeach; ?>
											</div>
										</td>
									</tr>
								<?php endif; ?>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

				<?php foreach ( $bn_extras as list( $bn_extra_title, $bn_extra_html ) ) : ?>
					<div class="bn-settings-section">
						<div class="bn-ss-header">
							<span class="bn-ss-title">
								<?php
								/* translators: %s: integration name. */
								echo esc_html( sprintf( __( '%s settings', 'buddynext' ), $bn_extra_title ) );
								?>
							</span>
						</div>
						<div class="bn-ss-body">
							<?php echo $bn_extra_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AddonSettingsForm escapes every row it prints. ?>
						</div>
					</div>
				<?php endforeach; ?>

				<div class="bn-save-bar">
					<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save changes', 'buddynext' ); ?></button>
				</div>
			<?php endif; ?>
		</form>
		<?php
		// Family plugins not yet connected, each with the step it actually needs next.
		// The whole block is skipped on a site where every companion is connected.
		$bn_available = array();
		if ( class_exists( '\\BuddyNext\\Integrations\\CompanionRegistry' ) ) {
			foreach ( \BuddyNext\Integrations\CompanionRegistry::all() as $bn_slug => $bn_c ) {
				$bn_step = \BuddyNext\Integrations\CompanionRegistry::next_step( (string) $bn_slug );
				if ( null !== $bn_step ) {
					$bn_available[] = array( $bn_c, $bn_step );
				}
			}
		}
		if ( ! empty( $bn_available ) ) :
			?>
			<div class="bn-settings-section">
				<div class="bn-ss-header">
					<span class="bn-ss-title"><?php esc_html_e( 'Available to add', 'buddynext' ); ?></span>
				</div>
				<div class="bn-ss-body">
					<?php
					foreach ( $bn_available as list( $bn_c, $bn_step ) ) :
						$bn_al = (string) ( $bn_c['label'] ?? '' );
						$bn_aw = (string) ( $bn_c['why'] ?? ( $bn_c['unlocks'] ?? '' ) );
						if ( '' === $bn_al ) {
							continue;
						}
						?>
						<div class="bn-toggle-row">
							<div class="bn-toggle-row__copy">
								<span class="bn-toggle-row__label"><?php echo esc_html( $bn_al ); ?></span>
								<?php if ( '' !== $bn_aw ) : ?>
									<p class="bn-field-hint"><?php echo esc_html( $bn_aw ); ?></p>
								<?php endif; ?>
							</div>
							<span class="bn-toggle-row__action">
								<?php if ( 'needs_pro' === $bn_step['state'] ) : ?>
									<span class="bn-field-hint"><?php esc_html_e( 'Needs BuddyNext Pro', 'buddynext' ); ?></span>
								<?php endif; ?>
								<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( $bn_step['url'] ); ?>"<?php echo $bn_step['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
									<?php echo esc_html( $bn_step['label'] ); ?>
								</a>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		endif;
	}

	/**
	 * Print one switch cell of the integration table, or a dash when the
	 * integration has no such surface.
	 *
	 * @param bool   $available Whether the integration offers this surface.
	 * @param string $name      Field name.
	 * @param bool   $on        Current state.
	 * @param string $aria      Accessible name of the switch.
	 * @return void
	 */
	private function toggle_cell( bool $available, string $name, bool $on, string $aria ): void {
		echo '<td data-align="center">';
		if ( $available ) {
			printf(
				'<label class="bn-toggle-label"><input type="checkbox" name="%1$s" value="1" %2$s role="switch" aria-label="%3$s"><span class="bn-toggle--inline"></span></label>',
				esc_attr( $name ),
				checked( $on, true, false ),
				esc_attr( $aria )
			);
		} else {
			echo '<span class="bn-int-matrix__none" aria-hidden="true">&ndash;</span><span class="screen-reader-text">' . esc_html__( 'Not available', 'buddynext' ) . '</span>';
		}
		echo '</td>';
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$search = isset( $_POST['search'] ) && is_array( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : array();

		$ok = true;
		foreach ( buddynext_integrations() as $key => $entry ) {
			$key = sanitize_key( (string) $key );

			if ( ! empty( $entry['has_nav'] ) ) {
				$ok = $this->write_flag( "buddynext_integration_{$key}_nav", ! empty( $nav[ $key ] ) ) && $ok;
			}
			if ( ! empty( $entry['has_feed'] ) ) {
				$ok = $this->write_flag( "buddynext_integration_{$key}_feed", ! empty( $feed[ $key ] ) ) && $ok;
			}
			if ( ! empty( $entry['has_search'] ) ) {
				$was = buddynext_integration_enabled( $key, 'search' );
				$now = ! empty( $search[ $key ] );

				$ok = $this->write_flag( "buddynext_integration_{$key}_search", $now ) && $ok;

				// Only on an actual transition — re-saving the settings page must not purge
				// and rebuild the index of every integration that was already switched off.
				if ( $was !== $now ) {
					if ( $now ) {
						/**
						 * An integration's search indexing was switched ON.
						 *
						 * The integration's own bridge listens for its key and backfills
						 * its content, because only the bridge knows which object_type it
						 * owns. Free deliberately does not: it must never learn that
						 * 'careerboard' means 'job'.
						 *
						 * @since 1.0.8
						 *
						 * @param string $key Integration key.
						 */
						do_action( 'buddynext_integration_search_enabled', $key );
					} else {
						/**
						 * An integration's search indexing was switched OFF.
						 *
						 * The bridge listens for its key and calls
						 * SearchService::deindex_type() for the type it owns. Gating new
						 * writes is not enough on its own — everything indexed while the
						 * integration was on would otherwise keep surfacing forever.
						 *
						 * @since 1.0.8
						 *
						 * @param string $key Integration key.
						 */
						do_action( 'buddynext_integration_search_disabled', $key );
					}
				}
			}
			foreach ( (array) ( $entry['subtabs'] ?? array() ) as $sub => $unused_label ) {
				$sub = sanitize_key( (string) $sub );
				// Unavailable right now: its switch was disabled, so keep the stored choice.
				if ( isset( $entry['subtabs_unavailable'][ $sub ] ) ) {
					continue;
				}
				$on = isset( $subtab[ $key ] ) && is_array( $subtab[ $key ] ) && ! empty( $subtab[ $key ][ $sub ] );
				$ok = $this->write_flag( "buddynext_integration_{$key}_subtab_{$sub}", $on ) && $ok;
			}
			$ok = ( new \BuddyNext\Addons\AddonSettingsForm() )->save( $key ) && $ok;
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
