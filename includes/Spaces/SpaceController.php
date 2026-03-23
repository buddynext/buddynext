<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for spaces.
 *
 * Routes (all under buddynext/v1):
 *   GET    /spaces                              — list spaces (public)
 *   POST   /spaces                              — create a space (auth required)
 *   GET    /spaces/{id}                         — get a space (public)
 *   PUT    /spaces/{id}                         — update a space (owner only)
 *   DELETE /spaces/{id}                         — delete a space (owner only)
 *   GET    /spaces/{id}/members                 — list members (public for open/private)
 *   GET    /spaces/{id}/pending-requests        — list pending join requests (owner/mod only)
 *   POST   /spaces/{id}/join                    — join or request-to-join (auth required)
 *   POST   /spaces/{id}/leave                   — leave a space (auth required)
 *   POST   /spaces/{id}/invite                  — invite a user (owner/mod only)
 *   POST   /spaces/{id}/approve-request         — approve a pending request (owner/mod only)
 *   POST   /spaces/{id}/ban                     — ban a member (owner/mod only)
 *
 * Join semantics by space type:
 *   Open    → status='active' immediately, returns {joined: true}
 *   Private → status='pending' (request), returns {requested: true}
 *   Secret  → invite-only; 403 unless user has a pending 'invited' status
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

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
			'/spaces/(?P<id>[\d]+)/pending-requests',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_pending_requests' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/join',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'join_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'leave_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
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

		// Spec-conformant approve/decline endpoints: POST /spaces/{id}/members/{user_id}/approve|decline.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)/decline',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decline_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		// Legacy approve-request endpoint (kept for backwards compatibility).
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

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/ban/(?P<user_id>[\d]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'ban_user' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unban_user' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)/role',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'change_member_role' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove_member' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/transfer-ownership',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'transfer_ownership' ),
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
		$per_page_param = $request->get_param( 'per_page' );
		$page_param     = $request->get_param( 'page' );
		$orderby_param  = $request->get_param( 'orderby' );
		$order_param    = $request->get_param( 'order' );

		$args = array(
			'per_page' => absint( null !== $per_page_param ? $per_page_param : 12 ),
			'page'     => absint( null !== $page_param ? $page_param : 1 ),
			'orderby'  => sanitize_key( (string) ( null !== $orderby_param ? $orderby_param : 'member_count' ) ),
			'order'    => sanitize_key( (string) ( null !== $order_param ? $order_param : 'DESC' ) ),
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

		if ( 'secret' === $space['type'] ) {
			$viewer_id = get_current_user_id();
			if ( 0 === $viewer_id || ! ( new SpaceMemberService() )->is_member( $space['id'], $viewer_id ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'Space not found.', 'buddynext' ),
					array( 'status' => 404 )
				);
			}
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
		$space_id  = (int) $request->get_param( 'id' );
		$space     = ( new SpaceService() )->get( $space_id );
		$viewer_id = get_current_user_id();

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// Secret spaces: only active members and site admins may see the roster.
		if ( 'secret' === $space['type'] && ! user_can( $viewer_id, 'manage_options' ) ) {
			$member_service = new SpaceMemberService();
			if ( 'active' !== $member_service->get_status( $space_id, $viewer_id ) ) {
				return new WP_Error(
					'forbidden',
					__( 'You do not have access to this space.', 'buddynext' ),
					array( 'status' => 403 )
				);
			}
		}

		$members = ( new SpaceMemberService() )->get_members( $space_id, $viewer_id );

		return new WP_REST_Response( $members, 200 );
	}

	/**
	 * Return pending join requests for a space.
	 *
	 * Only the space owner, a moderator, or a site admin may access this endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pending_requests( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id   = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		$space      = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$member_service = new SpaceMemberService();
		$actor_role     = $member_service->get_role( $space_id, $current_id );

		if ( ! in_array( $actor_role, array( 'owner', 'moderator' ), true ) && ! user_can( $current_id, 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Only space owners and moderators can view pending requests.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$requests = $member_service->get_pending_requests( $space_id );

		return new WP_REST_Response(
			array(
				'items' => $requests,
				'total' => count( $requests ),
			),
			200
		);
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

		// Enforce space ban at the REST layer before any join path executes.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$is_banned = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_bans WHERE space_id = %d AND user_id = %d",
				$space_id,
				$user_id
			)
		);

		if ( $is_banned ) {
			return new WP_Error(
				'space_banned',
				__( 'You have been banned from this space.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

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
	 * Decline a pending join request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function decline_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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

		$result = ( new SpaceMemberService() )->decline_request( $space_id, $actor_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$status_code = 'no_pending_request' === $result->get_error_code() ? 404 : 403;
			$result->add_data( array( 'status' => $status_code ) );
			return $result;
		}

		return new WP_REST_Response( array( 'declined' => true ), 200 );
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

	/**
	 * Ban a user from a space via POST /spaces/{id}/ban/{user_id}.
	 *
	 * Requires buddynext-moderate-space on the acting user. Inserts a hard ban
	 * row into bn_space_bans and removes any active membership row.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ban_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id    = (int) $request->get_param( 'id' );
		$ban_user_id = (int) $request->get_param( 'user_id' );
		$actor_id    = get_current_user_id();

		if ( 0 === $ban_user_id ) {
			return new WP_Error(
				'missing_user_id',
				__( 'A valid user_id is required.', 'buddynext' ),
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

		if ( ! buddynext_can( $actor_id, 'buddynext-moderate-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to moderate this space.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$reason_param = $request->get_param( 'reason' );
		$reason       = null !== $reason_param ? sanitize_textarea_field( (string) $reason_param ) : '';

		global $wpdb;

		// Write hard ban row (INSERT IGNORE — idempotent if already banned).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_space_bans (space_id, user_id, banned_by, reason)
				 VALUES (%d, %d, %d, %s)",
				$space_id,
				$ban_user_id,
				$actor_id,
				$reason
			)
		);

		// Remove the user from active membership, decrementing the count only
		// when they were previously an active member.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$was_active = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND user_id = %d AND status = 'active'",
				$space_id,
				$ban_user_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $ban_user_id,
			),
			array( '%d', '%d' )
		);

		if ( $was_active ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_spaces
					 SET member_count = GREATEST( member_count - 1, 0 )
					 WHERE id = %d",
					$space_id
				)
			);
		}

		wp_cache_delete( "space_{$space_id}", 'buddynext_spaces' );
		wp_cache_delete( "members_{$space_id}", 'buddynext_spaces' );
		wp_cache_delete( "role_{$space_id}_{$ban_user_id}", 'buddynext_space_members' );
		wp_cache_delete( "status_{$space_id}_{$ban_user_id}", 'buddynext_space_members' );

		/**
		 * Fires after a user is banned from a space via the REST ban endpoint.
		 *
		 * @param int    $space_id    Space ID.
		 * @param int    $ban_user_id User who was banned.
		 * @param int    $actor_id    User who performed the ban.
		 * @param string $reason      Ban reason (may be empty).
		 */
		do_action( 'buddynext_space_user_banned', $space_id, $ban_user_id, $actor_id, $reason );

		return new WP_REST_Response( array( 'banned' => true ), 200 );
	}

	/**
	 * Unban a user from a space via DELETE /spaces/{id}/ban/{user_id}.
	 *
	 * Requires buddynext-moderate-space on the acting user. Removes the hard
	 * ban row from bn_space_bans; does not reinstate membership.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unban_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id    = (int) $request->get_param( 'id' );
		$ban_user_id = (int) $request->get_param( 'user_id' );
		$actor_id    = get_current_user_id();

		if ( 0 === $ban_user_id ) {
			return new WP_Error(
				'missing_user_id',
				__( 'A valid user_id is required.', 'buddynext' ),
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

		if ( ! buddynext_can( $actor_id, 'buddynext-moderate-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to moderate this space.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_bans',
			array(
				'space_id' => $space_id,
				'user_id'  => $ban_user_id,
			),
			array( '%d', '%d' )
		);

		wp_cache_delete( "role_{$space_id}_{$ban_user_id}", 'buddynext_space_members' );
		wp_cache_delete( "status_{$space_id}_{$ban_user_id}", 'buddynext_space_members' );

		/**
		 * Fires after a space ban is lifted via the REST unban endpoint.
		 *
		 * @param int $space_id    Space ID.
		 * @param int $ban_user_id User who was unbanned.
		 * @param int $actor_id    User who lifted the ban.
		 */
		do_action( 'buddynext_space_user_unbanned', $space_id, $ban_user_id, $actor_id );

		return new WP_REST_Response( array( 'unbanned' => true ), 200 );
	}

	/**
	 * Change a member's role within a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_member_role( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id  = (int) $request->get_param( 'id' );
		$target_id = (int) $request->get_param( 'user_id' );
		$actor_id  = get_current_user_id();
		$new_role  = sanitize_key( (string) $request->get_param( 'role' ) );

		if ( ! in_array( $new_role, array( 'moderator', 'member' ), true ) ) {
			return new WP_Error( 'invalid_role', __( 'Role must be moderator or member.', 'buddynext' ), array( 'status' => 400 ) );
		}

		$result = ( new SpaceMemberService() )->change_role( $space_id, $target_id, $new_role, $actor_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'role' => $new_role ), 200 );
	}

	/**
	 * Remove a member from a space (without banning).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id  = (int) $request->get_param( 'id' );
		$target_id = (int) $request->get_param( 'user_id' );
		$actor_id  = get_current_user_id();

		$service = new SpaceMemberService();
		$role    = $service->get_role( $space_id, $actor_id );

		if ( ! in_array( $role, array( 'owner', 'moderator' ), true ) && ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Only owners and moderators can remove members.', 'buddynext' ), array( 'status' => 403 ) );
		}

		// Cannot remove the owner.
		$target_role = $service->get_role( $space_id, $target_id );
		if ( 'owner' === $target_role ) {
			return new WP_Error( 'cannot_remove_owner', __( 'The space owner cannot be removed.', 'buddynext' ), array( 'status' => 403 ) );
		}

		// Use leave() to cleanly remove and decrement count.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $target_id,
			),
			array( '%d', '%d' )
		);

		// Decrement member count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_spaces SET member_count = GREATEST( member_count - 1, 0 ) WHERE id = %d",
				$space_id
			)
		);

		wp_cache_delete( "space_{$space_id}", 'buddynext_spaces' );
		wp_cache_delete( "members_{$space_id}", 'buddynext_spaces' );

		/**
		 * Fires after a moderator removes a member from a space.
		 *
		 * @param int $target_id  User who was removed.
		 * @param int $space_id   Space ID.
		 * @param int $actor_id   User who performed the removal.
		 */
		do_action( 'buddynext_space_member_removed', $target_id, $space_id, $actor_id );

		return new WP_REST_Response( array( 'removed' => true ), 200 );
	}

	/**
	 * Transfer space ownership to another member.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function transfer_ownership( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id     = (int) $request->get_param( 'id' );
		$current_user = get_current_user_id();
		$new_owner_id = absint( $request->get_param( 'new_owner_id' ) );

		if ( 0 === $new_owner_id ) {
			return new WP_Error( 'missing_new_owner_id', __( 'A new_owner_id is required.', 'buddynext' ), array( 'status' => 400 ) );
		}

		$space = ( new SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return new WP_Error( 'space_not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		if ( $space['owner_id'] !== $current_user && ! user_can( $current_user, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Only the space owner can transfer ownership.', 'buddynext' ), array( 'status' => 403 ) );
		}

		$member_service = new SpaceMemberService();

		// New owner must be an active member.
		if ( ! $member_service->is_member( $space_id, $new_owner_id ) ) {
			return new WP_Error( 'not_a_member', __( 'The new owner must be an active member of the space.', 'buddynext' ), array( 'status' => 422 ) );
		}

		global $wpdb;

		// Demote current owner to member.
		$member_service->change_role( $space_id, $current_user, 'member', $current_user );

		// Promote new owner.
		$member_service->change_role( $space_id, $new_owner_id, 'owner', $current_user );

		// Update bn_spaces.owner_id — change_role alone does not do this.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_spaces',
			array( 'owner_id' => $new_owner_id ),
			array( 'id' => $space_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_cache_delete( "space_{$space_id}", 'buddynext_spaces' );

		return new WP_REST_Response(
			array(
				'transferred'  => true,
				'new_owner_id' => $new_owner_id,
			),
			200
		);
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
