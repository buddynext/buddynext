<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin setup wizard.
 *
 * Tracks the one-time admin configuration wizard. State is stored in
 * wp_options. The wizard has TOTAL_STEPS steps and completes by calling
 * finish(), which sets buddynext_setup_complete=1 and fires the
 * buddynext_onboarding_completed action.
 *
 * @package BuddyNext\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Onboarding;

/**
 * Admin first-run setup wizard state machine.
 */
class SetupWizard {

	/**
	 * Total number of wizard steps.
	 */
	public const TOTAL_STEPS = 6;

	/**
	 * Option key tracking the current step.
	 */
	private const OPTION_STEP = 'buddynext_setup_step';

	/**
	 * Option key marking the wizard as complete.
	 */
	private const OPTION_COMPLETE = 'buddynext_setup_complete';

	/**
	 * Allowed settings keys and their sanitize callbacks.
	 *
	 * @var array<string, callable>
	 */
	private const ALLOWED_SETTINGS = array(
		'site_name'    => 'sanitize_text_field',
		'brand_color'  => 'sanitize_hex_color',
		'reg_mode'     => 'sanitize_key',
		'email_verify' => 'rest_sanitize_boolean',
	);

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks for the setup wizard.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_wizard_page' ) );
		add_action( 'admin_post_buddynext_wizard_step', array( $this, 'handle_step_submit' ) );
	}

	/**
	 * Register the hidden wizard admin page.
	 *
	 * Listed under the BuddyNext top-level menu but with no visible label so
	 * it only appears when accessed directly via ?page=buddynext-setup.
	 *
	 * @return void
	 */
	public function add_wizard_page(): void {
		add_submenu_page(
			'buddynext',
			__( 'BuddyNext Setup', 'buddynext' ),
			'',  // No label — hides from submenu.
			'manage_options',
			'buddynext-setup',
			array( $this, 'render_page' )
		);
	}

	// ── State machine ─────────────────────────────────────────────────────────

	/**
	 * Whether the wizard has been completed.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return '1' === (string) get_option( self::OPTION_COMPLETE, '0' );
	}

	/**
	 * Return the current wizard step (1-based).
	 *
	 * @return int
	 */
	public function get_current_step(): int {
		$step = (int) get_option( self::OPTION_STEP, 1 );
		return max( 1, min( self::TOTAL_STEPS, $step ) );
	}

	/**
	 * Advance to the next step.
	 *
	 * Clamps at TOTAL_STEPS so repeat calls are safe.
	 *
	 * @return void
	 */
	public function advance(): void {
		$next = min( self::TOTAL_STEPS, $this->get_current_step() + 1 );
		update_option( self::OPTION_STEP, $next );
	}

	/**
	 * Mark the wizard as complete.
	 *
	 * @return void
	 */
	public function finish(): void {
		update_option( self::OPTION_COMPLETE, '1' );
		/**
		 * Fires when the BuddyNext admin setup wizard is completed.
		 */
		do_action( 'buddynext_onboarding_completed', 0 );
	}

	/**
	 * Persist wizard settings to wp_options.
	 *
	 * Only keys declared in ALLOWED_SETTINGS are saved. Unknown keys are
	 * silently ignored, preventing arbitrary option writes.
	 *
	 * @param array<string, mixed> $data Key-value pairs from the wizard form.
	 * @return void
	 */
	public function save_settings( array $data ): void {
		foreach ( self::ALLOWED_SETTINGS as $key => $sanitize ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			update_option( 'buddynext_' . $key, $sanitize( $data[ $key ] ) );
		}
	}

	// ── Form handler ──────────────────────────────────────────────────────────

	/**
	 * Handle wizard step form submission.
	 *
	 * @return void
	 */
	public function handle_step_submit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'buddynext' ) );
		}

		check_admin_referer( 'buddynext_wizard_step' );

		$current_step = $this->get_current_step();

		switch ( $current_step ) {
			case 1:
				$this->save_settings(
					array(
						'site_name'   => sanitize_text_field( wp_unslash( $_POST['site_name'] ?? '' ) ),
						'brand_color' => sanitize_hex_color( wp_unslash( (string) ( $_POST['brand_color'] ?? '#0073aa' ) ) ),
					)
				);
				break;

			case 2:
				$this->save_settings(
					array(
						'reg_mode'     => sanitize_key( wp_unslash( $_POST['reg_mode'] ?? 'open' ) ),
						'email_verify' => isset( $_POST['email_verify'] ),
					)
				);
				break;

			case 3:
				$this->save_default_notification_prefs(
					array(
						'follow'     => isset( $_POST['notif_follow'] ),
						'reaction'   => isset( $_POST['notif_reaction'] ),
						'comment'    => isset( $_POST['notif_comment'] ),
						'mention'    => isset( $_POST['notif_mention'] ),
						'connection' => isset( $_POST['notif_connection'] ),
					)
				);
				break;

			case 4:
				$this->save_space_categories(
					sanitize_textarea_field( wp_unslash( $_POST['categories'] ?? '' ) )
				);
				break;

			case 5:
				// Step 5 is informational — no settings to save.
				break;

			case 6:
				$this->finish();
				wp_safe_redirect( admin_url( 'admin.php?page=buddynext&wizard=done' ) );
				exit;
		}

		if ( $current_step < self::TOTAL_STEPS ) {
			$this->advance();
		}

		wp_safe_redirect(
			add_query_arg( 'page', 'buddynext-setup', admin_url( 'admin.php' ) )
		);
		exit;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render the full wizard page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'buddynext' ) );
		}

		$step        = $this->get_current_step();
		$skip_url    = add_query_arg(
			array(
				'page'   => 'buddynext',
				'wizard' => 'skipped',
			),
			admin_url( 'admin.php' )
		);
		$step_labels = array(
			1 => __( 'Branding', 'buddynext' ),
			2 => __( 'Registration', 'buddynext' ),
			3 => __( 'Notifications', 'buddynext' ),
			4 => __( 'Spaces', 'buddynext' ),
			5 => __( 'Addons', 'buddynext' ),
			6 => __( 'Done', 'buddynext' ),
		);
		?>
		<div class="bn-wizard-wrap">
			<?php $this->render_wizard_styles(); ?>
			<div class="bn-wizard">
				<div class="bn-wizard__header">
					<div class="bn-wizard__logo">
						<svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<circle cx="14" cy="14" r="14" fill="var(--brand, #0073aa)"/>
							<path d="M7 14a7 7 0 1 1 14 0A7 7 0 0 1 7 14Z" fill="#fff" fill-opacity=".2"/>
							<circle cx="14" cy="14" r="4" fill="#fff"/>
						</svg>
						<span><?php esc_html_e( 'BuddyNext Setup', 'buddynext' ); ?></span>
					</div>
					<a href="<?php echo esc_url( $skip_url ); ?>" class="bn-wizard__skip">
						<?php esc_html_e( 'Skip setup', 'buddynext' ); ?>
					</a>
				</div>

				<div class="bn-wizard__steps" role="list" aria-label="<?php esc_attr_e( 'Setup steps', 'buddynext' ); ?>">
					<?php for ( $i = 1; $i <= self::TOTAL_STEPS; $i++ ) : ?>
						<div role="listitem" class="bn-wizard__step-dot <?php echo $i === $step ? 'is-active' : ( $i < $step ? 'is-done' : '' ); ?>">
							<?php if ( $i < $step ) : ?>
								<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<?php else : ?>
								<?php echo absint( $i ); ?>
							<?php endif; ?>
						</div>
					<?php endfor; ?>
				</div>

				<div class="bn-wizard__body">
					<p class="bn-wizard__step-label">
						<?php
						printf(
							/* translators: 1: current step, 2: total steps, 3: step name */
							esc_html__( 'Step %1$d of %2$d — %3$s', 'buddynext' ),
							absint( $step ),
							absint( self::TOTAL_STEPS ),
							esc_html( $step_labels[ $step ] ?? '' )
						);
						?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-wizard__form">
						<?php wp_nonce_field( 'buddynext_wizard_step' ); ?>
						<input type="hidden" name="action" value="buddynext_wizard_step">

						<?php
						switch ( $step ) {
							case 1:
								$this->render_step_branding();
								break;
							case 2:
								$this->render_step_registration();
								break;
							case 3:
								$this->render_step_notifications();
								break;
							case 4:
								$this->render_step_spaces();
								break;
							case 5:
								$this->render_step_addons();
								break;
							case 6:
								$this->render_step_done();
								break;
						}
						?>

						<?php if ( $step < self::TOTAL_STEPS ) : ?>
							<div class="bn-wizard__footer">
								<button type="submit" class="bn-wizard-btn">
									<?php esc_html_e( 'Continue', 'buddynext' ); ?>
								</button>
							</div>
						<?php endif; ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Step renderers ────────────────────────────────────────────────────────

	/**
	 * Step 1: Branding — site name and brand colour.
	 *
	 * @return void
	 */
	private function render_step_branding(): void {
		$site_name   = (string) get_option( 'buddynext_site_name', get_bloginfo( 'name' ) );
		$brand_color = (string) get_option( 'buddynext_brand_color', '#0073aa' );
		?>
		<h2 class="bn-wizard__title"><?php esc_html_e( 'Make it yours', 'buddynext' ); ?></h2>
		<p class="bn-wizard__desc"><?php esc_html_e( 'Set a name and brand colour for your community.', 'buddynext' ); ?></p>

		<div class="bn-wizard__field">
			<label for="bn-wiz-site-name" class="bn-wizard__label"><?php esc_html_e( 'Community name', 'buddynext' ); ?></label>
			<input type="text" id="bn-wiz-site-name" name="site_name" class="bn-wizard__input" value="<?php echo esc_attr( $site_name ); ?>" required>
		</div>

		<div class="bn-wizard__field">
			<label for="bn-wiz-brand-color" class="bn-wizard__label"><?php esc_html_e( 'Brand colour', 'buddynext' ); ?></label>
			<div class="bn-wizard__color-row">
				<input type="color" id="bn-wiz-brand-color" name="brand_color" class="bn-wizard__color-picker" value="<?php echo esc_attr( $brand_color ); ?>">
				<span class="bn-wizard__color-preview"><?php echo esc_html( $brand_color ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 2: Registration settings.
	 *
	 * @return void
	 */
	private function render_step_registration(): void {
		$reg_mode     = (string) get_option( 'buddynext_reg_mode', 'open' );
		$email_verify = (bool) get_option( 'buddynext_email_verify', false );
		?>
		<h2 class="bn-wizard__title"><?php esc_html_e( 'Who can join?', 'buddynext' ); ?></h2>
		<p class="bn-wizard__desc"><?php esc_html_e( 'Control how new members sign up.', 'buddynext' ); ?></p>

		<div class="bn-wizard__field">
			<fieldset>
				<legend class="bn-wizard__label"><?php esc_html_e( 'Registration mode', 'buddynext' ); ?></legend>
				<div class="bn-wizard__radio-group">
					<?php
					$modes = array(
						'open'    => __( 'Open — anyone can register', 'buddynext' ),
						'invite'  => __( 'Invite-only — requires invite link', 'buddynext' ),
						'approve' => __( 'Admin approval — registrations reviewed manually', 'buddynext' ),
					);
					foreach ( $modes as $value => $label ) :
						?>
						<label class="bn-wizard__radio-label">
							<input type="radio" name="reg_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $reg_mode, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		</div>

		<div class="bn-wizard__field">
			<label class="bn-wizard__toggle-label">
				<input type="checkbox" name="email_verify" <?php checked( $email_verify ); ?>>
				<?php esc_html_e( 'Require email verification before accessing the community', 'buddynext' ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Step 3: Default notification preferences.
	 *
	 * @return void
	 */
	private function render_step_notifications(): void {
		$defaults = (array) get_option( 'buddynext_default_notif_prefs', array() );
		$notifs   = array(
			'follow'     => __( 'New follower', 'buddynext' ),
			'reaction'   => __( 'Someone reacted to your post', 'buddynext' ),
			'comment'    => __( 'Someone commented on your post', 'buddynext' ),
			'mention'    => __( 'Someone mentioned you', 'buddynext' ),
			'connection' => __( 'New connection request', 'buddynext' ),
		);
		?>
		<h2 class="bn-wizard__title"><?php esc_html_e( 'Default notifications', 'buddynext' ); ?></h2>
		<p class="bn-wizard__desc"><?php esc_html_e( 'Choose which notifications are on by default for new members. Members can override these in their own settings.', 'buddynext' ); ?></p>

		<div class="bn-wizard__field">
			<?php foreach ( $notifs as $key => $label ) : ?>
				<label class="bn-wizard__toggle-label">
					<input type="checkbox" name="notif_<?php echo esc_attr( $key ); ?>"
						<?php checked( $defaults[ $key ] ?? true ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Step 4: Create first space categories.
	 *
	 * @return void
	 */
	private function render_step_spaces(): void {
		$suggestions = array(
			__( 'General', 'buddynext' ),
			__( 'Announcements', 'buddynext' ),
			__( 'Help & Support', 'buddynext' ),
			__( 'Off-topic', 'buddynext' ),
		);
		?>
		<h2 class="bn-wizard__title"><?php esc_html_e( 'Space categories', 'buddynext' ); ?></h2>
		<p class="bn-wizard__desc"><?php esc_html_e( 'Add comma-separated categories to organise your spaces. You can always add more later.', 'buddynext' ); ?></p>

		<div class="bn-wizard__field">
			<label for="bn-wiz-categories" class="bn-wizard__label"><?php esc_html_e( 'Categories', 'buddynext' ); ?></label>
			<input type="text" id="bn-wiz-categories" name="categories" class="bn-wizard__input"
				value="<?php echo esc_attr( implode( ', ', $suggestions ) ); ?>"
				placeholder="<?php esc_attr_e( 'General, Announcements, Help & Support', 'buddynext' ); ?>">
			<p class="bn-wizard__hint"><?php esc_html_e( 'Separate categories with commas. Leave blank to skip.', 'buddynext' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Step 5: Review active addons.
	 *
	 * @return void
	 */
	private function render_step_addons(): void {
		$addons = array(
			array(
				'name' => 'WPMediaVerse',
				'slug' => 'wpmediaverse/wpmediaverse.php',
				'desc' => __( 'Media sharing, direct messaging, and media reactions.', 'buddynext' ),
			),
			array(
				'name' => 'Jetonomy',
				'slug' => 'jetonomy/jetonomy.php',
				'desc' => __( 'Forum-style discussions with threaded replies.', 'buddynext' ),
			),
			array(
				'name' => 'WBGamification',
				'slug' => 'wbgamification/wbgamification.php',
				'desc' => __( 'Points, badges, and leaderboards for community engagement.', 'buddynext' ),
			),
			array(
				'name' => 'Career Board',
				'slug' => 'wp-job-manager/wp-job-manager.php',
				'desc' => __( 'Job listings and member career profiles.', 'buddynext' ),
			),
		);
		?>
		<h2 class="bn-wizard__title"><?php esc_html_e( 'Your addons', 'buddynext' ); ?></h2>
		<p class="bn-wizard__desc"><?php esc_html_e( 'BuddyNext detected the following companion plugins. Active addons are automatically integrated.', 'buddynext' ); ?></p>

		<ul class="bn-wizard__addon-list">
			<?php foreach ( $addons as $addon ) : ?>
				<?php $active = is_plugin_active( $addon['slug'] ); ?>
				<li class="bn-wizard__addon <?php echo $active ? 'is-active' : 'is-inactive'; ?>">
					<span class="bn-wizard__addon-dot"></span>
					<div class="bn-wizard__addon-info">
						<strong><?php echo esc_html( $addon['name'] ); ?></strong>
						<span><?php echo esc_html( $addon['desc'] ); ?></span>
					</div>
					<span class="bn-wizard__addon-status">
						<?php echo $active ? esc_html__( 'Active', 'buddynext' ) : esc_html__( 'Inactive', 'buddynext' ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Step 6: Done.
	 *
	 * @return void
	 */
	private function render_step_done(): void {
		$dashboard_url = admin_url( 'admin.php?page=buddynext' );
		$frontend_url  = home_url( '/' );
		?>
		<div class="bn-wizard__done">
			<div class="bn-wizard__done-icon" aria-hidden="true">
				<svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
					<circle cx="24" cy="24" r="24" fill="var(--green-bg, #ecfdf5)"/>
					<path d="M14 24l7 7 13-13" stroke="var(--green, #059669)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</div>
			<h2 class="bn-wizard__title"><?php esc_html_e( 'You\'re all set!', 'buddynext' ); ?></h2>
			<p class="bn-wizard__desc"><?php esc_html_e( 'BuddyNext is configured and ready. You can always change any of these settings later.', 'buddynext' ); ?></p>
			<div class="bn-wizard__done-actions">
				<button type="submit" class="bn-wizard-btn">
					<?php esc_html_e( 'Go to dashboard', 'buddynext' ); ?>
				</button>
				<a href="<?php echo esc_url( $frontend_url ); ?>" class="bn-wizard-btn bn-wizard-btn--secondary" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View your community', 'buddynext' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Save default notification preferences from the wizard.
	 *
	 * @param array<string, bool> $prefs Map of notification type → enabled.
	 * @return void
	 */
	private function save_default_notification_prefs( array $prefs ): void {
		$clean = array();
		foreach ( $prefs as $key => $value ) {
			$clean[ sanitize_key( $key ) ] = (bool) $value;
		}
		update_option( 'buddynext_default_notif_prefs', $clean );
	}

	/**
	 * Create space categories from a comma-separated string.
	 *
	 * Silently skips if the space service is unavailable or the string is empty.
	 *
	 * @param mixed $raw_categories Raw input value from the wizard form.
	 * @return void
	 */
	private function save_space_categories( mixed $raw_categories ): void {
		if ( ! is_string( $raw_categories ) || '' === trim( $raw_categories ) ) {
			return;
		}

		$names = array_filter(
			array_map( 'trim', explode( ',', $raw_categories ) )
		);

		if ( empty( $names ) ) {
			return;
		}

		global $wpdb;

		foreach ( $names as $name ) {
			$slug = sanitize_title( $name );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prefix . 'bn_space_categories',
				array(
					'name'        => sanitize_text_field( $name ),
					'slug'        => $slug,
					'description' => '',
					'parent_id'   => 0,
					'order'       => 0,
					'created_at'  => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Inline CSS for the wizard chrome.
	 *
	 * Scoped to .bn-wizard-wrap so it cannot leak into the surrounding admin.
	 * Uses the same design tokens as all BuddyNext templates.
	 *
	 * @return void
	 */
	private function render_wizard_styles(): void {
		?>
		<style>
		.bn-wizard-wrap{--brand:#0073aa;--brand-hover:#005f8e;--green:#059669;--green-bg:#ecfdf5;--bg:#fff;--bg-subtle:#f8f8f7;--border:#e8e8e5;--text-1:#37352f;--text-2:#787774;--text-3:#aeaca8;--r-md:8px;--r-lg:12px;--s2:8px;--s3:12px;--s4:16px;--s6:24px;--s8:32px;font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;font-size:15px;color:var(--text-1);padding:var(--s8) var(--s4)}
		.bn-wizard{max-width:560px;margin:0 auto;background:var(--bg);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden}
		.bn-wizard__header{display:flex;align-items:center;justify-content:space-between;padding:var(--s4) var(--s6);border-bottom:1px solid var(--border)}
		.bn-wizard__logo{display:flex;align-items:center;gap:var(--s2);font-weight:600;font-size:16px}
		.bn-wizard__skip{font-size:13px;color:var(--text-2);text-decoration:none}
		.bn-wizard__skip:hover{color:var(--text-1)}
		.bn-wizard__steps{display:flex;align-items:center;gap:var(--s2);padding:var(--s4) var(--s6);border-bottom:1px solid var(--border)}
		.bn-wizard__step-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;background:var(--bg-subtle);color:var(--text-2);border:1.5px solid var(--border);flex-shrink:0;transition:background .15s,color .15s,border-color .15s}
		.bn-wizard__step-dot.is-active{background:var(--brand);color:#fff;border-color:var(--brand)}
		.bn-wizard__step-dot.is-done{background:var(--green-bg);color:var(--green);border-color:var(--green)}
		.bn-wizard__body{padding:var(--s6)}
		.bn-wizard__step-label{font-size:13px;color:var(--text-2);margin:0 0 var(--s4)}
		.bn-wizard__title{font-size:20px;font-weight:700;margin:0 0 var(--s2)}
		.bn-wizard__desc{color:var(--text-2);margin:0 0 var(--s6)}
		.bn-wizard__field{margin-bottom:var(--s4)}
		.bn-wizard__label{display:block;font-size:13px;font-weight:600;margin-bottom:var(--s2)}
		.bn-wizard__input{width:100%;box-sizing:border-box;padding:8px 12px;border:1.5px solid var(--border);border-radius:var(--r-md);font-size:15px;line-height:1.5;font-family:inherit}
		.bn-wizard__input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(0,115,170,.15)}
		.bn-wizard__hint{font-size:12px;color:var(--text-3);margin:var(--s2) 0 0}
		.bn-wizard__color-row{display:flex;align-items:center;gap:var(--s3)}
		.bn-wizard__color-picker{width:40px;height:40px;border:1.5px solid var(--border);border-radius:var(--r-md);padding:2px;cursor:pointer}
		.bn-wizard__color-preview{font-size:13px;color:var(--text-2)}
		.bn-wizard__radio-group{display:flex;flex-direction:column;gap:var(--s2)}
		.bn-wizard__radio-label,.bn-wizard__toggle-label{display:flex;align-items:flex-start;gap:var(--s2);cursor:pointer;font-size:14px;margin-bottom:var(--s2)}
		.bn-wizard__radio-label input,.bn-wizard__toggle-label input{margin-top:3px;flex-shrink:0}
		.bn-wizard__addon-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:var(--s2)}
		.bn-wizard__addon{display:flex;align-items:center;gap:var(--s3);padding:var(--s3) var(--s4);border:1.5px solid var(--border);border-radius:var(--r-md)}
		.bn-wizard__addon-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;background:var(--text-3)}
		.bn-wizard__addon.is-active .bn-wizard__addon-dot{background:var(--green)}
		.bn-wizard__addon-info{flex:1;display:flex;flex-direction:column;gap:2px;font-size:13px}
		.bn-wizard__addon-info strong{font-size:14px}
		.bn-wizard__addon-status{font-size:12px;color:var(--text-2)}
		.bn-wizard__addon.is-active .bn-wizard__addon-status{color:var(--green)}
		.bn-wizard__footer{margin-top:var(--s6);padding-top:var(--s6);border-top:1px solid var(--border)}
		.bn-wizard__done{text-align:center;padding:var(--s8) 0}
		.bn-wizard__done-icon{margin:0 auto var(--s4)}
		.bn-wizard__done-actions{display:flex;justify-content:center;gap:var(--s3);flex-wrap:wrap;margin-top:var(--s6)}
		.bn-wizard-btn{display:inline-flex;align-items:center;padding:10px 20px;background:var(--brand);color:#fff;border:none;border-radius:var(--r-md);font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s}
		.bn-wizard-btn:hover{background:var(--brand-hover)}
		.bn-wizard-btn--secondary{background:transparent;color:var(--brand);border:1.5px solid var(--border)}
		.bn-wizard-btn--secondary:hover{border-color:var(--brand)}
		</style>
		<?php
	}
}
