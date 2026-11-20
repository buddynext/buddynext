<?php
/**
 * BuddyNext — Integration display & activity controls (admin tab).
 *
 * Renders one card per registered integration (from the open
 * `buddynext_integrations` registry) with a switch per surface: navigation, the
 * activity feed and search, with its navigation sub-tabs folded under the card.
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
	 * Render the per-integration cards.
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

		$base    = defined( 'BUDDYNEXT_URL' ) ? constant( 'BUDDYNEXT_URL' ) : plugins_url( '', __DIR__ );
		$version = defined( 'BUDDYNEXT_VERSION' ) ? (string) constant( 'BUDDYNEXT_VERSION' ) : '1.0.0';
		wp_enqueue_script( 'bn-admin-integration-controls', $base . 'assets/js/admin/integration-controls.js', array(), $version, true );

		$integrations = buddynext_integrations();
		$bn_waiting   = \BuddyNext\Integrations\CompanionRegistry::waiting_for_pro();
		$bn_pro       = \BuddyNext\Integrations\CompanionRegistry::pro_action();

		echo '<p class="bn-field-hint bn-int-intro">';
		printf(
			/* translators: %s: link to the Add-ons tab. */
			esc_html__( 'Choose where each connected product appears in BuddyNext. Nothing changes for members until you save. Install more under %s.', 'buddynext' ),
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
				<?php
				$bn_extras = array();
				$bn_rows   = array();
				foreach ( $integrations as $key => $entry ) {
					$key          = (string) $key;
					$bn_label     = (string) ( $entry['label'] ?? $key );
					$bn_version   = (string) ( $entry['version'] ?? '' );
					$bn_floor     = (string) ( $entry['min_version'] ?? '' );
					$bn_tested    = (string) ( $entry['tested_version'] ?? '' );
					$bn_stale     = ( '' !== $bn_floor && '' !== $bn_version && version_compare( $bn_version, $bn_floor, '<' ) );
					$bn_behind    = ( ! $bn_stale && '' !== $bn_tested && '' !== $bn_version && version_compare( $bn_version, $bn_tested, '>' ) );
					$bn_needs     = \BuddyNext\Integrations\IntegrationRegistry::features_needing_update( $entry );
					$bn_unavail   = array_filter( (array) ( $entry['subtabs_unavailable'] ?? array() ) );
					$bn_companion = \BuddyNext\Integrations\CompanionRegistry::for_integration( $key );
					$bn_product   = (string) ( $bn_companion['label'] ?? $bn_label );
					$bn_mark      = null !== $bn_companion ? ProductIcon::url( (string) ( $bn_companion['icon'] ?? '' ), (string) ( $bn_companion['logo'] ?? $bn_companion['slug'] ) ) : '';
					$bn_rows[]    = array(
						'key'       => $key,
						'entry'     => $entry,
						'label'     => $bn_label,
						'product'   => $bn_product,
						'version'   => $bn_version,
						'floor'     => $bn_floor,
						'tested'    => $bn_tested,
						'stale'     => $bn_stale,
						'behind'    => $bn_behind,
						'needs'     => $bn_needs,
						'attention' => $bn_stale || ! empty( $bn_unavail ) || ! empty( $bn_needs ),
						// The icon follows the name: the active companion's mark, else the integration's own.
						'icon'      => ProductIcon::render( $bn_product, '' !== $bn_mark ? $bn_mark : (string) ( $entry['icon'] ?? '' ) ),
					);
				}
				?>
				<div class="bn-int-tools">
					<input type="search" class="bn-int-search" data-bn-int-search placeholder="<?php esc_attr_e( 'Search integrations', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'Search integrations', 'buddynext' ); ?>">
					<div class="bn-int-filters" role="group" aria-label="<?php esc_attr_e( 'Filter integrations', 'buddynext' ); ?>">
						<button type="button" class="bn-int-pill is-active" data-bn-int-filter="all" aria-pressed="true"><?php esc_html_e( 'All', 'buddynext' ); ?> <span data-bn-int-count="all"><?php echo esc_html( (string) count( $bn_rows ) ); ?></span></button>
						<button type="button" class="bn-int-pill" data-bn-int-filter="attention" aria-pressed="false"><?php esc_html_e( 'Needs attention', 'buddynext' ); ?> <span data-bn-int-count="attention"><?php echo esc_html( (string) count( array_filter( array_column( $bn_rows, 'attention' ) ) ) ); ?></span></button>
						<button type="button" class="bn-int-pill" data-bn-int-filter="hidden" aria-pressed="false"><?php esc_html_e( 'Hidden from members', 'buddynext' ); ?> <span data-bn-int-count="hidden">0</span></button>
					</div>
				</div>

				<ul class="bn-int-list" data-bn-int-list data-label-on="<?php echo esc_attr__( 'Connected', 'buddynext' ); ?>" data-label-hidden="<?php echo esc_attr__( 'Hidden', 'buddynext' ); ?>">
					<?php foreach ( $bn_rows as $bn_row ) : ?>
						<?php
						$key        = $bn_row['key'];
						$entry      = $bn_row['entry'];
						$bn_label   = $bn_row['label'];
						$bn_product = $bn_row['product'];
						$bn_subtabs = (array) ( $entry['subtabs'] ?? array() );
						$bn_version = $bn_row['version'];
						$bn_stale   = $bn_row['stale'];
						$bn_behind  = $bn_row['behind'];

						ob_start();
						( new \BuddyNext\Addons\AddonSettingsForm() )->render( $key );
						$bn_extra = trim( (string) ob_get_clean() );
						if ( '' !== $bn_extra ) {
							$bn_extras[] = array( $bn_product, $bn_extra );
						}

						$bn_surfaces = array(
							'nav'    => array( 'has_nav', __( 'Navigation', 'buddynext' ), __( 'Adds its tab to member menus.', 'buddynext' ) ),
							'feed'   => array( 'has_feed', __( 'Feed', 'buddynext' ), __( 'Posts its new activity to the community feed.', 'buddynext' ) ),
							'search' => array( 'has_search', __( 'Search', 'buddynext' ), __( 'Lets members find its content. Switching off also removes what was already indexed.', 'buddynext' ) ),
						);
						$bn_on_count = 0;
						foreach ( $bn_surfaces as $bn_sk => $bn_sd ) {
							if ( ! empty( $entry[ $bn_sd[0] ] ) && buddynext_integration_enabled( $key, $bn_sk ) ) {
								++$bn_on_count;
							}
						}
						$bn_offered = count( array_filter( array_map( static fn( array $d ): bool => ! empty( $entry[ $d[0] ] ), $bn_surfaces ) ) );
						$bn_meta    = array();
						if ( $bn_product !== $bn_label ) {
							$bn_meta[] = $bn_label;
						}
						if ( '' !== $bn_version ) {
							/* translators: %s: installed partner version. */
							$bn_meta[] = sprintf( __( 'Version %s', 'buddynext' ), $bn_version );
						}
						?>
						<li
							class="bn-int-card<?php echo $bn_row['attention'] ? ' is-open' : ''; ?>"
							data-bn-int-card
							data-name="<?php echo esc_attr( strtolower( $bn_product . ' ' . $bn_label ) ); ?>"
							data-attention="<?php echo $bn_row['attention'] ? '1' : '0'; ?>"
						>
							<div class="bn-int-row">
								<?php echo $bn_row['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ProductIcon escapes its markup. ?>
								<div class="bn-int-id">
									<span class="bn-int-card__title"><?php echo esc_html( $bn_product ); ?></span>
									<?php if ( ! empty( $bn_meta ) ) : ?>
										<span class="bn-int-card__meta"><?php echo esc_html( implode( ' · ', $bn_meta ) ); ?></span>
									<?php endif; ?>
								</div>
								<span class="bn-int-chip" data-bn-int-chip data-state="<?php echo esc_attr( $bn_row['attention'] ? 'attention' : ( $bn_on_count > 0 ? 'on' : 'hidden' ) ); ?>">
									<?php
									if ( $bn_row['attention'] ) {
										esc_html_e( 'Needs attention', 'buddynext' );
									} elseif ( $bn_on_count > 0 ) {
										esc_html_e( 'Connected', 'buddynext' );
									} else {
										esc_html_e( 'Hidden', 'buddynext' );
									}
									?>
								</span>
								<span class="bn-int-areas" aria-label="<?php esc_attr_e( 'Where it appears', 'buddynext' ); ?>">
									<?php foreach ( $bn_surfaces as $bn_sk => $bn_sd ) : ?>
										<?php if ( ! empty( $entry[ $bn_sd[0] ] ) ) : ?>
											<span class="bn-int-area<?php echo buddynext_integration_enabled( $key, $bn_sk ) ? '' : ' is-off'; ?>" data-bn-int-area="<?php echo esc_attr( $bn_sk ); ?>"><?php echo esc_html( $bn_sd[1] ); ?></span>
										<?php endif; ?>
									<?php endforeach; ?>
								</span>
								<?php if ( $bn_offered > 0 ) : ?>
									<?php // Master switch: sets every place at once. It has no name, so it is never saved on its own - the three switches below are. ?>
									<label class="bn-toggle-label bn-int-master">
										<input type="checkbox" role="switch" data-bn-int-master <?php checked( $bn_on_count > 0 ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: integration name. */ __( 'Show %s in BuddyNext', 'buddynext' ), $bn_product ) ); ?>">
										<span class="bn-toggle--inline"></span>
									</label>
								<?php endif; ?>
								<button type="button" class="bn-int-chev" data-bn-int-toggle aria-expanded="<?php echo $bn_row['attention'] ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: integration name. */ __( 'Details for %s', 'buddynext' ), $bn_product ) ); ?>"><?php echo \BuddyNext\Core\IconService::render( 'chevron-down', 'bn-int-chev__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns wp_kses'd SVG markup. ?></button>
							</div>

							<div class="bn-int-detail">
								<?php if ( $bn_stale || $bn_behind || ! empty( $bn_row['needs'] ) ) : ?>
									<div class="bn-int-note">
										<?php if ( $bn_stale ) : ?>
											<span>
												<?php
												/* translators: %s: required minimum version. */
												printf( esc_html__( 'Update to %s or newer to enable every feature this integration offers.', 'buddynext' ), esc_html( $bn_row['floor'] ) );
												?>
											</span>
										<?php elseif ( $bn_behind ) : ?>
											<span>
												<?php
												/* translators: %s: version the bridge was built for. */
												printf( esc_html__( 'Built for %s. Its newest features may not be wired yet.', 'buddynext' ), esc_html( $bn_row['tested'] ) );
												?>
											</span>
										<?php endif; ?>
										<?php foreach ( $bn_row['needs'] as $bn_feature => $bn_needs ) : ?>
											<span>
												<?php
												/* translators: 1: feature name (may be plural), 2: partner version it needs. */
												printf( esc_html__( '%1$s: available from version %2$s. Everything else works now.', 'buddynext' ), esc_html( (string) $bn_feature ), esc_html( (string) $bn_needs ) );
												?>
											</span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<div class="bn-int-opts">
									<?php
									$bn_nav_label = (string) ( $entry['nav_label'] ?? '' );
									$bn_arias     = array(
										'nav'    => '' !== $bn_nav_label
											/* translators: 1: name of the tab, e.g. Discussions, 2: integration name. */
											? sprintf( __( 'Show the %1$s tab (%2$s) in navigation', 'buddynext' ), $bn_nav_label, $bn_label )
											/* translators: %s: integration name. */
											: sprintf( __( 'Show %s in navigation', 'buddynext' ), $bn_label ),
										/* translators: %s: integration name. */
										'feed'   => sprintf( __( 'Post %s events to the activity feed', 'buddynext' ), $bn_label ),
										/* translators: %s: integration name. */
										'search' => sprintf( __( 'Include %s content in community search', 'buddynext' ), $bn_label ),
									);
									foreach ( $bn_surfaces as $bn_sk => $bn_sd ) {
										$this->surface( $bn_sd[1], $bn_sd[2], ! empty( $entry[ $bn_sd[0] ] ), "{$bn_sk}[{$key}]", buddynext_integration_enabled( $key, $bn_sk ), $bn_arias[ $bn_sk ] );
									}
									?>
								</div>
								<?php if ( ! empty( $bn_subtabs ) ) : ?>
									<div class="bn-int-subs">
										<span class="bn-int-subs__label"><?php esc_html_e( 'Tabs inside Navigation', 'buddynext' ); ?></span>
										<?php foreach ( $bn_subtabs as $bn_sub => $bn_sub_label ) : ?>
											<?php $bn_sub_off = $entry['subtabs_unavailable'][ $bn_sub ] ?? null; ?>
											<span class="bn-int-sub">
												<label class="bn-int-pillswitch">
													<?php // Raw sub flag, NOT the parent-AND-sub helper: with the parent nav off the helper reads false and the next save would write explicit 0s. ?>
													<input type="checkbox" name="subtab[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( (string) $bn_sub ); ?>]" value="1" <?php checked( null === $bn_sub_off && '0' !== (string) get_option( "buddynext_integration_{$key}_subtab_{$bn_sub}", '1' ) ); ?> <?php disabled( null !== $bn_sub_off ); ?> role="switch">
													<span><?php echo esc_html( (string) $bn_sub_label ); ?></span>
												</label>
												<?php if ( null !== $bn_sub_off ) : ?>
													<span class="bn-field-hint"><?php echo esc_html( $bn_sub_off['note'] ); ?></span>
													<?php if ( '' !== $bn_sub_off['action_url'] && '' !== $bn_sub_off['action_label'] ) : ?>
														<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( $bn_sub_off['action_url'] ); ?>"><?php echo esc_html( $bn_sub_off['action_label'] ); ?></a>
													<?php endif; ?>
												<?php endif; ?>
											</span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="bn-int-empty" data-bn-int-empty hidden><?php esc_html_e( 'No integrations match.', 'buddynext' ); ?></p>

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
					$bn_available[] = array( (string) $bn_slug, $bn_c, $bn_step );
				}
			}
		}
		if ( ! empty( $bn_available ) ) :
			?>
			<h2 class="bn-int-group"><?php esc_html_e( 'Available to connect', 'buddynext' ); ?></h2>
			<ul class="bn-int-list">
				<?php
				foreach ( $bn_available as list( $bn_slug, $bn_c, $bn_step ) ) :
					$bn_al = (string) ( $bn_c['label'] ?? '' );
					$bn_aw = (string) ( $bn_c['why'] ?? ( $bn_c['unlocks'] ?? '' ) );
					if ( '' === $bn_al ) {
						continue;
					}
					?>
					<li class="bn-int-card">
						<div class="bn-int-row">
							<?php echo ProductIcon::for_companion( $bn_slug, $bn_c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ProductIcon escapes its markup. ?>
							<div class="bn-int-id">
								<span class="bn-int-card__title"><?php echo esc_html( $bn_al ); ?></span>
								<?php if ( '' !== $bn_aw ) : ?>
									<span class="bn-int-card__meta"><?php echo esc_html( $bn_aw ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( 'needs_pro' === $bn_step['state'] ) : ?>
								<span class="bn-int-chip" data-state="hidden"><?php esc_html_e( 'Needs BuddyNext Pro', 'buddynext' ); ?></span>
							<?php endif; ?>
							<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( $bn_step['url'] ); ?>"<?php echo $bn_step['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
								<?php echo esc_html( $bn_step['label'] ); ?>
							</a>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
		endif;
	}

	/**
	 * Print one surface of an integration card: what the surface does, and its
	 * switch, or "Not offered" when the integration has no such surface.
	 *
	 * @param string $label     Surface name (Navigation, Feed, Search).
	 * @param string $hint      One line on what switching it on does.
	 * @param bool   $available Whether the integration offers this surface.
	 * @param string $name      Field name.
	 * @param bool   $on        Current state.
	 * @param string $aria      Accessible name of the switch.
	 * @return void
	 */
	private function surface( string $label, string $hint, bool $available, string $name, bool $on, string $aria ): void {
		if ( ! $available ) {
			echo '<div class="bn-int-opt is-off"><span class="bn-int-opt__copy"><span class="bn-int-opt__label">' . esc_html( $label ) . '</span><span class="bn-int-opt__hint">' . esc_html__( 'Not offered by this integration.', 'buddynext' ) . '</span></span></div>';
			return;
		}
		printf(
			'<label class="bn-int-opt"><span class="bn-toggle-label"><input type="checkbox" name="%1$s" value="1" %2$s role="switch" aria-label="%3$s" data-bn-int-area-input="%6$s"><span class="bn-toggle--inline"></span></span><span class="bn-int-opt__copy"><span class="bn-int-opt__label">%4$s</span><span class="bn-int-opt__hint">%5$s</span></span></label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_attr( $aria ),
			esc_html( $label ),
			esc_html( $hint ),
			esc_attr( (string) strstr( $name, '[', true ) )
		);
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
