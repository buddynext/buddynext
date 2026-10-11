<?php
/**
 * Every table can be created on a host with short index-key limits.
 *
 * MyISAM allows 1000 bytes for a whole key, and a table takes the server's
 * default engine. A utf8mb4 VARCHAR(255) is 1020 bytes, so one unprefixed index
 * on such a column is enough for CREATE TABLE to be refused: the table is never
 * made and the feature behind it is dead (card 10384673862, bn_spaces).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;

/**
 * Schema key-length tests.
 *
 * @covers \BuddyNext\Core\Installer
 */
class SchemaKeyLengthTest extends \WP_UnitTestCase {

	/**
	 * Each CREATE TABLE in the schema succeeds under MyISAM.
	 *
	 * MyISAM's 1000-byte key limit is the tightest one a host can have, and it can
	 * be exercised on any MySQL. The 767-byte InnoDB limit cannot be reproduced on
	 * MySQL 8, where a temporary table ignores ROW_FORMAT; a key that fits MyISAM
	 * with every text column prefixed to 191 or narrower fits that limit too.
	 *
	 * @return void
	 */
	public function test_every_table_is_created_on_myisam(): void {
		$engine = 'ENGINE=MyISAM';
		global $wpdb;

		$schema = new \ReflectionMethod( Installer::class, 'schema' );
		$prefix     = 'bnkeylen_';
		$statements = $schema->invoke( null, $prefix, 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
		$refused    = array();

		$wpdb->suppress_errors( true );
		foreach ( $statements as $sql ) {
			$sql = rtrim( trim( (string) $sql ), ';' ) . ' ' . $engine;
			preg_match( '/CREATE TABLE (\S+)/', $sql, $m );
			$table = $m[1];

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			if ( false === $wpdb->query( $sql ) ) {
				$refused[ $table ] = $wpdb->last_error;
			}
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->suppress_errors( false );

		$this->assertSame( array(), $refused );
	}
}
