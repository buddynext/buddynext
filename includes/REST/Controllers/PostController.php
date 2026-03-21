<?php
/**
 * REST controller for post CRUD.
 *
 * Routes (all under buddynext/v1):
 *   POST   /posts           — create a post
 *   GET    /posts/{id}      — get a post
 *   PUT    /posts/{id}      — update a post (owner only)
 *   DELETE /posts/{id}      — delete a post (owner only)
 *   POST   /posts/{id}/pin  — pin a post (owner only)
 *   DELETE /posts/{id}/pin  — unpin a post (owner only)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Feed\PostService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles post lifecycle over REST.
 */
class PostController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_post' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_post' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_post' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)/pin',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'pin_post' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unpin_post' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);
	}

	/**
	 * Create a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();

		$data = array(
			'type'         => sanitize_key( $request->get_param( 'type' ) ?? 'text' ),
			'content'      => wp_kses_post( (string) ( $request->get_param( 'content' ) ?? '' ) ),
			'privacy'      => sanitize_key( $request->get_param( 'privacy' ) ?? 'public' ),
			'space_id'     => $request->get_param( 'space_id' ) ? (int) $request->get_param( 'space_id' ) : null,
			'media_ids'    => $request->get_param( 'media_ids' ),
			'link_url'     => $this->parse_link_url( $request ),
			'link_meta'    => $request->get_param( 'link_meta' ),
			'options'      => $request->get_param( 'options' ),
			'scheduled_at' => $request->get_param( 'scheduled_at' ),
		);

		$result = ( new PostService() )->create( $user_id, $data );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		$post = ( new PostService() )->get( $result );

		return new WP_REST_Response( $post, 201 );
	}

	/**
	 * Get a single post.
	 *
	 * Private posts are only visible to their author.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$post    = ( new PostService() )->get( $post_id );

		if ( null === $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$viewer_id = get_current_user_id();

		if ( 'private' === $post['privacy'] && $post['user_id'] !== $viewer_id ) {
			return new WP_Error(
				'post_forbidden',
				__( 'You do not have permission to view this post.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return new WP_REST_Response( $post, 200 );
	}

	/**
	 * Update a post's content or privacy.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$service = new PostService();

		$data = array();
		if ( null !== $request->get_param( 'content' ) ) {
			$data['content'] = wp_kses_post( (string) $request->get_param( 'content' ) );
		}
		if ( null !== $request->get_param( 'privacy' ) ) {
			$data['privacy'] = sanitize_key( $request->get_param( 'privacy' ) );
		}

		$result = $service->update( $post_id, $user_id, $data );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( $service->get( $post_id ), 200 );
	}

	/**
	 * Delete a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$result  = ( new PostService() )->delete( $post_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Pin a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pin_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$result  = ( new PostService() )->pin( $post_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'pinned' => true ), 200 );
	}

	/**
	 * Unpin a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unpin_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$result  = ( new PostService() )->unpin( $post_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'pinned' => false ), 200 );
	}

	/**
	 * Sanitize and return the link_url from the request, or null if empty.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string|null
	 */
	private function parse_link_url( WP_REST_Request $request ): ?string {
		$raw = esc_url_raw( (string) ( $request->get_param( 'link_url' ) ?? '' ) );
		return '' !== $raw ? $raw : null;
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
