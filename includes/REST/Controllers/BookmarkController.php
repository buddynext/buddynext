<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for bookmarks.
 *
 * Routes (all under buddynext/v1):
 *   POST   /posts/{id}/bookmark — bookmark a post (auth required)
 *   DELETE /posts/{id}/bookmark — remove bookmark (auth required)
 *   GET    /me/bookmarks        — list bookmarked post IDs (auth required)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Feed\BookmarkService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles bookmark add/remove and bookmark-list reads.
 */
class BookmarkController {

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
	 * @return WP_REST_Response
	 */
	public function get_bookmarks(): WP_REST_Response {
		$user_id   = get_current_user_id();
		$bookmarks = ( new BookmarkService() )->user_bookmarks( $user_id );

		return new WP_REST_Response( array( 'ids' => $bookmarks ), 200 );
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
}
