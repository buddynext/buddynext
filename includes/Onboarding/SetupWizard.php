<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin setup wizard.
 *
 * Tracks the one-time admin configuration wizard. State is stored in
 * wp_options. The step list is a keyed, filterable registry (steps()), so Pro
 * and add-ons can append, remove, or reorder steps via the
 * buddynext_setup_wizard_steps filter without editing core; progress is stored
 * as the step KEY, so inserting or removing a step never corrupts an in-flight
 * wizard. The wizard completes by calling finish(), which sets
 * buddynext_setup_complete=1 and fires the buddynext_onboarding_completed action.
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
	 * Option key tracking the current step. Stores the step KEY (e.g. 'branding').
	 *
	 * Historically this held a 1-based positional integer; current_step_key()
	 * maps such a legacy value onto the equivalent key so an install saved
	 * mid-wizard on the old scheme resumes at the right step.
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
		$hook = add_submenu_page(
			'buddynext',
			__( 'BuddyNext Setup', 'buddynext' ),
			'',  // No label, hides from submenu.
			'manage_options',
			'buddynext-setup',
			array( $this, 'render_page' )
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue_wizard_assets' ) );
			add_action( 'admin_print_scripts-' . $hook, array( $this, 'enqueue_wizard_scripts' ) );
		}
	}

	/**
	 * Enqueue the wizard stylesheet on the setup wizard admin page.
	 *
	 * The wizard chrome lives in bn-onboarding.css (setup-wizard section).
	 * bn-fonts + bn-base are already registered by AssetService on every
	 * BuddyNext admin page; we just register bn-onboarding here so its
	 * cascade resolves the same v2 tokens.
	 *
	 * @return void
	 */
	public function enqueue_wizard_assets(): void {
		$version    = defined( 'BUDDYNEXT_VERSION' ) ? (string) constant( 'BUDDYNEXT_VERSION' ) : '1.0.0';
		$assets_url = defined( 'BUDDYNEXT_URL' ) ? constant( 'BUDDYNEXT_URL' ) . 'assets/' : plugins_url( 'assets/', __FILE__ );

		wp_enqueue_style(
			'bn-onboarding',
			$assets_url . 'css/bn-onboarding.css',
			array( 'bn-base' ),
			$version
		);
	}

	/**
	 * Enqueue the wizard JS (selected-state syncing, bulk select, colour).
	 *
	 * @return void
	 */
	public function enqueue_wizard_scripts(): void {
		$version    = defined( 'BUDDYNEXT_VERSION' ) ? (string) constant( 'BUDDYNEXT_VERSION' ) : '1.0.0';
		$assets_url = defined( 'BUDDYNEXT_URL' ) ? constant( 'BUDDYNEXT_URL' ) . 'assets/' : plugins_url( 'assets/', __FILE__ );

		wp_enqueue_script(
			'bn-setup-wizard',
			$assets_url . 'js/admin/setup-wizard.js',
			array( 'wp-i18n' ),
			$version,
			true
		);
		wp_set_script_translations( 'bn-setup-wizard', 'buddynext', BUDDYNEXT_DIR . 'languages' );
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
	 * The wizard's step registry: an ordered map of key => step definition.
	 *
	 * Each step declares its label, a render callback (draws the step body) and
	 * an optional save callback (persists that step's form data). A callback is
	 * either the name of a method on this class or any callable — an add-on
	 * step passes its own closure, which is invoked with the wizard instance.
	 *
	 * The list is filterable so Pro / add-ons can append, remove, or reorder
	 * steps without touching core. Entries missing a key or label, whose render
	 * callback is not callable, or duplicating an earlier key, are dropped — the
	 * same validation contract OnboardingService::step_list() uses. Order is
	 * preserved (insertion order); the last step is treated as the finish step.
	 *
	 * @return array<string, array{label:string, render:callable|string, save:callable|string|null}>
	 */
	public function steps(): array {
		$steps = array(
			'branding'       => array(
				'label'  => __( 'Branding', 'buddynext' ),
				'render' => 'render_step_branding',
				'save'   => 'save_step_branding',
			),
			'registration'   => array(
				'label'  => __( 'Registration', 'buddynext' ),
				'render' => 'render_step_registration',
				'save'   => 'save_step_registration',
			),
			'profile_fields' => array(
				'label'  => __( 'Profile Fields', 'buddynext' ),
				'render' => 'render_step_profile_fields',
				'save'   => null,
			),
			'notifications'  => array(
				'label'  => __( 'Notifications', 'buddynext' ),
				'render' => 'render_step_notifications',
				'save'   => 'save_step_notifications',
			),
			'spaces'         => array(
				'label'  => __( 'Spaces', 'buddynext' ),
				'render' => 'render_step_spaces',
				'save'   => 'save_step_spaces',
			),
			'pages'          => array(
				'label'  => __( 'Pages', 'buddynext' ),
				'render' => 'render_step_pages',
				'save'   => 'save_step_pages',
			),
			'addons'         => array(
				'label'  => __( 'Addons', 'buddynext' ),
				'render' => 'render_step_addons',
				'save'   => null,
			),
			'done'           => array(
				'label'  => __( 'Done', 'buddynext' ),
				'render' => 'render_step_done',
				'save'   => null,
			),
		);

		/**
		 * Filter the admin setup wizard's step list.
		 *
		 * Mirrors buddynext_onboarding_steps (the member wizard). Append, remove,
		 * or reorder keyed steps; each entry needs a 'label' and a callable
		 * 'render', with an optional 'save'. Entries missing key/label, with a
		 * non-callable render, or a duplicate key, are dropped. The last step is
		 * the finish step.
		 *
		 * @since 1.1.6
		 *
		 * @param array<string, array{label:string, render:callable|string, save:callable|string|null}> $steps Keyed step registry.
		 */
		$filtered = (array) apply_filters( 'buddynext_setup_wizard_steps', $steps );

		$clean = array();
		foreach ( $filtered as $key => $def ) {
			$key = (string) $key;
			if ( '' === $key || isset( $clean[ $key ] ) || ! is_array( $def ) ) {
				continue;
			}
			$label  = (string) ( $def['label'] ?? '' );
			$render = $def['render'] ?? null;
			if ( '' === $label || ! $this->step_callable( $render ) ) {
				continue;
			}
			$clean[ $key ] = array(
				'label'  => $label,
				'render' => $render,
				'save'   => $def['save'] ?? null,
			);
		}

		// A filter that dropped every step would strand the wizard; fall back to
		// the built-in registry rather than render nothing.
		return array() === $clean ? $steps : $clean;
	}

	/**
	 * The ordered list of step keys.
	 *
	 * @return array<int, string>
	 */
	private function step_keys(): array {
		return array_keys( $this->steps() );
	}

	/**
	 * Total number of steps in the (filtered) registry.
	 *
	 * @return int
	 */
	public function step_count(): int {
		return count( $this->steps() );
	}

	/**
	 * The key of the current step.
	 *
	 * Reads OPTION_STEP. A numeric value is a legacy positional index (1-based)
	 * from an install saved on the old scheme — map it onto the key at that
	 * position. An unknown key (e.g. a step an add-on used to register and has
	 * since removed) clamps to the first step so the wizard never dead-ends.
	 *
	 * @return string
	 */
	public function current_step_key(): string {
		$keys = $this->step_keys();
		if ( array() === $keys ) {
			return '';
		}

		$stored = (string) get_option( self::OPTION_STEP, '' );
		if ( '' === $stored ) {
			return $keys[0];
		}

		if ( ctype_digit( $stored ) ) {
			$idx = max( 1, (int) $stored ) - 1;
			return $keys[ $idx ] ?? $keys[ count( $keys ) - 1 ];
		}

		return in_array( $stored, $keys, true ) ? $stored : $keys[0];
	}

	/**
	 * Return the current wizard step as a 1-based position.
	 *
	 * Kept for the step indicator and progress bar (and as the wizard's public
	 * "where am I" signal); the source of truth is current_step_key().
	 *
	 * @return int
	 */
	public function get_current_step(): int {
		$pos = array_search( $this->current_step_key(), $this->step_keys(), true );
		return false === $pos ? 1 : ( (int) $pos + 1 );
	}

	/**
	 * Advance to the next step. Clamps at the last step so repeat calls are safe.
	 *
	 * @return void
	 */
	public function advance(): void {
		$keys = $this->step_keys();
		$pos  = array_search( $this->current_step_key(), $keys, true );
		$pos  = ( false === $pos ) ? 0 : (int) $pos;
		$next = min( count( $keys ) - 1, $pos + 1 );
		update_option( self::OPTION_STEP, $keys[ $next ] );
	}

	/**
	 * Whether a step render/save value is invokable by this wizard.
	 *
	 * A string is a method on this class; anything else must be a callable.
	 *
	 * @param mixed $cb Candidate callback.
	 * @return bool
	 */
	private function step_callable( mixed $cb ): bool {
		if ( is_string( $cb ) ) {
			return method_exists( $this, $cb );
		}
		return is_callable( $cb );
	}

	/**
	 * Invoke a step render/save callback.
	 *
	 * A string names a method on this class (invoked as $this->method()); any
	 * other callable is called with the wizard instance so an add-on step can
	 * reach public helpers.
	 *
	 * @param callable|string|null $cb Callback to run (no-op when null/uninvokable).
	 * @return void
	 */
	private function call_step( callable|string|null $cb ): void {
		if ( null === $cb ) {
			return;
		}
		if ( is_string( $cb ) && method_exists( $this, $cb ) ) {
			$this->$cb();
			return;
		}
		if ( is_callable( $cb ) ) {
			call_user_func( $cb, $this );
		}
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
		do_action( 'buddynext_setup_complete' );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$wizard_action = isset( $_POST['wizard_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['wizard_action'] ) ) : 'continue';

		// Back navigation: step back without saving the current form.
		if ( 'back' === $wizard_action ) {
			$keys = $this->step_keys();
			$pos  = array_search( $this->current_step_key(), $keys, true );
			if ( is_int( $pos ) && $pos > 0 ) {
				update_option( self::OPTION_STEP, $keys[ $pos - 1 ] );
			}
			wp_safe_redirect(
				add_query_arg( 'page', 'buddynext-setup', admin_url( 'admin.php' ) )
			);
			exit;
		}

		// Skip this step: advance without persisting form data.
		if ( 'skip' === $wizard_action ) {
			$keys = $this->step_keys();
			if ( $this->current_step_key() !== end( $keys ) ) {
				$this->advance();
			}
			wp_safe_redirect(
				add_query_arg( 'page', 'buddynext-setup', admin_url( 'admin.php' ) )
			);
			exit;
		}

		// Save & exit: return to the dashboard without persisting the current form.
		if ( 'save_exit' === $wizard_action ) {
			$exit_url = add_query_arg(
				array(
					'page'   => 'buddynext',
					'wizard' => 'saved',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $exit_url );
			exit;
		}

		$steps    = $this->steps();
		$this_key = $this->current_step_key();
		$def      = $steps[ $this_key ] ?? null;

		// Persist this step's form data through its save callback. A step with no
		// data to save (Profile Fields, Addons, Done) declares none.
		if ( is_array( $def ) ) {
			$this->call_step( $def['save'] ?? null );
		}

		// The last step is the finish step: mark the wizard complete and leave for
		// the dashboard (the old positional `case 8`).
		$keys = $this->step_keys();
		if ( end( $keys ) === $this_key ) {
			$this->finish();
			wp_safe_redirect( admin_url( 'admin.php?page=buddynext&wizard=done' ) );
			exit;
		}

		$this->advance();
		wp_safe_redirect(
			add_query_arg( 'page', 'buddynext-setup', admin_url( 'admin.php' ) )
		);
		exit;
	}

	/**
	 * Save step: Branding (site name + brand colour).
	 *
	 * @return void
	 */
	private function save_step_branding(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_step_submit().
		$this->save_settings(
			array(
				'site_name'   => sanitize_text_field( wp_unslash( $_POST['site_name'] ?? '' ) ),
				'brand_color' => sanitize_hex_color( wp_unslash( (string) ( $_POST['brand_color'] ?? '#0073aa' ) ) ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save step: Registration (mode + email verification).
	 *
	 * @return void
	 */
	private function save_step_registration(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_step_submit().
		$this->save_settings(
			array(
				'reg_mode'     => sanitize_key( wp_unslash( $_POST['reg_mode'] ?? 'open' ) ),
				'email_verify' => isset( $_POST['email_verify'] ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save step: Notifications (default per-type preferences).
	 *
	 * @return void
	 */
	private function save_step_notifications(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_step_submit().
		$this->save_default_notification_prefs(
			array(
				'follow'     => isset( $_POST['notif_follow'] ),
				'reaction'   => isset( $_POST['notif_reaction'] ),
				'comment'    => isset( $_POST['notif_comment'] ),
				'mention'    => isset( $_POST['notif_mention'] ),
				'connection' => isset( $_POST['notif_connection'] ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save step: Spaces (starter categories).
	 *
	 * @return void
	 */
	private function save_step_spaces(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_step_submit().
		$this->save_space_categories(
			sanitize_textarea_field( wp_unslash( $_POST['categories'] ?? '' ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save step: Pages (create the selected community hub pages).
	 *
	 * @return void
	 */
	private function save_step_pages(): void {
		$page_keys  = array( 'feed', 'members', 'spaces' );
		$page_slugs = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_step_submit().
		foreach ( $page_keys as $pk ) {
			if ( isset( $_POST[ 'page_create_' . $pk ] ) ) {
				$page_slugs[ $pk ] = sanitize_title( wp_unslash( $_POST[ 'page_slug_' . $pk ] ?? $pk ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->create_community_pages( $page_slugs );
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

		$steps       = $this->steps();
		$keys        = array_keys( $steps );
		$total       = count( $keys );
		$step        = $this->get_current_step();
		$current_key = $this->current_step_key();
		$is_final    = ( '' !== $current_key && end( $keys ) === $current_key );

		// Reaching the final ("Done") step marks setup complete, so the
		// "setup not complete" admin notice clears even when the owner leaves via
		// the menu or the "View your community" link rather than submitting the
		// final step's form. finish() is idempotent (guarded by is_complete()).
		if ( $is_final && ! $this->is_complete() ) {
			$this->finish();
		}

		$continue = $is_final ? __( 'Finish setup', 'buddynext' ) : __( 'Continue', 'buddynext' );
		$progress = (int) round( ( ( $step - 1 ) / max( 1, $total - 1 ) ) * 100 );
		?>
		<div class="bn-wizard-wrap">
			<div class="bn-wizard" data-v2 data-step="<?php echo absint( $step ); ?>">
				<header class="bn-wizard__header">
					<div class="bn-wizard__logo">
						<svg width="24" height="24" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
							<circle cx="14" cy="14" r="14" fill="currentColor"/>
							<path d="M7 14a7 7 0 1 1 14 0A7 7 0 0 1 7 14Z" fill="#fff" fill-opacity=".2"/>
							<circle cx="14" cy="14" r="4" fill="#fff"/>
						</svg>
						<span><?php esc_html_e( 'BuddyNext setup', 'buddynext' ); ?></span>
					</div>
					<div class="bn-wizard__progress" role="group" aria-label="<?php esc_attr_e( 'Setup progress', 'buddynext' ); ?>">
						<span class="bn-wizard__progress-text">
							<?php
							printf(
								/* translators: 1: current step number, 2: total step count. */
								esc_html__( 'Step %1$d of %2$d', 'buddynext' ),
								absint( $step ),
								absint( $total )
							);
							?>
						</span>
						<span class="bn-wizard__progress-bar" aria-hidden="true">
							<span class="bn-wizard__progress-fill" style="width: <?php echo absint( $progress ); ?>%"></span>
						</span>
					</div>
					<?php if ( ! $is_final ) : ?>
						<button
							type="submit"
							form="bn-wizard-form"
							name="wizard_action"
							value="save_exit"
							class="bn-wizard__exit"
						>
							<?php esc_html_e( 'Save & exit', 'buddynext' ); ?>
						</button>
					<?php endif; ?>
				</header>

				<ol class="bn-wizard__steps" aria-label="<?php esc_attr_e( 'Setup steps', 'buddynext' ); ?>">
					<?php
					$position = 0;
					foreach ( $steps as $step_key => $step_def ) :
						++$position;
						$state        = ( $position === $step ) ? 'active' : ( ( $position < $step ) ? 'done' : 'upcoming' );
						$label        = (string) ( $step_def['label'] ?? $step_key );
						$current_aria = ( 'active' === $state ) ? ' aria-current="step"' : '';
						?>
						<li class="bn-wizard__step" data-state="<?php echo esc_attr( $state ); ?>"<?php echo $current_aria; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is a fixed literal. ?>>
							<span class="bn-wizard__step-marker" aria-hidden="true">
								<?php if ( 'done' === $state ) : ?>
									<?php echo \BuddyNext\Core\IconService::render( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
								<?php else : ?>
									<?php echo absint( $position ); ?>
								<?php endif; ?>
							</span>
							<span class="bn-wizard__step-name"><?php echo esc_html( $label ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>

				<div class="bn-wizard__body">
					<form id="bn-wizard-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-wizard__form">
						<?php wp_nonce_field( 'buddynext_wizard_step' ); ?>
						<input type="hidden" name="action" value="buddynext_wizard_step">

						<?php
						// Draw the current step's body from the registry.
						$render_def = $steps[ $current_key ] ?? null;
						if ( is_array( $render_def ) ) {
							$this->call_step( $render_def['render'] );
						}
						?>

						<?php if ( ! $is_final ) : ?>
							<footer class="bn-wizard__footer">
								<div class="bn-wizard__footer-left">
									<?php if ( $step > 1 ) : ?>
										<button type="submit" name="wizard_action" value="back" class="bn-wizard__btn-back">
											<?php echo \BuddyNext\Core\IconService::render( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
											<span><?php esc_html_e( 'Back', 'buddynext' ); ?></span>
										</button>
									<?php endif; ?>
								</div>
								<div class="bn-wizard__footer-right">
									<button type="submit" name="wizard_action" value="skip" class="bn-wizard__btn-skip">
										<?php esc_html_e( 'Skip this step', 'buddynext' ); ?>
									</button>
									<button type="submit" name="wizard_action" value="continue" class="bn-wizard__btn-continue">
										<span><?php echo esc_html( $continue ); ?></span>
										<?php echo \BuddyNext\Core\IconService::render( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
									</button>
								</div>
							</footer>
						<?php endif; ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the step header (title + sub + reassurance pill).
	 *
	 * Every step calls this so layout, spacing, and reassurance copy stay
	 * identical across the wizard. Pass an empty $reassure to suppress the pill.
	 *
	 * @param string $title    The H2 question or label.
	 * @param string $sub      One-line context under the title.
	 * @param string $reassure Where the setting can be changed later (free text).
	 * @return void
	 */
	private function render_step_head( string $title, string $sub, string $reassure = '' ): void {
		?>
		<div class="bn-wizard__head">
			<h2 class="bn-wizard__title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( '' !== $sub ) : ?>
				<p class="bn-wizard__sub"><?php echo esc_html( $sub ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $reassure ) : ?>
				<p class="bn-wizard__reassure">
					<?php echo \BuddyNext\Core\IconService::render( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
					<span><?php echo esc_html( $reassure ); ?></span>
				</p>
			<?php endif; ?>
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

		$this->render_step_head(
			__( 'What should your community be called?', 'buddynext' ),
			__( 'A name and a single brand colour. You can refine the rest of your theme later.', 'buddynext' ),
			__( 'Editable later in Settings → Branding.', 'buddynext' )
		);
		?>

		<div class="bn-wizard__fields">
			<div class="bn-wizard__field">
				<label for="bn-wiz-site-name" class="bn-wizard__label"><?php esc_html_e( 'Community name', 'buddynext' ); ?></label>
				<input
					type="text"
					id="bn-wiz-site-name"
					name="site_name"
					class="bn-wizard__input"
					value="<?php echo esc_attr( $site_name ); ?>"
					required
					autocomplete="off"
					spellcheck="false"
				>
				<p class="bn-wizard__hint"><?php esc_html_e( 'Shown in headers, emails, and the browser tab.', 'buddynext' ); ?></p>
			</div>

			<div class="bn-wizard__field">
				<span class="bn-wizard__label"><?php esc_html_e( 'Brand colour', 'buddynext' ); ?></span>
				<div class="bn-wizard__color">
					<label class="bn-wizard__swatch" for="bn-wiz-brand-color" style="--bn-wiz-color: <?php echo esc_attr( $brand_color ); ?>">
						<input
							type="color"
							id="bn-wiz-brand-color"
							name="brand_color"
							class="bn-wizard__swatch-input"
							value="<?php echo esc_attr( $brand_color ); ?>"
						>
						<span class="bn-wizard__swatch-fill" aria-hidden="true"></span>
					</label>
					<code class="bn-wizard__swatch-value" aria-live="polite"><?php echo esc_html( strtoupper( $brand_color ) ); ?></code>
				</div>
				<p class="bn-wizard__hint"><?php esc_html_e( 'Used for primary buttons, links, and focus states.', 'buddynext' ); ?></p>
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
		$reg_mode     = (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() );
		$email_verify = (bool) get_option( 'buddynext_email_verify', false );

		$this->render_step_head(
			__( 'Who can join your community?', 'buddynext' ),
			__( 'Pick how new members get in. You can tighten or loosen this any time.', 'buddynext' ),
			__( 'Editable later in Members → Registration & Login.', 'buddynext' )
		);

		$modes = array(
			'open'    => array(
				'label' => __( 'Open registration', 'buddynext' ),
				'desc'  => __( 'Anyone can sign up directly. Best for public communities.', 'buddynext' ),
				'icon'  => 'globe',
			),
			'invite'  => array(
				'label' => __( 'Invite only', 'buddynext' ),
				'desc'  => __( 'New members need an invite link to join. Best for private circles.', 'buddynext' ),
				'icon'  => 'mail',
			),
			'approve' => array(
				'label' => __( 'Admin approval', 'buddynext' ),
				'desc'  => __( 'Anyone can apply, but admins review each request. Best when curation matters.', 'buddynext' ),
				'icon'  => 'shield',
			),
		);
		?>

		<fieldset class="bn-wizard__options" data-variant="radio">
			<legend class="bn-wizard__legend"><?php esc_html_e( 'Registration mode', 'buddynext' ); ?></legend>
			<?php
			foreach ( $modes as $value => $mode ) :
				$radio_id = 'bn-wiz-reg-' . sanitize_html_class( $value );
				$selected = ( $reg_mode === $value );
				?>
				<label class="bn-wizard__option" for="<?php echo esc_attr( $radio_id ); ?>" data-selected="<?php echo $selected ? 'true' : 'false'; ?>">
					<input
						type="radio"
						id="<?php echo esc_attr( $radio_id ); ?>"
						name="reg_mode"
						value="<?php echo esc_attr( $value ); ?>"
						class="bn-wizard__option-input"
						<?php checked( $reg_mode, $value ); ?>
					>
					<span class="bn-wizard__option-icon" aria-hidden="true">
						<?php echo \BuddyNext\Core\IconService::render( $mode['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
					</span>
					<span class="bn-wizard__option-text">
						<span class="bn-wizard__option-title"><?php echo esc_html( $mode['label'] ); ?></span>
						<span class="bn-wizard__option-desc"><?php echo esc_html( $mode['desc'] ); ?></span>
					</span>
					<span class="bn-wizard__option-mark" aria-hidden="true"></span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<label class="bn-wizard__switch" for="bn-wiz-email-verify">
			<span class="bn-wizard__switch-text">
				<span class="bn-wizard__switch-title"><?php esc_html_e( 'Require email verification', 'buddynext' ); ?></span>
				<span class="bn-wizard__switch-desc"><?php esc_html_e( 'Members must confirm their email before they can post or react.', 'buddynext' ); ?></span>
			</span>
			<input
				type="checkbox"
				id="bn-wiz-email-verify"
				name="email_verify"
				class="bn-wizard__switch-input"
				<?php checked( $email_verify ); ?>
			>
			<span class="bn-wizard__switch-track" aria-hidden="true"></span>
		</label>
		<?php
	}

	/**
	 * Step 3: Profile field group presets.
	 *
	 * Admins choose which optional group templates to add. Basic Info is already
	 * seeded on install and is not shown here. All groups remain deletable later.
	 *
	 * @return void
	 */
	private function render_step_profile_fields(): void {
		$presets = $this->get_profile_group_presets();

		$this->render_step_head(
			__( 'What member profiles include', 'buddynext' ),
			__( 'These field groups are set up and ready. Add, rename, reorder or remove any of them whenever you like — nothing here is fixed.', 'buddynext' ),
			__( 'Manage them in Members → Profile Fields.', 'buddynext' )
		);

		// Render the groups that actually exist, not the preset list. The two can
		// differ the moment an owner edits anything, and a setup screen that
		// describes a schema the site does not have is worse than no screen.
		$bn_groups = (array) buddynext_service( 'profiles' )->get_groups();
		?>

		<ul class="bn-wizard__options" data-variant="summary" role="list">
			<?php
			foreach ( $bn_groups as $bn_group ) :
				$bn_key   = (string) ( $bn_group['group_key'] ?? '' );
				$bn_label = (string) ( $bn_group['label'] ?? $bn_key );
				if ( '' === $bn_key ) {
					continue;
				}
				// Icon and blurb come from the preset table when the group is one
				// of the built-ins. basic_info and interests are seeded by the
				// Installer rather than the presets (they are system groups), so
				// they carry their own entries here; an owner-created group falls
				// back to the generic icon and no blurb rather than a wrong one.
				$bn_extras = array(
					'basic_info' => array(
						'icon' => 'user',
						'desc' => __( 'Headline, bio, location, website, pronouns', 'buddynext' ),
					),
					'interests'  => array(
						'icon' => 'heart',
						'desc' => __( 'Topic picks that power people and space suggestions', 'buddynext' ),
					),
				);
				$bn_preset = $presets[ $bn_key ] ?? array();
				$bn_icon   = (string) ( $bn_preset['icon'] ?? ( $bn_extras[ $bn_key ]['icon'] ?? 'list' ) );
				$bn_desc   = (string) ( $bn_preset['description'] ?? ( $bn_extras[ $bn_key ]['desc'] ?? '' ) );
				?>
				<li class="bn-wizard__option-row">
					<div class="bn-wizard__option" data-selected="true">
						<span class="bn-wizard__option-icon" aria-hidden="true">
							<?php echo \BuddyNext\Core\IconService::render( $bn_icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
						</span>
						<span class="bn-wizard__option-text">
							<span class="bn-wizard__option-title"><?php echo esc_html( $bn_label ); ?></span>
							<?php if ( '' !== $bn_desc ) : ?>
								<span class="bn-wizard__option-desc"><?php echo esc_html( $bn_desc ); ?></span>
							<?php endif; ?>
						</span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Returns the available profile group preset definitions.
	 *
	 * Each preset defines a group and its default fields. Used by both the
	 * render and save methods to keep labels, keys, and fields in one place.
	 *
	 * CONTRACT (G7, card 10055921163): every field type below MUST exist in
	 * FieldType::types() (no 'social'/'daterange'/'toggle' pseudo-types —
	 * resolve_type() silently degrades unknown slugs to a bare text input),
	 * and every field key/label/type MUST match the Installer seed
	 * (seed_default_profile_groups_and_fields) exactly, so the wizard path
	 * and the installer path produce ONE canonical schema. Guarded by
	 * tests/Onboarding/SetupWizardTest.php.
	 *
	 * Field tuple: [ field_key, label, type, is_searchable ].
	 *
	 * @return array<string, array{label: string, icon: string, description: string, type: string, fields: array}>
	 */
	private function get_profile_group_presets(): array {
		return array(
			'social_links'    => array(
				'label'       => __( 'Social Links', 'buddynext' ),
				'icon'        => 'link',
				'description' => __( 'Twitter/X, LinkedIn, GitHub, Instagram, YouTube', 'buddynext' ),
				'type'        => 'flat',
				'fields'      => array(
					array( 'social_twitter', 'Twitter / X', 'url', 0 ),
					array( 'social_linkedin', 'LinkedIn', 'url', 0 ),
					array( 'social_github', 'GitHub', 'url', 0 ),
					array( 'social_instagram', 'Instagram', 'url', 0 ),
					array( 'social_youtube', 'YouTube', 'url', 0 ),
				),
			),
			'work_experience' => array(
				'label'       => __( 'Work Experience', 'buddynext' ),
				'icon'        => 'briefcase',
				'description' => __( 'Company, job title, start and end dates - repeatable entries', 'buddynext' ),
				'type'        => 'repeater',
				'fields'      => array(
					array( 'work_company', __( 'Company', 'buddynext' ), 'text', 0 ),
					array( 'work_title', __( 'Job Title', 'buddynext' ), 'text', 0 ),
					array( 'work_location', __( 'Location', 'buddynext' ), 'text', 0 ),
					array( 'work_start_date', __( 'Start Date', 'buddynext' ), 'date', 0 ),
					array( 'work_end_date', __( 'End Date', 'buddynext' ), 'date', 0 ),
					array( 'work_current', __( 'Currently Working', 'buddynext' ), 'boolean', 0 ),
					array( 'work_description', __( 'Description', 'buddynext' ), 'textarea', 0 ),
				),
			),
			'education'       => array(
				'label'       => __( 'Education', 'buddynext' ),
				'icon'        => 'graduation-cap',
				'description' => __( 'Institution, degree, field of study - repeatable entries', 'buddynext' ),
				'type'        => 'repeater',
				'fields'      => array(
					array( 'edu_institution', __( 'Institution', 'buddynext' ), 'text', 0 ),
					array( 'edu_degree', __( 'Degree', 'buddynext' ), 'text', 0 ),
					array( 'edu_field', __( 'Field of Study', 'buddynext' ), 'text', 0 ),
					array( 'edu_start_year', __( 'Start Year', 'buddynext' ), 'number', 0 ),
					array( 'edu_end_year', __( 'End Year', 'buddynext' ), 'number', 0 ),
					array( 'edu_current', __( 'Currently Attending', 'buddynext' ), 'boolean', 0 ),
				),
			),
			'skills'          => array(
				'label'       => __( 'Skills', 'buddynext' ),
				'icon'        => 'zap',
				'description' => __( 'A free-text skills field, searchable in the member directory', 'buddynext' ),
				'type'        => 'flat',
				'fields'      => array(
					array( 'skills', __( 'Skills', 'buddynext' ), 'text', 1 ),
				),
			),
		);
		// NOTE: the former 'interests' preset is gone — the Interests group is
		// core-seeded by the Installer as a system field (category_multiselect
		// backed by the owner's space categories), so it is never optional and
		// cannot drift. See docs/plans/interests-personalization.md.
	}

	/**
	 * Step 4: Default notification preferences.
	 *
	 * @return void
	 */
	private function render_step_notifications(): void {
		$defaults = (array) get_option( 'buddynext_default_notif_prefs', array() );
		$notifs   = array(
			'follow'     => array(
				'title' => __( 'New follower', 'buddynext' ),
				'desc'  => __( 'When someone starts following a member.', 'buddynext' ),
			),
			'reaction'   => array(
				'title' => __( 'Reactions on posts', 'buddynext' ),
				'desc'  => __( 'When someone reacts to a member’s post.', 'buddynext' ),
			),
			'comment'    => array(
				'title' => __( 'Comments on posts', 'buddynext' ),
				'desc'  => __( 'When someone replies to a member’s post.', 'buddynext' ),
			),
			'mention'    => array(
				'title' => __( 'Mentions', 'buddynext' ),
				'desc'  => __( 'When a member is @-mentioned anywhere.', 'buddynext' ),
			),
			'connection' => array(
				'title' => __( 'Connection requests', 'buddynext' ),
				'desc'  => __( 'When someone asks to connect.', 'buddynext' ),
			),
		);

		$this->render_step_head(
			__( 'Which notifications should be on by default?', 'buddynext' ),
			__( 'These are the starting preferences for every new member. Each member can override their own.', 'buddynext' ),
			__( 'Editable later in the Notifications section.', 'buddynext' )
		);
		?>

		<ul class="bn-wizard__switches" role="list">
			<?php
			foreach ( $notifs as $key => $notif ) :
				$notif_id = 'bn-wiz-notif-' . sanitize_html_class( $key );
				?>
				<li>
					<label class="bn-wizard__switch" for="<?php echo esc_attr( $notif_id ); ?>">
						<span class="bn-wizard__switch-text">
							<span class="bn-wizard__switch-title"><?php echo esc_html( $notif['title'] ); ?></span>
							<span class="bn-wizard__switch-desc"><?php echo esc_html( $notif['desc'] ); ?></span>
						</span>
						<input
							type="checkbox"
							id="<?php echo esc_attr( $notif_id ); ?>"
							name="notif_<?php echo esc_attr( $key ); ?>"
							class="bn-wizard__switch-input"
							<?php checked( $defaults[ $key ] ?? true ); ?>
						>
						<span class="bn-wizard__switch-track" aria-hidden="true"></span>
					</label>
				</li>
			<?php endforeach; ?>
		</ul>
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

		$this->render_step_head(
			__( 'How should spaces be organised?', 'buddynext' ),
			__( 'Spaces are themed rooms (Help, Announcements, Off-topic…). Pick some starter categories — your members can suggest more later.', 'buddynext' ),
			__( 'Editable later in Spaces → Categories.', 'buddynext' )
		);
		?>

		<div class="bn-wizard__field">
			<label for="bn-wiz-categories" class="bn-wizard__label"><?php esc_html_e( 'Starter categories', 'buddynext' ); ?></label>
			<input
				type="text"
				id="bn-wiz-categories"
				name="categories"
				class="bn-wizard__input"
				value="<?php echo esc_attr( implode( ', ', $suggestions ) ); ?>"
				placeholder="<?php esc_attr_e( 'General, Announcements, Help & Support', 'buddynext' ); ?>"
				autocomplete="off"
			>
			<p class="bn-wizard__hint"><?php esc_html_e( 'Comma-separated. Leave blank to set up categories later.', 'buddynext' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Step 5: Create community pages.
	 *
	 * Shows the core hub pages (Feed, Members, Spaces) with editable slugs.
	 * Pages that already exist are shown with a "Created" badge and are skipped
	 * on form submission.
	 *
	 * @return void
	 */
	private function render_step_pages(): void {
		// Canonical hub definitions — the option key, slug, and shortcode MUST
		// match Installer::create_hub_pages() so the wizard detects pages the
		// installer already created and never produces a duplicate (e.g. a
		// second "Members" page slugged members-2). Profile is intentionally
		// absent: profiles render at the pretty /members/{slug}/ route, not a
		// standalone page (there is no buddynext_page_profile reader or
		// [buddynext_profile] shortcode).
		$page_defs = array(
			'feed'    => array(
				'title'        => __( 'Community Feed', 'buddynext' ),
				'option'       => 'buddynext_page_activity',
				'default_slug' => 'activity',
				'shortcode'    => '[buddynext_activity]',
				'desc'         => __( 'Main activity stream for your community.', 'buddynext' ),
			),
			'members' => array(
				'title'        => __( 'Members', 'buddynext' ),
				'option'       => 'buddynext_page_people',
				'default_slug' => 'members',
				'shortcode'    => '[buddynext_people]',
				'desc'         => __( 'Browse and search community members.', 'buddynext' ),
			),
			'spaces'  => array(
				'title'        => __( 'Spaces', 'buddynext' ),
				'option'       => 'buddynext_page_spaces',
				'default_slug' => 'spaces',
				'shortcode'    => '[buddynext_spaces]',
				'desc'         => __( 'Browse and join community spaces.', 'buddynext' ),
			),
		);

		$this->render_step_head(
			__( 'Set up the pages members will visit', 'buddynext' ),
			__( 'BuddyNext needs a few core pages to host the feed, member directory, and spaces. We’ll create them with sensible URLs — adjust if you need to.', 'buddynext' ),
			__( 'Slugs editable later in Settings → Pages.', 'buddynext' )
		);
		?>

		<ul class="bn-wizard__pages" role="list">
			<?php
			foreach ( $page_defs as $key => $def ) :
				$option_key  = (string) $def['option'];
				$existing_id = (int) get_option( $option_key, 0 );
				$exists      = $existing_id > 0 && 'publish' === get_post_status( $existing_id );
				$slug_value  = $exists ? (string) get_post_field( 'post_name', $existing_id ) : $def['default_slug'];
				$check_id    = 'bn-wiz-page-' . sanitize_html_class( $key );
				$slug_id     = 'bn-wiz-page-slug-' . sanitize_html_class( $key );
				?>
				<li class="bn-wizard__page" data-state="<?php echo $exists ? 'created' : 'pending'; ?>">
					<label class="bn-wizard__page-check" for="<?php echo esc_attr( $check_id ); ?>">
						<input
							type="checkbox"
							id="<?php echo esc_attr( $check_id ); ?>"
							name="page_create_<?php echo esc_attr( $key ); ?>"
							value="1"
							class="bn-wizard__option-input"
							<?php checked( true ); ?>
							<?php
							if ( $exists ) :
								?>
								disabled aria-disabled="true"<?php endif; ?>
						>
						<span class="bn-wizard__option-mark" aria-hidden="true">
							<?php echo \BuddyNext\Core\IconService::render( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
						</span>
						<span class="screen-reader-text">
							<?php
							printf(
								/* translators: %s: page title */
								esc_html__( 'Create the %s page', 'buddynext' ),
								esc_html( $def['title'] )
							);
							?>
						</span>
					</label>
					<div class="bn-wizard__page-info">
						<span class="bn-wizard__page-title"><?php echo esc_html( $def['title'] ); ?></span>
						<span class="bn-wizard__page-desc"><?php echo esc_html( $def['desc'] ); ?></span>
					</div>
					<div class="bn-wizard__page-slug">
						<?php if ( $exists ) : ?>
							<a
								href="<?php echo esc_url( (string) get_permalink( $existing_id ) ); ?>"
								class="bn-wizard__page-link"
								target="_blank"
								rel="noopener noreferrer"
							>/<?php echo esc_html( $slug_value ); ?>/</a>
							<span class="bn-wizard__page-badge"><?php esc_html_e( 'Created', 'buddynext' ); ?></span>
						<?php else : ?>
							<label class="screen-reader-text" for="<?php echo esc_attr( $slug_id ); ?>">
								<?php
								printf(
									/* translators: %s: page title */
									esc_html__( 'URL slug for %s', 'buddynext' ),
									esc_html( $def['title'] )
								);
								?>
							</label>
							<span class="bn-wizard__slug">
								<span class="bn-wizard__slug-sep" aria-hidden="true">/</span>
								<input
									type="text"
									id="<?php echo esc_attr( $slug_id ); ?>"
									name="page_slug_<?php echo esc_attr( $key ); ?>"
									class="bn-wizard__slug-input"
									value="<?php echo esc_attr( $slug_value ); ?>"
									placeholder="<?php echo esc_attr( $def['default_slug'] ); ?>"
									autocomplete="off"
								>
								<span class="bn-wizard__slug-sep" aria-hidden="true">/</span>
							</span>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Step 6: Review active addons.
	 *
	 * @return void
	 */
	private function render_step_addons(): void {
		// One declarative catalog (CompanionRegistry) shared with the Integrations
		// admin tab, so onboarding and Settings never drift. Each installable row is
		// pre-checked: a site owner who clicks straight through gets the full
		// community stack installed + activated on Continue. Already-active plugins
		// are shown as connected (no action). Unchecking opts a plugin out.
		$companions  = \BuddyNext\Integrations\CompanionRegistry::all();
		$can_install = current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
		$pending     = 0;
		foreach ( $companions as $bn_slug => $bn_c ) {
			if ( 'active' !== \BuddyNext\Integrations\CompanionRegistry::status( (string) $bn_slug ) ) {
				++$pending;
			}
		}

		$this->render_step_head(
			__( 'What’s powering your community?', 'buddynext' ),
			$can_install && $pending > 0
				? __( 'These companion plugins extend BuddyNext. They’re all selected — Continue installs and activates them. Uncheck any you don’t want.', 'buddynext' )
				: __( 'These companion plugins extend BuddyNext. Anything already active integrates automatically.', 'buddynext' ),
			$can_install
				? __( 'Installs the free editions from wbcomdesigns.com. You can manage them later under Plugins.', 'buddynext' )
				: __( 'Ask an administrator to install these, or add them later from Plugins.', 'buddynext' )
		);
		?>

		<ul class="bn-wizard__addons"
			role="list"
			data-bn-companions-setup
			data-rest="<?php echo esc_url( rest_url( 'buddynext/v1/companions/install' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-i18n-installing="<?php esc_attr_e( 'Installing…', 'buddynext' ); ?>"
			data-i18n-activating="<?php esc_attr_e( 'Activating…', 'buddynext' ); ?>"
			data-i18n-done="<?php esc_attr_e( 'Active', 'buddynext' ); ?>"
			data-i18n-failed="<?php esc_attr_e( 'Failed', 'buddynext' ); ?>"
			data-i18n-retry="<?php esc_attr_e( 'Retry', 'buddynext' ); ?>">
			<?php
			foreach ( $companions as $bn_slug => $bn_c ) :
				$bn_status = \BuddyNext\Integrations\CompanionRegistry::status( (string) $bn_slug );
				$bn_active = ( 'active' === $bn_status );
				$bn_label  = (string) ( $bn_c['label'] ?? $bn_slug );
				$bn_why    = (string) ( $bn_c['why'] ?? '' );
				// Pre-check every not-yet-active companion when the owner can install.
				$bn_check = ( ! $bn_active && $can_install );
				$bn_field = 'bn-companion-' . sanitize_html_class( (string) $bn_slug );
				?>
				<li class="bn-wizard__addon" data-state="<?php echo $bn_active ? 'active' : esc_attr( $bn_status ); ?>" data-slug="<?php echo esc_attr( $bn_slug ); ?>">
					<?php if ( $bn_active ) : ?>
						<span class="bn-wizard__addon-dot" aria-hidden="true"></span>
					<?php elseif ( $can_install ) : ?>
						<input type="checkbox"
							class="bn-wizard__addon-check"
							id="<?php echo esc_attr( $bn_field ); ?>"
							value="<?php echo esc_attr( $bn_slug ); ?>"
							<?php checked( $bn_check ); ?>>
					<?php else : ?>
						<span class="bn-wizard__addon-dot" aria-hidden="true"></span>
					<?php endif; ?>
					<label class="bn-wizard__addon-info" <?php echo ( ! $bn_active && $can_install ) ? 'for="' . esc_attr( $bn_field ) . '"' : ''; ?>>
						<span class="bn-wizard__addon-name"><?php echo esc_html( $bn_label ); ?></span>
						<span class="bn-wizard__addon-desc"><?php echo esc_html( $bn_why ); ?></span>
						<span class="bn-wizard__addon-msg" role="status" aria-live="polite"></span>
					</label>
					<span class="bn-wizard__addon-status">
						<?php
						if ( $bn_active ) {
							esc_html_e( 'Active', 'buddynext' );
						} elseif ( 'inactive' === $bn_status ) {
							esc_html_e( 'Installed — will activate', 'buddynext' );
						} else {
							esc_html_e( 'Not installed', 'buddynext' );
						}
						?>
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
		$frontend_url = home_url( '/' );

		// Opt-in sample content: a fresh community looks empty, so offer to seed demo
		// members/spaces/posts so the owner can visualise the layout. Reuses the same
		// DemoDataService the Tools screen + CLI use (no second seed path); it can be
		// removed cleanly later from Tools. Shown only when not already seeded.
		$demo_service = new \BuddyNext\Demo\DemoDataService();
		$demo_seeded  = $demo_service->is_seeded();
		?>
		<div class="bn-wizard__done">
			<div class="bn-wizard__done-icon" aria-hidden="true">
				<?php echo \BuddyNext\Core\IconService::render( 'sparkles' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
			</div>
			<h2 class="bn-wizard__title bn-wizard__title--done"><?php esc_html_e( 'Your community is ready.', 'buddynext' ); ?></h2>
			<p class="bn-wizard__sub"><?php esc_html_e( 'Everything is configured. Open your dashboard to start inviting members, or jump to the front-end to see what they’ll see.', 'buddynext' ); ?></p>

			<div class="bn-wizard__sample">
				<?php if ( $demo_seeded ) : ?>
					<p class="bn-wizard__sample-note">
						<?php echo \BuddyNext\Core\IconService::render( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
						<?php esc_html_e( 'Sample content has been added. You can remove it anytime from Tools → Demo Content.', 'buddynext' ); ?>
					</p>
				<?php else : ?>
					<p class="bn-wizard__sample-note"><?php esc_html_e( 'New community feeling empty? Add sample members, spaces, and posts to visualise the layout. You can remove it cleanly later from Tools.', 'buddynext' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_demo_seed">
						<?php wp_nonce_field( 'bn_demo_seed' ); ?>
						<button type="submit" class="bn-wizard__btn-secondary">
							<?php esc_html_e( 'Add sample content', 'buddynext' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<div class="bn-wizard__done-actions">
				<button type="submit" name="wizard_action" value="continue" class="bn-wizard__btn-continue">
					<span><?php esc_html_e( 'Go to dashboard', 'buddynext' ); ?></span>
					<?php echo \BuddyNext\Core\IconService::render( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?>
				</button>
				<a href="<?php echo esc_url( $frontend_url ); ?>" class="bn-wizard__btn-secondary" target="_blank" rel="noopener noreferrer">
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

		// Delegate to the canonical owner of bn_space_categories so the columns,
		// slug derivation, colour defaults, and duplicate-slug guard stay in one
		// place. The previous raw insert wrote phantom parent_id/order/created_at
		// columns and failed silently, so wizard categories were never saved.
		$service  = new \BuddyNext\Spaces\SpaceCategoryService();
		$position = 0;
		foreach ( $names as $name ) {
			// create() derives the slug, defaults colours, and returns a
			// slug_conflict WP_Error for an existing category — which is the
			// expected outcome when the wizard is re-run, so it is ignored.
			$service->create(
				array(
					'name'       => sanitize_text_field( $name ),
					'sort_order' => $position,
				)
			);
			++$position;
		}
	}

	/**
	 * Create the community pages from the wizard form data.
	 *
	 * For each key in $pages, inserts a published WP page with the corresponding
	 * BuddyNext shortcode. Skips pages that already exist (option set + published).
	 * Page ID + slug are stored under the canonical option keys shared with
	 * Installer::create_hub_pages() (buddynext_page_activity / _people / _spaces),
	 * so a page the installer already created is reused rather than duplicated.
	 *
	 * @param array<string, string> $pages Map of page key → desired slug.
	 * @return void
	 */
	private function create_community_pages( array $pages ): void {
		// Canonical hub definitions — option key, slug option, shortcode, and
		// default slug MUST match Installer::create_hub_pages(). The shortcodes
		// are the ones ShortcodeService actually registers ([buddynext_activity]
		// /_people/_spaces); the legacy [buddynext_feed]/[buddynext_members]
		// were never registered and rendered as literal text.
		$definitions = array(
			'feed'    => array(
				'title'        => __( 'Community Feed', 'buddynext' ),
				'option'       => 'buddynext_page_activity',
				'slug_option'  => 'buddynext_slug_activity',
				'shortcode'    => '[buddynext_activity]',
				'default_slug' => 'activity',
			),
			'members' => array(
				'title'        => __( 'Members', 'buddynext' ),
				'option'       => 'buddynext_page_people',
				'slug_option'  => 'buddynext_slug_people',
				'shortcode'    => '[buddynext_people]',
				'default_slug' => 'members',
			),
			'spaces'  => array(
				'title'        => __( 'Spaces', 'buddynext' ),
				'option'       => 'buddynext_page_spaces',
				'slug_option'  => 'buddynext_slug_spaces',
				'shortcode'    => '[buddynext_spaces]',
				'default_slug' => 'spaces',
			),
		);

		foreach ( $pages as $key => $slug ) {
			if ( ! array_key_exists( $key, $definitions ) ) {
				continue;
			}

			$def         = $definitions[ $key ];
			$option_key  = (string) $def['option'];
			$existing_id = (int) get_option( $option_key, 0 );

			// Skip if the page already exists and is published — whether the
			// installer or a previous wizard run created it (both store the
			// same canonical option key).
			if ( $existing_id > 0 && 'publish' === get_post_status( $existing_id ) ) {
				continue;
			}

			$slug    = '' !== $slug ? sanitize_title( $slug ) : (string) $def['default_slug'];
			$post_id = wp_insert_post(
				array(
					'post_title'   => $def['title'],
					'post_name'    => $slug,
					'post_content' => $def['shortcode'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( ! is_wp_error( $post_id ) ) {
				update_option( $option_key, $post_id );
				update_option( (string) $def['slug_option'], $slug );
			}
		}

		// Flush rewrite rules so pretty hub URLs work immediately.
		flush_rewrite_rules( false );
	}
}
