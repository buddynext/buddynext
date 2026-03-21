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
	 * Null = no role-based default; the capability must be explicitly granted
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

		// Hard-deny: space-banned users cannot perform any space action.
		if ( isset( $context['space_id'] ) && str_starts_with( $capability, 'buddynext-spaces/' ) ) {
			if ( $this->is_space_banned( $user_id, (int) $context['space_id'] ) ) {
				return false;
			}
		}

		if ( $user && $user->has_cap( 'manage_options' ) ) {
			$result = true;
		} else {
			$result = $this->passes_role_check( $user_id, $capability, $context );

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
	private function passes_role_check( int $user_id, string $capability, array $context = array() ): bool {
		$required = self::ROLE_MAP[ $capability ] ?? null;

		if ( null === $required ) {
			return false;
		}

		$req_level = self::ROLE_HIERARCHY[ $required ] ?? 1;

		// Space-scoped check: when a space_id is in context, resolve the user's
		// role within that specific space from bn_space_members.
		if ( isset( $context['space_id'] ) && str_starts_with( $capability, 'buddynext-spaces/' ) ) {
			$space_role = $this->get_space_role( $user_id, (int) $context['space_id'] );
			$user_level = self::ROLE_HIERARCHY[ $space_role ] ?? 0;
			return $user_level >= $req_level;
		}

		$user_role  = (string) ( get_user_meta( $user_id, 'bn_community_role', true ) ?: 'member' ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		$user_level = self::ROLE_HIERARCHY[ $user_role ] ?? 1;

		return $user_level >= $req_level;
	}

	/**
	 * Resolve a user's active role within a specific space.
	 *
	 * Returns 'owner', 'moderator', or 'member' for active membership,
	 * or empty string when the user is not an active member.
	 *
	 * @param int $user_id  WordPress user ID.
	 * @param int $space_id Space row ID.
	 * @return string Role name or '' if not an active member.
	 */
	private function get_space_role( int $user_id, int $space_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$role = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT role FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND user_id = %d AND status = 'active'",
				$space_id,
				$user_id
			)
		);

		return (string) ( $role ?? '' );
	}

	/**
	 * Check whether a user is banned from a specific space.
	 *
	 * Checks both the bn_space_bans table (hard bans) and the
	 * bn_space_members status='banned' row (soft bans set via member management).
	 *
	 * @param int $user_id  WordPress user ID.
	 * @param int $space_id Space row ID.
	 * @return bool True when the user is banned from the space.
	 */
	public function is_space_banned( int $user_id, int $space_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ban_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_bans
				 WHERE space_id = %d AND user_id = %d",
				$space_id,
				$user_id
			)
		);

		if ( $ban_count > 0 ) {
			return true;
		}

		// Also catch the member-status='banned' path used by SpaceMemberService.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$member_banned = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND user_id = %d AND status = 'banned'",
				$space_id,
				$user_id
			)
		);

		return $member_banned > 0;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
