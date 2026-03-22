<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext Member Type service.
 *
 * Manages member type definitions and user assignments.
 *
 * Scale design — 100 k members:
 *   Source of truth : bn_member_type_assignments (join table with audit trail)
 *   Fast read cache : wp_usermeta key=bn_member_type (write-through on every assign)
 *                     Powers WP_User_Query meta_query — no custom JOIN in directory hot path.
 *   Object cache    : CacheService keys bn_member_type_{user_id}, bn_member_types_all,
 *                     bn_member_type_count_{type_id}
 *
 * Free tier enforces one type per user in application code.
 * Pro tier removes that constraint without schema changes.
 *
 * @package BuddyNext\MemberTypes
 */

declare( strict_types=1 );

namespace BuddyNext\MemberTypes;

use BuddyNext\Core\CacheService;
use WP_Error;

/**
 * Handles member type CRUD and user assignment.
 */
class MemberTypeService {

	// ── Cache TTL ─────────────────────────────────────────────────────────────

	/**
	 * Cache lifetime in seconds.
	 */
	private const CACHE_TTL = 3600;

	// ── Constructor ───────────────────────────────────────────────────────────

	/**
	 * Constructor.
	 *
	 * @param CacheService $cache Cache service.
	 */
	public function __construct( private readonly CacheService $cache ) {}

	// ── Type definitions ──────────────────────────────────────────────────────

