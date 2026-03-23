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

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles space category CRUD over REST.
 */
class SpaceCategoryController {

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
			)
		);
	}

	/**
	 * Return all categories ordered by sort_order ascending.
	 *
	 * @return WP_REST_Response
	 */
	public function list_categories(): WP_REST_Response {
		global $wpdb;

		$table = $wpdb->prefix . 'bn_space_categories';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, name, slug, description, sort_order FROM {$table} ORDER BY sort_order ASC, id ASC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$rows = $rows ?? array();

		$categories = array_map(
			static function ( object $row ): array {
				return array(
					'id'          => (int) $row->id,
					'name'        => $row->name,
					'slug'        => $row->slug,
					'description' => $row->description,
					'sort_order'  => (int) $row->sort_order,
				);
			},
			$rows
		);

		return new WP_REST_Response( $categories, 200 );
	}

	/**
	 * Create a new space category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_category( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$name        = (string) $request->get_param( 'name' );
		$description = (string) $request->get_param( 'description' );
		$sort_order  = (int) $request->get_param( 'sort_order' );
		$slug        = sanitize_title( $name );
		$table       = $wpdb->prefix . 'bn_space_categories';

		if ( '' === $slug ) {
			return new WP_Error(
				'invalid_name',
				__( 'Category name produced an empty slug.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE slug = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug
			)
		);

		if ( null !== $existing ) {
			return new WP_Error(
				'slug_conflict',
				__( 'A category with that slug already exists.', 'buddynext' ),
				array( 'status' => 409 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
				'sort_order'  => $sort_order,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'db_error',
				__( 'Failed to create category.', 'buddynext' ),
				array( 'status' => 500 )
			);
		}

		$new_id = (int) $wpdb->insert_id;

		return new WP_REST_Response(
			array(
				'id'          => $new_id,
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
				'sort_order'  => $sort_order,
			),
			201
		);
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
		global $wpdb;

		$id           = (int) $request->get_param( 'id' );
		$cat_table    = $wpdb->prefix . 'bn_space_categories';
		$spaces_table = $wpdb->prefix . 'bn_spaces';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$cat_table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		if ( null === $exists ) {
			return new WP_Error(
				'not_found',
				__( 'Category not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$spaces_table} WHERE category_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$cat_table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error(
				'db_error',
				__( 'Failed to delete category.', 'buddynext' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( null, 204 );
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
