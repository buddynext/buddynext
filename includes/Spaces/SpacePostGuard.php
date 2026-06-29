<?php
/**
 * Enforce per-space posting rules at the point a post is saved.
 *
 * The space settings panel persists "Who can post" (who_can_post) as a
 * bn_space_{id}_* option, but nothing applied it on the server when a post was
 * created — the composer gate alone was bypassable via the REST API. This
 * listener hooks the buddynext_post_before_save filter so the rule holds for
 * every create path:
 *   - who_can_post: a 403 when the author's space role is below the threshold.
 *
 * There is deliberately no pre-publish approval gate: like every mainstream
 * social platform, members post freely and moderation is reactive (report →
 * review → remove), never editorial sign-off that holds posts in a queue.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use WP_Error;

/**
 * Server-side guard for space post creation.
 */
class SpacePostGuard {

	/**
	 * Hook the post-create filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_post_before_save', array( $this, 'enforce' ), 10, 3 );
	}

	/**
	 * Apply who_can_post access control to a space post on create.
	 *
	 * @param mixed    $data    Post data array, or a WP_Error from an earlier listener.
	 * @param int      $user_id Author user ID.
	 * @param int|null $post_id Null on create; set on edit.
	 * @return mixed Modified data array, or a WP_Error to block the save.
	 */
	public function enforce( $data, $user_id, $post_id ) {
		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			return $data;
		}

		$space_id = isset( $data['space_id'] ) ? (int) $data['space_id'] : 0;
		if ( $space_id <= 0 ) {
			return $data; // Not a space post.
		}

		// Only gate creation; edits keep their existing status.
		if ( null !== $post_id ) {
			return $data;
		}

		// "Who can post" threshold (members | mods | owner). Shared with the
		// space-home feed panel so the composer the template renders and the
		// server gate below evaluate the exact same rule.
		if ( ! self::can_post( $space_id, (int) $user_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to post in this space.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// No pre-publish approval gate: members post freely (FB / LinkedIn
		// model) and moderation is reactive — problematic posts are reported and
		// removed via the moderation queue, never held for editorial sign-off.
		return $data;
	}

	/**
	 * Whether a user satisfies the space's "Who can post" threshold.
	 *
	 * Single source of truth for the posting gate: the space-home feed panel
	 * uses it to decide whether to render the composer, and enforce() uses it
	 * to block server-side. A site admin always passes; otherwise the user's
	 * active space role must meet the configured threshold (members | mods |
	 * owner). Archived-space read-only state is enforced by the caller, not here.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User ID.
	 * @return bool
	 */
	public static function can_post( int $space_id, int $user_id ): bool {
		$space_id = (int) $space_id;
		$user_id  = (int) $user_id;

		if ( $space_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$members = new SpaceMemberService();
		$role    = ( 'active' === $members->get_status( $space_id, $user_id ) )
			? (string) $members->get_role( $space_id, $user_id )
			: '';

		// Non-members never satisfy the gate.
		if ( '' === $role ) {
			return false;
		}

		$who       = (string) buddynext_get_space_field( $space_id, 'who_can_post' );
		$role_rank = array(
			'member'    => 1,
			'moderator' => 2,
			'owner'     => 3,
		);
		$req_rank  = array(
			'members' => 1,
			'mods'    => 2,
			'owner'   => 3,
		);

		return ( $role_rank[ $role ] ?? 0 ) >= ( $req_rank[ $who ] ?? 1 );
	}
}
