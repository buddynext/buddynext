<?php
/**
 * REST controller for block and mute relationships.
 *
 * Routes (all under buddynext/v1):
 *   POST   /users/{id}/block — block a user
 *   DELETE /users/{id}/block — unblock a user
 *   POST   /users/{id}/mute  — mute a user
 *   DELETE /users/{id}/mute  — unmute a user
 *   GET    /me/blocked       — list blocked users
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\SocialGraph\BlockService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles block/mute operations and blocked-users reads.
 */
class BlockController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/block',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'block' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unblock' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/mute',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'mute' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unmute' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/blocked',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_blocked' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Block a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function block( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		$result     = ( new BlockService() )->block( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'blocked' => true ), 200 );
	}

	/**
	 * Unblock a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unblock( WP_REST_Request $request ): WP_REST_Response {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		( new BlockService() )->unblock( $current_id, $target_id );

		return new WP_REST_Response( array( 'blocked' => false ), 200 );
	}

	/**
	 * Mute a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mute( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		$result     = ( new BlockService() )->mute( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'muted' => true ), 200 );
	}

	/**
	 * Unmute a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unmute( WP_REST_Request $request ): WP_REST_Response {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		( new BlockService() )->unmute( $current_id, $target_id );

		return new WP_REST_Response( array( 'muted' => false ), 200 );
	}

	/**
	 * Return the list of users blocked by the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function get_blocked(): WP_REST_Response {
		$current_id = get_current_user_id();
		$blocked    = ( new BlockService() )->blocked_users( $current_id );

		return new WP_REST_Response( array( 'ids' => $blocked ), 200 );
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
