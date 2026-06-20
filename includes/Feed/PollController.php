<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for poll voting.
 *
 * Routes (all under buddynext/v1):
 *   POST /posts/{id}/vote    — cast a vote (auth required)
 *   GET  /posts/{id}/poll    — get poll options and counts (public)
 *   GET  /posts/{id}/my-vote — get the current user's vote on a poll (auth required)
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Feed\PollService;
use BuddyNext\Feed\PostService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuddyNext\REST\BaseRestController;

/**
 * Handles poll voting and result reads.
 */
class PollController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)/vote',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'vote' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)/poll',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_results' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)/my-vote',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'my_vote' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Cast a vote on a poll.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function vote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id   = (int) $request->get_param( 'id' );
		$option_id = (int) $request->get_param( 'option_id' );
		$user_id   = get_current_user_id();

		if ( ! $option_id ) {
			return new WP_Error(
				'missing_option_id',
				__( 'option_id is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$result = ( new PollService() )->vote( $user_id, $post_id, $option_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		$results = ( new PollService() )->results( $post_id );

		return new WP_REST_Response(
			array(
				'voted'   => true,
				'results' => $results,
			),
			200
		);
	}

	/**
	 * Return poll results (options with vote counts).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_results( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );

		// Privacy gate: poll counts disclose engagement on the post. When the post
		// is not viewable by the current user, return empty results rather than the
		// tallies. Gated directly by the route's post id (a poll always belongs to
		// its post). Shares the single PostService visibility gate.
		if ( $this->is_post_hidden_from_viewer( $post_id ) ) {
			return new WP_REST_Response( array( 'results' => array() ), 200 );
		}

		$results = ( new PollService() )->results( $post_id );

		return new WP_REST_Response( array( 'results' => $results ), 200 );
	}

	/**
	 * Whether a poll's post is hidden from the current viewer.
	 *
	 * Applies the single shared visibility gate (PostService::visibility_error())
	 * directly to the route's post id. Degrades to "visible" when the service
	 * container is unavailable.
	 *
	 * @param int $post_id Poll post ID from the route.
	 * @return bool True when the post is not viewable by the current user.
	 */
	private function is_post_hidden_from_viewer( int $post_id ): bool {
		if ( $post_id <= 0 || ! function_exists( 'buddynext_service' ) ) {
			return false;
		}

		$posts = buddynext_service( 'post_service' );
		if ( ! $posts instanceof PostService ) {
			return false;
		}

		return $posts->visibility_error( $post_id, get_current_user_id() ) instanceof WP_Error;
	}

	/**
	 * Return the current user's vote on a poll post.
	 *
	 * Returns {option_id: int} when the user has voted, or {option_id: null}
	 * when they have not.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function my_vote( WP_REST_Request $request ): WP_REST_Response {
		$post_id   = (int) $request->get_param( 'id' );
		$user_id   = get_current_user_id();
		$option_id = ( new PollService() )->user_vote( $user_id, $post_id );

		return new WP_REST_Response( array( 'option_id' => $option_id ), 200 );
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
}
