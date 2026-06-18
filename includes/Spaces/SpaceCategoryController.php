<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Space categories REST controller.
 *
 * Routes (all under buddynext/v1):
 *   GET    /space-categories        — list all categories ordered by sort_order (public)
 *   POST   /space-categories        — create a category (manage_options only)
 *   DELETE /space-categories/{id}   — delete a category (manage_options only, 409 if spaces use it)
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use BuddyNext\REST\BaseRestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles space category CRUD over REST.
 */
class SpaceCategoryController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/space-categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_categories' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_category' ),
					'permission_callback' => array( $this, 'require_manage_options' ),
					'args'                => array(
						'name'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'color'       => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_hex_color',
						),
						'text_color'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_hex_color',
						),
						'show_in_dir' => array(
							'required' => false,
							'type'     => 'boolean',
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
			'/space-categories/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_category' ),
					'permission_callback' => array( $this, 'require_manage_options' ),
					'args'                => array(
						'id'          => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'name'        => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'color'       => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_hex_color',
						),
						'text_color'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_hex_color',
						),
						'show_in_dir' => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'sort_order'  => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_category' ),
					'permission_callback' => array( $this, 'require_manage_options' ),
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
	}

	/**
	 * Return all categories ordered by sort_order ascending.
	 *
	 * @return WP_REST_Response
	 */
	public function list_categories(): WP_REST_Response {
		return new WP_REST_Response( $this->service()->get_all(), 200 );
	}

	/**
	 * Create a new space category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_category( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service()->create( $this->payload( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$category = $this->service()->get_by_id( $result );

		return new WP_REST_Response( $category, 201 );
	}

	/**
	 * Update a space category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_category( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$service = $this->service();

		$current = $service->get_by_id( $id );
		if ( null === $current ) {
			return new WP_Error( 'not_found', __( 'Category not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// Only override fields actually present on the request so partial
		// updates keep their existing values.
		$data = array();
		foreach ( array( 'name', 'description', 'color', 'text_color', 'sort_order', 'show_in_dir' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		$result = $service->update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $service->get_by_id( $id ), 200 );
	}

	/**
	 * Delete a space category by ID.
	 *
	 * Returns 409 if any spaces still reference this category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_category( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$service = $this->service();

		if ( null === $service->get_by_id( $id ) ) {
			return new WP_Error( 'not_found', __( 'Category not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$space_count = $service->count_spaces_using( $id );
		if ( $space_count > 0 ) {
			return new WP_Error(
				'category_in_use',
				sprintf(
					/* translators: %d: number of spaces using this category */
					_n(
						'Cannot delete: %d space is still assigned to this category.',
						'Cannot delete: %d spaces are still assigned to this category.',
						$space_count,
						'buddynext'
					),
					$space_count
				),
				array( 'status' => 409 )
			);
		}

		$result = $service->delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Build a create/update payload from request params.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	private function payload( WP_REST_Request $request ): array {
		$data = array(
			'name'        => (string) $request->get_param( 'name' ),
			'description' => (string) $request->get_param( 'description' ),
			'sort_order'  => (int) $request->get_param( 'sort_order' ),
		);

		foreach ( array( 'color', 'text_color', 'show_in_dir' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		return $data;
	}

	/**
	 * Resolve the category service.
	 *
	 * @return SpaceCategoryService
	 */
	private function service(): SpaceCategoryService {
		return new SpaceCategoryService();
	}

	/**
	 * Permission callback: current user must have manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public function require_manage_options(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to perform this action.', 'buddynext' ),
			array( 'status' => 403 )
		);
	}
}
