<?php
/**
 * REST controller for post shares (reposts).
 *
 * Routes (all under buddynext/v1):
 *   POST   /posts/{id}/share — share a post (auth required)
 *   DELETE /posts/{id}/share — unshare a post (auth required)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Feed\ShareService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles post share and unshare.
 */
class ShareController {

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

		return new WP_REST_Response(
			array(
				'shared'   => true,
				'share_id' => $result,
			),
			200
		);
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
