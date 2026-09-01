<?php
/**
 * A fixture that omits a UNIQUE column is a flaky test waiting to happen.
 *
 * `WP_UnitTestCase` wraps each test in a transaction and rolls it back, which is
 * usually enough to keep fixtures from seeing each other. It is not enough here:
 * the installer runs DDL, and DDL forces an implicit COMMIT, so rows written
 * before it survive the rollback. What is left in a table at the start of a test
 * therefore depends on which tests ran before it.
 *
 * That only becomes a FAILURE when a fixture collides with what survived. Insert
 * into `bn_spaces` without a slug and the row takes the empty string; the table
 * carries `UNIQUE KEY slug`, so the first such row wins and every later one is
 * rejected:
 *
 *   Duplicate entry '' for key 'wptests_bn_spaces.slug'
 *
 * Whether the test then passes depends on what happened to survive. Running
 * `tests/Admin` twice gave 2 failures and then OK. That was the confirmed
 * mechanism behind "the suite does not give the same answer twice"
 * (card 10228094255), alongside the installer DDL itself and unreset statics.
 *
 * ## Why a scanner rather than a fixed list
 *
 * The four offending inserts are fixed. A list of them would not stop the fifth.
 * This reads the UNIQUE KEYs out of `Installer.php` and every direct
 * `$wpdb->insert()` out of `tests/`, and fails when a fixture omits a column the
 * schema says must be unique — so a new fixture written next year is a failing
 * test rather than a new flake, and the schema stays the single source of truth.
 *
 * ## Scope, stated honestly
 *
 * This catches DIRECT `$wpdb->insert()` calls against a `bn_*` table with a
 * UNIQUE key. It does not catch fixtures built through a service, raw SQL, or
 * `$wpdb->query()` — those go through code that supplies its own keys, which is
 * the reason to prefer them. It is a floor, not a proof.
 *
 * @package BuddyNext\Tests\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Support;

use WP_UnitTestCase;

/**
 * Fixture inserts must supply every UNIQUE column.
 *
 * @coversNothing
 */
class FixtureUniqueKeyTest extends WP_UnitTestCase {

	/**
	 * Absolute path to the plugin root.
	 *
	 * @return string
	 */
	private function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Read table => UNIQUE column names out of the installer's CREATE TABLE blocks.
	 *
	 * Prefix lengths (`slug(191)`) are stripped, so the column name is what comes
	 * back — the schema writes the constraint, this only has to name it.
	 *
	 * @return array<string, string[]>
	 */
	private function unique_columns_by_table(): array {
		$src    = (string) file_get_contents( $this->plugin_root() . '/includes/Core/Installer.php' );
		$blocks = array_slice( preg_split( '/CREATE TABLE/', $src ) ?: array(), 1 );

		$tables = array();
		foreach ( $blocks as $block ) {
			if ( ! preg_match( '/(bn_[a-z_]+)/', substr( $block, 0, 200 ), $name ) ) {
				continue;
			}

			$end  = strpos( $block, ') $charset' );
			$body = false !== $end ? substr( $block, 0, $end ) : substr( $block, 0, 6000 );

			preg_match_all( '/UNIQUE KEY\s+\S+\s*\((.+?)\),?\s*\n/', $body, $keys );
			foreach ( $keys[1] as $key ) {
				foreach ( explode( ',', (string) preg_replace( '/\(\s*\d+\s*\)/', '', $key ) ) as $column ) {
					$column = trim( $column, " \t)" );
					if ( '' !== $column && preg_match( '/^\w+$/', $column ) ) {
						$tables[ $name[1] ][ $column ] = $column;
					}
				}
			}
		}

		return array_map( 'array_values', $tables );
	}

	/**
	 * Every test file under tests/.
	 *
	 * @return string[]
	 */
	private function test_files(): array {
		$found = array();
		$dir   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->plugin_root() . '/tests' ) );
		foreach ( $dir as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$found[] = $file->getPathname();
			}
		}

		return $found;
	}

	/**
	 * No fixture inserts into a bn_* table without the columns its UNIQUE keys name.
	 *
	 * @return void
	 */
	public function test_no_fixture_omits_a_unique_column(): void {
		$tables = $this->unique_columns_by_table();

		$this->assertNotEmpty( $tables, 'Read no UNIQUE keys out of Installer.php - the scanner is broken, not the fixtures.' );
		$this->assertArrayHasKey( 'bn_spaces', $tables, 'bn_spaces should report a UNIQUE key; the parser has drifted from the schema.' );

		// Matches `$wpdb->insert(` even with a trailing phpcs comment, then the
		// prefixed table name and the argument array.
		$pattern = '/\$wpdb->insert\([^\n]*\n\s*\$wpdb->prefix\s*\.\s*\'(bn_[a-z_]+)\'\s*,\s*array\(\s*\n(.*?)\n\s*\)\s*\n?\s*\);/s';

		$offenders = array();
		foreach ( $this->test_files() as $file ) {
			$contents = (string) file_get_contents( $file );
			if ( ! preg_match_all( $pattern, $contents, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$table = $match[1];
				if ( ! isset( $tables[ $table ] ) ) {
					continue;
				}

				preg_match_all( '/\'(\w+)\'\s*=>/', $match[2], $keys );
				$missing = array_diff( $tables[ $table ], $keys[1] );

				if ( array() !== $missing ) {
					$offenders[] = sprintf(
						'%s -> %s missing %s',
						str_replace( $this->plugin_root() . '/', '', $file ),
						$table,
						implode( ', ', $missing )
					);
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A fixture inserts a row without a column the schema requires to be unique.\n"
				. "Rows like that all take the same default and collide with whatever an earlier\n"
				. "test left behind, which passes or fails depending on run order:\n\n  "
				. implode( "\n  ", $offenders )
		);
	}
}
