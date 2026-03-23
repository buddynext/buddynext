<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for user profiles.
 *
 * Routes (all under buddynext/v1):
 *   GET    /users/{id}/profile              — get a user's profile (public)
 *   PUT    /users/{id}/profile              — update any user's profile (admin only)
 *   POST   /users/{id}/avatar               — set avatar for any user (admin only)
 *   POST   /users/{id}/cover                — upload cover for any user (admin only)
 *   DELETE /users/{id}/cover                — remove cover for any user (admin only)
 *   PUT    /me/profile                      — update own profile (auth required)
 *   POST   /me/avatar                       — upload own avatar (auth required)
 *   DELETE /me/avatar                       — remove own avatar (auth required)
 *   POST   /me/cover                        — upload own cover photo (auth required)
 *   DELETE /me/cover                        — remove own cover photo (auth required)
 *   GET    /me/profile-slug                 — get own profile slug + URL (auth required)
 *   PUT    /me/profile-slug                 — set own profile slug (auth required)
 *   GET    /profile-slug/check              — check if a slug is available (auth required)
 *   GET    /profile-fields                  — list all field definitions grouped by group (public)
 *   POST   /profile-fields                  — create a field definition (admin only)
 *   PUT    /profile-fields/{id}             — update a field definition (admin only)
 *   DELETE /profile-fields/{id}             — delete a field definition (admin only)
 *   POST   /profile-fields/{id}/reorder     — reorder a field (admin only)
 *   GET    /profile-groups                  — list all groups (public)
 *   POST   /profile-groups                  — create a group (admin only)
 *   PUT    /profile-groups/{id}             — update a group (admin only)
 *   DELETE /profile-groups/{id}             — delete a group (admin only)
 *   POST   /profile-groups/{id}/reorder     — reorder a group (admin only)
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

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
			'/users/(?P<id>[\d]+)/profile',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'admin_update_profile' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/avatar',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'admin_upload_avatar' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/cover',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'admin_upload_cover' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'admin_delete_cover' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/avatar',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_avatar' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_avatar' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/cover',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_cover' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_cover' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/profile',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_own_profile' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_profile' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/profile-slug',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_profile_slug' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_profile_slug' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'slug' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_title',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-slug/check',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'check_slug_availability' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
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
						'group_id'    => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
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

		register_rest_route(
			'buddynext/v1',
			'/profile-groups',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_groups' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_group' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'group_key'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'label'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'type'       => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'flat',
							'sanitize_callback' => 'sanitize_key',
						),
						'visibility' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'public',
							'sanitize_callback' => 'sanitize_key',
						),
						'sort_order' => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-groups/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_group' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'label'      => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'visibility' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'sort_order' => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_group' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-groups/(?P<id>[\d]+)/reorder',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reorder_group' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'        => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'direction' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'up', 'down' ),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-fields/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_field' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id'          => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'label'       => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'type'        => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'options'     => array(
							'required' => false,
							'type'     => 'string',
						),
						'is_required' => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'visibility'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'sort_order'  => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_field' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/profile-fields/(?P<id>[\d]+)/reorder',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reorder_field' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'        => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'direction' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'up', 'down' ),
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
	 * GET /me/profile — return the authenticated user's own full profile.
	 *
	 * Returns all field groups regardless of visibility (owner view), plus
	 * completion score and social graph counts. Equivalent to calling
	 * GET /users/{id}/profile as the profile owner.
	 *
	 * @return WP_REST_Response
	 */
	public function get_own_profile(): WP_REST_Response {
		$user_id = get_current_user_id();
		$service = buddynext_service( 'profiles' );
		$profile = $service->get_profile( $user_id, $user_id );

		if ( null === $profile ) {
			// Should not happen for an authenticated user, but guard defensively.
			$profile = array(
				'user_id' => $user_id,
				'groups'  => array(),
				'fields'  => array(),
			);
		}

		$profile['completion'] = $service->get_completion_score( $user_id );

		return new WP_REST_Response( $profile, 200 );
	}

	/**
	 * Update the current user's profile.
	 *
	 * Body params are treated as field_key => value pairs for flat fields, or
	 * group_key => [ [field_key => value, ...], ... ] for repeater groups.
	 * Unknown keys are silently ignored.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_profile( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$service = buddynext_service( 'profiles' );
		$json    = $request->get_json_params();
		$data    = is_array( $json ) && ! empty( $json ) ? $json : (array) $request->get_body_params();

		// Handle display_name — WP core field, not a profile value row.
		if ( isset( $data['display_name'] ) ) {
			$display_name = sanitize_text_field( (string) $data['display_name'] );
			if ( '' !== $display_name ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $display_name,
					)
				);
			}
			unset( $data['display_name'] );
		}

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
	 * Update any user's profile (admin only).
	 *
	 * Body params: display_name + any field_key => value pairs (same format as PUT /me/profile).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_update_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$service = buddynext_service( 'profiles' );
		$json    = $request->get_json_params();
		$data    = is_array( $json ) && ! empty( $json ) ? $json : (array) $request->get_body_params();

		// Handle display_name separately — it's a WP core field, not a profile value.
		if ( isset( $data['display_name'] ) ) {
			$display_name = sanitize_text_field( (string) $data['display_name'] );
			if ( '' !== $display_name ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $display_name,
					)
				);
			}
			unset( $data['display_name'] );
		}

		$service->save_profile( $user_id, $data );
		do_action( 'buddynext_index_user', $user_id );
		$profile = $service->get_profile( $user_id, $user_id );

		return new WP_REST_Response( $profile, 200 );
	}

	/**
	 * Return the current user's profile slug and canonical URL.
	 *
	 * @return WP_REST_Response
	 */
	public function get_profile_slug(): WP_REST_Response {
		$user_id = get_current_user_id();
		$slug    = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		$url     = \BuddyNext\Core\PageRouter::profile_url( $user_id );

		return new WP_REST_Response(
			array(
				'slug' => '' !== $slug ? $slug : null,
				'url'  => $url,
			),
			200
		);
	}

	/**
	 * Set the current user's custom profile slug.
	 *
	 * Slug must be unique and not match the reserved user-{id} pattern.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile_slug( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id        = get_current_user_id();
		$requested_slug = sanitize_title( (string) $request->get_param( 'slug' ) );

		if ( '' === $requested_slug ) {
			return new WP_Error(
				'invalid_slug',
				__( 'Slug cannot be empty.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		if ( ! \BuddyNext\Core\PageRouter::is_slug_available( $requested_slug, $user_id ) ) {
			return new WP_Error(
				'slug_taken',
				__( 'That profile URL is already taken.', 'buddynext' ),
				array( 'status' => 409 )
			);
		}

		update_user_meta( $user_id, 'bn_profile_slug', $requested_slug );

		return new WP_REST_Response(
			array(
				'slug' => $requested_slug,
				'url'  => \BuddyNext\Core\PageRouter::profile_url( $user_id ),
			),
			200
		);
	}

	/**
	 * Check whether a profile slug is available for the current user to claim.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function check_slug_availability( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$slug      = sanitize_title( (string) $request->get_param( 'slug' ) );
		$available = '' !== $slug && \BuddyNext\Core\PageRouter::is_slug_available( $slug, $user_id );

		return new WP_REST_Response(
			array(
				'slug'      => $slug,
				'available' => $available,
			),
			200
		);
	}

	/**
	 * Return all profile field definitions grouped by their parent group.
	 *
	 * Response shape: { "groups": [ { "id", "group_key", "label", "type", "fields": [...] } ] }
	 *
	 * @return WP_REST_Response
	 */
	public function list_fields(): WP_REST_Response {
		$groups = buddynext_service( 'profiles' )->get_fields();

		return new WP_REST_Response( array( 'groups' => $groups ), 200 );
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
	 * Return all profile group definitions (metadata only, no fields).
	 *
	 * @return WP_REST_Response
	 */
	public function list_groups(): WP_REST_Response {
		$groups = buddynext_service( 'profiles' )->get_groups();

		return new WP_REST_Response( array( 'groups' => $groups ), 200 );
	}

	/**
	 * Create a new profile group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function create_group( WP_REST_Request $request ): WP_REST_Response {
		$group_id = buddynext_service( 'profiles' )->create_group( $request->get_params() );

		return new WP_REST_Response( array( 'id' => $group_id ), 201 );
	}

	/**
	 * Update an existing profile group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_group( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$data = array();

		$label = $request->get_param( 'label' );
		if ( null !== $label ) {
			$data['label'] = sanitize_text_field( (string) $label );
		}

		$visibility = $request->get_param( 'visibility' );
		if ( null !== $visibility ) {
			$data['visibility'] = sanitize_key( (string) $visibility );
		}

		$sort_order = $request->get_param( 'sort_order' );
		if ( null !== $sort_order ) {
			$data['sort_order'] = absint( $sort_order );
		}

		buddynext_service( 'profiles' )->update_group( $id, $data );

		return new WP_REST_Response( array( 'updated' => true ), 200 );
	}

	/**
	 * Delete a profile group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function delete_group( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		buddynext_service( 'profiles' )->delete_group( $id );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Reorder a profile group by moving it up or down.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function reorder_group( WP_REST_Request $request ): WP_REST_Response {
		$id        = (int) $request->get_param( 'id' );
		$direction = sanitize_key( (string) $request->get_param( 'direction' ) );

		buddynext_service( 'profiles' )->reorder_group( $id, $direction );

		return new WP_REST_Response( array( 'reordered' => true ), 200 );
	}

	/**
	 * Update an existing profile field definition.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_field( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$data = array();

		$label = $request->get_param( 'label' );
		if ( null !== $label ) {
			$data['label'] = sanitize_text_field( (string) $label );
		}

		$type = $request->get_param( 'type' );
		if ( null !== $type ) {
			$data['type'] = sanitize_key( (string) $type );
		}

		$options_raw = $request->get_param( 'options' );
		if ( null !== $options_raw ) {
			$data['options'] = array_values(
				array_filter(
					array_map( 'trim', explode( "\n", (string) $options_raw ) )
				)
			);
		}

		$is_required = $request->get_param( 'is_required' );
		if ( null !== $is_required ) {
			$data['is_required'] = (bool) $is_required;
		}

		$visibility = $request->get_param( 'visibility' );
		if ( null !== $visibility ) {
			$data['visibility'] = sanitize_key( (string) $visibility );
		}

		$sort_order = $request->get_param( 'sort_order' );
		if ( null !== $sort_order ) {
			$data['sort_order'] = absint( $sort_order );
		}

		buddynext_service( 'profiles' )->update_field( $id, $data );

		return new WP_REST_Response( array( 'updated' => true ), 200 );
	}

	/**
	 * Delete a profile field definition.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function delete_field( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		buddynext_service( 'profiles' )->delete_field( $id );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Reorder a profile field by moving it up or down within its group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function reorder_field( WP_REST_Request $request ): WP_REST_Response {
		$id        = (int) $request->get_param( 'id' );
		$direction = sanitize_key( (string) $request->get_param( 'direction' ) );

		buddynext_service( 'profiles' )->reorder_field( $id, $direction );

		return new WP_REST_Response( array( 'reordered' => true ), 200 );
	}

	/**
	 * Upload an avatar for the current user.
	 *
	 * Expects a multipart/form-data POST with a single file field named "avatar".
	 * Max size 2 MB. Allowed MIME types: JPEG, PNG, GIF, WebP.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_avatar(): WP_REST_Response|WP_Error {
		return $this->handle_avatar_upload( get_current_user_id() );
	}

	/**
	 * Remove the current user's custom avatar.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_avatar(): WP_REST_Response {
		buddynext_service( 'profiles' )->delete_avatar( get_current_user_id() );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Upload an avatar on behalf of any user (admin only).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_upload_avatar( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		return $this->handle_avatar_upload( $user_id );
	}

	/**
	 * POST /users/{id}/cover — upload cover photo for any user (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_upload_cover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = absint( $request->get_param( 'id' ) );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		return $this->handle_cover_upload( $user_id );
	}

	/**
	 * DELETE /users/{id}/cover — remove cover photo for any user (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete_cover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = absint( $request->get_param( 'id' ) );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		delete_user_meta( $user_id, 'buddynext_cover_url' );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Upload a cover photo for the current user.
	 *
	 * Expects a multipart/form-data POST with a single file field named "avatar".
	 * Max size 5 MB. Allowed MIME types: JPEG, PNG, WebP.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_cover(): WP_REST_Response|WP_Error {
		return $this->handle_cover_upload( get_current_user_id() );
	}

	/**
	 * Remove the current user's cover photo.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_cover(): WP_REST_Response {
		$user_id = get_current_user_id();
		delete_user_meta( $user_id, 'buddynext_cover_url' );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Shared cover upload logic.
	 *
	 * Validates the uploaded file, moves it to the uploads directory via the
	 * WordPress upload handler, and stores the resulting URL in usermeta.
	 *
	 * @param int $user_id Target user ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_cover_upload( int $user_id ): WP_REST_Response|WP_Error {
		/*
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		 */
		$cover_file = isset( $_FILES['avatar'] ) && is_array( $_FILES['avatar'] )
			? $_FILES['avatar']
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( empty( $cover_file ) || UPLOAD_ERR_OK !== (int) ( $cover_file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error(
				'cover_missing',
				__( 'No file uploaded or upload error.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		if ( (int) ( $cover_file['size'] ?? 0 ) > 5 * 1024 * 1024 ) {
			return new WP_Error(
				'cover_too_large',
				__( 'File must be under 5MB.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		$file_data = array(
			'name'     => sanitize_file_name( (string) ( $cover_file['name'] ?? '' ) ),
			'type'     => (string) ( $cover_file['type'] ?? '' ),
			'tmp_name' => (string) ( $cover_file['tmp_name'] ?? '' ),
			'error'    => (int) ( $cover_file['error'] ?? UPLOAD_ERR_NO_FILE ),
			'size'     => (int) ( $cover_file['size'] ?? 0 ),
		);

		$check = wp_check_filetype_and_ext(
			$file_data['tmp_name'],
			$file_data['name']
		);

		$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );

		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return new WP_Error(
				'cover_invalid_type',
				__( 'Only JPEG, PNG, or WebP images are accepted.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$result = wp_handle_upload( $file_data, array( 'test_form' => false ) );

		if ( isset( $result['error'] ) ) {
			return new WP_Error(
				'cover_upload_failed',
				$result['error'],
				array( 'status' => 500 )
			);
		}

		update_user_meta( $user_id, 'buddynext_cover_url', esc_url_raw( $result['url'] ) );

		return new WP_REST_Response( array( 'cover_url' => $result['url'] ), 200 );
	}

	/**
	 * Shared avatar upload logic.
	 *
	 * Validates the uploaded file, moves it to the uploads directory via the
	 * WordPress upload handler, and stores the resulting URL in usermeta.
	 *
	 * @param int $user_id Target user ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_avatar_upload( int $user_id ): WP_REST_Response|WP_Error {
		/*
		 * The WP REST API verifies the X-WP-Nonce header before this callback
		 * fires; nonce handling is therefore already done. WPCS cannot see the
		 * REST authentication layer, so we suppress its nonce and index checks
		 * only for the $_FILES reads below.
		 *
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		 */
		$avatar_file = isset( $_FILES['avatar'] ) && is_array( $_FILES['avatar'] )
			? $_FILES['avatar']
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( empty( $avatar_file ) || UPLOAD_ERR_OK !== (int) ( $avatar_file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error(
				'avatar_missing',
				__( 'No file uploaded or upload error.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		if ( (int) ( $avatar_file['size'] ?? 0 ) > 2 * 1024 * 1024 ) {
			return new WP_Error(
				'avatar_too_large',
				__( 'File must be under 2MB.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		$file_data = array(
			'name'     => sanitize_file_name( (string) ( $avatar_file['name'] ?? '' ) ),
			'type'     => (string) ( $avatar_file['type'] ?? '' ),
			'tmp_name' => (string) ( $avatar_file['tmp_name'] ?? '' ),
			'error'    => (int) ( $avatar_file['error'] ?? UPLOAD_ERR_NO_FILE ),
			'size'     => (int) ( $avatar_file['size'] ?? 0 ),
		);

		$check = wp_check_filetype_and_ext(
			$file_data['tmp_name'],
			$file_data['name']
		);

		$allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return new WP_Error(
				'avatar_invalid_type',
				__( 'Only JPEG, PNG, GIF, or WebP images are accepted.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$result = wp_handle_upload( $file_data, array( 'test_form' => false ) );

		if ( isset( $result['error'] ) ) {
			return new WP_Error(
				'avatar_upload_failed',
				$result['error'],
				array( 'status' => 500 )
			);
		}

		buddynext_service( 'profiles' )->update_avatar( $user_id, esc_url_raw( $result['url'] ) );

		return new WP_REST_Response( array( 'avatar_url' => $result['url'] ), 200 );
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
