<?php
/**
 * Clean up rows a schema test commits.
 *
 * A test that really changes the schema (DROP / ALTER, with WordPress's
 * temporary-table rewrite switched off) makes MySQL commit, so whatever the test
 * or the installer it drives wrote survives the rollback (card 10369216907;
 * LeakGuard names such tests). Call note_committed_rows() in set_up() and
 * remove_committed_rows() in tear_down() AFTER parent::tear_down(): every row
 * added since the note, keyed on each table's auto-increment id, is deleted.
 *
 * @package BuddyNext\Tests\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Support;

/**
 * Note-then-remove helper for tests whose DDL commits.
 */
trait RemovesCommittedRows {

	/**
	 * Highest auto-increment id per table at the note: table => [ column, max ].
	 *
	 * @var array<string,array{0:string,1:int}>
	 */
	private array $committed_rows_mark = array();

	/**
	 * Tables keyed by a parent instead of their own id: table => [ column, parent table ].
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private static array $committed_rows_children = array(
		'bn_space_members' => array( 'space_id', 'bn_spaces' ),
	);

	/**
	 * Record where every BuddyNext and core content table stands.
	 *
	 * @return void
	 */
	protected function note_committed_rows(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$keys = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE %s AND ( TABLE_NAME LIKE %s OR TABLE_NAME IN ( %s, %s, %s, %s, %s ) )",
				'%auto_increment%',
				$wpdb->esc_like( $wpdb->prefix . 'bn_' ) . '%',
				$wpdb->users,
				$wpdb->usermeta,
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->options
			),
			ARRAY_A
		);
		$this->committed_rows_mark = array();
		foreach ( $keys as $k ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- names from information_schema.
			$max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(`{$k['c']}`), 0) FROM `{$k['t']}`" );

			$this->committed_rows_mark[ (string) $k['t'] ] = array( (string) $k['c'], $max );
		}
	}

	/**
	 * Delete every row added since note_committed_rows(). Run after the rollback.
	 *
	 * @return void
	 */
	protected function remove_committed_rows(): void {
		global $wpdb;
		foreach ( self::$committed_rows_children as $child => [ $column, $parent ] ) {
			$parent_mark = $this->committed_rows_mark[ $wpdb->prefix . $parent ][1] ?? null;
			if ( null !== $parent_mark ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table/column names.
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->prefix}{$child}` WHERE `{$column}` > %d", $parent_mark ) );
			}
		}
		foreach ( $this->committed_rows_mark as $table => [ $column, $max ] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- names from information_schema.
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE `{$column}` > %d", $max ) );
		}
		// Commit the cleanup: WP_UnitTestCase leaves autocommit off after its
		// rollback, so without this the next ROLLBACK would bring the rows back.
		// Nothing else is pending here; the test's own writes are rolled back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );
		wp_cache_flush();
		$this->committed_rows_mark = array();
	}
}
