<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for feed endpoints.
 *
 * Routes (all under buddynext/v1):
 *   GET /feed/home             — home feed (auth required)
 *   GET /feed/explore          — explore feed (public)
 *   GET /users/{id}/feed       — profile feed (public)
 *   GET /spaces/{id}/feed      — space feed (public; secret spaces gated to members)
 *
 * All feeds support cursor-based pagination via ?cursor= and ?per_page= params.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves home, explore, and profile feeds over REST.
 */
class FeedController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/feed/home',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'home_feed' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'filter' => array(
						'type'              => 'string',
						'default'           => 'for-you',
						'enum'              => FeedService::HOME_FILTERS,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/counts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'feed_counts' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/explore',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'explore_feed' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/feed',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'profile_feed' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/feed',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_space_feed' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/announcements/(?P<id>[\d]+)/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dismiss_announcement' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Return the authenticated user's home feed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function home_feed( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$cursor   = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20;
		$filter   = (string) ( $request->get_param( 'filter' ) ?? 'for-you' );
		if ( ! in_array( $filter, FeedService::HOME_FILTERS, true ) ) {
			$filter = 'for-you';
		}

		$result = $this->feed_service()->home_feed( $user_id, $cursor, $per_page, $filter );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return per-tab counts for the home feed filter strip.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function feed_counts( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user_id = get_current_user_id();
		$counts  = $this->feed_service()->home_feed_counts( $user_id );

		return new WP_REST_Response( $counts, 200 );
	}

	/**
	 * Return the public explore feed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function explore_feed( WP_REST_Request $request ): WP_REST_Response {
		$cursor   = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20;

		$result = $this->feed_service()->explore_feed( $cursor, $per_page );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return a user's profile feed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function profile_feed( WP_REST_Request $request ): WP_REST_Response {
		$profile_user_id = (int) $request->get_param( 'id' );
		$viewer_id       = get_current_user_id();
		$cursor          = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page        = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20;

		$result = $this->feed_service()->profile_feed( $profile_user_id, $viewer_id, $cursor, $per_page );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return the feed for a given space.
	 *
	 * Enforces secret-space membership before returning posts: secret spaces
	 * are only readable by active members and site admins. Open and private
	 * spaces are listed publicly (private spaces show metadata; member-only
	 * content is gated by the post-privacy layer in PostController::get_post).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_space_feed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id  = (int) $request->get_param( 'id' );
		$viewer_id = get_current_user_id();
		$cursor    = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page  = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20;

		$space = ( new SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		if ( 'secret' === ( $space['type'] ?? '' ) ) {
			$is_member = $viewer_id > 0 && ( new SpaceMemberService() )->is_member( $space_id, $viewer_id );
			if ( ! $is_member && ! user_can( $viewer_id, 'manage_options' ) ) {
				return new WP_Error(
					'space_not_found',
					__( 'Space not found.', 'buddynext' ),
					array( 'status' => 404 )
				);
			}
		}

		$result = $this->feed_service()->space_feed( $space_id, $viewer_id, $cursor, $per_page );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Dismiss a site-wide announcement for the current user.
	 *
	 * Stores a row in bn_announcement_dismissals so the announcement no longer
	 * appears in the user's home feed. Idempotent — safe to call multiple times.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_announcement( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();

		// Verify this is an active announcement before recording the dismissal.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_posts
				 WHERE id = %d AND is_announcement = 1 AND type = 'announcement'
				 LIMIT 1",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $exists ) {
			return new WP_Error(
				'not_found',
				__( 'Announcement not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_announcement_dismissals (user_id, post_id)
				 VALUES (%d, %d)",
				$user_id,
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new WP_REST_Response( null, 204 );
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

	/**
	 * Build the FeedService instance with its dependencies.
	 *
	 * @return FeedService
	 */
	private function feed_service(): FeedService {
		return new FeedService( new FollowService(), new PostService() );
	}
}
