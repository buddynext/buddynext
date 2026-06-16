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

use BuddyNext\REST\BaseRestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles profile reads and writes over REST.
 */
class ProfileController extends BaseRestController {

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

		// DELETE merges onto the same path as the POST above.
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/avatar',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'admin_delete_avatar' ),
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

		// Member self-service privacy: export my data / delete my account.
		register_rest_route(
			'buddynext/v1',
			'/me/data-export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_my_data' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/account',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_my_account' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * GET /me/data-export — return the current member's own data for download.
	 *
	 * Gated by the Privacy → "Allow data export" setting. Reuses the existing
	 * PrivacyTools exporter (the same data WordPress's personal-data export uses),
	 * walked to completion across its pages.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_my_data(): WP_REST_Response|WP_Error {
		if ( ! (bool) get_option( 'buddynext_allow_data_export', true ) ) {
			return new WP_Error(
				'export_disabled',
				__( 'Data export is not available on this community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$user    = wp_get_current_user();
		$privacy = new \BuddyNext\Privacy\PrivacyTools();
		$items   = array();
		$page    = 1;
		do {
			$result = $privacy->export( $user->user_email, $page );
			if ( ! empty( $result['data'] ) && is_array( $result['data'] ) ) {
				$items = array_merge( $items, $result['data'] );
			}
			$done = ! isset( $result['done'] ) || (bool) $result['done'];
			++$page;
		} while ( ! $done && $page < 100 );

		return new WP_REST_Response(
			array(
				'generated_at' => current_time( 'mysql', true ),
				'user'         => array(
					'id'           => (int) $user->ID,
					'username'     => $user->user_login,
					'email'        => $user->user_email,
					'display_name' => $user->display_name,
					'registered'   => $user->user_registered,
				),
				'items'        => $items,
			),
			200
		);
	}

	/**
	 * DELETE /me/account — let a member delete their own account.
	 *
	 * Gated by the Privacy → "Allow account deletion" setting. Scrubs the
	 * member's BuddyNext data via the existing privacy eraser, then removes the
	 * WP account. The "Anonymize on delete" setting controls whether any
	 * remaining WP-authored content is reassigned to a neutral author (kept,
	 * de-identified) or deleted with the account. Administrators cannot self-delete.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_my_account(): WP_REST_Response|WP_Error {
		if ( ! (bool) get_option( 'buddynext_allow_account_deletion', true ) ) {
			return new WP_Error(
				'deletion_disabled',
				__( 'Account deletion is not available on this community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'no_user', __( 'No account found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// Never let an administrator self-delete through this member-facing route.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'admin_cannot_self_delete',
				__( 'Administrators cannot delete their own account here.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$anonymize = (bool) get_option( 'buddynext_anonymize_on_delete', true );

		// Erase the member's BuddyNext data (follows, connections, blocks, prefs,
		// posts, comments) via the existing eraser, paginated to completion.
		$privacy = new \BuddyNext\Privacy\PrivacyTools();
		$page    = 1;
		do {
			$result = $privacy->erase( $user->user_email, $page );
			$done   = ! isset( $result['done'] ) || (bool) $result['done'];
			++$page;
		} while ( ! $done && $page < 100 );

		// Remove the WP account. When anonymising, reassign any remaining authored
		// content to the site's first administrator instead of deleting it.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$reassign = null;
		if ( $anonymize ) {
			$admins   = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
			$reassign = ! empty( $admins ) ? (int) $admins[0]->ID : null;
		}
		wp_delete_user( $user_id, $reassign );

		// The session is now invalid; tell the client to send the user home.
		return new WP_REST_Response(
			array(
				'deleted'     => true,
				'redirect_to' => home_url( '/' ),
			),
			200
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

		/**
		 * Fires after a user's profile is loaded and the response is built.
		 *
		 * Only fires when the viewer is different from the profile owner (self-views
		 * are not counted). Use: Pro analytics reach tracking and profile view events.
		 *
		 * @since 1.0.0
		 *
		 * @param int $profile_user_id User ID of the profile being viewed.
		 * @param int $viewer_id       User ID of the person viewing the profile (0 = anonymous).
		 */
		if ( $viewer_id !== $profile_user_id ) {
			do_action( 'buddynext_profile_viewed', $profile_user_id, $viewer_id );
		}

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

		// Validate input before any persistence. Field-level errors are
		// returned as a 422 payload the JS store can map to inline errors.
		$errors = $this->validate_profile_payload( $data );
		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'saved'  => false,
					'errors' => $errors,
				),
				422
			);
		}

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

		// Handle privacy + notification preference keys — stored as usermeta,
		// not profile-field rows. Audience enums are constrained to the
		// canonical four values; boolean toggles are coerced.
		$audience_keys = array( 'bn_privacy_see_email', 'bn_privacy_dm', 'bn_privacy_mention' );
		// Profile-view / follow / connect gates. Each meta key accepts only the
		// vocabulary its PrivacyService gate honours (can_view_profile /
		// can_follow / can_connect); the validator above rejects anything else.
		$gate_keys     = array(
			'bn_privacy_profile_visibility' => array( 'public', 'followers', 'connections', 'private' ),
			'bn_privacy_who_can_follow'     => array( 'everyone', 'nobody' ),
			'bn_privacy_who_can_connect'    => array( 'everyone', 'followers', 'nobody' ),
		);
		$bool_keys     = array(
			'bn_account_private',
			'bn_privacy_show_in_directory',
			'bn_privacy_search_indexable',
			'bn_pro_hide_profile_views',
			'bn_pref_email_replies',
			'bn_pref_email_mentions',
			'bn_pref_email_follows',
			'bn_pref_email_digest',
		);
		foreach ( $audience_keys as $aud_key ) {
			if ( array_key_exists( $aud_key, $data ) ) {
				update_user_meta( $user_id, $aud_key, sanitize_key( (string) $data[ $aud_key ] ) );
				unset( $data[ $aud_key ] );
			}
		}
		$privacy = buddynext_service( 'privacy' );
		foreach ( $gate_keys as $gate_key => $allowed ) {
			if ( array_key_exists( $gate_key, $data ) ) {
				$gate_val = sanitize_key( (string) $data[ $gate_key ] );
				if ( in_array( $gate_val, $allowed, true ) ) {
					// Route through PrivacyService (single source of truth) so the
					// buddynext_privacy_preference_changed action fires on the live edit flow.
					$privacy->set_preference( $user_id, substr( $gate_key, strlen( 'bn_privacy_' ) ), $gate_val );
				}
				unset( $data[ $gate_key ] );
			}
		}
		foreach ( $bool_keys as $bk ) {
			if ( array_key_exists( $bk, $data ) ) {
				update_user_meta( $user_id, $bk, ! empty( $data[ $bk ] ) ? '1' : '0' );
				unset( $data[ $bk ] );
			}
		}

		// Cap long-form fields at sensible lengths before they hit the service.
		$caps = array(
			'bio'      => 1000,
			'headline' => 160,
			'location' => 120,
			'pronouns' => 40,
		);
		foreach ( $caps as $field_key => $max ) {
			if ( isset( $data[ $field_key ] ) && is_string( $data[ $field_key ] ) ) {
				$data[ $field_key ] = mb_substr( $data[ $field_key ], 0, $max );
			}
		}

		// Normalise URL fields — accept input without protocol by prefixing https.
		$url_fields = array( 'website', 'social_twitter', 'social_linkedin', 'social_github', 'social_instagram', 'social_youtube' );
		foreach ( $url_fields as $url_key ) {
			if ( isset( $data[ $url_key ] ) && is_string( $data[ $url_key ] ) && '' !== trim( $data[ $url_key ] ) ) {
				$raw = trim( $data[ $url_key ] );
				if ( ! preg_match( '#^https?://#i', $raw ) ) {
					$raw = 'https://' . ltrim( $raw, '/' );
				}
				$data[ $url_key ] = esc_url_raw( $raw );
			}
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

		return new WP_REST_Response(
			array(
				'saved'   => true,
				'errors'  => array(),
				'profile' => $profile,
			),
			200
		);
	}

	/**
	 * Validate an incoming profile payload.
	 *
	 * Returns an associative array of `field => message` for every field that
	 * fails validation. An empty array means the payload is clean.
	 *
	 * Rules:
	 *   - display_name (when present) must be non-empty after trimming.
	 *   - URL fields (website + social_*) must pass wp_http_validate_url when
	 *     non-empty. Empty strings are allowed (they clear the field).
	 *
	 * @param array<string, mixed> $data Raw request payload.
	 * @return array<string, string> Field-keyed error messages (possibly empty).
	 */
	private function validate_profile_payload( array $data ): array {
		$errors = array();

		if ( array_key_exists( 'display_name', $data ) ) {
			$dn = trim( (string) $data['display_name'] );
			if ( '' === $dn ) {
				$errors['display_name'] = __( 'Display name is required.', 'buddynext' );
			}
		}

		$url_fields = array( 'website', 'social_twitter', 'social_linkedin', 'social_github', 'social_instagram', 'social_youtube' );
		foreach ( $url_fields as $url_key ) {
			if ( ! isset( $data[ $url_key ] ) || ! is_string( $data[ $url_key ] ) ) {
				continue;
			}
			$value = trim( $data[ $url_key ] );
			if ( '' === $value ) {
				continue;
			}
			$candidate = preg_match( '#^https?://#i', $value ) ? $value : 'https://' . ltrim( $value, '/' );
			if ( ! wp_http_validate_url( $candidate ) ) {
				$errors[ $url_key ] = __( 'Enter a valid URL (https://example.com).', 'buddynext' );
			}
		}

		// Audience enums: must match the canonical four-value vocabulary.
		$audiences     = array( 'everyone', 'members', 'connections', 'nobody' );
		$audience_keys = array( 'bn_privacy_see_email', 'bn_privacy_dm', 'bn_privacy_mention' );
		foreach ( $audience_keys as $aud_key ) {
			if ( ! array_key_exists( $aud_key, $data ) ) {
				continue;
			}
			$val = sanitize_key( (string) $data[ $aud_key ] );
			if ( ! in_array( $val, $audiences, true ) ) {
				$errors[ $aud_key ] = __( 'Choose a valid audience.', 'buddynext' );
			}
		}

		// Profile-view / follow / connect gates: each key has its own enum,
		// mirroring the PrivacyService gate that reads it back.
		$gate_enums = array(
			'bn_privacy_profile_visibility' => array( 'public', 'followers', 'connections', 'private' ),
			'bn_privacy_who_can_follow'     => array( 'everyone', 'nobody' ),
			'bn_privacy_who_can_connect'    => array( 'everyone', 'followers', 'nobody' ),
		);
		foreach ( $gate_enums as $gate_key => $allowed ) {
			if ( ! array_key_exists( $gate_key, $data ) ) {
				continue;
			}
			$gval = sanitize_key( (string) $data[ $gate_key ] );
			if ( ! in_array( $gval, $allowed, true ) ) {
				$errors[ $gate_key ] = __( 'Choose a valid privacy option.', 'buddynext' );
			}
		}

		return $errors;
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
	 * DELETE /users/{id}/avatar — remove any user's avatar (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete_avatar( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'user_not_found', __( 'User not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		buddynext_service( 'profiles' )->delete_avatar( $user_id );

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'user_id' => $user_id,
			),
			200
		);
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

		// Store as organized, per-owner WebP variations (uploads/bn-covers/{id}/)
		// — no attachment rows, no orphans on replace. See ImageStorageService.
		$cover_stored = ( new \BuddyNext\Media\ImageStorageService() )->store(
			(string) $file_data['tmp_name'],
			'cover',
			'user',
			$user_id
		);
		if ( is_wp_error( $cover_stored ) ) {
			return new WP_Error(
				'cover_upload_failed',
				$cover_stored->get_error_message(),
				array( 'status' => 500 )
			);
		}

		update_user_meta( $user_id, 'buddynext_cover_url', esc_url_raw( $cover_stored ) );

		// Reposition data — `focal_x`/`focal_y` (object-position percent 0–100)
		// and `focal_zoom` (scale factor 1–3). Stored as `buddynext_cover_focal`
		// user meta and applied by templates/parts/profile-hero.php to the cover
		// <img> via object-position + transform:scale (non-destructive).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$focal_x_raw = isset( $_POST['focal_x'] ) ? (float) wp_unslash( (string) $_POST['focal_x'] ) : -1;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$focal_y_raw = isset( $_POST['focal_y'] ) ? (float) wp_unslash( (string) $_POST['focal_y'] ) : -1;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$focal_zoom_raw = isset( $_POST['focal_zoom'] ) ? (float) wp_unslash( (string) $_POST['focal_zoom'] ) : 1.0;
		if ( $focal_x_raw >= 0 && $focal_x_raw <= 100 && $focal_y_raw >= 0 && $focal_y_raw <= 100 ) {
			update_user_meta(
				$user_id,
				'buddynext_cover_focal',
				array(
					'x'    => round( $focal_x_raw, 2 ),
					'y'    => round( $focal_y_raw, 2 ),
					'zoom' => round( max( 1.0, min( 3.0, $focal_zoom_raw ) ), 3 ),
				)
			);
		}

		$focal = (array) get_user_meta( $user_id, 'buddynext_cover_focal', true );

		return new WP_REST_Response(
			array(
				'cover_url'  => $cover_stored,
				'focal_x'    => isset( $focal['x'] ) ? (float) $focal['x'] : 50.0,
				'focal_y'    => isset( $focal['y'] ) ? (float) $focal['y'] : 50.0,
				'focal_zoom' => isset( $focal['zoom'] ) ? (float) $focal['zoom'] : 1.0,
			),
			200
		);
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

		// Store as organized, per-owner WebP variations (uploads/bn-avatars/{id}/)
		// instead of a wp_handle_upload() file in uploads/YYYY/MM — no
		// attachment rows, no orphans on replace. See Media\ImageStorageService.
		$stored = ( new \BuddyNext\Media\ImageStorageService() )->store(
			(string) $file_data['tmp_name'],
			'avatar',
			'user',
			$user_id
		);
		if ( is_wp_error( $stored ) ) {
			return new WP_Error(
				'avatar_upload_failed',
				$stored->get_error_message(),
				array( 'status' => 500 )
			);
		}

		buddynext_service( 'profiles' )->update_avatar( $user_id, esc_url_raw( $stored ) );

		return new WP_REST_Response( array( 'avatar_url' => $stored ), 200 );
	}
}
