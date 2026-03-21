<?php
/**
 * Comment REST controller.
 *
 * Routes (all under buddynext/v1):
 *   POST   /comments                — create a comment (auth required)
 *   GET    /comments                — list comments for an object (public)
 *   PUT    /comments/{id}           — update a comment (owner or admin)
 *   DELETE /comments/{id}           — delete a comment (owner or admin)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Comments\CommentService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles comment CRUD and listing over REST.
 */
class CommentController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/comments',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'object_type' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'object_id'   => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
						'content'     => array(
							'required' => true,
							'type'     => 'string',
						),
						'parent_id'   => array(
							'required' => false,
							'type'     => 'integer',
							'minimum'  => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_comments' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'object_type' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'object_id'   => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
						'per_page'    => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						),
						'page'        => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/comments/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id'      => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
						'content' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id' => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * Create a comment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service     = new CommentService();
		$user_id     = get_current_user_id();
		$object_type = (string) ( $request->get_param( 'object_type' ) ?? '' );
		$object_id   = (int) $request->get_param( 'object_id' );
		$content     = wp_kses_post( (string) ( $request->get_param( 'content' ) ?? '' ) );
		$parent_id   = $request->get_param( 'parent_id' ) !== null ? (int) $request->get_param( 'parent_id' ) : null;

		$result = $service->create( $user_id, $object_type, $object_id, $content, $parent_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( $service->get( $result ), 201 );
	}

	/**
	 * List top-level comments for an object.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_comments( WP_REST_Request $request ): WP_REST_Response {
		$service     = new CommentService();
		$object_type = (string) ( $request->get_param( 'object_type' ) ?? '' );
		$object_id   = (int) $request->get_param( 'object_id' );
		$per_page    = (int) ( $request->get_param( 'per_page' ) ?? 20 );
		$page        = (int) ( $request->get_param( 'page' ) ?? 1 );

		return new WP_REST_Response( $service->list_for_object( $object_type, $object_id, $per_page, $page ), 200 );
	}

	/**
	 * Update a comment's content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service    = new CommentService();
		$comment_id = (int) $request->get_param( 'id' );
		$user_id    = get_current_user_id();
		$content    = wp_kses_post( (string) ( $request->get_param( 'content' ) ?? '' ) );

		$result = $service->update( $comment_id, $user_id, $content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $service->get( $comment_id ), 200 );
	}

	/**
	 * Delete a comment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service    = new CommentService();
		$comment_id = (int) $request->get_param( 'id' );
		$user_id    = get_current_user_id();

		$result = $service->delete( $comment_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Require the user to be logged in.
	 *
	 * @return bool|WP_Error
	 */
	public function require_auth(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}
		return true;
	}
}
