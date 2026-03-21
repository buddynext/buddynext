<?php
/**
 * Admin setup wizard.
 *
 * Tracks the one-time admin configuration wizard. State is stored in
 * wp_options. The wizard has TOTAL_STEPS steps and completes by calling
 * finish(), which sets buddynext_setup_complete=1 and fires the
 * buddynext_setup_complete action.
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
}
