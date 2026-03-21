<?php
/**
 * Member onboarding wizard service.
 *
 * Tracks the four-step new-member onboarding flow. Wizard state is stored in
 * user meta. Calling finish() fires buddynext_onboarding_completed — but only
 * on the first completion so downstream hooks (nudge email cancellation,
 * WBGam points) do not fire twice.
 *
 * Steps:
 *   1. Display name + avatar + bio
 *   2. Pick interests (space category IDs)
 *   3. Join suggested spaces (handled by caller)
 *   4. Follow suggested people (handled by caller)
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
	 * User meta key for stored interest category IDs.
	 */
	private const META_INTERESTS = 'bn_onboarding_interests';

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
		switch ( $step ) {
			case 1:
				$this->save_step1( $user_id, $data );
				break;
			case 2:
				$this->save_step2( $user_id, $data );
				break;
			default:
				break;
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

	/**
	 * Save step 2 data (interest category IDs).
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $data    Step data — expects 'interest_ids' as int[].
	 * @return void
	 */
	private function save_step2( int $user_id, array $data ): void {
		$ids = array_map( 'absint', (array) ( $data['interest_ids'] ?? array() ) );
		update_user_meta( $user_id, self::META_INTERESTS, $ids );
	}

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
