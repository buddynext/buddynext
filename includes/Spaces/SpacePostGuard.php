<?php
/**
 * Enforce per-space posting rules at the point a post is saved.
 *
 * The space settings panels persist "Who can post" (who_can_post) and
 * "Pre-moderate posts / require approval" (require_post_approval) as
 * bn_space_{id}_* options, but nothing applied them on the server when a post
 * was created — the composer gate alone was bypassable via the REST API. This
 * listener hooks the buddynext_post_before_save filter so the rules hold for
 * every create path:
 *   - who_can_post: a 403 when the author's space role is below the threshold.
 *   - require_post_approval: non owner/mod posts are held as 'pending' (the
 *     space moderation queue surfaces and approves them).
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
	 * Apply who_can_post + require_post_approval to a space post on create.
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

		$user_id       = (int) $user_id;
		$members       = new SpaceMemberService();
		$role          = ( 'active' === $members->get_status( $space_id, $user_id ) )
			? (string) $members->get_role( $space_id, $user_id )
			: '';
		$is_owner_mod  = in_array( $role, array( 'owner', 'moderator' ), true );
		$is_site_admin = user_can( $user_id, 'manage_options' );

		// "Who can post" threshold: members | mods | owner.
		if ( ! $is_site_admin ) {
			$who       = (string) get_option( 'bn_space_' . $space_id . '_who_can_post', 'members' );
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
			if ( ( $role_rank[ $role ] ?? 0 ) < ( $req_rank[ $who ] ?? 1 ) ) {
				return new WP_Error(
					'forbidden',
					__( 'You do not have permission to post in this space.', 'buddynext' ),
					array( 'status' => 403 )
				);
			}
		}

		// Pre-moderation: hold non owner/mod posts for approval.
		if ( ! $is_owner_mod && ! $is_site_admin
			&& (bool) get_option( 'bn_space_' . $space_id . '_require_post_approval', 0 )
		) {
			$data['status'] = 'pending';
		}

		return $data;
	}
}
