<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext admin integration hub.
 *
 * Provides a submenu page listing all first-party addons that
 * integrate with BuddyNext, along with their activation status.
 *
 * Third-party code can register addons via the `buddynext_register_addons`
 * filter. Each addon entry is an associative array:
 *
 *   'id'          => string  Unique kebab-case identifier.
 *   'label'       => string  Human-readable name.
 *   'description' => string  Short description (1-2 sentences).
 *   'plugin_file' => string  Plugin file relative to plugins dir.
 *   'logo_icon'   => string  Icon slug from assets/icons/.
 *   'logo_bg'     => string  Colour variant: purple|teal|amber|blue.
 *   'category'    => string  Short category label (e.g. "Forum Engine").
 *   'url'         => string  Product URL for "Get Plugin" CTA.
 *   'enables'     => array   Feature strings shown in the card body.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Admin hub for discovering and displaying BuddyNext addon status.
 */
class IntegrationHub extends AdminPageBase {

	/**
	 * Filter name for registering addon entries.
	 */
	public const FILTER_ADDONS = 'buddynext_register_addons';

	/**
	 * Cached addon list, populated on first call to get_addons().
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $addons_cache = null;

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
	}

	/**
	 * Add the Integrations submenu under the BuddyNext top-level menu.
	 *
	 * @return void
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'buddynext',
			__( 'Integrations', 'buddynext' ),
			__( 'Integrations', 'buddynext' ),
			'manage_options',
			'buddynext-integrations',
			array( $this, 'render_page' )
		);
	}

	// ── AdminPageBase interface ────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Integration Hub', 'buddynext' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return __( 'Connect BuddyNext with your WordPress ecosystem. Each integration adds new tabs, widgets, and features.', 'buddynext' );
	}

	/**
	 * Override page header to add integration stat pills.
	 *
	 * @return void
	 */
	protected function render_page_header(): void {
		$addons       = $this->get_addons();
		$active_count = count( array_filter( $addons, fn( array $a ) => $a['active'] ) );
		$avail_count  = count( array_filter( $addons, fn( array $a ) => ! $a['active'] ) );
		?>
		<div class="bn-admin-header bn-ih-header">
			<div class="bn-ih-header-left">
				<h1 class="bn-admin-title"><?php echo esc_html( $this->get_title() ); ?></h1>
				<p class="bn-admin-sub"><?php echo esc_html( $this->get_subtitle() ); ?></p>
			</div>
			<div class="bn-ih-stats" aria-label="<?php esc_attr_e( 'Integration status summary', 'buddynext' ); ?>">
				<?php if ( $active_count > 0 ) : ?>
					<span class="bn-ih-stat-pill bn-ih-stat-pill--active">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of active integrations */
								_n( '%d Active', '%d Active', $active_count, 'buddynext' ),
								$active_count
							)
						);
						?>
					</span>
				<?php endif; ?>
				<?php if ( $avail_count > 0 ) : ?>
					<span class="bn-ih-stat-pill bn-ih-stat-pill--available">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of not-installed integrations */
								_n( '%d Not Installed', '%d Not Installed', $avail_count, 'buddynext' ),
								$avail_count
							)
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ── render_content ────────────────────────────────────────────────────────

	/**
	 * Render the integration hub page body.
	 *
	 * @return void
	 */
	protected function render_content(): void {
		$addons   = $this->get_addons();
		$active   = array_values( array_filter( $addons, fn( array $a ) => $a['active'] ) );
		$inactive = array_values( array_filter( $addons, fn( array $a ) => ! $a['active'] ) );

		if ( empty( $addons ) ) {
			?>
			<p><?php esc_html_e( 'No integrations registered.', 'buddynext' ); ?></p>
			<?php
			return;
		}

		if ( ! empty( $active ) ) {
			$this->render_section_divider( __( 'Active Integrations', 'buddynext' ), 'green' );
			?>
			<div class="bn-addon-grid">
				<?php foreach ( $active as $addon ) : ?>
					<?php $this->render_active_card( $addon ); ?>
				<?php endforeach; ?>
			</div>
			<?php
		}

		if ( ! empty( $inactive ) ) {
			$this->render_section_divider( __( 'Not Installed', 'buddynext' ), 'amber' );
			?>
			<div class="bn-addon-grid">
				<?php foreach ( $inactive as $addon ) : ?>
					<?php $this->render_available_card( $addon ); ?>
				<?php endforeach; ?>
			</div>
			<?php
		}
	}

	// ── Section heading ────────────────────────────────────────────────────────

	/**
	 * Render a colored section divider with label and horizontal rule.
	 *
	 * @param string $label   Section heading text.
	 * @param string $variant Colour variant: green, amber, or grey.
	 * @return void
	 */
	private function render_section_divider( string $label, string $variant ): void {
		?>
		<div class="bn-section-divider bn-section-divider--<?php echo esc_attr( sanitize_html_class( $variant ) ); ?>"
			role="heading" aria-level="2">
			<span class="bn-section-divider-label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	// ── Card renderers ─────────────────────────────────────────────────────────

	/**
	 * Render an active integration card.
	 *
	 * @param array<string, mixed> $addon Addon data array.
	 * @return void
	 */
	private function render_active_card( array $addon ): void {
		$id       = sanitize_key( (string) ( $addon['id'] ?? '' ) );
		$label    = (string) ( $addon['label'] ?? '' );
		$desc     = (string) ( $addon['description'] ?? '' );
		$category = (string) ( $addon['category'] ?? '' );
		$icon     = sanitize_key( (string) ( $addon['logo_icon'] ?? 'zap' ) );
		$logo_bg  = sanitize_html_class( (string) ( $addon['logo_bg'] ?? 'blue' ) );
		$enables  = is_array( $addon['enables'] ?? null ) ? $addon['enables'] : array();
		?>
		<div class="bn-addon-card bn-addon-card--active bn-addon-card--<?php echo esc_attr( $id ); ?>">

			<div class="bn-addon-card-header">
				<div class="bn-addon-logo">
					<div class="bn-addon-logo-icon bn-logo-bg--<?php echo esc_attr( $logo_bg ); ?>"
						aria-hidden="true">
						<?php
						if ( function_exists( 'buddynext_icon' ) ) {
							buddynext_icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>
					<div class="bn-addon-logo-text">
						<strong class="bn-addon-label"><?php echo esc_html( $label ); ?></strong>
						<?php if ( '' !== $category ) : ?>
							<span class="bn-addon-logo-meta"><?php echo esc_html( $category ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<span class="bn-badge bn-badge-active"><?php esc_html_e( 'Active', 'buddynext' ); ?></span>
			</div>

			<p class="bn-addon-desc"><?php echo esc_html( $desc ); ?></p>

			<?php if ( ! empty( $enables ) ) : ?>
				<p class="bn-enables-label"><?php esc_html_e( 'What this enables', 'buddynext' ); ?></p>
				<ul class="bn-addon-features" aria-label="<?php esc_attr_e( 'Enabled features', 'buddynext' ); ?>">
					<?php foreach ( $enables as $feature ) : ?>
						<li>
							<span class="bn-feature-check" aria-hidden="true">
								<?php
								if ( function_exists( 'buddynext_icon' ) ) {
									buddynext_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								?>
							</span>
							<?php echo esc_html( (string) $feature ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="bn-addon-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=buddynext' ) ); ?>"
					class="bn-btn">
					<?php esc_html_e( 'Configure', 'buddynext' ); ?>
				</a>
			</div>

		</div>
		<?php
	}

	/**
	 * Render an available (not-yet-installed) integration card.
	 *
	 * @param array<string, mixed> $addon Addon data array.
	 * @return void
	 */
	private function render_available_card( array $addon ): void {
		$id       = sanitize_key( (string) ( $addon['id'] ?? '' ) );
		$label    = (string) ( $addon['label'] ?? '' );
		$desc     = (string) ( $addon['description'] ?? '' );
		$category = (string) ( $addon['category'] ?? '' );
		$icon     = sanitize_key( (string) ( $addon['logo_icon'] ?? 'zap' ) );
		$logo_bg  = sanitize_html_class( (string) ( $addon['logo_bg'] ?? 'blue' ) );
		$url      = (string) ( $addon['url'] ?? '' );
		$enables  = is_array( $addon['enables'] ?? null ) ? $addon['enables'] : array();
		?>
		<div class="bn-addon-card bn-addon-card--available bn-addon-card--<?php echo esc_attr( $id ); ?>">

			<div class="bn-addon-card-header">
				<div class="bn-addon-logo">
					<div class="bn-addon-logo-icon bn-logo-bg--<?php echo esc_attr( $logo_bg ); ?> bn-logo-bg--muted"
						aria-hidden="true">
						<?php
						if ( function_exists( 'buddynext_icon' ) ) {
							buddynext_icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>
					<div class="bn-addon-logo-text">
						<strong class="bn-addon-label"><?php echo esc_html( $label ); ?></strong>
						<?php if ( '' !== $category ) : ?>
							<span class="bn-addon-logo-meta"><?php echo esc_html( $category ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<span class="bn-badge bn-badge-inactive"><?php esc_html_e( 'Not Installed', 'buddynext' ); ?></span>
			</div>

			<p class="bn-addon-desc"><?php echo esc_html( $desc ); ?></p>

			<?php if ( ! empty( $enables ) ) : ?>
				<p class="bn-enables-label"><?php esc_html_e( 'Unlocks when installed', 'buddynext' ); ?></p>
				<ul class="bn-addon-features" aria-label="<?php esc_attr_e( 'Features available after installation', 'buddynext' ); ?>">
					<?php foreach ( $enables as $feature ) : ?>
						<li class="bn-feature-locked-item">
							<span class="bn-feature-locked" aria-hidden="true">
								<?php
								if ( function_exists( 'buddynext_icon' ) ) {
									buddynext_icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								?>
							</span>
							<?php echo esc_html( (string) $feature ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="bn-addon-footer">
				<?php if ( '' !== $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>"
						target="_blank"
						rel="noopener noreferrer"
						class="bn-btn bn-btn-primary">
						<?php esc_html_e( 'Get Plugin', 'buddynext' ); ?>
					</a>
				<?php else : ?>
					<span class="bn-addon-plugin-file">
						<?php echo esc_html( (string) ( $addon['plugin_file'] ?? '' ) ); ?>
					</span>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}

	// ── Addon registry ────────────────────────────────────────────────────────

	/**
	 * Return all registered addon entries, each decorated with an 'active' flag.
	 *
	 * Results are cached after the first call.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_addons(): array {
		if ( null !== $this->addons_cache ) {
			return $this->addons_cache;
		}

		$built_in = $this->built_in_addons();

		/**
		 * Filter the list of BuddyNext integration addons.
		 *
		 * @param array<int, array<string, mixed>> $addons Registered addon entries.
		 */
		$addons = (array) apply_filters( self::FILTER_ADDONS, $built_in );

		foreach ( $addons as &$addon ) {
			$addon['active'] = $this->is_plugin_active( (string) ( $addon['plugin_file'] ?? '' ) );
		}
		unset( $addon );

		$this->addons_cache = array_values( $addons );

		return $this->addons_cache;
	}

	/**
	 * Return whether a specific addon is currently active.
	 *
	 * @param string $addon_id The addon ID from the registry.
	 * @return bool
	 */
	public function get_addon_status( string $addon_id ): bool {
		foreach ( $this->get_addons() as $addon ) {
			if ( $addon['id'] === $addon_id ) {
				return (bool) $addon['active'];
			}
		}
		return false;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Return the built-in first-party addon definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function built_in_addons(): array {
		return array(
			array(
				'id'          => 'wpmediaverse',
				'label'       => 'WPMediaVerse',
				'description' => __( 'Direct messaging, media galleries, and social feeds.', 'buddynext' ),
				'plugin_file' => 'wpmediaverse/wpmediaverse.php',
				'logo_icon'   => 'zap',
				'logo_bg'     => 'teal',
				'category'    => __( 'Media & Messaging', 'buddynext' ),
				'url'         => '',
				'enables'     => array(
					__( 'Direct messaging inbox and threads', 'buddynext' ),
					__( 'Message request flow', 'buddynext' ),
					__( 'Block user from sending messages', 'buddynext' ),
				),
			),
			array(
				'id'          => 'jetonomy',
				'label'       => 'Jetonomy',
				'description' => __( 'Forum-style threaded discussions and Q&A boards.', 'buddynext' ),
				'plugin_file' => 'jetonomy/jetonomy.php',
				'logo_icon'   => 'message-circle',
				'logo_bg'     => 'purple',
				'category'    => __( 'Forum Engine', 'buddynext' ),
				'url'         => '',
				'enables'     => array(
					__( 'Forum tab inside spaces', 'buddynext' ),
					__( 'Threaded discussion feed cards', 'buddynext' ),
					__( 'Mention parsing from forum posts', 'buddynext' ),
				),
			),
			array(
				'id'          => 'wb-gamification',
				'label'       => 'WBGamification',
				'description' => __( 'Points, badges, levels, and leaderboards.', 'buddynext' ),
				'plugin_file' => 'wb-gamification/wb-gamification.php',
				'logo_icon'   => 'star',
				'logo_bg'     => 'amber',
				'category'    => __( 'Gamification', 'buddynext' ),
				'url'         => '',
				'enables'     => array(
					__( 'Points and badges on member profiles', 'buddynext' ),
					__( 'Level-up feed notifications', 'buddynext' ),
					__( 'Leaderboard page and sidebar widget', 'buddynext' ),
				),
			),
			array(
				'id'          => 'career-board',
				'label'       => 'Career Board',
				'description' => __( 'Job listings and applicant management.', 'buddynext' ),
				'plugin_file' => 'career-board/career-board.php',
				'logo_icon'   => 'briefcase',
				'logo_bg'     => 'blue',
				'category'    => __( 'Jobs', 'buddynext' ),
				'url'         => '',
				'enables'     => array(
					__( 'Job post cards in activity feed', 'buddynext' ),
					__( 'Application submitted notifications', 'buddynext' ),
					__( 'Automatic cleanup on job expiry', 'buddynext' ),
				),
			),
		);
	}

	/**
	 * Check whether a plugin file is active.
	 *
	 * @param string $plugin_file Plugin file path relative to the plugins directory.
	 * @return bool
	 */
	private function is_plugin_active( string $plugin_file ): bool {
		if ( '' === $plugin_file ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}
}
