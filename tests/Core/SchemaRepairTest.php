<?php
/**
 * Tools > Repair sees a missing column and puts it back.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Tests\Support\RemovesCommittedRows;

/**
 * An interrupted update or a partial restore can leave a BuddyNext table without
 * a column. missing_columns() must name it, and Installer::run() (what the
 * "Check and repair database" button calls) must add it back.
 *
 * ALTER is used rather than DROP TABLE: the harness rewrites DROP/CREATE TABLE to
 * TEMPORARY variants (see SchemaFailureIsNotSuccessTest), ALTER reaches the real table.
 *
 * @covers \BuddyNext\Core\Installer::missing_columns
 */
class SchemaRepairTest extends \WP_UnitTestCase {

	use RemovesCommittedRows;

	/**
	 * The ALTER below commits; note where every table stands first.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->note_committed_rows();
	}

	/**
	 * Remove what the ALTER committed, after the rollback.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		parent::tear_down();
		$this->remove_committed_rows();
	}

	/**
	 * A dropped column is reported, then restored by the repair.
	 *
	 * @return void
	 */
	public function test_missing_column_is_reported_and_repaired(): void {
		global $wpdb;
		$registration = get_option( 'users_can_register' );
		Installer::run();
		$this->assertArrayNotHasKey( 'bn_email_templates', Installer::missing_columns(), 'Healthy schema.' );

		$wpdb->query( "ALTER TABLE {$wpdb->prefix}bn_email_templates DROP COLUMN preview_text" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( array( 'preview_text' ), Installer::missing_columns()['bn_email_templates'] ?? array() );

		// The repair button calls run(); under the harness run() skips convergence
		// when no table is missing (see install_schema()), so force it here.
		Installer::install_schema( true );
		$this->assertArrayNotHasKey( 'bn_email_templates', Installer::missing_columns(), 'Repair re-adds the column.' );
		update_option( 'users_can_register', $registration );
	}
}
