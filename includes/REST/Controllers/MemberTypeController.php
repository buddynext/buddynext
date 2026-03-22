<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for member types.
 *
 * Routes (all under buddynext/v1):
 *   GET    /member-types                          — list all types (public)
 *   POST   /member-types                          — create type (admin only)
 *   PUT    /member-types/(?P<slug>[a-z0-9-]+)     — update type (admin only)
 *   DELETE /member-types/(?P<slug>[a-z0-9-]+)     — delete type (admin only)
 *   GET    /users/(?P<id>\d+)/member-type          — get user's type (public)
 *   PUT    /users/(?P<id>\d+)/member-type          — assign type to user (admin or self if self_select)
 *   DELETE /users/(?P<id>\d+)/member-type          — remove user's type (admin only)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\MemberTypes\MemberTypeService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles member type reads and writes over REST.
 */
class MemberTypeController {

	/**
	 * Constructor.
	 *
	 * @param MemberTypeService $service Member type service.
	 */
	public function __construct( private readonly MemberTypeService $service ) {}

	// ── Route registration ────────────────────────────────────────────────────

	/**
	 * Register all routes.
	 */
	public function register_routes(): void {

		// ── Type definitions ──────────────────────────────────────────────────

		register_rest_route(
			'buddynext/v1',
			'/member-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_types' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/member-types',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_type' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/member-types/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_type' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/member-types/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_type' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// ── User assignment ───────────────────────────────────────────────────

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/member-type',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_user_type' ),
				'permission_callback' => '__return_true',
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
			'/users/(?P<id>[\d]+)/member-type',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'set_user_type' ),
				'permission_callback' => array( $this, 'can_set_user_type' ),
				'args'                => array(
					'id'        => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'type_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/member-type',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove_user_type' ),
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
	}

	// ── Type definition handlers ──────────────────────────────────────────────

	/**
	 * GET /member-types — list all member types.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_types( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$types = $this->service->get_all();

		$data = array_map( array( $this, 'prepare_type_for_response' ), $types );

		return rest_ensure_response( $data );
	}

	/**
	 * POST /member-types — create a member type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_type( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params() ?? $request->get_body_params();

		$result = $this->service->create(
			array(
				'slug'        => sanitize_key( (string) ( $params['slug'] ?? '' ) ),
				'name'        => sanitize_text_field( (string) ( $params['name'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $params['description'] ?? '' ) ),
				'color'       => sanitize_hex_color( (string) ( $params['color'] ?? '#0073aa' ) ) ?? '#0073aa',
				'text_color'  => sanitize_hex_color( (string) ( $params['text_color'] ?? '#ffffff' ) ) ?? '#ffffff',
				'icon_svg'    => (string) ( $params['icon_svg'] ?? '' ),
				'sort_order'  => (int) ( $params['sort_order'] ?? 0 ),
				'show_in_dir' => ! empty( $params['show_in_dir'] ),
				'self_select' => ! empty( $params['self_select'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$type = $this->service->get_by_id( $result );

		return new WP_REST_Response( $this->prepare_type_for_response( $type ?? array() ), 201 );
	}

	/**
	 * PUT /member-types/{slug} — update a member type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_type( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		$type = $this->service->get_by_slug( $slug );

		if ( ! $type ) {
			return new WP_Error( 'not_found', __( 'Member type not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params() ?? $request->get_body_params();
		$result = $this->service->update( (int) $type['id'], $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = $this->service->get_by_id( (int) $type['id'] );

		return rest_ensure_response( $this->prepare_type_for_response( $updated ?? array() ) );
	}

	/**
	 * DELETE /member-types/{slug} — delete a member type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_type( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		$type = $this->service->get_by_slug( $slug );

		if ( ! $type ) {
			return new WP_Error( 'not_found', __( 'Member type not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$result = $this->service->delete( (int) $type['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'slug'    => $slug,
			),
			200
		);
	}

	// ── User assignment handlers ──────────────────────────────────────────────

	/**
	 * GET /users/{id}/member-type — return the type assigned to a user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_user_type( WP_REST_Request $request ): WP_REST_Response {
		$user_id = absint( $request->get_param( 'id' ) );
		$type    = $this->service->get_user_type( $user_id );

		if ( ! $type ) {
			return new WP_REST_Response( null, 200 );
		}

		return rest_ensure_response( $this->prepare_type_for_response( $type ) );
	}

	/**
	 * PUT /users/{id}/member-type — assign a type to a user.
	 *
	 * Allowed when:
	 *   - Current user is an admin (manage_options), OR
	 *   - Current user is the same as {id} AND the requested type has self_select = 1.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_user_type( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id   = absint( $request->get_param( 'id' ) );
		$type_slug = sanitize_key( (string) $request->get_param( 'type_slug' ) );

		$type = $this->service->get_by_slug( $type_slug );
		if ( ! $type ) {
			return new WP_Error( 'not_found', __( 'Member type not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// Self-assign gate: if not admin, type must allow self_select.
		if ( ! current_user_can( 'manage_options' ) && ! (bool) $type['self_select'] ) {
			return new WP_Error(
				'forbidden',
				__( 'This member type cannot be self-assigned.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$assigned_by = current_user_can( 'manage_options' ) ? get_current_user_id() : 0;
		$result      = $this->service->assign_type( $user_id, (int) $type['id'], $assigned_by );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->prepare_type_for_response( $type ) );
	}

	/**
	 * DELETE /users/{id}/member-type — remove the assigned type from a user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function remove_user_type( WP_REST_Request $request ): WP_REST_Response {
		$user_id = absint( $request->get_param( 'id' ) );
		$removed = $this->service->remove_user_type( $user_id );

		return new WP_REST_Response( array( 'removed' => $removed ), 200 );
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	/**
	 * Require manage_options capability.
	 *
	 * @return bool|WP_Error
	 */
	public function require_admin(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error( 'forbidden', __( 'You do not have permission to manage member types.', 'buddynext' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check for PUT /users/{id}/member-type.
	 *
	 * Admin: always allowed.
	 * Self: allowed only when the requested type has self_select = 1.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function can_set_user_type( WP_REST_Request $request ): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user_id = absint( $request->get_param( 'id' ) );

		if ( get_current_user_id() !== $user_id ) {
			return new WP_Error( 'forbidden', __( 'You cannot set a member type for another user.', 'buddynext' ), array( 'status' => 403 ) );
		}

		// Type-specific self_select check happens in the handler after type lookup.
		return true;
	}

	// ── Response formatter ────────────────────────────────────────────────────

	/**
	 * Prepare a type row for REST output.
	 *
	 * @param array<string, mixed> $type Raw DB row.
	 * @return array<string, mixed>
	 */
	private function prepare_type_for_response( array $type ): array {
		return array(
			'id'          => (int) ( $type['id'] ?? 0 ),
			'slug'        => (string) ( $type['slug'] ?? '' ),
			'name'        => (string) ( $type['name'] ?? '' ),
			'description' => (string) ( $type['description'] ?? '' ),
			'color'       => (string) ( $type['color'] ?? '#0073aa' ),
			'text_color'  => (string) ( $type['text_color'] ?? '#ffffff' ),
			'icon_svg'    => (string) ( $type['icon_svg'] ?? '' ),
			'sort_order'  => (int) ( $type['sort_order'] ?? 0 ),
			'show_in_dir' => (bool) ( $type['show_in_dir'] ?? true ),
			'self_select' => (bool) ( $type['self_select'] ?? false ),
		);
	}
}
