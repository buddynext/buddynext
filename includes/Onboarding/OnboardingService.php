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
			$this->save_profile( $user_id, $data );
		} elseif ( 2 === $step && isset( $data['interest_ids'] ) ) {
			$this->save_interest_ids( $user_id, (array) $data['interest_ids'] );
		}
		$next = min( self::TOTAL_STEPS, $step + 1 );
		update_user_meta( $user_id, self::META_STEP, $next );
	}

	/**
	 * Persist the Step 2 interest category IDs.
	 *
	 * @param int                $user_id      WordPress user ID.
	 * @param array<int,mixed>   $interest_ids Interest category IDs.
	 * @return void
	 */
	public function save_interest_ids( int $user_id, array $interest_ids ): void {
		$ids = array_values( array_filter( array_map( 'absint', $interest_ids ) ) );
		update_user_meta( $user_id, 'bn_onboarding_interests', $ids );
	}

	/**
	 * Persist free-text interest labels (the member's chosen interests).
	 *
	 * @param int           $user_id WordPress user ID.
	 * @param array<int,mixed> $labels Interest label strings.
	 * @return string The stored comma-joined label string.
	 */
	public function save_interests( int $user_id, array $labels ): string {
		$clean = array_values(
			array_filter(
				array_map(
					static fn( $l ): string => sanitize_text_field( (string) $l ),
					$labels
				),
				static fn( string $l ): bool => '' !== $l
			)
		);
		$value = implode( ',', $clean );
		update_user_meta( $user_id, 'bn_interests', $value );
		return $value;
	}

	/**
	 * Persist the Step 1 profile fields (display name + bio).
	 *
	 * Display name routes through wp_update_user; bio routes through the
	 * canonical profiles service when available so it lands in the same
	 * field row the profile header reads, falling back to the `description`
	 * + `bn_bio` usermeta keys when the service is not registered (e.g. the
	 * isolation mu-plugin stripped it on a front-end route).
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $data    Profile data (display_name, bio).
	 * @return void
	 */
	public function save_profile( int $user_id, array $data ): void {
		$display_name = isset( $data['display_name'] ) ? sanitize_text_field( (string) $data['display_name'] ) : '';
		if ( '' !== $display_name ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $display_name,
				)
			);
		}

		// Bio may arrive as `bio` (REST/profile shape) or `description`
		// (legacy step payload). Accept either.
		$bio = '';
		if ( isset( $data['bio'] ) ) {
			$bio = (string) $data['bio'];
		} elseif ( isset( $data['description'] ) ) {
			$bio = (string) $data['description'];
		}

		if ( '' !== $bio ) {
			$bio = sanitize_textarea_field( mb_substr( $bio, 0, 1000 ) );

			$persisted = false;
			if ( function_exists( 'buddynext_service' ) ) {
				$profiles = buddynext_service( 'profiles' );
				if ( $profiles && method_exists( $profiles, 'save_profile' ) ) {
					$profiles->save_profile( $user_id, array( 'bio' => $bio ) );
					$persisted = true;
				}
			}

			// Mirror to the usermeta keys the directory + profile header
			// fall back to, so the bio is visible regardless of whether the
			// profiles service was loaded on this request.
			update_user_meta( $user_id, 'bn_bio', $bio );
			if ( ! $persisted ) {
				update_user_meta( $user_id, 'description', $bio );
			}
		}
	}

	/**
	 * Persist the chosen profile slug ("handle"), if available.
	 *
	 * Returns the slug that was stored, or an empty string when the slug
	 * was blank, unchanged, or already taken by another member. Collisions
	 * are non-fatal — the live availability badge in the wizard already
	 * warned the user before they reached Finish.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $slug    Requested slug.
	 * @return string Stored slug, or '' when not persisted.
	 */
	public function save_slug( int $user_id, string $slug ): string {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return '';
		}

		$current = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		if ( $slug === $current ) {
			return $slug;
		}

		if ( class_exists( '\\BuddyNext\\Core\\PageRouter' )
			&& ! \BuddyNext\Core\PageRouter::is_slug_available( $slug, $user_id ) ) {
			return '';
		}

		update_user_meta( $user_id, 'bn_profile_slug', $slug );
		return $slug;
	}

	/**
	 * Persist notification channel preferences (email / in-app / push).
	 *
	 * Merges into the existing bn_channel_prefs map so per-event toggles set
	 * elsewhere are preserved. Only known channel keys present in the input
	 * are written.
	 *
	 * @param int                 $user_id  WordPress user ID.
	 * @param array<string, mixed> $channels Channel flags keyed email/in_app/push.
	 * @return void
	 */
	public function save_channels( int $user_id, array $channels ): void {
		if ( empty( $channels ) ) {
			return;
		}

		$current = get_user_meta( $user_id, 'bn_channel_prefs', true );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		foreach ( array( 'in_app', 'email', 'push', 'sound' ) as $key ) {
			if ( array_key_exists( $key, $channels ) ) {
				$current[ $key ] = (bool) $channels[ $key ];
			}
		}

		update_user_meta( $user_id, 'bn_channel_prefs', $current );
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

	// Step 2 (Spaces)        → join handled in OnboardingController::complete()
	//                          and the inline joinSuggestedSpace REST call.
	// Step 3 (People)        → follow handled in OnboardingController::complete()
	//                          and the inline followSuggestedUser REST call.
	// Step 4 (Notifications) → save_channels() above, invoked on complete.

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
