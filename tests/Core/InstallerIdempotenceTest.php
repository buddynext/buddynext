<?php
/**
 * The schema must converge: a second install_schema() on an up-to-date database
 * must change nothing.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Guards schema convergence.
 *
 * @covers \BuddyNext\Core\Installer::install_schema
 */
class InstallerIdempotenceTest extends WP_UnitTestCase {

	/**
	 * The schema must converge - dbDelta must want to change NOTHING on an
	 * already-installed database.
	 *
	 * This is not a style test. dbDelta extracts a column's type with a regex that
	 * expects exactly ONE space between the column name and the type. Our schema was
	 * column-aligned for readability:
	 *
	 *     follower_id       BIGINT(20) UNSIGNED NOT NULL,
	 *
	 * so dbDelta parsed the desired type as an EMPTY string, concluded that every
	 * column had "changed", and re-issued ALTER TABLE for all 246 of them - on every
	 * single call, forever.
	 *
	 * Two consequences, both severe:
	 *
	 *   PRODUCTION. install_schema() runs on activation and on the upgrade check. On
	 *   a large community that meant 247 ALTER TABLEs against tables holding millions
	 *   of rows (bn_posts, bn_notifications, bn_search_index) - each one a full table
	 *   rebuild, every time. The exact fleet we build for is the one it hurt most.
	 *
	 *   TESTS. DDL forces an implicit COMMIT in MySQL, which ended the transaction
	 *   WP_UnitTestCase relies on for rollback. Rows then survived the test that made
	 *   them and leaked into every later run, permanently. Six Moderation tests were
	 *   failing on nothing but three stale bn_reports rows from some previous run, and
	 *   an audit read those as product defects.
	 *
	 * A suite that cannot roll back cannot be trusted, and a schema that rewrites
	 * itself on every boot cannot be shipped. So this asserts the invariant directly:
	 * apply the schema twice, and the second time must be a no-op.
	 *
	 * @return void
	 */
	public function test_schema_is_idempotent_on_an_installed_database(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		Installer::install_schema();

		$schema = new ReflectionMethod( Installer::class, 'schema' );
		$schema->setAccessible( true );
		$tables = (array) $schema->invoke( null, $wpdb->prefix, $wpdb->get_charset_collate() );

		$this->assertNotEmpty( $tables, 'the schema must actually declare tables' );

		$changes = array();
		foreach ( $tables as $sql ) {
			// dbDelta() with $execute = false reports what it WOULD change.
			$changes = array_merge( $changes, dbDelta( (string) $sql, false ) );
		}

		$this->assertSame(
			array(),
			$changes,
			"install_schema() is not idempotent. dbDelta still wants to change:\n  "
				. implode( "\n  ", array_slice( array_values( $changes ), 0, 12 ) )
				. "\n\nAlmost always the column-name-to-type spacing: dbDelta needs exactly ONE"
				. ' space between a column name and its type. Do not column-align the CREATE TABLE.'
		);
	}
}
