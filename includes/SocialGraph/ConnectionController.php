<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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
 * @package BuddyNext\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\SocialGraph;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuddyNext\REST\BaseRestController;

/**
 * Handles connection request lifecycle and connection-graph reads.
 */
class ConnectionController extends BaseRestController {

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
					'args'                => array(
						'note' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
							'description'       => __( 'Optional message included with the connection request (capped at 280 characters).', 'buddynext' ),
						),
					),
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
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/connection-requests',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_connection_requests' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/connection/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'connection_status' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'id'       => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/mutual-connections',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'mutual_connections' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
				),
			)
		);
	}

	/**
	 * GET /users/{id}/connection/status — the current user's connection status with a peer.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function connection_status( WP_REST_Request $request ): WP_REST_Response {
		$status = buddynext_service( 'connections' )->status( get_current_user_id(), (int) $request['id'] );

		return new WP_REST_Response( array( 'status' => $status ), 200 );
	}

	/**
	 * GET /users/{id}/mutual-connections — ids connected to both the viewer and the peer.
	 *
	 * Paginated. This returned EVERY mutual ID in one response with no limit and no page
	 * param, so two hub accounts could ask the server to build, and the client to parse, a
	 * five-figure array of integers. The total is a COUNT(*), not count() over the page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function mutual_connections( WP_REST_Request $request ): WP_REST_Response {
		$connections = buddynext_service( 'connections' );

		$viewer_id = get_current_user_id();
		$peer_id   = (int) $request['id'];
		// Defaults from the route schema are normally filled in by the dispatcher, but a
		// direct WP_REST_Request (tests, internal calls) can arrive without them — and a
		// per_page of 0 would slice the list down to nothing and silently return an empty
		// array to a caller that asked for the default page.
		$per_page = (int) $request['per_page'];
		$per_page = $per_page > 0 ? min( $per_page, 100 ) : 20;
		$page     = max( 1, (int) $request['page'] );

		$total = $connections->mutual_count( $viewer_id, $peer_id );

		// mutual_connections() has no offset, so slice the capped list. The cap is the
		// site's mutual ceiling, which bounds what is loaded regardless of the page asked
		// for — a deep page beyond it is empty rather than expensive.
		$ids    = $connections->mutual_connections( $viewer_id, $peer_id );
		$offset = ( $page - 1 ) * $per_page;
		$slice  = array_slice( array_map( 'intval', $ids ), $offset, $per_page );

		$response = new WP_REST_Response(
			array(
				'ids'   => $slice,
				'total' => $total,
			),
			200
		);

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 ) );

		return $response;
	}

	/**
	 * Send a connection request to another user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->require_cap( 'buddynext-connections/connect' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error(
				'buddynext_user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		if ( buddynext_service( 'blocks' )->is_blocking_either( $current_id, $target_id ) ) {
			return new WP_Error(
				'buddynext_blocked',
				__( 'You cannot connect with this user.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// Optional note (LinkedIn-style "I'd like to connect because…").
		// Service layer hard-caps the length + strips tags.
		$note = (string) $request->get_param( 'note' );

		$result = buddynext_service( 'connections' )->send_request( $current_id, $target_id, $note );

		if ( is_wp_error( $result ) ) {
			return $this->preserve_status( $result, 400 );
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
		$result       = buddynext_service( 'connections' )->accept_request( $current_id, $requester_id );

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
		$result       = buddynext_service( 'connections' )->decline_request( $current_id, $requester_id );

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
		$service    = buddynext_service( 'connections' );

		$result = $service->withdraw_request( $current_id, $target_id );

		if ( is_wp_error( $result ) && 'not_found' === $result->get_error_code() ) {
			// Pending request not found — try removing an accepted connection.
			$result = $service->remove_connection( $current_id, $target_id );
		}

		if ( is_wp_error( $result ) ) {
			return $this->preserve_status( $result, 400 );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Return the accepted connections list for the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_connections( WP_REST_Request $request ): WP_REST_Response {
		$current_id = get_current_user_id();
		$per_page   = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
		$page       = max( 1, (int) $request->get_param( 'page' ) );

		$service     = buddynext_service( 'connections' );
		$connections = $service->connections( $current_id, $per_page, ( $page - 1 ) * $per_page );

		return new WP_REST_Response(
			array(
				'ids'      => $connections,
				'total'    => $service->connection_count( $current_id ),
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
	}

	/**
	 * Return the pending received requests for the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_connection_requests( WP_REST_Request $request ): WP_REST_Response {
		$current_id = get_current_user_id();
		$per_page   = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
		$page       = max( 1, (int) $request->get_param( 'page' ) );

		$pending = buddynext_service( 'connections' )->pending_received( $current_id, $per_page, ( $page - 1 ) * $per_page );

		return new WP_REST_Response(
			array(
				'ids'      => $pending,
				'page'     => $page,
				'per_page' => $per_page,
				'has_more' => count( $pending ) === $per_page,
			),
			200
		);
	}
}
