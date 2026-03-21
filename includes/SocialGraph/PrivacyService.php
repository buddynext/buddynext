<?php
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
			// "followers" means: the actor must appear in the target's following list.
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
}
