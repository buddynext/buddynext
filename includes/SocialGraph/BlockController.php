<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for block and mute relationships.
 *
 * Routes (all under buddynext/v1):
 *   POST   /users/{id}/block — block a user
 *   DELETE /users/{id}/block — unblock a user
 *   POST   /users/{id}/mute  — mute a user
 *   DELETE /users/{id}/mute  — unmute a user
 *   GET    /me/blocked       — list blocked users
 *   GET    /me/muted         — list muted users
 *
 * @package BuddyNext\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\SocialGraph;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuddyNext\REST\BaseRestController;

/**
 * Handles block/mute operations and blocked-users reads.
 */
class BlockController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/block',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'block' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unblock' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/mute',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'mute' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unmute' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/restrict',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'restrict' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unrestrict' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/blocked',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_blocked' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/muted',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_muted' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/restricted',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_restricted' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Block a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function block( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		$result     = buddynext_service( 'blocks' )->block( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'blocked' => true ), 200 );
	}

	/**
	 * Unblock a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unblock( WP_REST_Request $request ): WP_REST_Response {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		buddynext_service( 'blocks' )->unblock( $current_id, $target_id );

		return new WP_REST_Response( array( 'blocked' => false ), 200 );
	}

	/**
	 * Mute a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mute( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		$result     = buddynext_service( 'blocks' )->mute( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'muted' => true ), 200 );
	}

	/**
	 * Unmute a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unmute( WP_REST_Request $request ): WP_REST_Response {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		buddynext_service( 'blocks' )->unmute( $current_id, $target_id );

		return new WP_REST_Response( array( 'muted' => false ), 200 );
	}

	/**
	 * Return the list of users blocked by the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function get_blocked(): WP_REST_Response {
		$current_id = get_current_user_id();
		$blocked    = buddynext_service( 'blocks' )->blocked_users( $current_id );

		return new WP_REST_Response( array( 'ids' => $blocked ), 200 );
	}

	/**
	 * Return the list of users muted by the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function get_muted(): WP_REST_Response {
		$current_id = get_current_user_id();
		$muted      = buddynext_service( 'blocks' )->muted_users( $current_id );

		return new WP_REST_Response( array( 'ids' => $muted ), 200 );
	}

	/**
	 * Restrict a user (Instagram-style soft block).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restrict( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		$result     = buddynext_service( 'blocks' )->restrict( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'restricted' => true ), 200 );
	}

	/**
	 * Unrestrict a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unrestrict( WP_REST_Request $request ): WP_REST_Response {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		buddynext_service( 'blocks' )->unrestrict( $current_id, $target_id );

		return new WP_REST_Response( array( 'restricted' => false ), 200 );
	}

	/**
	 * Return the list of users restricted by the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function get_restricted(): WP_REST_Response {
		$current_id = get_current_user_id();
		$restricted = buddynext_service( 'blocks' )->restricted_users( $current_id );

		return new WP_REST_Response( array( 'ids' => $restricted ), 200 );
	}
}
