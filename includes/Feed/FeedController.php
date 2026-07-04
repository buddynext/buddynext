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
use BuddyNext\REST\BaseRestController;

/**
 * Serves home, explore, and profile feeds over REST.
 */
class FeedController extends BaseRestController {

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
			'/feed/viewer-state',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'viewer_state' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'post_ids' => array(
						'required'    => true,
						'description' => 'Comma-separated post IDs to fetch the viewer\'s reaction/bookmark/vote state for.',
						'type'        => 'string',
					),
				),
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
				'permission_callback' => array( $this, 'require_public_explore' ),
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
			'/feed/announcements',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_announcements' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/explore/page',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'explore_feed_page' ),
				'permission_callback' => array( $this, 'require_public_explore' ),
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

		register_rest_route(
			'buddynext/v1',
			'/feed/announcements/(?P<id>[\d]+)/end',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'end_announcement' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Permission gate for the explore feed.
	 *
	 * Logged-in members can always read explore. Guests can read it only when
	 * the site owner has left "Public explore feed" on
	 * (buddynext_public_explore, default true). When that option is off, the
	 * explore feed becomes a members-only surface and guests get a 401 — the
	 * REST mirror of the PageRouter redirect that gates the /explore/ page.
	 *
	 * @return true|WP_Error
	 */
	public function require_public_explore(): bool|WP_Error {
		if ( is_user_logged_in() ) {
			return true;
		}

		if ( (bool) get_option( 'buddynext_public_explore', true ) ) {
			return true;
		}

		return new WP_Error(
			'rest_explore_members_only',
			__( 'The explore feed is available to members only.', 'buddynext' ),
			array( 'status' => 401 )
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

		if ( ! empty( $result['items'] ) && is_array( $result['items'] ) ) {
			$result['items'] = $this->enrich_items_for_rest( (array) $result['items'], $user_id );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Enrich raw feed rows into an app-renderable shape.
	 *
	 * The JSON feed previously returned raw hydrate() rows (author as a bare
	 * user_id, no viewer state, no rendered content), so the native app could not
	 * render what the web SSR renders without N extra calls per card. This adds,
	 * per item: an author object (display_name + avatar), formatted content_html
	 * (the same buddynext_format_content() the comment REST uses), and a
	 * viewer_state block (my_reaction / is_bookmarked / my_voted_option_id /
	 * can_edit). Everything is primed ONCE for the whole page — author cache via
	 * cache_users(), reactions + poll votes via single batch maps, bookmarks via
	 * the already-cached user_bookmarks() — so enrichment adds a constant handful
	 * of queries, not four per card.
	 *
	 * @param array $items  Raw feed rows from FeedService::home_feed().
	 * @param int   $viewer Current user ID (0 when logged out).
	 * @return array Enriched items.
	 */
	private function enrich_items_for_rest( array $items, int $viewer ): array {
		if ( empty( $items ) ) {
			return $items;
		}

		$post_ids   = array();
		$author_ids = array();
		$poll_ids   = array();
		foreach ( $items as $item ) {
			$pid = absint( $item['id'] ?? 0 );
			$aid = absint( $item['user_id'] ?? 0 );
			if ( $pid ) {
				$post_ids[] = $pid;
			}
			if ( $aid ) {
				$author_ids[] = $aid;
			}
			if ( $pid && 'poll' === ( $item['type'] ?? '' ) ) {
				$poll_ids[] = $pid;
			}
		}

		// Prime once for the whole page (no per-card lookups below).
		if ( ! empty( $author_ids ) ) {
			cache_users( array_values( array_unique( $author_ids ) ) );
		}

		$reactions = array();
		$votes     = array();
		$bookmarks = array();
		if ( $viewer > 0 ) {
			$reactions = buddynext_service( 'reactions' )->get_user_emoji_map( $viewer, 'post', $post_ids );
			$votes     = buddynext_service( 'polls' )->user_votes_map( $viewer, $poll_ids );
			$bookmarks = array_flip( buddynext_service( 'bookmarks' )->user_bookmarks( $viewer ) );
		}

		$format = function_exists( 'buddynext_format_content' );

		foreach ( $items as &$item ) {
			$pid    = absint( $item['id'] ?? 0 );
			$aid    = absint( $item['user_id'] ?? 0 );
			$author = $aid ? get_userdata( $aid ) : null;

			$item['author'] = array(
				'id'           => $aid,
				'display_name' => $author ? $author->display_name : __( 'Community Member', 'buddynext' ),
				'avatar_url'   => $aid ? get_avatar_url( $aid, array( 'size' => 96 ) ) : '',
			);

			$item['content_html'] = $format
				? buddynext_format_content( (string) ( $item['content'] ?? '' ) )
				: (string) ( $item['content'] ?? '' );

			$item['viewer_state'] = array(
				'my_reaction'        => $reactions[ $pid ] ?? null,
				'is_bookmarked'      => isset( $bookmarks[ $pid ] ),
				'my_voted_option_id' => isset( $votes[ $pid ] ) ? (int) $votes[ $pid ] : 0,
				// Baseline: author or admin. Under-reports for space moderators
				// (safe direction — never shows an edit affordance the API rejects).
				'can_edit'           => $viewer > 0 && ( $viewer === $aid || user_can( $viewer, 'manage_options' ) ),
			);
		}
		unset( $item );

		return $items;
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
	 * GET /feed/announcements — the active announcements the viewer should see.
	 *
	 * The site-wide announcement plus one per space the viewer belongs to, each
	 * respecting the viewer's dismissals + expiry. Lets the native app fetch/badge/
	 * push announcements instead of scraping the /feed/home page-1 prepend.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_announcements( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$viewer = get_current_user_id();
		$feed   = $this->feed_service();
		$items  = array();

		$site = $feed->active_announcement( $viewer );
		if ( is_array( $site ) ) {
			$items[] = $this->shape_announcement( $site );
		}

		if ( $viewer > 0 ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$space_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT space_id FROM {$wpdb->prefix}bn_space_members WHERE user_id = %d AND status = 'active'",
					$viewer
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $space_ids as $sid ) {
				$ann = $feed->space_announcement( (int) $sid, $viewer );
				if ( is_array( $ann ) ) {
					$items[] = $this->shape_announcement( $ann );
				}
			}
		}

		return new WP_REST_Response( array( 'announcements' => $items ), 200 );
	}

	/**
	 * Shape an announcement post for the REST payload.
	 *
	 * @param array $post Hydrated announcement post row.
	 * @return array
	 */
	private function shape_announcement( array $post ): array {
		$author_id = (int) ( $post['user_id'] ?? 0 );
		$author    = $author_id ? get_userdata( $author_id ) : null;
		$content   = (string) ( $post['content'] ?? '' );

		return array(
			'id'           => (int) ( $post['id'] ?? 0 ),
			'space_id'     => (int) ( $post['space_id'] ?? 0 ),
			'content'      => $content,
			'content_html' => function_exists( 'buddynext_format_content' ) ? buddynext_format_content( $content ) : $content,
			'created_at'   => (string) ( $post['created_at'] ?? '' ),
			'expires_at'   => $post['site_pin_expires_at'] ?? null,
			'author'       => array(
				'id'           => $author_id,
				'display_name' => $author ? $author->display_name : '',
				'avatar_url'   => $author_id ? get_avatar_url( $author_id, array( 'size' => 96 ) ) : '',
			),
		);
	}

	/**
	 * Batch viewer-state for a set of posts.
	 *
	 * Lets the app refresh reaction / bookmark / vote state for posts it already
	 * holds (e.g. after a scroll or a cross-feed merge) in ONE request instead of
	 * one call per post. Uses the same batch primitives as the feed enrichment, so
	 * it stays O(1) in queries regardless of how many ids are passed.
	 *
	 * @param WP_REST_Request $request Incoming request (post_ids: CSV of IDs).
	 * @return WP_REST_Response Map of post_id => { my_reaction, is_bookmarked, my_voted_option_id }.
	 */
	public function viewer_state( WP_REST_Request $request ): WP_REST_Response {
		$viewer   = get_current_user_id();
		$raw      = (string) $request->get_param( 'post_ids' );
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );
		$post_ids = array_slice( $post_ids, 0, 100 ); // bound the batch.

		if ( empty( $post_ids ) ) {
			return new WP_REST_Response( array( 'states' => (object) array() ), 200 );
		}

		$reactions = buddynext_service( 'reactions' )->get_user_emoji_map( $viewer, 'post', $post_ids );
		$votes     = buddynext_service( 'polls' )->user_votes_map( $viewer, $post_ids );
		$bookmarks = array_flip( buddynext_service( 'bookmarks' )->user_bookmarks( $viewer ) );

		$states = array();
		foreach ( $post_ids as $pid ) {
			$states[ $pid ] = array(
				'my_reaction'        => $reactions[ $pid ] ?? null,
				'is_bookmarked'      => isset( $bookmarks[ $pid ] ),
				'my_voted_option_id' => isset( $votes[ $pid ] ) ? (int) $votes[ $pid ] : 0,
			);
		}

		return new WP_REST_Response( array( 'states' => $states ), 200 );
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

		// Only OPEN spaces are publicly readable. Private AND secret both require
		// membership to read posts — previously only 'secret' was gated, so a
		// private (membership-restricted) space leaked its posts to non-members /
		// guests. Secret stays fully hidden (404); private exists and is
		// discoverable, but its posts are members-only (403).
		$space_type = (string) ( $space['type'] ?? '' );
		if ( 'open' !== $space_type ) {
			$is_member = $viewer_id > 0 && ( new SpaceMemberService() )->is_member( $space_id, $viewer_id );
			if ( ! $is_member && ! user_can( $viewer_id, 'manage_options' ) ) {
				if ( 'secret' === $space_type ) {
					return new WP_Error(
						'space_not_found',
						__( 'Space not found.', 'buddynext' ),
						array( 'status' => 404 )
					);
				}
				return new WP_Error(
					'space_members_only',
					__( 'This space is members only. Join to view its posts.', 'buddynext' ),
					array( 'status' => 403 )
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
		// Announcements feature gate — when off, no announcement is surfaced, so a
		// dismiss request can only be stale; treat it as not found.
		if ( ! buddynext_feature_enabled( 'announcements' ) ) {
			return new WP_Error(
				'feature_disabled',
				__( 'Announcements are disabled.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();

		if ( null === buddynext_service( 'post_service' )->get_announcement( $post_id ) ) {
			return new WP_Error(
				'not_found',
				__( 'Announcement not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		FeedService::dismiss_announcement( $user_id, $post_id );

		// Bust this user's page-1 home feed so the dismissed announcement is gone
		// on the next load, not after the 30s TTL.
		$this->feed_service()->flush_home_cache( $user_id );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * End a site-wide announcement (admin only) by expiring its pin now, so
	 * active_announcement() stops surfacing it for everyone.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function end_announcement( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to do this.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$post_id = (int) $request->get_param( 'id' );

		if ( ! buddynext_service( 'post_service' )->end_announcement( $post_id ) ) {
			return new WP_Error(
				'not_found',
				__( 'Announcement not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// Ending an announcement affects everyone — bust all page-1 home feeds so it
		// disappears immediately rather than after the 30s TTL.
		$this->feed_service()->flush_all_home_caches();

		return new WP_REST_Response( array( 'ended' => true ), 200 );
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
		$filter   = $request->get_param( 'filter' ) ? sanitize_key( (string) $request->get_param( 'filter' ) ) : 'all';
		if ( ! in_array( $filter, ExploreService::FILTERS, true ) ) {
			$filter = 'all';
		}

		$result = ( new ExploreService( $this->feed_service() ) )->deck( $filter, $cursor, $per_page );
		$cards  = (array) ( $result['items'] ?? array() );
		$html   = $this->render_explore_cards_html( $cards, $user_id );

		return new WP_REST_Response(
			array(
				'html'        => $html,
				'next_cursor' => $result['next_cursor'] ?? null,
				'count'       => count( $cards ),
			),
			200
		);
	}

	/**
	 * Render a list of Explore discovery cards to a single HTML string.
	 *
	 * Mirrors the SSR loop in templates/feed/explore.php so appended pages match
	 * the first paint byte-for-byte: each normalized card payload is delegated to
	 * partials/explore-card.php inside an output buffer.
	 *
	 * @param array<int,array<string,mixed>> $cards  Normalized card payloads from ExploreService.
	 * @param int                            $viewer Viewing user ID (0 for guests).
	 * @return string HTML markup ready to inject into the explore grid container.
	 */
	private function render_explore_cards_html( array $cards, int $viewer ): string {
		if ( empty( $cards ) || ! function_exists( 'buddynext_get_template' ) ) {
			return '';
		}

		ob_start();
		foreach ( $cards as $card ) {
			buddynext_get_template(
				'partials/explore-card.php',
				array(
					'card'            => $card,
					'current_user_id' => $viewer,
				)
			);
		}
		return (string) ob_get_clean();
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
	/**
	 * Render a single feed post card to its canonical server-side HTML.
	 *
	 * Reuses the exact `partials/post-card.php` pipeline the feed and
	 * infinite-scroll use, so a freshly-created post can be prepended into the
	 * feed in place (no reload) and hydrate identically. Returns '' when the
	 * template helper is unavailable.
	 *
	 * @param array  $post    Hydrated post array (PostService::hydrate shape).
	 * @param int    $viewer  Current user ID.
	 * @param string $context Render context (default 'home').
	 * @return string Escape-on-output card markup, or '' if unavailable.
	 */
	public static function render_card_html( array $post, int $viewer, string $context = 'home' ): string {
		if ( empty( $post ) || ! function_exists( 'buddynext_get_template' ) ) {
			return '';
		}

		ob_start();
		buddynext_get_template(
			'partials/post-card.php',
			array(
				'post'            => PostService::attach_poll_options( $post ),
				'current_user_id' => $viewer,
				'context'         => $context,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render a list of feed post cards to their canonical server-side HTML.
	 *
	 * Loops the hydrated items through the same `partials/post-card.php`
	 * pipeline as a single card, so infinite-scroll pages hydrate identically
	 * to the initial render. Returns '' when there is nothing to render or the
	 * template helper is unavailable.
	 *
	 * @param array<int,array<string,mixed>> $items   Hydrated post arrays (PostService::hydrate shape).
	 * @param int                            $viewer  Current user ID.
	 * @param string                         $context Render context (e.g. 'home').
	 * @return string Escape-on-output card markup, or '' if unavailable.
	 */
	private function render_items_html( array $items, int $viewer, string $context ): string {
		if ( empty( $items ) || ! function_exists( 'buddynext_get_template' ) ) {
			return '';
		}

		$this->feed_service()->prime_viewer_state( $items, $viewer );

		ob_start();
		foreach ( $items as $item ) {
			$post = PostService::attach_poll_options( $item );

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
