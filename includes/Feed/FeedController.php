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
			'/feed/new-count',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'feed_new_count' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'after_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'filter'   => array(
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
			'/feed/home/page',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'home_feed_page' ),
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
			'/feed/explore/page',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'explore_feed_page' ),
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
	 * Return the number of home-feed posts newer than the client's top-of-feed id.
	 *
	 * Drives the Free "N new posts" pill on /activity: the client passes the id
	 * of the post currently at the top of its rendered feed (`after_id`) plus the
	 * active filter, and receives a lightweight count + the newest visible id so
	 * the poll can advance its watermark. Authors' own posts are excluded so a
	 * member's own composer submission never inflates the pill.
	 *
	 * Response shape: { count: int, newest_id: int }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function feed_new_count( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$after_id = (int) $request->get_param( 'after_id' );
		$filter   = (string) ( $request->get_param( 'filter' ) ?? 'for-you' );
		if ( ! in_array( $filter, FeedService::HOME_FILTERS, true ) ) {
			$filter = 'for-you';
		}

		$result = $this->feed_service()->home_feed_new_count( $user_id, $after_id, $filter );

		return new WP_REST_Response( $result, 200 );
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
	 * Persists the dismissal as a user_meta entry so the announcement no longer
	 * appears in the user's home feed. Idempotent — safe to call multiple times.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_announcement( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();

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

		FeedService::dismiss_announcement( $user_id, $post_id );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Return the home feed as pre-rendered HTML for infinite-scroll appending.
	 *
	 * Calls home_feed() then renders each item with the canonical
	 * `partials/post-card.php` partial so the appended cards are byte-identical
	 * to first-paint cards. The client only needs to inject the returned HTML
	 * and update the cursor — no card markup is duplicated in JS.
	 *
	 * Response shape:
	 *   { html: string, next_cursor: string|null, count: int }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function home_feed_page( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$cursor   = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 15;
		$filter   = (string) ( $request->get_param( 'filter' ) ?? 'for-you' );
		if ( ! in_array( $filter, FeedService::HOME_FILTERS, true ) ) {
			$filter = 'for-you';
		}

		$result = $this->feed_service()->home_feed( $user_id, $cursor, $per_page, $filter );
		$html   = $this->render_items_html( (array) ( $result['items'] ?? array() ), $user_id, 'home' );

		return new WP_REST_Response(
			array(
				'html'        => $html,
				'next_cursor' => $result['next_cursor'] ?? null,
				'count'       => count( (array) ( $result['items'] ?? array() ) ),
			),
			200
		);
	}

	/**
	 * Return the explore feed as pre-rendered HTML for infinite-scroll appending.
	 *
	 * Public endpoint — visible to guests. Same response shape as
	 * {@see self::home_feed_page()}.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function explore_feed_page( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$cursor   = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 12;

		$result = $this->feed_service()->explore_feed( $cursor, $per_page );
		$html   = $this->render_items_html( (array) ( $result['items'] ?? array() ), $user_id, 'explore' );

		return new WP_REST_Response(
			array(
				'html'        => $html,
				'next_cursor' => $result['next_cursor'] ?? null,
				'count'       => count( (array) ( $result['items'] ?? array() ) ),
			),
			200
		);
	}

	/**
	 * Render a list of hydrated post records to a single HTML string.
	 *
	 * Loops through items, hydrates poll options for poll posts (the feed query
	 * doesn't join them), and delegates each card to `partials/post-card.php`
	 * inside an output buffer.
	 *
	 * @param array<int,array<string,mixed>> $items   Post records (hydrated by FeedService).
	 * @param int                            $viewer  Viewing user ID (0 for guests).
	 * @param string                         $context Feed context ('home' or 'explore').
	 * @return string HTML markup ready to inject into the feed list container.
	 */
	private function render_items_html( array $items, int $viewer, string $context ): string {
		if ( empty( $items ) || ! function_exists( 'buddynext_get_template' ) ) {
			return '';
		}

		$post_service = new PostService();

		ob_start();
		foreach ( $items as $item ) {
			$post = $item;
			if ( ! isset( $post['poll_options'] ) && 'poll' === ( $post['type'] ?? '' ) ) {
				$full = $post_service->get( (int) ( $post['id'] ?? 0 ) );
				if ( null !== $full && ! empty( $full['poll_options'] ) ) {
					$post['poll_options'] = $full['poll_options'];
				}
			}

			buddynext_get_template(
				'partials/post-card.php',
				array(
					'post'            => $post,
					'current_user_id' => $viewer,
					'context'         => $context,
				)
			);
		}
		return (string) ob_get_clean();
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
	 * Resolve the active feed service.
	 *
	 * Prefers the container-bound `feed` service so that any rebind — notably
	 * the Pro `AiRankedFeedService`, which fires the `buddynext_feed_query_args`
	 * / `buddynext_feed_order_by` ranking hooks — is honoured on every REST
	 * pagination and filter-switch request. Without this, infinite scroll would
	 * revert to chronological order even when the first SSR paint was AI-ranked.
	 *
	 * Falls back to a directly-constructed FeedService when the container is
	 * unavailable (e.g. the front-end isolation harness strips the bootstrap),
	 * so the endpoints never fatal — they simply serve the chronological feed.
	 *
	 * @return FeedService
	 */
	private function feed_service(): FeedService {
		if ( function_exists( 'buddynext_service' ) ) {
			$service = buddynext_service( 'feed' );
			if ( $service instanceof FeedService ) {
				return $service;
			}
		}

		return new FeedService( new FollowService(), new PostService() );
	}
}
