<?php
/**
 * Registration creation pipeline — the one way a BuddyNext member is created.
 *
 * Doors must not call wp_create_user() themselves. Social login used to, and as
 * a direct result it skipped the default DM-privacy seed, the registration
 * profile fields, and the canonical registration hooks — silently, for every
 * member who signed up with Google. Centralising creation is what stops a door
 * from forgetting a step nobody remembered it owned.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use BuddyNext\Onboarding\InviteService;
use BuddyNext\Spaces\SpaceMemberService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a member and runs every post-create step exactly once.
 */
class RegistrationService {

	/**
	 * Create a member from validated signup data.
	 *
	 * Callers MUST have already cleared RegistrationPolicy::check_access(), and —
	 * for doors that collect them — RegistrationPolicy::missing() must be empty.
	 * This method does not re-run the access policy; it runs the creation.
	 *
	 * @param array<string,mixed> $data Signup data. Required: email, user_login,
	 *                                  password. Optional: invite (token),
	 *                                  bn_field_* values, and social =>
	 *                                  array{provider:string,uid:string,email_verified:bool}.
	 * @return int|WP_Error New user id, or WP_Error on failure.
	 */
	public function create( array $data ): int|WP_Error {
		$policy = new RegistrationPolicy();

		$values = $policy->validate_data( $data );
		if ( is_wp_error( $values ) ) {
			return $values;
		}

		$user_id = wp_create_user(
			(string) $data['user_login'],
			(string) $data['password'],
			(string) $data['email']
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$user_id = (int) $user_id;

		$this->redeem_invite( $user_id, $data );

		// Reuse the canonical seeder rather than duplicating the audience list.
		AuthController::seed_default_dm_access( $user_id );

		$this->save_fields( $user_id, $values, $policy );
		$this->link_social( $user_id, $data );
		$this->maybe_hold_for_approval( $user_id, (string) $data['email'] );

		return $user_id;
	}

	/**
	 * Redeem an invite and join the inviting space, when one was presented.
	 *
	 * @param int                 $user_id New user id.
	 * @param array<string,mixed> $data    Signup data.
	 * @return void
	 */
	private function redeem_invite( int $user_id, array $data ): void {
		$token = (string) ( $data['invite'] ?? '' );
		if ( '' === $token ) {
			return;
		}

		$invites = new InviteService();
		$invite  = $invites->get_by_token( $token );
		if ( ! $invite ) {
			return;
		}

		$invites->mark_registered( (int) $invite['id'] );

		if ( ! empty( $invite['space_id'] ) ) {
			( new SpaceMemberService() )->join( (int) $invite['space_id'], $user_id );
		}
	}

	/**
	 * Persist the custom registration field values onto the new member.
	 *
	 * @param int                 $user_id New user id.
	 * @param array<string,mixed> $values  Clean values keyed by field_key.
	 * @param RegistrationPolicy  $policy  Policy, for the field definitions.
	 * @return void
	 */
	private function save_fields( int $user_id, array $values, RegistrationPolicy $policy ): void {
		if ( empty( $values ) ) {
			return;
		}

		// Reuse the canonical writer: it splits DB-backed fields (bn_profile_values
		// + the searchable usermeta mirror) from virtual/programmatic ones (which
		// have no row and go straight to bn_field_{key} usermeta), and it fires
		// buddynext_registration_fields_saved. Reimplementing it here would have
		// silently dropped every code-registered field.
		AuthController::save_registration_fields(
			$user_id,
			$policy->requirements()['fields'],
			$values,
			buddynext_service( 'profiles' )
		);
	}

	/**
	 * Record the provider link for a social signup.
	 *
	 * Written here, at the end of a COMPLETED signup — never mid-flight. Writing
	 * it early is what let a visitor defeat admin-approval mode by clicking the
	 * social button twice: the link persisted, so the second attempt matched an
	 * existing owner and sailed past the approval branch entirely.
	 *
	 * @param int                 $user_id New user id.
	 * @param array<string,mixed> $data    Signup data.
	 * @return void
	 */
	private function link_social( int $user_id, array $data ): void {
		$social = $data['social'] ?? null;
		if ( ! is_array( $social ) || empty( $social['provider'] ) || empty( $social['uid'] ) ) {
			return;
		}

		update_user_meta(
			$user_id,
			'bn_social_' . sanitize_key( (string) $social['provider'] ) . '_id',
			(string) $social['uid']
		);

		// A provider-verified email satisfies BuddyNext's own email check. Use the
		// canonical key VerificationService reads/writes.
		if ( ! empty( $social['email_verified'] ) ) {
			update_user_meta( $user_id, 'buddynext_email_verified', 1 );
		}
	}

	/**
	 * Flag the member for admin approval when the owner requires it.
	 *
	 * The flag is all this does. Enforcement lives on the core authenticate
	 * chain (Plugin.php) and is applied by SessionIssuer, so no door can hand out
	 * a session to a member the owner has not approved.
	 *
	 * @param int    $user_id New user id.
	 * @param string $email   Member email, for the notification listeners.
	 * @return void
	 */
	private function maybe_hold_for_approval( int $user_id, string $email ): void {
		$mode = (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() );
		if ( 'approval' !== $mode ) {
			return;
		}

		update_user_meta( $user_id, 'bn_pending_approval', '1' );

		/** This action is documented in AuthController::register(). */
		do_action( 'buddynext_registration_pending', $user_id, $email );
	}
}
