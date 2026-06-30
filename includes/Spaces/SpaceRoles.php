<?php
/**
 * Space role-rank thresholds — single source for the who_can_* gates.
 *
 * The role hierarchy (member < moderator < owner) and the per-space requirement
 * levels (members < mods < owner) were duplicated in SpacePostGuard and
 * SpaceMemberService. This is a security-class boundary, so the two copies are
 * consolidated here to remove drift risk. The default requirement differs by
 * action (posting defaults to "members", inviting to "mods"), so callers pass
 * the default explicitly.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

/**
 * Resolves whether a member's role meets a per-space requirement level.
 */
class SpaceRoles {

	/**
	 * Rank of a member's role in a space.
	 *
	 * @var array<string,int>
	 */
	private const ROLE_RANK = array(
		'member'    => 1,
		'moderator' => 2,
		'owner'     => 3,
	);

	/**
	 * Rank of a per-space "who can …" requirement level.
	 *
	 * @var array<string,int>
	 */
	private const REQ_RANK = array(
		'members' => 1,
		'mods'    => 2,
		'owner'   => 3,
	);

	/**
	 * Numeric rank of a role (0 for non-members / unknown roles).
	 *
	 * @param string $role Role slug (owner|moderator|member).
	 * @return int
	 */
	public static function role_rank( string $role ): int {
		return self::ROLE_RANK[ $role ] ?? 0;
	}

	/**
	 * Whether a role meets a per-space requirement level.
	 *
	 * @param string $role        The member's role in the space.
	 * @param string $required    Requirement level (members|mods|owner).
	 * @param int    $default_req Rank to assume when $required is empty/unknown.
	 * @return bool
	 */
	public static function meets( string $role, string $required, int $default_req ): bool {
		return self::role_rank( $role ) >= ( self::REQ_RANK[ $required ] ?? $default_req );
	}

	/**
	 * Whether an actor may moderate a space (owner/moderator role, or a site
	 * admin via the manage_options capability).
	 *
	 * Consolidates the gate `! in_array( $role, [ 'owner', 'moderator' ], true )
	 * && ! user_can( $user_id, 'manage_options' )` that was duplicated across the
	 * approve/decline/ban/remove flows. By De Morgan, `! can_moderate()` is
	 * byte-equivalent to that original predicate, including its short-circuit
	 * order (role check first, capability check only if the role check fails).
	 *
	 * @param string $role    The actor's role in the space (owner|moderator|member).
	 * @param int    $user_id The actor's user ID (for the manage_options fallback).
	 * @return bool
	 */
	public static function can_moderate( string $role, int $user_id ): bool {
		return in_array( $role, array( 'owner', 'moderator' ), true ) || user_can( $user_id, 'manage_options' );
	}
}
