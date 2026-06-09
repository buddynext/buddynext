<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Privacy preference service.
 *
 * Controls who can follow, connect with, or view the profile of a given user.
 * Preferences are stored as user meta (key: bn_privacy_{$key}). All permission
 * checks consult the block graph first — a block always denies access.
 *
 * Supported preference keys and their valid values:
 *   who_can_follow      — 'everyone' | 'nobody'
 *   who_can_connect     — 'everyone' | 'followers' | 'nobody'
 *   profile_visibility  — 'public' | 'followers' | 'connections' | 'private'
 *
 * @package BuddyNext\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\SocialGraph;

/**
 * Resolves privacy preferences and enforces them on social-graph actions.
 */
class PrivacyService {

	/**
	 * User meta key prefix for all BuddyNext privacy settings.
	 */
	private const META_PREFIX = 'bn_privacy_';

	/**
	 * Default preference values applied when no user meta is set.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULTS = array(
		'who_can_follow'     => 'everyone',
		'who_can_connect'    => 'everyone',
		'profile_visibility' => 'public',
	);

	/**
	 * Follow-graph service.
	 *
	 * @var FollowService
	 */
	private FollowService $follows;

	/**
	 * Connection-graph service.
	 *
	 * @var ConnectionService
	 */
	private ConnectionService $connections;

	/**
	 * Block/mute service.
	 *
	 * @var BlockService
	 */
	private BlockService $blocks;

	/**
	 * Inject the social-graph services.
	 *
	 * @param FollowService     $follows     Follow-graph service.
	 * @param ConnectionService $connections Connection-graph service.
	 * @param BlockService      $blocks      Block/mute service.
	 */
	public function __construct(
		FollowService $follows,
		ConnectionService $connections,
		BlockService $blocks
	) {
		$this->follows     = $follows;
		$this->connections = $connections;
		$this->blocks      = $blocks;
	}

	/**
	 * Return a privacy preference value for a user.
	 *
	 * Falls back to the built-in default when no value has been stored.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Preference key (e.g. 'who_can_follow').
	 * @return string
	 */
	public function get_preference( int $user_id, string $key ): string {
		$stored = get_user_meta( $user_id, self::META_PREFIX . $key, true );

		if ( '' !== $stored && false !== $stored ) {
			return (string) $stored;
		}

		return self::DEFAULTS[ $key ] ?? 'everyone';
	}

	/**
	 * Persist a privacy preference value for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Preference key.
	 * @param string $value   Preference value.
	 */
	public function set_preference( int $user_id, string $key, string $value ): void {
		update_user_meta( $user_id, self::META_PREFIX . $key, $value );

		/**
		 * Fires after a user's privacy preference is changed.
		 *
		 * @param int    $user_id User whose preference changed.
		 * @param string $key     Preference key (e.g. 'who_can_follow', 'who_can_connect', 'profile_visibility').
		 * @param string $value   New preference value.
		 */
		do_action( 'buddynext_privacy_preference_changed', $user_id, $key, $value );
	}

	/**
	 * Check whether an actor can follow a target user.
	 *
	 * Denied when:
	 *   - The target has blocked the actor.
	 *   - The target's who_can_follow preference is 'nobody'.
	 *
	 * @param int $actor_id  ID of the user wanting to follow.
	 * @param int $target_id ID of the user to be followed.
	 * @return bool
	 */
	public function can_follow( int $actor_id, int $target_id ): bool {
		if ( $this->blocks->is_blocked( $target_id, $actor_id ) ) {
			return false;
		}

		$preference = $this->get_preference( $target_id, 'who_can_follow' );

		return 'everyone' === $preference;
	}

	/**
	 * Check whether an actor can send a connection request to a target user.
	 *
	 * Denied when:
	 *   - The target has blocked the actor.
	 *   - The target's who_can_connect preference is 'nobody'.
	 *   - The preference is 'followers' and the target does not follow the actor.
	 *
	 * @param int $actor_id  ID of the user sending the request.
	 * @param int $target_id ID of the user receiving the request.
	 * @return bool
	 */
	public function can_connect( int $actor_id, int $target_id ): bool {
		if ( $this->blocks->is_blocked( $target_id, $actor_id ) ) {
			return false;
		}

		$preference = $this->get_preference( $target_id, 'who_can_connect' );

		if ( 'everyone' === $preference ) {
			return true;
		}

		if ( 'followers' === $preference ) {
			// "followers" means: the target must already follow the actor back (target follows actor).
			return $this->follows->is_following( $target_id, $actor_id );
		}

		return false;
	}

