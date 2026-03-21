<?php
/**
 * 4-layer permission model.
 *
 * Layer 1 — WP site admin: manage_options holders pass every check.
 * Layer 2 — Community role: role hierarchy checked against ROLE_MAP defaults.
 * Layer 3 — Explicit ability grant: rows in bn_user_abilities with optional expiry.
 * Layer 4 — Developer filter: buddynext_user_can can override in either direction.
 *
 * All permission checks in BuddyNext flow through buddynext_can(), which calls
 * PermissionService::can(). Never bypass this class with direct capability checks.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Resolves user permissions against BuddyNext capabilities.
 */
class PermissionService {

	/**
	 * Minimum community role required per capability.
	 *
	 * null = no role-based default; the capability must be explicitly granted
	 * via a row in bn_user_abilities (or via the developer filter).
	 *
	 * @var array<string, string|null>
	 */
	private const ROLE_MAP = array(
		'buddynext-profile/edit-own'        => 'member',
		'buddynext-profile/edit-any'        => 'admin',
		'buddynext-profile/view'            => null,
		'buddynext-feed/create-post'        => 'member',
		'buddynext-feed/delete-own-post'    => 'member',
		'buddynext-feed/delete-any-post'    => 'moderator',
		'buddynext-feed/pin-post'           => 'moderator',
		'buddynext-feed/schedule-post'      => 'member',
		'buddynext-spaces/create'           => 'member',
		'buddynext-spaces/join'             => 'member',
		'buddynext-spaces/join-gated'       => null,
		'buddynext-spaces/post'             => 'member',
		'buddynext-spaces/moderate'         => 'moderator',
		'buddynext-spaces/manage-settings'  => 'moderator',
		'buddynext-spaces/delete'           => 'moderator',
		'buddynext-connections/follow'      => 'member',
		'buddynext-connections/connect'     => 'member',
		'buddynext-moderation/report'       => 'member',
		'buddynext-moderation/review-queue' => 'moderator',
		'buddynext-moderation/issue-strike' => 'moderator',
		'buddynext-moderation/suspend-user' => 'admin',
	);

	/**
	 * Numeric weight for each community role.
	 *
	 * @var array<string, int>
	 */
	private const ROLE_HIERARCHY = array(
		'admin'     => 3,
		'moderator' => 2,
		'member'    => 1,
	);

	/**
	 * Check whether a user holds a capability.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $capability Capability slug.
	 * @param array  $context    Optional context (e.g. ['space_id' => 42]).
	 * @return bool
	 */
	public function can( int $user_id, string $capability, array $context = array() ): bool {
		$user = get_userdata( $user_id );

		if ( $user && $user->has_cap( 'manage_options' ) ) {
			$result = true;
		} else {
			$result = $this->passes_role_check( $user_id, $capability );

			if ( ! $result ) {
				$result = $this->has_active_grant( $user_id, $capability );
			}
		}

		/**
		 * Filters the resolved permission result.
		 *
		 * Return true to grant, false to deny, regardless of the resolved value.
		 *
		 * @param bool   $result     Current resolved result.
		 * @param int    $user_id    User being checked.
		 * @param string $capability Capability slug.
		 * @param array  $context    Optional context array.
		 */
		return (bool) apply_filters( 'buddynext_user_can', $result, $user_id, $capability, $context );
	}

	/**
	 * Check the community role hierarchy.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $capability Capability slug.
	 * @return bool
	 */
	private function passes_role_check( int $user_id, string $capability ): bool {
		$required = self::ROLE_MAP[ $capability ] ?? null;

		if ( null === $required ) {
			return false;
		}

		$user_role  = (string) ( get_user_meta( $user_id, 'bn_community_role', true ) ?: 'member' );
		$user_level = self::ROLE_HIERARCHY[ $user_role ] ?? 1;
		$req_level  = self::ROLE_HIERARCHY[ $required ] ?? 1;

		return $user_level >= $req_level;
	}

	/**
	 * Check for an unexpired explicit ability grant.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $ability  Ability slug.
	 * @return bool
	 */
	private function has_active_grant( int $user_id, string $ability ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_user_abilities
				 WHERE user_id = %d
				   AND ability = %s
				   AND ( expires_at IS NULL OR expires_at > NOW() )",
				$user_id,
				$ability
			)
		);

		return $count > 0;
	}
}
