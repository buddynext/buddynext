<?php
/**
 * REST controller for user profiles.
 *
 * Routes (all under buddynext/v1):
 *   GET  /users/{id}/profile — get a user's profile (public)
 *   PUT  /me/profile         — update own profile (auth required)
 *   GET  /profile-fields     — list all field definitions (public)
 *   POST /profile-fields     — create a field definition (admin only)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles profile reads and writes over REST.
 */
class ProfileController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/profile',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_profile' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/profile',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_profile' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-fields',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_fields' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_field' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'field_key'   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'label'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'type'        => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'text',
							'sanitize_callback' => 'sanitize_key',
						),
						'is_required' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						),
						'visibility'  => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'public',
							'sanitize_callback' => 'sanitize_key',
						),
						'group_name'  => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'general',
							'sanitize_callback' => 'sanitize_key',
						),
						'sort_order'  => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Get a user's profile.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$profile_user_id = (int) $request->get_param( 'id' );
		$viewer_id       = get_current_user_id();
		$service         = buddynext_service( 'profiles' );
		$profile         = $service->get_profile( $profile_user_id, $viewer_id );

		if ( null === $profile ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$profile['completion'] = $service->get_completion_score( $profile_user_id );

		return new WP_REST_Response( $profile, 200 );
	}

	/**
	 * Update the current user's profile.
	 *
	 * All body params are treated as field_key => value pairs and passed to
	 * ProfileService::save_profile(). Unknown keys are silently ignored.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_profile( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$service = buddynext_service( 'profiles' );
		$data    = (array) $request->get_body_params();

		$service->save_profile( $user_id, $data );

		/**
		 * Fire indexing as a pluggable action so Action Scheduler (Phase 6+)
		 * can override the synchronous fallback with an async job.
		 *
		 * @param int $user_id User whose search index entry should be refreshed.
		 */
		do_action( 'buddynext_index_user', $user_id );

		$profile = $service->get_profile( $user_id, $user_id );

		return new WP_REST_Response( $profile, 200 );
	}

	/**
	 * Return all profile field definitions.
	 *
	 * @return WP_REST_Response
	 */
	public function list_fields(): WP_REST_Response {
		$fields = buddynext_service( 'profiles' )->get_fields();

		return new WP_REST_Response( array( 'fields' => $fields ), 200 );
	}

	/**
	 * Create a new profile field definition.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function create_field( WP_REST_Request $request ): WP_REST_Response {
		$field_id = buddynext_service( 'profiles' )->create_field( $request->get_params() );

		return new WP_REST_Response( array( 'id' => $field_id ), 201 );
	}

	/**
	 * Permission callback: require manage_options capability.
	 *
	 * @return true|WP_Error
	 */
	public function require_admin(): true|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Admins only.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
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
