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
	 * Where a category may be used: main spaces, subspaces, or both.
	 */
	public const LEVELS = array( 'both', 'main', 'sub' );

	/**
	 * Whether a category may be used at this level.
	 *
	 * @param array<string, mixed> $category Hydrated category.
	 * @param bool                 $is_sub   True for a subspace, false for a main space.
	 * @return bool
	 */
	public static function allows( array $category, bool $is_sub ): bool {
		$level = (string) ( $category['level'] ?? 'both' );
		return 'both' === $level || ( $is_sub ? 'sub' : 'main' ) === $level;
	}


	/**
	 * Object-cache group for the category lists.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'buddynext_space_cats';

	/**
	 * TTL for the plain category list (changes only on category CRUD).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 600;

	/**
	 * TTL for the list-with-counts. Short, because the count also moves when any
	 * space changes its category_id (a write outside this service); the short TTL
	 * bounds that staleness without coupling to SpaceService.
	 *
	 * @var int
	 */
	private const CACHE_TTL_COUNTS = 60;

	/**
	 * Return all categories ordered for admin/directory display.
	 *
	 * Cached: a global list rendered on the spaces directory + explore aside.
	 *
	 * @param bool $visible_only Only categories flagged show_in_dir (member-facing callers).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all( bool $visible_only = false ): array {
		// See get_all_with_counts(): show_in_dir was never filtered on anywhere. Member-facing
		// callers pass true; the admin and IconService need every category and pass nothing.
		$cache_key = $visible_only ? 'visible' : 'all';

		$hit = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $hit ) {
			return (array) $hit;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_space_categories';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $visible_only selects between two LITERAL queries; no value is interpolated into the SQL.
		$rows = $wpdb->get_results(
			$visible_only
				? "SELECT * FROM {$table} WHERE show_in_dir = 1 ORDER BY sort_order ASC, name ASC"
				: "SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$out = array_map( array( $this, 'hydrate' ), (array) ( $rows ?? array() ) );
		wp_cache_set( $cache_key, $out, self::CACHE_GROUP, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Categories a member may pick as an interest (onboarding, profile Interests).
	 *
	 * The owner's two existing flags decide it: shown in the directory, and not
	 * "Subspaces only" (a member cannot browse by a subspace-only category, so it
	 * would lead nowhere from the For You feed and space suggestions). Label
	 * lookups and saved values keep using get_all(), so a member's earlier pick
	 * of a category hidden later is neither shown as a choice nor lost on save.
	 * A category whose spaces are all hidden from visitors is left out too.
	 *
	 * @since 1.3.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_for_members(): array {
		// A category whose spaces are all hidden (secret, archived) leads nowhere and its
		// presence hints at what is hidden, so it is left out. An empty category stays:
		// the owner just made it and spaces are on the way.
		return array_values(
			array_filter(
				$this->get_all_with_counts( true ),
				static fn( array $category ): bool => 'sub' !== (string) ( $category['level'] ?? 'both' )
					&& ( empty( $category['has_spaces'] ) || (int) ( $category['space_count'] ?? 0 ) > 0 )
			)
		);
	}

	/**
	 * IDs of categories hidden from the directory (show_in_dir = 0).
	 *
	 * The directory scope builder uses these to exclude their spaces from the
	 * public grid, the "Popular this week" rail and the REST list - the flag was
	 * only ever honored for the category chips, never for the space list itself.
	 * Reserved hubs (e.g. Wellbee Circles) depend on this exclusion. Cached in the
	 * shared group and invalidated on any category write via invalidate().
	 *
	 * @return int[] Category IDs whose spaces must not appear in the directory.
	 */
	public function get_hidden_ids(): array {
		$hit = wp_cache_get( 'hidden_ids', self::CACHE_GROUP );
		if ( false !== $hit ) {
			return (array) $hit;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bn_space_categories';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- LITERAL query; only the table name (a prefix constant) is interpolated, no value.
		$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table} WHERE show_in_dir = 0" ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		wp_cache_set( 'hidden_ids', $ids, self::CACHE_GROUP, self::CACHE_TTL );
		return $ids;
	}

	/**
	 * Return all categories with a space count per row.
	 *
	 * Admin callers ($visible_only = false) get the raw total: every space in the
	 * category, whatever its type or state, because the admin manages all of them.
	 *
	 * Member-facing callers ($visible_only = true) get `space_count` = the spaces a
	 * logged-out visitor finds in the directory grid for that category: a listed
	 * type, not archived, top level. The raw count used to be shown here, so a
	 * category holding only secret spaces read "2 spaces" to guests and then listed
	 * nothing, which told everyone those secret spaces exist. `has_spaces` says
	 * whether the category holds any space at all, so a caller can tell an empty
	 * category from one whose spaces are all hidden.
	 *
	 * ponytail: one count for every viewer, so it can be cached once. A member of a
	 * secret space sees one fewer than they could open (an undercount, never a leak);
	 * per-viewer counts would need a cache entry per member.
	 *
	 * One GROUP BY over KEY category, cached against the spaces-listing salt
	 * (SpaceService::lists_salt()), so any space write retires it with the listings.
	 *
	 * @param bool $visible_only Only categories flagged show_in_dir (member-facing callers).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_with_counts( bool $visible_only = false ): array {
		/*
		 * `show_in_dir` was stored, shaped and returned - and never filtered on. Every listing
		 * did SELECT * with no WHERE, so a category the owner had switched OFF still appeared in
		 * the directory chips and the public REST list. The toggle saved, persisted, and did
		 * nothing.
		 *
		 * The ADMIN still needs every category (you cannot manage a category you cannot see), and
		 * so does IconService (a hidden category still needs its icon wherever it IS shown). So
		 * this is a parameter, not a blanket WHERE - and it carries its own cache key, or the two
		 * callers would poison each other's cached list.
		 */
		$cache_key = $visible_only ? 'visible_counts_' . SpaceService::lists_salt() : 'all_counts';

		$hit = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $hit ) {
			return (array) $hit;
		}

		global $wpdb;
		$cats   = $wpdb->prefix . 'bn_space_categories';
		$spaces = $wpdb->prefix . 'bn_spaces';

		if ( $visible_only ) {
			// Registry keys are sanitize_key()'d, so each is bound as a %s.
			$unlisted = SpaceTypeRegistry::instance()->unlisted_keys();
			$listed   = $unlisted
				? 'type NOT IN ( ' . implode( ', ', array_fill( 0, count( $unlisted ), '%s' ) ) . ' )'
				: '1 = 1';
			$sql      = "SELECT c.*, COALESCE( n.total, 0 ) AS space_total, COALESCE( n.listed, 0 ) AS space_count
				 FROM {$cats} c
				 LEFT JOIN (
					SELECT category_id, COUNT(*) AS total,
					       SUM( CASE WHEN {$listed} AND is_archived = 0 AND parent_id IS NULL THEN 1 ELSE 0 END ) AS listed
					  FROM {$spaces}
					 WHERE category_id > 0
					 GROUP BY category_id
				 ) n ON n.category_id = c.id
				WHERE c.show_in_dir = 1
				ORDER BY c.sort_order ASC, c.name ASC";
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names are prefix constants; the type list is %s placeholders bound here.
			$rows = $wpdb->get_results( $unlisted ? $wpdb->prepare( $sql, ...$unlisted ) : $sql, ARRAY_A );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- LITERAL query; only the prefixed table names are interpolated.
			$rows = $wpdb->get_results(
				"SELECT c.*, ( SELECT COUNT(*) FROM {$spaces} s WHERE s.category_id = c.id ) AS space_count
					 FROM {$cats} c
					ORDER BY c.sort_order ASC, c.name ASC",
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}

		$out = array_map(
			function ( array $row ): array {
				$cat                = $this->hydrate( $row );
				$cat['space_count'] = (int) ( $row['space_count'] ?? 0 );
				$cat['has_spaces']  = (int) ( $row['space_total'] ?? $row['space_count'] ?? 0 ) > 0;
				return $cat;
			},
			(array) ( $rows ?? array() )
		);
		wp_cache_set( $cache_key, $out, self::CACHE_GROUP, self::CACHE_TTL_COUNTS );
		return $out;
	}

	/**
	 * Evict both category-list caches. Called from every category write.
	 *
	 * @return void
	 */
	private function invalidate(): void {
		wp_cache_delete( 'all', self::CACHE_GROUP );
		wp_cache_delete( 'visible', self::CACHE_GROUP );
		wp_cache_delete( 'all_counts', self::CACHE_GROUP );
		// The directory's visible-only list is salted by the spaces-listing salt. A category
		// write changes what the listings show too (show_in_dir fences spaces out of them), so
		// retiring that salt evicts both - miss it and switching show_in_dir off leaves the
		// category sitting in the directory until the TTL expires.
		SpaceService::flush_space_lists();
		// The hidden-id set feeds the directory space-list exclusion; same staleness trap.
		wp_cache_delete( 'hidden_ids', self::CACHE_GROUP );
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $visible_only selects between two LITERAL queries; no value is interpolated into the SQL.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $visible_only selects between two LITERAL queries; no value is interpolated into the SQL.
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $clean['slug'] )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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
				'level'       => $clean['level'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_error', __( 'Failed to create category.', 'buddynext' ), array( 'status' => 500 ) );
		}

		$this->invalidate();
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
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $visible_only selects between two LITERAL queries; no value is interpolated into the SQL.
			$clash = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1", $clean['slug'], $id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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
				'level'       => $clean['level'],
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		$this->invalidate();
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

		$this->invalidate();
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
			'level'       => in_array( (string) ( $data['level'] ?? '' ), self::LEVELS, true ) ? (string) $data['level'] : 'both',
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
			'level'       => in_array( (string) ( $row['level'] ?? '' ), self::LEVELS, true ) ? (string) $row['level'] : 'both',
		);
	}

	/**
	 * Allowed HTML tags for SVG icon sanitisation.
	 *
	 * Delegates to IconService, which owns THE one allowlist a category icon is
	 * measured against. Keeping a second copy here is what let the two drift: this
	 * list permitted <g> but not <polygon>, while the render side permitted
	 * <polygon> but not <g>, so an ordinary star or triangle icon was silently
	 * stripped to an empty <svg> on save and a <g>-wrapped one lost its grouping on
	 * render. One list, both directions.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function allowed_svg_tags(): array {
		return \BuddyNext\Core\IconService::category_icon_allowed_tags();
	}
}
