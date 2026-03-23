<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for unified search and member directory.
 *
 * Routes (all under buddynext/v1):
 *   GET /search   — full-text search across posts, users, spaces (public)
 *   GET /members  — paginated member directory (public)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Search\MemberDirectoryService;
use BuddyNext\Search\SearchService;
use BuddyNext\SocialGraph\FollowService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles search and member directory reads over REST.
 */
class SearchController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'per_page' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 20,
					),
					'page'     => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 1,
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/members',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_members' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'cursor'            => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page'          => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
					'search'            => array(
						'description'       => __( 'Filter members by name or username (partial match).', 'buddynext' ),
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'location'          => array(
						'description'       => __( 'Filter members by location (partial match).', 'buddynext' ),
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'skills'            => array(
						'description'       => __( 'Filter members by skills (partial match).', 'buddynext' ),
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'space_id'          => array(
						'description'       => __( 'Return only members of this space ID.', 'buddynext' ),
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'connection_status' => array(
						'description'       => __( 'connections = only viewer\'s accepted connections; everyone = all members (default).', 'buddynext' ),
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'online_only'       => array(
						'description' => __( 'When true, return only members active in the last 5 minutes.', 'buddynext' ),
						'type'        => 'boolean',
						'required'    => false,
						'default'     => false,
					),
					'sort'              => array(
						'description'       => __( 'Sort order: newest, alphabetical, most_active, or online.', 'buddynext' ),
						'type'              => 'string',
						'required'          => false,
						'default'           => 'newest',
						'enum'              => array( 'newest', 'alphabetical', 'most_active', 'online' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Perform a unified search.
	 *
	 * When no `type` is provided, returns grouped results keyed by content type
	 * (up to 5 results per type). When `type` is provided, returns a flat
	 * paginated result list for that specific type.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$query = trim( (string) ( $request->get_param( 'q' ) ?? '' ) );

		if ( '' === $query ) {
			return new WP_Error(
				'missing_query',
				__( 'The q parameter is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$type      = (string) ( $request->get_param( 'type' ) ?? '' );
		$viewer_id = get_current_user_id();
		$service   = new SearchService();

		if ( '' === $type ) {
			$grouped = $service->grouped_search( $query, $viewer_id );
			return new WP_REST_Response(
				array(
					'grouped' => true,
					'results' => $grouped,
				),
				200
			);
		}

		$per_page = min( (int) ( $request->get_param( 'per_page' ) ?? 20 ), 50 );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$results  = $service->search( $query, $type, $per_page, $page, $viewer_id );

		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * Return the paginated member directory.
	 *
	 * Accepts optional filter params: search, location, skills, space_id,
	 * connection_status, online_only, sort.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_members( WP_REST_Request $request ): WP_REST_Response {
		$viewer_id = get_current_user_id();
		$cursor    = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page  = min( (int) ( $request->get_param( 'per_page' ) ?? 20 ), 50 );

		$filters = array(
			'search'            => sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) ),
			'location'          => sanitize_text_field( (string) ( $request->get_param( 'location' ) ?? '' ) ),
			'skills'            => sanitize_text_field( (string) ( $request->get_param( 'skills' ) ?? '' ) ),
			'space_id'          => absint( $request->get_param( 'space_id' ) ?? 0 ),
			'connection_status' => sanitize_key( (string) ( $request->get_param( 'connection_status' ) ?? 'everyone' ) ),
			'online_only'       => (bool) $request->get_param( 'online_only' ),
			'sort'              => sanitize_key( (string) ( $request->get_param( 'sort' ) ?? 'newest' ) ),
		);

		$result = ( new MemberDirectoryService( new FollowService() ) )->list_members(
			$viewer_id,
			$cursor,
			$per_page,
			$filters
		);

		return new WP_REST_Response( $result, 200 );
	}
}
