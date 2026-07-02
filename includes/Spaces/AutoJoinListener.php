<?php
/**
 * BuddyNext — Space auto-join listener.
 *
 * Joins members to their auto-join spaces on the two going-forward triggers:
 *   - `user_register` (the only guaranteed-once-per-account hook) → spaces flagged
 *     "auto-join new members" with no member-type filter.
 *   - `buddynext_member_type_assigned` → spaces whose member-type filter includes
 *     the newly assigned type.
 *
 * Each is a single-user event joining a handful of spaces, so it runs inline.
 * `SpaceMemberService::join()` is idempotent, skips banned users, and fires
 * `buddynext_space_member_joined`, so notifications / feed / bridges all run normally.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Wires the auto-join triggers.
 */
final class AutoJoinListener implements ListenerInterface {

	/**
	 * Hook the going-forward triggers.
	 *
	 * @return void
	 */
	public function register(): void {
		// Priority 20: after VerificationListener so the account row is settled.
		add_action( 'user_register', array( $this, 'on_user_register' ), 20, 1 );
		add_action( 'buddynext_member_type_assigned', array( $this, 'on_member_type_assigned' ), 20, 3 );
	}

	/**
	 * Join a brand-new member to the unconditional auto-join spaces.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public function on_user_register( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$this->join_all( ( new AutoJoinService() )->spaces_for_signup(), $user_id );
	}

	/**
	 * Join a member to the spaces mapped to their newly assigned member type.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $slug     New member-type slug.
	 * @param string $old_slug Previous member-type slug (unused).
	 * @return void
	 */
	public function on_member_type_assigned( int $user_id, string $slug, string $old_slug = '' ): void {
		unset( $old_slug );
		if ( $user_id <= 0 || '' === $slug ) {
			return;
		}
		$this->join_all( ( new AutoJoinService() )->spaces_for_type( $slug ), $user_id );
	}

	/**
	 * Join a user to a list of spaces (idempotent, ban-respecting via join()).
	 *
	 * @param int[] $space_ids Space IDs.
	 * @param int   $user_id   User ID.
	 * @return void
	 */
	private function join_all( array $space_ids, int $user_id ): void {
		if ( empty( $space_ids ) ) {
			return;
		}
		$members = new SpaceMemberService();
		foreach ( $space_ids as $space_id ) {
			$members->join( (int) $space_id, $user_id );
		}
	}
}
