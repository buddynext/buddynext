<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Reaction REST controller.
 *
 * Routes (all under buddynext/v1):
 *   POST /reactions/toggle — toggle a reaction for the current user (auth required)
 *   GET  /reactions        — get reaction count + current user status (public)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Reactions\ReactionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles reaction toggle and count reads over REST.
 */
class ReactionController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/reactions/toggle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle' ),
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
					'emoji'       => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'like',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/reactions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_count' ),
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
				),
			)
		);
	}

	/**
	 * Toggle a reaction for the current user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function toggle( WP_REST_Request $request ): WP_REST_Response {
		$service     = new ReactionService();
		$user_id     = get_current_user_id();
		$object_type = (string) $request->get_param( 'object_type' );
		$object_id   = (int) $request->get_param( 'object_id' );
		$emoji       = (string) $request->get_param( 'emoji' );

		$service->toggle( $user_id, $object_type, $object_id, $emoji );

		$has_reacted = $service->has_reacted( $user_id, $object_type, $object_id );

		return new WP_REST_Response(
			array(
				'has_reacted' => $has_reacted,
				'emoji'       => $has_reacted ? $service->get_user_emoji( $user_id, $object_type, $object_id ) : null,
				'count'       => $service->count( $object_type, $object_id ),
			),
			200
		);
	}

	/**
	 * Return reaction count (and current user state if authenticated).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_count( WP_REST_Request $request ): WP_REST_Response {
		$service     = new ReactionService();
		$object_type = (string) $request->get_param( 'object_type' );
		$object_id   = (int) $request->get_param( 'object_id' );

		$data = array( 'count' => $service->count( $object_type, $object_id ) );

		if ( is_user_logged_in() ) {
			$user_id             = get_current_user_id();
			$data['has_reacted'] = $service->has_reacted( $user_id, $object_type, $object_id );
			$data['emoji']       = $service->get_user_emoji( $user_id, $object_type, $object_id );
		}

		return new WP_REST_Response( $data, 200 );
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
