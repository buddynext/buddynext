<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Hashtag REST controller.
 *
 * Routes (all under buddynext/v1):
 *   GET    /hashtags/trending          — top trending hashtags (public)
 *   GET    /hashtags/autocomplete      — prefix-search suggestions (public)
 *   POST   /hashtags/{slug}/follow     — follow a hashtag (authenticated)
 *   DELETE /hashtags/{slug}/follow     — unfollow a hashtag (authenticated)
 *   GET    /hashtags/{slug}/feed       — paginated public posts for a hashtag (public)
 *   GET    /hashtags/{slug}            — look up a hashtag by slug (public)
 *
 * Literal-path routes (trending, autocomplete, follow, feed) are registered
 * before the /{slug} wildcard to prevent them being captured by the slug regex.
 *
 * @package BuddyNext\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Hashtags;

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
				'permission_callback' => array( $this, 'require_hashtags_enabled' ),
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
				'permission_callback' => array( $this, 'require_hashtags_enabled' ),
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
					'permission_callback' => array( $this, 'require_hashtags_enabled_auth' ),
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
					'permission_callback' => array( $this, 'require_hashtags_enabled_auth' ),
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
			'/hashtags/(?P<slug>[a-zA-Z0-9_-]+)/feed',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_feed' ),
				'permission_callback' => array( $this, 'require_hashtags_enabled' ),
				'args'                => array(
					'slug'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'per_page' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
					'cursor'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
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
				'permission_callback' => array( $this, 'require_hashtags_enabled' ),
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
	 * Permission gate: the Hashtags feature must be enabled.
	 *
	 * Mirrors ReactionController's gate so toggling Settings > Features >
	 * Hashtags off actually disables the REST API (it previously had no effect).
	 *
	 * @return true|\WP_Error
	 */
	public function require_hashtags_enabled() {
		$features = function_exists( 'buddynext_service' ) ? buddynext_service( 'features' ) : null;

		if ( is_object( $features ) && method_exists( $features, 'is_enabled' ) && ! $features->is_enabled( 'hashtags' ) ) {
			return new \WP_Error(
				'hashtags_disabled',
				__( 'Hashtags are turned off on this community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission gate for write routes: logged in AND the Hashtags feature on.
	 *
	 * @return true|\WP_Error
	 */
	public function require_hashtags_enabled_auth() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}

		return $this->require_hashtags_enabled();
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
	 * Return paginated public posts for a hashtag.
	 *
	 * Delegates to HashtagService::get_feed(), which surfaces only public,
	 * published posts using keyset cursor pagination. The response mirrors the
	 * service contract: items, next_cursor, and the hashtag metadata.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_feed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = (string) $request->get_param( 'slug' );
		$args = array( 'per_page' => (int) $request->get_param( 'per_page' ) );

		$cursor = (string) $request->get_param( 'cursor' );
		if ( '' !== $cursor ) {
			$args['cursor'] = $cursor;
		}

		$result = ( new HashtagService() )->get_feed( $slug, $args );

		if ( null === $result['hashtag'] ) {
			return new WP_Error( 'not_found', __( 'Hashtag not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

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
