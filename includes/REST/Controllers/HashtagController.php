<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Hashtag REST controller.
 *
 * Routes (all under buddynext/v1):
 *   GET    /hashtags/trending          — top trending hashtags (public)
 *   GET    /hashtags/autocomplete      — prefix-search suggestions (public)
 *   POST   /hashtags/{slug}/follow     — follow a hashtag (authenticated)
 *   DELETE /hashtags/{slug}/follow     — unfollow a hashtag (authenticated)
 *   GET    /hashtags/{slug}            — look up a hashtag by slug (public)
 *
 * Literal-path routes (trending, autocomplete, follow) are registered before
 * the /{slug} wildcard to prevent them being captured by the slug regex.
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
			'/hashtags/autocomplete',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'autocomplete' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/hashtags/(?P<slug>[a-zA-Z0-9_-]+)/follow',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'follow' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array(
						'slug' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unfollow' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array(
						'slug' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
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
	 * Return hashtag suggestions matching a search prefix.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function autocomplete( WP_REST_Request $request ): WP_REST_Response {
		$prefix  = sanitize_key( (string) $request->get_param( 'q' ) );
		$limit   = (int) $request->get_param( 'limit' );
		$results = ( new HashtagService() )->autocomplete( $prefix, $limit );

		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * Follow a hashtag on behalf of the current user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function follow( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug    = (string) $request->get_param( 'slug' );
		$service = new HashtagService();
		$hashtag = $service->get_by_slug( $slug );

		if ( null === $hashtag ) {
			return new WP_Error( 'not_found', __( 'Hashtag not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$service->follow( get_current_user_id(), $hashtag['id'] );

		return new WP_REST_Response( array( 'following' => true ), 200 );
	}

	/**
	 * Unfollow a hashtag on behalf of the current user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unfollow( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug    = (string) $request->get_param( 'slug' );
		$service = new HashtagService();
		$hashtag = $service->get_by_slug( $slug );

		if ( null === $hashtag ) {
			return new WP_Error( 'not_found', __( 'Hashtag not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$service->unfollow( get_current_user_id(), $hashtag['id'] );

		return new WP_REST_Response( array( 'following' => false ), 200 );
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
