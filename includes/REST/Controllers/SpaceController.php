<?php
/**
 * REST controller for spaces.
 *
 * Routes (all under buddynext/v1):
 *   GET    /spaces                        — list spaces (public)
 *   POST   /spaces                        — create a space (auth required)
 *   GET    /spaces/{id}                   — get a space (public)
 *   PUT    /spaces/{id}                   — update a space (owner only)
 *   DELETE /spaces/{id}                   — delete a space (owner only)
 *   GET    /spaces/{id}/members           — list members (public for open/private)
 *   POST   /spaces/{id}/join              — join or request-to-join (auth required)
 *   POST   /spaces/{id}/leave             — leave a space (auth required)
 *   POST   /spaces/{id}/invite            — invite a user (owner/mod only)
 *   POST   /spaces/{id}/approve-request   — approve a pending request (owner/mod only)
 *   POST   /spaces/{id}/ban               — ban a member (owner/mod only)
 *
 * Join semantics by space type:
 *   Open    → status='active' immediately, returns {joined: true}
 *   Private → status='pending' (request), returns {requested: true}
 *   Secret  → invite-only; 403 unless user has a pending 'invited' status
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles space lifecycle and membership over REST.
 */
class SpaceController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/spaces',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_spaces' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_space' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_space_members' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/join',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'join_space' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/leave',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'leave_space' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/invite',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'invite_member' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/approve-request',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/ban',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ban_member' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	// ── Space CRUD ──────────────────────────────────────────────────────────

	/**
	 * List spaces with optional filters.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_spaces( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'per_page' => absint( $request->get_param( 'per_page' ) ?: 12 ),
			'page'     => absint( $request->get_param( 'page' ) ?: 1 ),
			'orderby'  => sanitize_key( (string) ( $request->get_param( 'orderby' ) ?: 'member_count' ) ),
			'order'    => sanitize_key( (string) ( $request->get_param( 'order' ) ?: 'DESC' ) ),
		);

		if ( null !== $request->get_param( 'type' ) ) {
			$args['type'] = sanitize_key( (string) $request->get_param( 'type' ) );
		}

		if ( null !== $request->get_param( 'category_id' ) ) {
			$args['category_id'] = absint( $request->get_param( 'category_id' ) );
		}

		if ( null !== $request->get_param( 'search' ) ) {
			$spaces = ( new SpaceService() )->search(
				sanitize_text_field( (string) $request->get_param( 'search' ) ),
				$args
			);
		} else {
			$spaces = ( new SpaceService() )->list_spaces( $args );
		}

		return new WP_REST_Response( $spaces, 200 );
	}

	/**
	 * Create a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$data    = array(
			'name'        => sanitize_text_field( (string) ( $request->get_param( 'name' ) ?? '' ) ),
			'slug'        => sanitize_title( (string) ( $request->get_param( 'slug' ) ?? '' ) ),
			'type'        => sanitize_key( (string) ( $request->get_param( 'type' ) ?? 'open' ) ),
			'description' => sanitize_textarea_field( (string) ( $request->get_param( 'description' ) ?? '' ) ),
		);

		$result = ( new SpaceService() )->create( $user_id, $data );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		$space = ( new SpaceService() )->get( $result );

		return new WP_REST_Response( $space, 201 );
	}

	/**
	 * Get a single space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$space    = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $space, 200 );
	}

	/**
	 * Update a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$service  = new SpaceService();

		$data = array();
		if ( null !== $request->get_param( 'name' ) ) {
			$data['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$data['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		}
		if ( null !== $request->get_param( 'type' ) ) {
			$data['type'] = sanitize_key( (string) $request->get_param( 'type' ) );
		}

		$result = $service->update( $space_id, $user_id, $data );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( $service->get( $space_id ), 200 );
	}

	/**
	 * Delete a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$result   = ( new SpaceService() )->delete( $space_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	// ── Membership ──────────────────────────────────────────────────────────

	/**
	 * List active members of a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_space_members( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$space    = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$members = ( new SpaceMemberService() )->get_members( $space_id );

		return new WP_REST_Response( $members, 200 );
	}

	/**
	 * Join a space (or request to join for private, 403 for secret without invite).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function join_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$space    = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$members = new SpaceMemberService();

		// Secret spaces are invite-only.
		if ( 'secret' === $space['type'] ) {
			if ( 'invited' !== $members->get_status( $space_id, $user_id ) ) {
				return new WP_Error(
					'invite_only',
					__( 'This space is invite only. You must be invited to join.', 'buddynext' ),
					array( 'status' => 403 )
				);
			}
		}

		// Private spaces: submit a join request.
		if ( 'private' === $space['type'] ) {
			$current_status = $members->get_status( $space_id, $user_id );

			// If already an active member or has a pending request, treat as success.
			if ( 'active' === $current_status ) {
				return new WP_REST_Response( array( 'joined' => true ), 200 );
			}

			$result = $members->request_join( $space_id, $user_id );

			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );
				return $result;
			}

			return new WP_REST_Response( array( 'requested' => true ), 200 );
		}

		// Open spaces: join directly.
		$result = $members->join( $space_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'joined' => true ), 200 );
	}

	/**
	 * Leave a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function leave_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$result   = ( new SpaceMemberService() )->leave( $space_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'joined' => false ), 200 );
	}

	/**
	 * Invite a user to a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function invite_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id        = (int) $request->get_param( 'id' );
		$inviter_id      = get_current_user_id();
		$invited_user_id = absint( $request->get_param( 'user_id' ) );

		if ( 0 === $invited_user_id ) {
			return new WP_Error(
				'missing_user_id',
				__( 'A user_id parameter is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$space = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$result = ( new SpaceMemberService() )->invite( $space_id, $inviter_id, $invited_user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'invited' => true ), 200 );
	}

	/**
	 * Approve a pending join request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$actor_id = get_current_user_id();
		$user_id  = absint( $request->get_param( 'user_id' ) );

		if ( 0 === $user_id ) {
			return new WP_Error(
				'missing_user_id',
				__( 'A user_id parameter is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$space = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$result = ( new SpaceMemberService() )->approve_request( $space_id, $actor_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$status_code = 'no_pending_request' === $result->get_error_code() ? 404 : 403;
			$result->add_data( array( 'status' => $status_code ) );
			return $result;
		}

		return new WP_REST_Response( array( 'approved' => true ), 200 );
	}

	/**
	 * Ban a member from a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ban_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$actor_id = get_current_user_id();
		$user_id  = absint( $request->get_param( 'user_id' ) );

		if ( 0 === $user_id ) {
			return new WP_Error(
				'missing_user_id',
				__( 'A user_id parameter is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$space = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$result = ( new SpaceMemberService() )->ban( $space_id, $actor_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'banned' => true ), 200 );
	}

	// ── Permissions ─────────────────────────────────────────────────────────

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
