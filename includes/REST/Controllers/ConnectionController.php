<?php
/**
 * REST controller for connection (mutual friendship) requests.
 *
 * Routes (all under buddynext/v1):
 *   POST   /users/{id}/connect         — send connection request
 *   DELETE /users/{id}/connect         — withdraw request or disconnect
 *   POST   /users/{id}/connect/accept  — accept incoming request from {id}
 *   POST   /users/{id}/connect/decline — decline incoming request from {id}
 *   GET    /me/connections             — list accepted connections
 *   GET    /me/connection-requests     — list pending received requests
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\SocialGraph\BlockService;
use BuddyNext\SocialGraph\ConnectionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles connection request lifecycle and connection-graph reads.
 */
class ConnectionController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/connect',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'send_request' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'withdraw_request' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/connect/accept',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'accept_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/connect/decline',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decline_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/connections',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_connections' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/connection-requests',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_connection_requests' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Send a connection request to another user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();

		if ( ( new BlockService() )->is_blocking_either( $current_id, $target_id ) ) {
			return new WP_Error(
				'buddynext_blocked',
				__( 'You cannot connect with this user.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$result = ( new ConnectionService() )->send_request( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'status' => 'pending' ), 200 );
	}

	/**
	 * Accept an incoming connection request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function accept_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$requester_id = (int) $request->get_param( 'id' );
		$current_id   = get_current_user_id();
		$result       = ( new ConnectionService() )->accept_request( $current_id, $requester_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 404 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'status' => 'accepted' ), 200 );
	}

	/**
	 * Decline an incoming connection request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function decline_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$requester_id = (int) $request->get_param( 'id' );
		$current_id   = get_current_user_id();
		$result       = ( new ConnectionService() )->decline_request( $current_id, $requester_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 404 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'status' => 'declined' ), 200 );
	}

	/**
	 * Withdraw a pending request or remove an accepted connection.
	 *
	 * Tries to withdraw a pending outgoing request first. If the connection is
	 * already accepted (mutual friends), removes the accepted connection.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function withdraw_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		$service    = new ConnectionService();

		$result = $service->withdraw_request( $current_id, $target_id );

		if ( is_wp_error( $result ) && 'not_found' === $result->get_error_code() ) {
			// Pending request not found — try removing an accepted connection.
			$result = $service->remove_connection( $current_id, $target_id );
		}

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Return the accepted connections list for the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function get_connections(): WP_REST_Response {
		$current_id  = get_current_user_id();
		$connections = ( new ConnectionService() )->connections( $current_id );

		return new WP_REST_Response( array( 'ids' => $connections ), 200 );
	}

	/**
	 * Return the pending received requests for the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function get_connection_requests(): WP_REST_Response {
		$current_id = get_current_user_id();
		$pending    = ( new ConnectionService() )->pending_received( $current_id );

		return new WP_REST_Response( array( 'ids' => $pending ), 200 );
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
