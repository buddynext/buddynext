<?php
/**
 * Hashtag REST controller.
 *
 * Routes (all under buddynext/v1):
 *   GET /hashtags/trending       — top trending hashtags (public)
 *   GET /hashtags/{slug}         — look up a hashtag by slug (public)
 *
 * The trending route is registered before the slug pattern to prevent it
 * being captured by the slug regex.
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Hashtags\HashtagService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles hashtag lookup over REST.
 */
class HashtagController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/hashtags/trending',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_trending' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'limit' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/hashtags/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_by_slug' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Return trending hashtags.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_trending( WP_REST_Request $request ): WP_REST_Response {
		$limit  = (int) $request->get_param( 'limit' );
		$result = ( new HashtagService() )->get_trending( $limit );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return a hashtag by slug.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_by_slug( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug    = (string) $request->get_param( 'slug' );
		$hashtag = ( new HashtagService() )->get_by_slug( $slug );

		if ( null === $hashtag ) {
			return new WP_Error( 'not_found', __( 'Hashtag not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $hashtag, 200 );
	}
}
