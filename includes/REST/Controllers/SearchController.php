<?php
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
					'cursor'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page'    => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
					'location'    => array(
						'description'       => __( 'Filter members by location (partial match).', 'buddynext' ),
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'online_only' => array(
						'description' => __( 'When true, return only members active in the last 5 minutes.', 'buddynext' ),
						'type'        => 'boolean',
						'required'    => false,
						'default'     => false,
					),
					'sort'        => array(
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
	 * Requires the `q` parameter. Optional `type` filters to a specific
	 * object_type (post, user, space). Optional `per_page` and `page`.
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

		$type     = sanitize_key( (string) ( $request->get_param( 'type' ) ?? '' ) );
		$per_page = min( (int) ( $request->get_param( 'per_page' ) ?? 20 ), 50 );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );

		$results = ( new SearchService() )->search( $query, $type, $per_page, $page );

		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * Return the paginated member directory.
	 *
	 * Accepts optional filter params: location, online_only, sort.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_members( WP_REST_Request $request ): WP_REST_Response {
		$viewer_id = get_current_user_id();
		$cursor    = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page  = min( (int) ( $request->get_param( 'per_page' ) ?? 20 ), 50 );

		$filters = array(
			'location'    => sanitize_text_field( (string) ( $request->get_param( 'location' ) ?? '' ) ),
			'online_only' => (bool) $request->get_param( 'online_only' ),
			'sort'        => sanitize_key( (string) ( $request->get_param( 'sort' ) ?? 'newest' ) ),
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
