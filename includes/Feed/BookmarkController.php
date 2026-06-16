<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for bookmarks.
 *
 * Routes (all under buddynext/v1):
 *   POST   /posts/{id}/bookmark — bookmark a post (auth required)
 *   DELETE /posts/{id}/bookmark — remove bookmark (auth required)
 *   GET    /me/bookmarks        — list bookmarked post IDs (auth required)
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Feed\BookmarkService;
use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\BlockService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuddyNext\REST\BaseRestController;

/**
 * Handles bookmark add/remove and bookmark-list reads.
 */
class BookmarkController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)/bookmark',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'bookmark' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unbookmark' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/bookmarks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_bookmarks' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'expand'   => array(
						'type'              => 'string',
						'default'           => '',
						'enum'              => array( '', 'posts' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 50,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * Bookmark a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function bookmark( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();

		if ( ! (bool) get_option( 'buddynext_allow_bookmarks', true ) ) {
			return new WP_REST_Response( array( 'code' => 'bookmarks_disabled' ), 403 );
		}

		( new BookmarkService() )->bookmark( $user_id, $post_id );

		return new WP_REST_Response( array( 'bookmarked' => true ), 200 );
	}

	/**
	 * Remove a bookmark.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unbookmark( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();

		( new BookmarkService() )->unbookmark( $user_id, $post_id );

		return new WP_REST_Response( array( 'bookmarked' => false ), 200 );
	}

	/**
	 * Return the current user's bookmarked post IDs.
	 *
	 * Default response is the legacy IDs-only shape so existing clients
	 * (post-card bookmark toggle) keep working unchanged:
	 *   { ids: int[] }
	 *
	 * When `?expand=posts` is passed, returns paginated hydrated post records
	 * with the same visibility gates as the bookmarks hub template:
	 *   { items: array[], total: int, page: int, per_page: int, has_more: bool }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_bookmarks( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$expand  = (string) ( $request->get_param( 'expand' ) ?? '' );
		$all_ids = ( new BookmarkService() )->user_bookmarks( $user_id );

		if ( 'posts' !== $expand ) {
			return new WP_REST_Response( array( 'ids' => $all_ids ), 200 );
		}

		$per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?? 20 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$page_ids = array_slice( $all_ids, $offset, $per_page + 1 );
		$has_more = count( $page_ids ) > $per_page;
		if ( $has_more ) {
			$page_ids = array_slice( $page_ids, 0, $per_page );
		}

		$items = $this->hydrate_visible_posts( $page_ids, $user_id );

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => count( $all_ids ),
				'page'     => $page,
				'per_page' => $per_page,
				'has_more' => $has_more,
			),
			200
		);
	}

	/**
	 * Hydrate a list of post IDs into visible post records.
	 *
	 * Applies the same visibility gates as the bookmarks template + the
	 * PostController::get_post() endpoint: blocks, secret-space membership,
	 * followers-only privacy, private privacy, suspended author. Deleted or
	 * unpublished posts are filtered silently.
	 *
	 * @param int[] $post_ids Post IDs to hydrate.
	 * @param int   $viewer   Viewing user ID.
	 * @return array<int,array<string,mixed>> Hydrated, visible posts (order preserved).
	 */
	private function hydrate_visible_posts( array $post_ids, int $viewer ): array {
		if ( empty( $post_ids ) ) {
			return array();
		}

		$post_service = new PostService();
		$blocks       = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : new BlockService();
		$follows      = function_exists( 'buddynext_service' ) ? buddynext_service( 'follows' ) : new FollowService();
		$space_svc    = new SpaceService();
		$space_mem    = new SpaceMemberService();

		$visible = array();
		foreach ( $post_ids as $post_id ) {
			$post = $post_service->get( (int) $post_id );
			if ( null === $post ) {
				continue;
			}
			if ( isset( $post['status'] ) && 'published' !== $post['status'] ) {
				continue;
			}

			$author_id = (int) ( $post['user_id'] ?? 0 );
			if ( $author_id <= 0 ) {
				continue;
			}
			if ( $author_id !== $viewer && $blocks->is_blocking_either( $viewer, $author_id ) ) {
				continue;
			}

			$space_id = (int) ( $post['space_id'] ?? 0 );
			if ( $space_id > 0 ) {
				$space = $space_svc->get( $space_id );
				if ( null !== $space && 'secret' === ( $space['type'] ?? '' ) ) {
					$is_member = $space_mem->is_member( $space_id, $viewer );
					if ( ! $is_member && ! user_can( $viewer, 'manage_options' ) ) {
						continue;
					}
				}
			}

			if ( 'followers' === ( $post['privacy'] ?? '' ) && $author_id !== $viewer ) {
				if ( ! $follows->is_following( $viewer, $author_id ) ) {
					continue;
				}
			}
			if ( 'private' === ( $post['privacy'] ?? '' ) && $author_id !== $viewer ) {
				continue;
			}

			if ( $author_id !== $viewer && ! user_can( $viewer, 'manage_options' ) ) {
				// Canonical suspension check (bn_user_suspensions) — not the
				// bn_suspended usermeta, which auto-suspensions don't set.
				$suspended = buddynext_service( 'moderation' )->is_suspended( $author_id );
				$shadow    = (bool) get_user_meta( $author_id, 'bn_shadow_banned', true );
				if ( $suspended || $shadow ) {
					continue;
				}
			}

			$visible[] = $post;
		}

		return $visible;
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
}
