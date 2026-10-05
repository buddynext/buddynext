<?php
/**
 * Report tests that leave committed rows behind.
 *
 * Every test runs in a transaction that tear_down rolls back. MySQL commits
 * implicitly on ALTER TABLE (and other DDL WP_UnitTestCase does not rewrite to
 * a temporary table), so whatever a test wrote BEFORE such a statement
 * survives the rollback and leaks into every later test. With random order the
 * suite then disagrees with itself run to run (card 10369216907).
 *
 * After each test this counts the rows of every BuddyNext table plus the core
 * user/post tables in one query; a table that grew since the previous test
 * names that test. BN_LEAK_GUARD=strict turns the report into a failed run.
 *
 * @package BuddyNext\Tests\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Support;

use PHPUnit\Runner\AfterLastTestHook;
use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeFirstTestHook;

/**
 * Leaked-row guard.
 */
final class LeakGuard implements BeforeFirstTestHook, AfterTestHook, AfterLastTestHook {

	/**
	 * Row counts after the previous test: table => rows.
	 *
	 * @var array<string,int>|null
	 */
	private ?array $last = null;

	/**
	 * Leaks found: test => [ table => +rows ].
	 *
	 * @var array<string,array<string,int>>
	 */
	private array $leaks = array();

	/**
	 * Baseline before the first test, so a leak in the first test is caught too.
	 *
	 * @return void
	 */
	public function executeBeforeFirstTest(): void {
		$this->last = $this->counts();
	}

	/**
	 * Count rows after a test (its transaction is rolled back by now).
	 *
	 * @param string $test Test name.
	 * @param float  $time Duration.
	 * @return void
	 */
	public function executeAfterTest( string $test, float $time ): void {
		$now = $this->counts();
		if ( null !== $this->last ) {
			foreach ( $now as $table => $rows ) {
				$grew = $rows - ( $this->last[ $table ] ?? 0 );
				if ( $grew > 0 ) {
					$this->leaks[ $test ][ $table ] = $grew;
				}
			}
		}
		$this->last = $now;
	}

	/**
	 * Print the report; fail the run in strict mode.
	 *
	 * @return void
	 */
	public function executeAfterLastTest(): void {
		if ( ! $this->leaks ) {
			fwrite( STDERR, "\nLeakGuard: no test left committed rows behind.\n" );
			return;
		}
		$out = "\nLeakGuard: these tests left committed rows behind (DDL inside a test commits what came before it):\n";
		foreach ( $this->leaks as $test => $tables ) {
			$parts = array();
			foreach ( $tables as $table => $n ) {
				$parts[] = "{$table} +{$n}";
			}
			$out .= "  {$test}: " . implode( ', ', $parts ) . "\n";
		}
		fwrite( STDERR, $out );
		if ( 'strict' === getenv( 'BN_LEAK_GUARD' ) ) {
			// Fail the run once PHPUnit has printed its own summary, not before it.
			register_shutdown_function( static fn() => exit( 1 ) );
		}
	}

	/**
	 * Row count of every BuddyNext table and the core user/post tables, in one query.
	 *
	 * @return array<string,int>
	 */
	private function counts(): array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}
		static $tables = null;
		if ( null === $tables ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'bn_' ) . '%' ) );
			array_push( $tables, $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta );
		}
		if ( ! $tables ) {
			return array();
		}
		$selects = array();
		foreach ( $tables as $t ) {
			$selects[] = "SELECT '" . esc_sql( $t ) . "' AS t, COUNT(*) AS n FROM `" . esc_sql( $t ) . '`';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table names from SHOW TABLES, escaped.
		$rows = (array) $wpdb->get_results( implode( ' UNION ALL ', $selects ), ARRAY_A );
		// WP_UnitTestCase leaves autocommit off after its rollback, so the SELECT
		// above opened a transaction holding read locks on every counted table. End
		// it, or a process-isolated test's installer (DROP TABLE) waits forever.
		// ROLLBACK, not COMMIT: the guard must never be what commits a row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['t'] ] = (int) $r['n'];
		}
		return $out;
	}
}