	/**
	 * Check whether a viewer can view a user's profile.
	 *
	 * Users can always view their own profile. Otherwise:
	 *   - Denied when the profile owner has blocked the viewer.
	 *   - 'public'      — always visible.
	 *   - 'followers'   — viewer must follow the owner.
	 *   - 'connections' — viewer must share an accepted connection with the owner.
	 *   - 'private'     — not visible to others.
	 *
	 * @param int $viewer_id ID of the user requesting the profile.
	 * @param int $owner_id  ID of the profile owner.
	 * @return bool
	 */
	public function can_view_profile( int $viewer_id, int $owner_id ): bool {
		if ( $viewer_id === $owner_id ) {
			return true;
		}

		if ( $this->blocks->is_blocked( $owner_id, $viewer_id ) ) {
			return false;
		}

		$visibility = $this->get_preference( $owner_id, 'profile_visibility' );

		switch ( $visibility ) {
			case 'public':
				return true;

			case 'followers':
				return $this->follows->is_following( $viewer_id, $owner_id );

			case 'connections':
				return $this->connections->are_connected( $viewer_id, $owner_id );

			default:
				return false;
		}
	}

	/**
	 * Whether a viewer can see the OWNER's posts / activity.
	 *
	 * Layers, evaluated in order:
	 *   - Owner viewing their own content → always yes.
	 *   - Owner has blocked viewer → no.
	 *   - Owner has a private account → yes only if viewer is an
	 *     approved follower. Pending requests don't grant access.
	 *   - Otherwise → yes (per-post privacy still applies at the
	 *     PostService layer).
	 *
	 * Callers (FeedService audience build, profile activity tab, search
	 * index visibility) should consult this method instead of duplicating
	 * the rules.
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $owner_id  Profile owner.
	 * @return bool
	 */
	public function can_view_activity( int $viewer_id, int $owner_id ): bool {
		if ( $viewer_id === $owner_id ) {
			return true;
		}

		if ( $this->blocks->is_blocked( $owner_id, $viewer_id ) ) {
			return false;
		}

		if ( $this->follows->is_private_account( $owner_id ) ) {
			return $this->follows->is_following( $viewer_id, $owner_id );
		}

		return true;
	}

	/**
	 * Build a single bn_blocks exclusion SQL fragment for a query surface.
	 *
	 * ONE source of truth for the relationship-exclusion rules that feed, search
	 * and directory each previously hand-wrote in divergent SQL. The per-surface
	 * type semantics stay explicit at the call site through $forward_types /
	 * $reverse_types, so the intentional differences remain visible instead of
	 * being silently re-derived three times:
	 *
	 *   - feed      : forward block|mute, reverse block (mute is a feed-only soft hide)
	 *   - directory : forward block,      reverse block (bidirectional hard stop only)
	 *   - search    : two calls — all types both directions on the item subject,
	 *                 plus forward `restrict` on the author column (search-surface limit)
	 *
	 * Direction argument semantics:
	 *   - `null` → include this direction with NO type filter (all relationship types)
	 *   - `[]`   → omit this direction entirely
	 *   - `[..]` → include this direction filtered to `type IN (...)`
	 *
	 * Returns a bare predicate (no leading `AND`) plus its ordered $wpdb->prepare
	 * params, so directory can drop it into a WHERE-clause list while feed/search
	 * prefix `AND ` themselves. Empty for logged-out viewers and when bn_blocks
	 * is absent, so every surface degrades gracefully on a fresh install or the
	 * isolation harness.
	 *
	 * @param int                $viewer_id     Viewing user (0 = anonymous → empty fragment).
	 * @param string             $column        Column to gate (e.g. 'user_id', 'u.ID', 'si.object_id').
	 * @param array<string>|null $forward_types Types where the viewer is the blocker.
	 * @param array<string>|null $reverse_types Types where the viewer is the blocked.
	 * @return array{0:string,1:array<int|string>} [predicate, ordered params].
	 */
	public function block_exclude_sql( int $viewer_id, string $column, ?array $forward_types, ?array $reverse_types ): array {
		global $wpdb;

		if ( $viewer_id <= 0 ) {
			return array( '', array() );
		}

		$table = $wpdb->prefix . 'bn_blocks';

		// Degrade gracefully if the block table is not installed yet (fresh
		// install / isolation harness) rather than emitting a SQL error.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array( '', array() );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		$selects = array();
		$params  = array();

		// Forward: rows where the viewer is the blocker → exclude blocked_id.
		if ( null === $forward_types || array() !== $forward_types ) {
			$cond     = 'blocker_id = %d';
			$params[] = $viewer_id;
			if ( null !== $forward_types ) {
				$cond  .= ' AND type IN (' . implode( ', ', array_fill( 0, count( $forward_types ), '%s' ) ) . ')';
				$params = array_merge( $params, $forward_types );
			}
			$selects[] = "SELECT blocked_id FROM {$table} WHERE {$cond}";
		}

		// Reverse: rows where the viewer is the blocked → exclude blocker_id.
		if ( null === $reverse_types || array() !== $reverse_types ) {
			$cond     = 'blocked_id = %d';
			$params[] = $viewer_id;
			if ( null !== $reverse_types ) {
				$cond  .= ' AND type IN (' . implode( ', ', array_fill( 0, count( $reverse_types ), '%s' ) ) . ')';
				$params = array_merge( $params, $reverse_types );
			}
			$selects[] = "SELECT blocker_id FROM {$table} WHERE {$cond}";
		}

		if ( array() === $selects ) {
			return array( '', array() );
		}

		return array( $column . ' NOT IN ( ' . implode( ' UNION ', $selects ) . ' )', $params );
	}
}