	/**
	 * Return all member types ordered by sort_order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all(): array {
		$cached = $this->cache->get( 'bn_member_types_all' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_member_types';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$types = is_array( $rows ) ? $rows : array();
		$this->cache->set( 'bn_member_types_all', $types, self::CACHE_TTL );

		return $types;
	}

	/**
	 * Return all types with a live member count for each.
	 *
	 * Used by the admin types list table where counts are displayed per row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_with_counts(): array {
		global $wpdb;
		$types_table       = $wpdb->prefix . 'bn_member_types';
		$assignments_table = $wpdb->prefix . 'bn_member_type_assignments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT t.*, COUNT(a.user_id) AS member_count
			   FROM {$types_table} t
			   LEFT JOIN {$assignments_table} a ON a.type_id = t.id
			  GROUP BY t.id
			  ORDER BY t.sort_order ASC, t.id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find a type by its integer ID.
	 *
	 * @param int $id Type ID.
	 * @return array<string, mixed>|null Null when not found.
	 */
	public function get_by_id( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_member_types';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find a type by its URL slug.
	 *
	 * @param string $slug Type slug.
	 * @return array<string, mixed>|null Null when not found.
	 */
	public function get_by_slug( string $slug ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_member_types';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create a new member type.
	 *
	 * @param array<string, mixed> $data {
	 *     Required: 'slug', 'name'. All others optional.
	 *     @type string $slug        URL-safe identifier. Must be unique.
	 *     @type string $name        Display name.
	 *     @type string $description Optional description.
	 *     @type string $color       Badge background hex. Default '#0073aa'.
	 *     @type string $text_color  Badge text hex. Default '#ffffff'.
	 *     @type string $icon_svg    Inline SVG string. Default ''.
	 *     @type int    $sort_order  Sort position. Default 0.
	 *     @type bool   $show_in_dir Appear as directory tab. Default true.
	 *     @type bool   $self_select User can self-assign. Default false.
	 * }
	 * @return int|WP_Error New type ID on success.
	 */
	public function create( array $data ): int|WP_Error {
		$validated = $this->validate_type_data( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Slug uniqueness check.
		if ( $this->get_by_slug( $validated['slug'] ) ) {
			return new WP_Error( 'slug_exists', __( 'A member type with this slug already exists.', 'buddynext' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_member_types';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$table,
			array(
				'slug'        => $validated['slug'],
				'name'        => $validated['name'],
				'description' => $validated['description'],
				'color'       => $validated['color'],
				'text_color'  => $validated['text_color'],
				'icon_svg'    => $validated['icon_svg'],
				'sort_order'  => $validated['sort_order'],
				'show_in_dir' => $validated['show_in_dir'] ? 1 : 0,
				'self_select' => $validated['self_select'] ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'Could not save member type.', 'buddynext' ) );
		}

		$new_id = (int) $wpdb->insert_id;

		$this->cache->delete( 'bn_member_types_all' );

		$type_data       = $validated;
		$type_data['id'] = $new_id;
		do_action( 'buddynext_member_type_created', $new_id, $type_data );

		return $new_id;
	}

	/**
	 * Update an existing member type.
	 *
	 * @param int                  $id   Type ID.
	 * @param array<string, mixed> $data Fields to update (same keys as create()).
	 * @return true|WP_Error
	 */
	public function update( int $id, array $data ): true|WP_Error {
		$existing = $this->get_by_id( $id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'Member type not found.', 'buddynext' ) );
		}

		// Merge with existing so partial updates work.
		$merged = array_merge( $existing, $data );

		$validated = $this->validate_type_data( $merged );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Slug uniqueness: if slug changed, check it is not taken.
		if ( $validated['slug'] !== $existing['slug'] ) {
			$conflict = $this->get_by_slug( $validated['slug'] );
			if ( $conflict && (int) $conflict['id'] !== $id ) {
				return new WP_Error( 'slug_exists', __( 'A member type with this slug already exists.', 'buddynext' ) );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_member_types';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'slug'        => $validated['slug'],
				'name'        => $validated['name'],
				'description' => $validated['description'],
				'color'       => $validated['color'],
				'text_color'  => $validated['text_color'],
				'icon_svg'    => $validated['icon_svg'],
				'sort_order'  => $validated['sort_order'],
				'show_in_dir' => $validated['show_in_dir'] ? 1 : 0,
				'self_select' => $validated['self_select'] ? 1 : 0,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// When slug changes, update the usermeta read cache for all assigned users.
		if ( $validated['slug'] !== $existing['slug'] ) {
			$this->rewrite_usermeta_slug( $id, $validated['slug'] );
		}

		$this->cache->delete( 'bn_member_types_all' );

		return true;
	}

	/**
	 * Delete a member type and cascade all assignments.
	 *
	 * Side effects:
	 *   - All user assignments for this type are removed.
	 *   - bn_member_type usermeta is deleted for affected users.
	 *   - bn_profile_groups.type_restriction is cleared for groups scoped to this type.
	 *   - Fires buddynext_member_type_deleted.
	 *
	 * @param int $id Type ID.
	 * @return true|WP_Error
	 */
	public function delete( int $id ): true|WP_Error {
		$type = $this->get_by_id( $id );
		if ( ! $type ) {
			return new WP_Error( 'not_found', __( 'Member type not found.', 'buddynext' ) );
		}

		global $wpdb;
		$assignments_table = $wpdb->prefix . 'bn_member_type_assignments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Collect affected user IDs before deleting so we can bust their caches.
		$affected_users = $wpdb->get_col(
			$wpdb->prepare( "SELECT user_id FROM {$assignments_table} WHERE type_id = %d", $id )
		);

		// Remove assignments.
		$wpdb->delete( $assignments_table, array( 'type_id' => $id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Clear usermeta read cache for affected users.
		foreach ( $affected_users as $user_id ) {
			delete_user_meta( (int) $user_id, 'bn_member_type' );
			$this->cache->delete( 'bn_member_type_' . $user_id );
			do_action( 'buddynext_member_type_removed', (int) $user_id, $type['slug'] );
		}

		// Clear profile group restrictions that referenced this type's slug.
		$groups_table = $wpdb->prefix . 'bn_profile_groups';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$groups_table,
			array( 'type_restriction' => null ),
			array( 'type_restriction' => $type['slug'] ),
			array( '%s' ),
			array( '%s' )
		);

		// Delete the type row.
		$wpdb->delete( $wpdb->prefix . 'bn_member_types', array( 'id' => $id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->cache->delete( 'bn_member_types_all' );
		$this->cache->delete( 'bn_member_type_count_' . $id );

		do_action( 'buddynext_member_type_deleted', $id, $type['slug'] );

		return true;
	}

	// ── User assignment ───────────────────────────────────────────────────────

	/**
	 * Return the single member type assigned to a user, or null.
	 *
	 * Read path (ordered by speed):
	 *   1. CacheService object cache.
	 *   2. wp_usermeta bn_member_type (indexed, no custom table JOIN).
	 *   3. bn_member_type_assignments JOIN bn_member_types (source of truth).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed>|null Type row or null when unassigned.
	 */
	public function get_user_type( int $user_id ): ?array {
		$cache_key = 'bn_member_type_' . $user_id;
		$cached    = $this->cache->get( $cache_key );

		if ( false !== $cached ) {
			// Cache stores the type array or the string 'none' to avoid repeated DB hits.
			return 'none' === $cached ? null : $cached;
		}

		// Fast path: usermeta read cache.
		$slug = (string) get_user_meta( $user_id, 'bn_member_type', true );

		if ( '' !== $slug ) {
			$type = $this->get_by_slug( $slug );
			$this->cache->set( $cache_key, $type ?? 'none', self::CACHE_TTL );
			return $type;
		}

		// Slow path: authoritative join table (first access, or usermeta was cleared).
		global $wpdb;
		$types_table       = $wpdb->prefix . 'bn_member_types';
		$assignments_table = $wpdb->prefix . 'bn_member_type_assignments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.* FROM {$types_table} t
				  INNER JOIN {$assignments_table} a ON a.type_id = t.id
				  WHERE a.user_id = %d
				  ORDER BY a.assigned_at ASC
				  LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$type = is_array( $row ) ? $row : null;

		if ( $type ) {
			// Warm the usermeta read cache so future directory queries are fast.
			update_user_meta( $user_id, 'bn_member_type', $type['slug'] );
		}

		$this->cache->set( $cache_key, $type ?? 'none', self::CACHE_TTL );

		return $type;
	}

	/**
	 * Assign a member type to a user.
	 *
	 * Free tier: removes any existing type before assigning (single-type enforcement).
	 * The uq_user_type constraint prevents duplicate assignments under concurrent writes.
	 *
	 * @param int $user_id     WordPress user ID.
	 * @param int $type_id     Type ID to assign.
	 * @param int $assigned_by User ID performing the assignment (0 = self).
	 * @return true|WP_Error
	 */
	public function assign_type( int $user_id, int $type_id, int $assigned_by = 0 ): true|WP_Error {
		$type = $this->get_by_id( $type_id );
		if ( ! $type ) {
			return new WP_Error( 'not_found', __( 'Member type not found.', 'buddynext' ) );
		}

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'invalid_user', __( 'User not found.', 'buddynext' ) );
		}

		// Record the previous type slug for the action hook.
		$previous = $this->get_user_type( $user_id );
		$old_slug = $previous ? (string) $previous['slug'] : '';

		global $wpdb;
		$assignments_table = $wpdb->prefix . 'bn_member_type_assignments';

		// Free-tier single-type enforcement: remove all existing assignments first.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $assignments_table, array( 'user_id' => $user_id ), array( '%d' ) );

		// Insert new assignment. uq_user_type prevents double-write under race conditions.
		$inserted = $wpdb->insert(
			$assignments_table,
			array(
				'user_id'     => $user_id,
				'type_id'     => $type_id,
				'assigned_by' => $assigned_by,
			),
			array( '%d', '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'Could not assign member type.', 'buddynext' ) );
		}

		// Write-through: keep usermeta in sync for fast WP_User_Query directory filtering.
		update_user_meta( $user_id, 'bn_member_type', $type['slug'] );

		// Bust caches.
		$this->cache->delete( 'bn_member_type_' . $user_id );
		$this->cache->delete( 'bn_member_type_count_' . $type_id );
		if ( $previous ) {
			$this->cache->delete( 'bn_member_type_count_' . $previous['id'] );
		}

		do_action( 'buddynext_member_type_assigned', $user_id, (string) $type['slug'], $old_slug );

		return true;
	}

	/**
	 * Remove the assigned member type from a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if a type was removed, false if user had no type.
	 */
	public function remove_user_type( int $user_id ): bool {
		$existing = $this->get_user_type( $user_id );

		global $wpdb;
		$assignments_table = $wpdb->prefix . 'bn_member_type_assignments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $assignments_table, array( 'user_id' => $user_id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		delete_user_meta( $user_id, 'bn_member_type' );

		$this->cache->delete( 'bn_member_type_' . $user_id );
		if ( $existing ) {
			$this->cache->delete( 'bn_member_type_count_' . $existing['id'] );
			do_action( 'buddynext_member_type_removed', $user_id, (string) $existing['slug'] );
		}

		return (bool) $deleted;
	}

	/**
	 * Return the number of users assigned to a specific type.
	 *
	 * Cached to avoid per-request COUNT queries in admin stats.
	 *
	 * @param int $type_id Type ID.
	 * @return int
	 */
	public function get_type_member_count( int $type_id ): int {
		$cache_key = 'bn_member_type_count_' . $type_id;
		$cached    = $this->cache->get( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_member_type_assignments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type_id = %d", $type_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->cache->set( $cache_key, $count, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Return user IDs assigned to a given type slug.
	 *
	 * Used for admin export and bulk operations.
	 * For directory display, prefer WP_User_Query with meta_query on bn_member_type usermeta.
	 *
	 * @param string $slug     Type slug.
	 * @param int    $limit    Max rows. Default 200.
	 * @param int    $offset   Row offset for batching. Default 0.
	 * @return int[]
	 */
	public function get_user_ids_by_type( string $slug, int $limit = 200, int $offset = 0 ): array {
		global $wpdb;
		$assignments_table = $wpdb->prefix . 'bn_member_type_assignments';
		$types_table       = $wpdb->prefix . 'bn_member_types';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT a.user_id
				   FROM {$assignments_table} a
				   INNER JOIN {$types_table} t ON t.id = a.type_id
				   WHERE t.slug = %s
				   ORDER BY a.assigned_at DESC
				   LIMIT %d OFFSET %d",
				$slug,
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Validate and sanitize type data for create/update.
	 *
	 * @param array<string, mixed> $data Raw input.
	 * @return array<string, mixed>|WP_Error Cleaned data or error.
	 */
	private function validate_type_data( array $data ): array|WP_Error {
		$slug = sanitize_key( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Member type slug is required.', 'buddynext' ) );
		}

		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Member type name is required.', 'buddynext' ) );
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
			'slug'        => $slug,
			'name'        => $name,
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'color'       => $color,
			'text_color'  => $text_color,
			'icon_svg'    => wp_kses( (string) ( $data['icon_svg'] ?? '' ), $this->allowed_svg_tags() ),
			'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
			'show_in_dir' => ! empty( $data['show_in_dir'] ),
			'self_select' => ! empty( $data['self_select'] ),
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

	/**
	 * Rewrite the bn_member_type usermeta read cache when a type's slug changes.
	 *
	 * @param int    $type_id  Type ID.
	 * @param string $new_slug New slug value.
	 * @return void
	 */
	private function rewrite_usermeta_slug( int $type_id, string $new_slug ): void {
		$user_ids = $this->get_user_ids_by_type( $new_slug, 5000, 0 );

		foreach ( $user_ids as $user_id ) {
			update_user_meta( $user_id, 'bn_member_type', $new_slug );
			$this->cache->delete( 'bn_member_type_' . $user_id );
		}
	}
}
