<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Owner-curated featured spaces.
 *
 * Storage is a single site option, `buddynext_featured_spaces`: an ordered array
 * of space ids the site owner highlights, max {@see self::limit()}. It is OWNER
 * DATA, not configuration — it is never a resettable settings field, so "Restore
 * defaults" leaves it alone. The resolver that turns it into per-viewer, visible,
 * ordered space rows (with the auto-join fallback) is
 * {@see SpaceService::featured_spaces()}; this class owns the option + its
 * validation only.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

/**
 * Read/write + validate the featured-spaces option.
 */
class FeaturedSpaces {

	/**
	 * The site option holding the ordered featured space ids.
	 */
	public const OPTION = 'buddynext_featured_spaces';

	/**
	 * Default number of spaces an owner may feature.
	 */
	private const DEFAULT_LIMIT = 6;

	/**
	 * Maximum spaces an owner may feature (filterable, clamped 1-12).
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * Filter the most spaces an owner can feature.
		 *
		 * @since 1.2.1
		 *
		 * @param int $limit Default 6.
		 */
		$limit = (int) apply_filters( 'buddynext_featured_spaces_limit', self::DEFAULT_LIMIT );

		return max( 1, min( 12, $limit ) );
	}

	/**
	 * The stored featured space ids, in the owner's order.
	 *
	 * @return int[]
	 */
	public static function get_ids(): array {
		$ids = get_option( self::OPTION, array() );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Validate + persist a new ordered featured list.
	 *
	 * Keeps only ids that are real, non-archived spaces (prunes deleted/archived,
	 * which is also how a stale id gets cleaned on the next save), de-duplicates
	 * preserving order, and caps at {@see self::limit()}.
	 *
	 * @param int[] $ids Requested ordered space ids.
	 * @return int[] The stored, validated ids.
	 */
	public static function set_ids( array $ids ): array {
		$clean = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		$clean = self::keep_live( $clean );
		$clean = array_slice( $clean, 0, self::limit() );

		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Filter a list of ids down to spaces that still exist and are not archived,
	 * preserving the input order.
	 *
	 * @param int[] $ids Candidate ids.
	 * @return int[]
	 */
	private static function keep_live( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$live = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_spaces WHERE is_archived = 0 AND id IN ({$placeholders})",
				...$ids
			)
		);

		$live = array_map( 'absint', (array) $live );

		// Preserve the caller's order (the SQL IN does not).
		return array_values( array_filter( $ids, static fn( $id ) => in_array( (int) $id, $live, true ) ) );
	}
}
