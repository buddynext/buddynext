<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * 4-layer permission model.
 *
 * Layer 1 — WP site admin: manage_options holders pass every check.
 * Layer 2 — Community role: role hierarchy checked against ROLE_MAP defaults.
 * Layer 3 — Explicit ability grant: user_meta key bn_ability_{slug} with the
 *           expiry encoded as an int unix timestamp (0 = never expires).
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
	 * via a bn_ability_{slug} user_meta entry (or via the developer filter).
	 *
	 * Space-scoped capabilities (buddynext-moderate-space, buddynext-manage-space)
	 * bypass the generic role-map path and are resolved by dedicated methods that
	 * query bn_space_members directly.
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
		// Space-scoped capabilities — resolved by can_moderate_space() / can_manage_space().
		'buddynext-moderate-space'          => null,
		'buddynext-manage-space'            => null,
	);

	/**
	 * Memoised, filtered capability → required-role map (per request).
	 *
	 * @var array<string, string|null>|null
	 */
	private static ?array $role_map_cache = null;

	/**
	 * The capability → required-role map, filterable via buddynext_role_map.
	 *
	 * Composes with the layer-4 buddynext_user_can filter: this map sets the
	 * baseline role each capability needs (fires once, memoised), while
	 * buddynext_user_can runs on every check for fine-grained overrides. Add a
	 * capability by returning it here mapped to a role slug ('member'..'owner'),
	 * or null for "no role gate".
	 *
	 * @return array<string, string|null>
	 */
	public static function get_role_map(): array {
		if ( null === self::$role_map_cache ) {
			$map = self::ROLE_MAP;

			// Fold the legacy Spaces-tab "who can create spaces" option into the
			// role map so it composes with the Roles & Capabilities tab instead of
			// fighting it. An existing "admins only" choice is preserved; the
			// default ('member') leaves the map default untouched.
			if ( 'admin' === (string) get_option( 'buddynext_space_creation_role', 'member' ) ) {
				$map['buddynext-spaces/create'] = 'admin';
			}

			self::$role_map_cache = (array) apply_filters( 'buddynext_role_map', $map );
		}

		return self::$role_map_cache;
	}

	/**
	 * Numeric weight for each community role.
	 *
	 * @var array<string, int>
	 */
	private const ROLE_HIERARCHY = array(
		'owner'     => 4,
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
		} elseif ( 'buddynext-moderate-space' === $capability ) {
			$space_id = isset( $context['space_id'] ) ? (int) $context['space_id'] : 0;
			$result   = $space_id > 0 && $this->can_moderate_space( $user_id, $space_id );
		} elseif ( 'buddynext-manage-space' === $capability ) {
			$space_id = isset( $context['space_id'] ) ? (int) $context['space_id'] : 0;
			$result   = $space_id > 0 && $this->can_manage_space( $user_id, $space_id );
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
	 * @param array  $context    Optional context (e.g. ['space_id' => 42]).
	 * @return bool
	 */
	private function passes_role_check( int $user_id, string $capability, array $context = array() ): bool {
		$required = self::get_role_map()[ $capability ] ?? null;

		if ( null === $required ) {
			return false;
		}

		$req_level = self::ROLE_HIERARCHY[ $required ];

		// Space-scoped check: when a space_id is in context, resolve the user's
		// role within that specific space from bn_space_members.
		if (
			isset( $context['space_id'] )
			&& str_starts_with( $capability, 'buddynext-spaces/' )
			// "join" is an entry capability performed by a non-member, who by
			// definition has no in-space role yet — so it is gated by the
			// community role below, not the in-space role. The space type
			// (open/request/invite) is still enforced separately in the join
			// flow, so this never lets anyone into private or secret spaces.
			&& 'buddynext-spaces/join' !== $capability
		) {
			$space_role = $this->get_space_role( $user_id, (int) $context['space_id'] );
			$user_level = self::ROLE_HIERARCHY[ $space_role ] ?? 0;
			return $user_level >= $req_level;
		}

		$user_role  = (string) ( get_user_meta( $user_id, 'bn_community_role', true ) ?: 'member' ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		$user_level = self::ROLE_HIERARCHY[ $user_role ] ?? 1;

		return $user_level >= $req_level;
	}

	/**
	 * Determine whether a user may moderate a specific space.
	 *
	 * A user can moderate a space when they are the space owner or a space
	 * moderator. Both roles are resolved per-space from bn_space_members for
	 * this space_id, so holding either role here is sufficient authority — the
	 * role assignment is itself the scoping mechanism.
	 *
	 * @param int $user_id  WordPress user ID.
	 * @param int $space_id Space ID.
	 * @return bool
	 */
	private function can_moderate_space( int $user_id, int $space_id ): bool {
		$role = $this->get_space_role( $user_id, $space_id );

		return in_array( $role, array( 'owner', 'moderator' ), true );
	}

	/**
	 * Determine whether a user may manage settings for a specific space.
	 *
	 * Only the space owner holds manage authority.
	 *
	 * @param int $user_id  WordPress user ID.
	 * @param int $space_id Space ID.
	 * @return bool
	 */
	private function can_manage_space( int $user_id, int $space_id ): bool {
		return 'owner' === $this->get_space_role( $user_id, $space_id );
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
		$value = get_user_meta( $user_id, self::ability_meta_key( $ability ), true );

		if ( '' === $value || null === $value ) {
			return false;
		}

		// '0' = no expiry; otherwise unix timestamp (string from user_meta).
		$expires_at = (int) $value;

		return 0 === $expires_at || $expires_at > time();
	}

	/**
	 * Build the user_meta key for an ability grant.
	 *
	 * Ability slugs may contain '/' and '-' (e.g. "buddynext-feed/pin-post"); we
	 * translate those into '_' so the resulting meta_key is readable and stable
	 * when inspecting wp_usermeta in phpMyAdmin: `bn_ability_buddynext_feed_pin_post`.
	 *
	 * @param string $ability Ability slug.
	 * @return string user_meta key.
	 */
	public static function ability_meta_key( string $ability ): string {
		return 'bn_ability_' . preg_replace( '/[^a-z0-9_]+/i', '_', $ability );
	}
}
