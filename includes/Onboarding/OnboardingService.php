<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Member onboarding wizard service.
 *
 * Tracks the four-step new-member onboarding flow. Wizard state is stored in
 * user meta. Calling finish() fires buddynext_onboarding_completed — but only
 * on the first completion so downstream hooks (nudge email cancellation,
 * WBGam points) do not fire twice.
 *
 * Steps:
 *   1. Profile — display name, handle, avatar, bio
 *   2. Spaces — join recommended spaces (handled by caller)
 *   3. People — follow suggested members (handled by caller)
 *   4. Notifications — pick delivery channels (handled by caller)
 *
 * @package BuddyNext\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Onboarding;

/**
 * Per-user member onboarding wizard state machine.
 */
class OnboardingService {

	/**
	 * Total number of onboarding steps.
	 */
	public const TOTAL_STEPS = 4;

	/**
	 * User meta key for the current step.
	 */
	private const META_STEP = 'bn_onboarding_step';

	/**
	 * User meta key for completion flag.
	 */
	private const META_COMPLETE = 'bn_onboarding_complete';

	/**
	 * Whether the user has completed onboarding.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public function is_complete( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, self::META_COMPLETE, true );
	}

	/**
	 * Get the current wizard step for a user (1-based).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public function get_step( int $user_id ): int {
		$raw  = get_user_meta( $user_id, self::META_STEP, true );
		$step = '' === $raw ? 1 : (int) $raw;
		return max( 1, min( self::TOTAL_STEPS, $step ) );
	}

	/**
	 * Save data for a wizard step and advance to the next step.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param int                  $step    Step number being submitted (1-based).
	 * @param array<string, mixed> $data    Submitted step data.
	 * @return void
	 */
	public function save_step( int $user_id, int $step, array $data ): void {
		// Step 1 is the only step whose data lands here (display name +
		// bio). Step 2 (Spaces), Step 3 (People), and Step 4 (channels)
		// each go through their own dedicated REST endpoints when the
		// affordance is used, so this dispatcher only persists profile
		// data and advances the saved-step pointer.
		if ( 1 === $step ) {
			$this->save_step1( $user_id, $data );
		}
		$next = min( self::TOTAL_STEPS, $step + 1 );
		update_user_meta( $user_id, self::META_STEP, $next );
	}

	/**
	 * Skip the wizard — marks complete without saving step data.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function skip( int $user_id ): void {
		$this->mark_complete( $user_id );
	}

	/**
	 * Mark the wizard as finished for this user.
	 *
	 * Fires buddynext_onboarding_completed on first call only.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function finish( int $user_id ): void {
		if ( $this->is_complete( $user_id ) ) {
			return;
		}
		$this->mark_complete( $user_id );
		/**
		 * Fires when a member completes the BuddyNext onboarding wizard.
		 *
		 * @param int $user_id The user who completed onboarding.
		 */
		do_action( 'buddynext_onboarding_completed', $user_id );
	}

	// -------------------------------------------------------------------------
	// Step handlers
	// -------------------------------------------------------------------------

	/**
	 * Save step 1 data (display name, bio).
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $data    Step data.
	 * @return void
	 */
	private function save_step1( int $user_id, array $data ): void {
		if ( ! empty( $data['display_name'] ) ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => sanitize_text_field( (string) $data['display_name'] ),
				)
			);
		}
		if ( ! empty( $data['description'] ) ) {
			update_user_meta( $user_id, 'description', sanitize_textarea_field( (string) $data['description'] ) );
		}
	}

	// Step 2 (Spaces) → joinSuggestedSpace REST call from JS.
	// Step 3 (People) → followSuggestedUser REST call from JS.
	// Step 4 (Notifications) → toggleChannel updates context; finish()
	// PUTs /me/notification-channels. All three persist through their
	// own endpoints, so save_step1 above is the only step handler.

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Set the completion meta flag.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	private function mark_complete( int $user_id ): void {
		update_user_meta( $user_id, self::META_COMPLETE, '1' );
	}
}
