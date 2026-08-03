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
		// There was no comment capability at all. Pro's AI reply checked buddynext_can( $u,
		// 'comment' ) — an unmapped slug, so passes_role_check() returned false for every
		// member and the feature was dead for exactly the people it was for. It is NOT a
		// typo to be corrected to an existing cap: there was nothing to correct it to, and
		// mapping it to create-post would tie replying to POST-creation rights (and drag in
		// unrelated plan gating). So Free builds the door rather than Pro reaching through a
		// wall.
		'buddynext-comments/create'         => 'member',
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
		// Space-scoped capabilities — resolved by can_moderate_space() /
		// can_manage_space() / can_own_space().
		'buddynext-moderate-space'          => null,
		'buddynext-manage-space'            => null,
		'buddynext-own-space'               => null,
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

		if ( $user_id <= 0 ) {
			// A logged-out visitor holds nothing. Decided once, here, rather than
			// left to the resolvers below — two of them independently defaulted a
			// guest to a granted state (the role map's `?: 'member'`, and
			// has_active_grant()'s reading of get_user_meta( 0 ) === false as a
			// permanent grant). Both are fixed at source, but this makes the
			// invariant explicit so a third resolver cannot reintroduce it.
			//
			// Set rather than returned, so `buddynext_user_can` still runs and a
			// site that deliberately wants to grant a guest something (a public
			// read ability, say) keeps that seam.
			$result = false;
		} elseif ( $user && $user->has_cap( 'manage_options' ) ) {
			$result = true;
		} elseif ( 'buddynext-moderate-space' === $capability ) {
			$space_id = isset( $context['space_id'] ) ? (int) $context['space_id'] : 0;
			$result   = $space_id > 0 && $this->can_moderate_space( $user_id, $space_id );
		} elseif ( 'buddynext-manage-space' === $capability ) {
			$space_id = isset( $context['space_id'] ) ? (int) $context['space_id'] : 0;
			$result   = $space_id > 0 && $this->can_manage_space( $user_id, $space_id );
		} elseif ( 'buddynext-own-space' === $capability ) {
			$space_id = isset( $context['space_id'] ) ? (int) $context['space_id'] : 0;
			$result   = $space_id > 0 && $this->can_own_space( $user_id, $space_id );
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
		// A logged-out visitor holds no community role. Without this guard the
		// read below resolves to the DEFAULT 'member' for user 0 — get_user_meta( 0 )
		// returns '' and the `?:` fills in 'member' — so every capability mapped to
		// 'member' (10 of them: create-post, comments/create, spaces/create,
		// spaces/join, spaces/post, connections/follow, connections/connect,
		// moderation/report, feed/delete-own-post, feed/schedule-post) returned TRUE
		// for a guest. REST was never exposed: every route puts require_auth() in
		// front, which is why this surfaced as a UI bug rather than a breach. But
		// any TEMPLATE gating on buddynext_can( get_current_user_id(), … ) rendered
		// member-only controls to logged-out visitors — the "Create a space" button
		// on the spaces directory being the one somebody reported.
		//
		// Guarding here rather than in each template is deliberate: there are 7 such
		// call sites across 4 templates today, and the next one added would inherit
		// the bug again.
		if ( $user_id <= 0 ) {
			return false;
		}

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
	 * Owner OR moderator. Owner decision, 2026-07-30: "moderators have manage space,
	 * they just cannot remove the owner or delete the space."
	 *
	 * This used to be owner-only, which put it in direct conflict with the two places
	 * that build the space navigation — `SpaceNav` computed
	 * `in_array( $role, array( 'owner', 'moderator' ) )` and `role_at_least(
	 * 'moderator' )`. So a moderator was SHOWN the manage panel and its field tabs and
	 * then refused by the capability layer when they used them. Whether that surfaced
	 * as a dead control or a permission error depended on which layer the action went
	 * through, which is why it tended to be reported as "the moderator role does not
	 * work properly" rather than as a permissions bug.
	 *
	 * The two powers a moderator does NOT get are gated separately and deliberately
	 * NOT folded in here — see can_own_space(). They were both riding this capability,
	 * so widening it without splitting them first would have handed every moderator
	 * space deletion and ownership transfer.
	 *
	 * @param int $user_id  WordPress user ID.
	 * @param int $space_id Space ID.
	 * @return bool
	 */
	public function can_manage_space( int $user_id, int $space_id ): bool {
		return in_array( $this->get_space_role( $user_id, $space_id ), array( 'owner', 'moderator' ), true );
	}

	/**
	 * Determine whether a user holds OWNER authority over a specific space.
	 *
	 * The two things a moderator must never do: delete the space, and change who owns
	 * it. Both are irreversible from the moderator's side — a deleted space is gone,
	 * and a transferred space can only be transferred back by whoever now owns it —
	 * so they stay with the one member who cannot be removed from the space.
	 *
	 * Split out of can_manage_space() rather than layered on top of it: `delete()`,
	 * `transfer_ownership()` and `assign_owner()` all checked `buddynext-manage-space`,
	 * so the moment that capability included moderators these came with it. A separate
	 * gate is also the honest shape — "can configure this space" and "owns this space"
	 * are different questions, and conflating them is what produced the original
	 * divergence.
	 *
	 * Removing the OWNER as a member is refused independently, in
	 * SpaceController::remove_member() (`cannot_remove_owner`), and needs nothing here.
	 *
	 * @param int $user_id  WordPress user ID.
	 * @param int $space_id Space ID.
	 * @return bool
	 */
	public function can_own_space( int $user_id, int $space_id ): bool {
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
		// Delegate to SpaceMemberService::get_role() — the identical
		// status='active' role query, but object-cached with proper
		// invalidation on every membership write (join/leave/role-change/ban via
		// invalidate_cache()). A page that gates many capabilities for the same
		// (user, space) — nav build + REST gate + template gates — collapses onto
		// one cached read instead of one query per check. Not memoized in
		// can(), so the buddynext_user_can filter still runs on every call; and a
		// mid-request membership write busts the cache, so no stale role survives.
		return (string) ( buddynext_service( 'space_members' )->get_role( $space_id, $user_id ) ?? '' );
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

		// Hard ban (bn_space_bans) is left as a direct query on purpose: it is a
		// security gate with no existing cache + invalidation, so correctness wins
		// over shaving one query — there must be no window where a stale cache
		// reports a banned user as allowed.
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

		// Soft ban (member status='banned') reads through SpaceMemberService's
		// cached get_status() — same value, but served from cache across the many
		// permission checks on a page and busted on every membership write.
		return 'banned' === buddynext_service( 'space_members' )->get_status( $space_id, $user_id );
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

		// `false` HAS to be caught here, and it was not.
		//
		// get_user_meta() returns '' when a real user simply has no grant, but it
		// returns `false` when the object id is not a real user — user 0, i.e. every
		// logged-out visitor. `false` is neither '' nor null, so it fell through this
		// guard; `(int) false` is 0; and 0 is this function's own encoding for "no
		// expiry". A guest therefore held a permanent grant to EVERY ability, up to
		// and including buddynext-moderation/suspend-user and abilities that do not
		// exist at all — has_active_grant() never checks the slug against a registry,
		// it only reads a meta key that will never be set.
		//
		// Verified before and after with a wp eval of
		// buddynext_can( 0, 'buddynext-moderation/suspend-user' ), which returned
		// bool(true) and now returns bool(false).
		if ( '' === $value || null === $value || false === $value ) {
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
