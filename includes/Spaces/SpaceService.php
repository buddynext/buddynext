<?php
/**
 * Space lifecycle service.
 *
 * Manages space creation, retrieval, updates, and deletion. Spaces are
 * community containers that users can join. Each space has a unique slug,
 * a type (open / private / secret), and is owned by the creator.
 *
 * When a space is created the owner is automatically inserted into
 * bn_space_members with role='owner' and the member_count is set to 1.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use WP_Error;

/**
 * Handles space CRUD.
 */
class SpaceService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_spaces';

	/**
	 * Cache TTL in seconds (10 minutes).
	 */
	private const CACHE_TTL = 600;

	/**
	 * Allowed space types.
	 */
	private const ALLOWED_TYPES = array( 'open', 'private', 'secret' );

	/**
	 * Create a new space.
	 *
	 * @param int   $owner_id Creator/owner user ID.
	 * @param array $data     Space data: name (required), slug (required), type, description, category_id, parent_id.
	 * @return int|WP_Error Inserted space ID or WP_Error on validation failure.
	 */
	public function create( int $owner_id, array $data ): int|WP_Error {
		global $wpdb;

		$slug = sanitize_title( $data['slug'] ?? '' );

		if ( '' === $slug ) {
			return new WP_Error( 'missing_slug', __( 'A space slug is required.', 'buddynext' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_spaces WHERE slug = %s",
				$slug
			)
		);

		if ( null !== $existing ) {
			return new WP_Error( 'slug_taken', __( 'This slug is already taken.', 'buddynext' ) );
		}

		$type = in_array( $data['type'] ?? 'open', self::ALLOWED_TYPES, true ) ? $data['type'] : 'open';

		// Enforce two-level sub-space depth limit.
		$parent_id = isset( $data['parent_id'] ) ? (int) $data['parent_id'] : null;
		if ( null !== $parent_id && $parent_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$grandparent = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT parent_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d",
					$parent_id
				)
			);
			if ( null !== $grandparent && $grandparent > 0 ) {
				return new WP_Error(
					'max_depth_exceeded',
					__( 'Spaces may only be nested two levels deep.', 'buddynext' )
				);
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'         => sanitize_text_field( $data['name'] ?? '' ),
				'slug'         => $slug,
				'description'  => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : null,
				'category_id'  => isset( $data['category_id'] ) ? (int) $data['category_id'] : null,
				'parent_id'    => $parent_id,
				'type'         => $type,
				'owner_id'     => $owner_id,
				'member_count' => 1,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d' )
		);

		$space_id = (int) $wpdb->insert_id;

		// Auto-add owner as member.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $owner_id,
				'role'     => 'owner',
			),
			array( '%d', '%d', '%s' )
		);

		/**
		 * Fires after a new space is created.
		 *
		 * @param int $space_id  Newly created space ID.
		 * @param int $owner_id  ID of the space owner.
		 */
		do_action( 'buddynext_space_created', $space_id, $owner_id );

		return $space_id;
	}

	/**
	 * Return a single space by ID.
	 *
	 * @param int $space_id Space ID.
	 * @return array|null Null if not found.
	 */
	public function get( int $space_id ): ?array {
		$cache_key = "space_{$space_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces WHERE id = %d",
				$space_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		$space = $this->hydrate( $row );

		wp_cache_set( $cache_key, $space, self::CACHE_GROUP, self::CACHE_TTL );

		return $space;
	}

	/**
	 * Update a space.
	 *
	 * Only the owner (or a user with manage_options) may update a space.
	 *
	 * @param int   $space_id  Space to update.
	 * @param int   $user_id   User requesting the update.
	 * @param array $data      Fields to update: name, description, type, category_id.
	 * @return true|WP_Error
	 */
	public function update( int $space_id, int $user_id, array $data ): true|WP_Error {
		$space = $this->get( $space_id );

		if ( null === $space ) {
			return new WP_Error( 'not_found', __( 'Space not found.', 'buddynext' ) );
		}

		if ( $space['owner_id'] !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to update this space.', 'buddynext' ) );
		}

		global $wpdb;

		$fields = array();
		$format = array();

		if ( isset( $data['name'] ) ) {
			$fields['name'] = sanitize_text_field( $data['name'] );
			$format[]       = '%s';
		}
		if ( isset( $data['description'] ) ) {
			$fields['description'] = sanitize_textarea_field( $data['description'] );
			$format[]              = '%s';
		}
		if ( isset( $data['type'] ) && in_array( $data['type'], self::ALLOWED_TYPES, true ) ) {
			$fields['type'] = $data['type'];
			$format[]       = '%s';
		}
		if ( isset( $data['category_id'] ) ) {
			$fields['category_id'] = (int) $data['category_id'];
			$format[]              = '%d';
		}
		if ( isset( $data['slug'] ) ) {
			$new_slug = sanitize_title( $data['slug'] );
			if ( '' !== $new_slug && $new_slug !== $space['slug'] ) {
				// Ensure new slug is unique.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$conflict = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}bn_spaces WHERE slug = %s AND id != %d LIMIT 1",
						$new_slug,
						$space_id
					)
				);
				if ( null === $conflict ) {
					$fields['slug'] = $new_slug;
					$format[]       = '%s';
				}
			}
		}
		if ( isset( $data['avatar_url'] ) ) {
			$fields['avatar_url'] = esc_url_raw( $data['avatar_url'] );
			$format[]             = '%s';
		}
		if ( isset( $data['cover_image_url'] ) ) {
			$fields['cover_image_url'] = esc_url_raw( $data['cover_image_url'] );
			$format[]                  = '%s';
		}

		if ( ! empty( $fields ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_spaces',
				$fields,
				array( 'id' => $space_id ),
				$format,
				array( '%d' )
			);
		}

		wp_cache_delete( "space_{$space_id}", self::CACHE_GROUP );

		return true;
	}

	/**
	 * Delete a space.
	 *
	 * Only the owner (or manage_options) may delete. Also removes all
	 * member rows for this space.
	 *
	 * @param int $space_id Space to delete.
	 * @param int $user_id  User requesting the deletion.
	 * @return true|WP_Error
	 */
	public function delete( int $space_id, int $user_id ): true|WP_Error {
		$space = $this->get( $space_id );

		if ( null === $space ) {
			return new WP_Error( 'not_found', __( 'Space not found.', 'buddynext' ) );
		}

		if ( $space['owner_id'] !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to delete this space.', 'buddynext' ) );
		}

		global $wpdb;

		// Remove all members first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array( 'space_id' => $space_id ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_spaces',
			array( 'id' => $space_id ),
			array( '%d' )
		);

		wp_cache_delete( "space_{$space_id}", self::CACHE_GROUP );

		/**
		 * Fires after a space is deleted.
		 *
		 * @param int $space_id Deleted space ID.
		 * @param int $user_id  User who deleted it.
		 */
		do_action( 'buddynext_space_deleted', $space_id, $user_id );

		return true;
	}

	/**
	 * Return a paginated list of spaces.
	 *
	 * Supported args:
	 *   per_page    int     Spaces per page. Default 12. Max 100.
	 *   page        int     1-based page number. Default 1.
	 *   type        string  Filter by type ('open', 'private', 'secret'). Default: exclude secret.
	 *   category_id int     Filter by category. Default: no filter.
	 *   orderby     string  'member_count' | 'name' | 'created_at'. Default 'member_count'.
	 *   order       string  'ASC' | 'DESC'. Default 'DESC'.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array[]
	 */
	public function list_spaces( array $args = array() ): array {
		global $wpdb;

		$per_page    = max( 1, min( 100, absint( $args['per_page'] ?? 12 ) ) );
		$page        = max( 1, absint( $args['page'] ?? 1 ) );
		$offset      = ( $page - 1 ) * $per_page;
		$type        = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : '';
		$category_id = isset( $args['category_id'] ) ? absint( $args['category_id'] ) : 0;

		$allowed_orderby = array( 'member_count', 'name', 'created_at' );
		$raw_orderby     = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'member_count';
		$orderby         = in_array( $raw_orderby, $allowed_orderby, true ) ? $raw_orderby : 'member_count';
		$order           = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$params = array();
		$where  = array();

		if ( '' !== $type ) {
			$where[]  = 'type = %s';
			$params[] = $type;
		} else {
			// Exclude secret spaces from the public directory by default.
			$where[] = "type != 'secret'";
		}

		if ( $category_id > 0 ) {
			$where[]  = 'category_id = %d';
			$params[] = $category_id;
		}

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
		$params[]  = $per_page;
		$params[]  = $offset;

		// $where_sql contains only hardcoded strings or validated enum values.
		// $orderby is validated against an allowlist; $order is either 'ASC' or 'DESC'.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				...$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Search spaces by name or description.
	 *
	 * Secret spaces are always excluded from search results.
	 *
	 * @param string               $term Search term.
	 * @param array<string, mixed> $args Optional per_page / page.
	 * @return array[]
	 */
	public function search( string $term, array $args = array() ): array {
		global $wpdb;

		$like     = '%' . $wpdb->esc_like( $term ) . '%';
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 12 ) ) );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces
				 WHERE type != 'secret' AND (name LIKE %s OR description LIKE %s)
				 ORDER BY member_count DESC
				 LIMIT %d OFFSET %d",
				$like,
				$like,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Hydrate a raw DB row into a typed space array.
	 *
	 * @param array $row Raw ARRAY_A row from bn_spaces.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'name'         => $row['name'],
			'slug'         => $row['slug'],
			'description'  => $row['description'],
			'category_id'  => isset( $row['category_id'] ) ? (int) $row['category_id'] : null,
			'parent_id'    => isset( $row['parent_id'] ) ? (int) $row['parent_id'] : null,
			'type'         => $row['type'],
			'owner_id'     => (int) $row['owner_id'],
			'member_count' => (int) $row['member_count'],
			'created_at'   => $row['created_at'],
		);
	}
}
