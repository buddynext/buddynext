<?php
/**
 * REST controller for follow relationships.
 *
 * Routes (all under buddynext/v1):
 *   POST   /users/{id}/follow         — follow a user
 *   DELETE /users/{id}/follow         — unfollow a user
 *   GET    /users/{id}/followers      — list user's followers (public)
 *   GET    /users/{id}/following      — list who a user follows (public)
 *   GET    /follow-suggestions        — friends-of-friends suggestions
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles follow/unfollow and follow-graph reads.
 */
class FollowController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/follow',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'follow' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unfollow' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/followers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_followers' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/following',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_following' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/follow-suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_suggestions' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Follow a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function follow( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();

		if ( buddynext_service( 'blocks' )->is_blocking_either( $current_id, $target_id ) ) {
			return new WP_Error(
				'buddynext_blocked',
				__( 'You cannot follow this user.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$result = buddynext_service( 'follows' )->follow( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'following' => true ), 200 );
	}

	/**
	 * Unfollow a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unfollow( WP_REST_Request $request ): WP_REST_Response {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		buddynext_service( 'follows' )->unfollow( $current_id, $target_id );

		return new WP_REST_Response( array( 'following' => false ), 200 );
	}

	/**
	 * Return the list of followers for a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_followers( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = (int) $request->get_param( 'id' );
		$followers = buddynext_service( 'follows' )->followers( $user_id );

		return new WP_REST_Response( array( 'ids' => $followers ), 200 );
	}

	/**
	 * Return the list of users that a given user follows.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_following( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = (int) $request->get_param( 'id' );
		$following = buddynext_service( 'follows' )->following( $user_id );

		return new WP_REST_Response( array( 'ids' => $following ), 200 );
	}

	/**
	 * Return follow suggestions for the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function get_suggestions(): WP_REST_Response {
		$current_id  = get_current_user_id();
		$suggestions = buddynext_service( 'follows' )->suggestions( $current_id );

		return new WP_REST_Response( array( 'ids' => $suggestions ), 200 );
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
	public function require_auth(): true|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}
}
