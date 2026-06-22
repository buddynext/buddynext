<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for post shares (reposts).
 *
 * Routes (all under buddynext/v1):
 *   POST   /posts/{id}/share — share a post (auth required)
 *   DELETE /posts/{id}/share — unshare a post (auth required)
 *   GET    /me/shares        — share history for the current user (auth required)
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Feed\ShareService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuddyNext\REST\BaseRestController;

/**
 * Handles post share and unshare.
 */
class ShareController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)/share',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'share' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unshare' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/shares',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'my_shares' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Share a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function share( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$content = sanitize_textarea_field( (string) ( $request->get_param( 'content' ) ?? '' ) );

		$result = ( new ShareService() )->share( $user_id, $post_id, $content );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		// Return the hydrated repost + its server-rendered card so the client can
		// prepend it in place (no reload), mirroring the composer create flow.
		$service  = function_exists( 'buddynext_service' ) ? buddynext_service( 'post_service' ) : new PostService();
		$post     = $service->get( $result );
		$response = array(
			'shared'  => true,
			'post_id' => $result,
		);
		if ( is_array( $post ) ) {
			$response['post'] = $post;
			$response['html'] = FeedController::render_card_html( $post, $user_id, 'home' );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Remove a share.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unshare( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();

		( new ShareService() )->unshare( $user_id, $post_id );

		return new WP_REST_Response( array( 'shared' => false ), 200 );
	}

	/**
	 * Return the share history for the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function my_shares( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$per_page = absint( $request->get_param( 'per_page' ) );
		$page     = absint( $request->get_param( 'page' ) );

		$result = ( new ShareService() )->user_shares_paginated( $user_id, $per_page, $page );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
}
