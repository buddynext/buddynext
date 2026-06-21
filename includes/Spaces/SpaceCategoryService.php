<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Space category lifecycle service.
 *
 * Single owner of all bn_space_categories data access so admin classes and the
 * REST controller never touch $wpdb directly. Categories carry the same
 * presentation columns as member types (colour, text colour, icon, directory
 * visibility) for the unified taxonomy editor.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use WP_Error;

/**
 * Handles space category CRUD.
 */
class SpaceCategoryService {

	/**
	 * Return all categories ordered for admin/directory display.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_space_categories';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( $this, 'hydrate' ), (array) ( $rows ?? array() ) );
	}

	/**
	 * Return all categories with a live space-count per row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_with_counts(): array {
		global $wpdb;
		$cats   = $wpdb->prefix . 'bn_space_categories';
		$spaces = $wpdb->prefix . 'bn_spaces';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT c.*, ( SELECT COUNT(*) FROM {$spaces} s WHERE s.category_id = c.id ) AS space_count
			   FROM {$cats} c
			  ORDER BY c.sort_order ASC, c.name ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			function ( array $row ): array {
				$cat                = $this->hydrate( $row );
				$cat['space_count'] = (int) ( $row['space_count'] ?? 0 );
				return $cat;
			},
			(array) ( $rows ?? array() )
		);
	}

	/**
	 * Find a category by ID.
	 *
	 * @param int $id Category ID.
	 * @return array<string, mixed>|null Null when not found.
	 */
	public function get_by_id( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_space_categories';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Create a new space category.
	 *
	 * @param array<string, mixed> $data {
	 *     Required: 'name'. All others optional.
	 *     @type string $name        Display name.
	 *     @type string $slug        URL-safe identifier. Derived from name when empty.
	 *     @type string $description Optional description.
	 *     @type string $color       Badge background hex. Default '#0073aa'.
	 *     @type string $text_color  Badge text hex. Default '#ffffff'.
	 *     @type string $icon_svg    Inline SVG string. Default ''.
	 *     @type int    $sort_order  Sort position. Default 0.
	 *     @type bool   $show_in_dir Appear in the directory. Default true.
	 * }
	 * @return int|WP_Error New category ID on success.
	 */
	public function create( array $data ): int|WP_Error {
		$clean = $this->validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_space_categories';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $clean['slug'] )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null !== $exists ) {
			return new WP_Error( 'slug_conflict', __( 'A category with that slug already exists.', 'buddynext' ), array( 'status' => 409 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$table,
			array(
				'name'        => $clean['name'],
				'slug'        => $clean['slug'],
				'description' => $clean['description'],
				'color'       => $clean['color'],
				'text_color'  => $clean['text_color'],
				'icon_svg'    => $clean['icon_svg'],
				'sort_order'  => $clean['sort_order'],
				'show_in_dir' => $clean['show_in_dir'] ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_error', __( 'Failed to create category.', 'buddynext' ), array( 'status' => 500 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing space category.
	 *
	 * @param int                  $id   Category ID.
	 * @param array<string, mixed> $data Fields to update (same keys as create()).
	 * @return true|WP_Error
	 */
	public function update( int $id, array $data ): bool|WP_Error {
		$existing = $this->get_by_id( $id );
		if ( null === $existing ) {
			return new WP_Error( 'not_found', __( 'Category not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// Merge so partial updates keep existing values.
		$clean = $this->validate( array_merge( $existing, $data ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_space_categories';

		if ( $clean['slug'] !== $existing['slug'] ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$clash = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1", $clean['slug'], $id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null !== $clash ) {
				return new WP_Error( 'slug_conflict', __( 'A category with that slug already exists.', 'buddynext' ), array( 'status' => 409 ) );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'name'        => $clean['name'],
				'slug'        => $clean['slug'],
				'description' => $clean['description'],
				'color'       => $clean['color'],
				'text_color'  => $clean['text_color'],
				'icon_svg'    => $clean['icon_svg'],
				'sort_order'  => $clean['sort_order'],
				'show_in_dir' => $clean['show_in_dir'] ? 1 : 0,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Delete a category and detach it from any spaces using it.
	 *
	 * @param int $id Category ID.
	 * @return true|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$existing = $this->get_by_id( $id );
		if ( null === $existing ) {
			return new WP_Error( 'not_found', __( 'Category not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		global $wpdb;

		// Null out category_id on spaces using this category so we don't orphan.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_spaces',
			array( 'category_id' => null ),
			array( 'category_id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_space_categories', array( 'id' => $id ), array( '%d' ) );

		return true;
	}

	/**
	 * Count spaces still assigned to a category.
	 *
	 * @param int $id Category ID.
	 * @return int
	 */
	public function count_spaces_using( int $id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE category_id = %d", $id )
		);
	}

	/**
	 * Validate and sanitize category data for create/update.
	 *
	 * @param array<string, mixed> $data Raw input.
	 * @return array<string, mixed>|WP_Error Cleaned data or error.
	 */
	private function validate( array $data ): array|WP_Error {
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Category name is required.', 'buddynext' ), array( 'status' => 422 ) );
		}

		$raw_slug = sanitize_text_field( (string) ( $data['slug'] ?? '' ) );
		$slug     = sanitize_title( '' !== $raw_slug ? $raw_slug : $name );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_name', __( 'Category name produced an empty slug.', 'buddynext' ), array( 'status' => 422 ) );
		}

		$color = sanitize_hex_color( (string) ( $data['color'] ?? '#0073aa' ) );
		if ( ! $color ) {
			$color = '#0073aa';
		}

		$text_color = sanitize_hex_color( (string) ( $data['text_color'] ?? '#ffffff' ) );
		if ( ! $text_color ) {
			$text_color = '#ffffff';
		}

		return array(
			'name'        => $name,
			'slug'        => $slug,
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'color'       => $color,
			'text_color'  => $text_color,
			'icon_svg'    => wp_kses( (string) ( $data['icon_svg'] ?? '' ), $this->allowed_svg_tags() ),
			'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
			'show_in_dir' => array_key_exists( 'show_in_dir', $data ) ? ! empty( $data['show_in_dir'] ) : true,
		);
	}

	/**
	 * Hydrate a raw DB row into a typed category array.
	 *
	 * @param array<string, mixed> $row Raw ARRAY_A row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'name'        => (string) $row['name'],
			'slug'        => (string) $row['slug'],
			'description' => (string) ( $row['description'] ?? '' ),
			'color'       => (string) ( $row['color'] ?? '#0073aa' ),
			'text_color'  => (string) ( $row['text_color'] ?? '#ffffff' ),
			'icon_svg'    => (string) ( $row['icon_svg'] ?? '' ),
			'sort_order'  => (int) ( $row['sort_order'] ?? 0 ),
			'show_in_dir' => ! empty( $row['show_in_dir'] ),
		);
	}

	/**
	 * Allowed HTML tags for SVG icon sanitisation.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function allowed_svg_tags(): array {
		return array(
			'svg'      => array(
				'xmlns'           => true,
				'viewbox'         => true,
				'width'           => true,
				'height'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
			),
			'path'     => array(
				'd'               => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
			'circle'   => array(
				'cx'           => true,
				'cy'           => true,
				'r'            => true,
				'fill'         => true,
				'stroke'       => true,
				'stroke-width' => true,
			),
			'rect'     => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'fill'   => true,
			),
			'polyline' => array(
				'points'       => true,
				'fill'         => true,
				'stroke'       => true,
				'stroke-width' => true,
			),
			'line'     => array(
				'x1'           => true,
				'y1'           => true,
				'x2'           => true,
				'y2'           => true,
				'stroke'       => true,
				'stroke-width' => true,
			),
			'g'        => array(
				'fill'   => true,
				'stroke' => true,
			),
		);
	}
}
