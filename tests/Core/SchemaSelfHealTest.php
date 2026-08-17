<?php
/**
 * A stamped schema version is not evidence that the tables exist.
 *
 * `Installer::run()` stamps `buddynext_schema_version` unconditionally, AFTER an
 * `install_schema()` that runs dbDelta with errors suppressed. So a creation that
 * failed still records success, and `maybe_upgrade()` — which returned as soon as
 * the stored number matched the constant — then trusted that number forever.
 *
 * The reported end state was a site advertising schema 40 with zero `bn_*` tables,
 * where publishing a post answered HTTP 201 and wrote nothing, for every member.
 *
 * Worth being precise about who is affected, because "new install or upgrade?" is
 * the natural question and the answer is neither, exactly. A healthy install and a
 * healthy upgrade are both fine. The broken state is reachable whenever the option
 * and reality disagree: dbDelta failing silently on either path (permissions,
 * max_allowed_packet, encoding, a restricted host), a database restore or clone
 * that carried wp_options but not the bn_* tables, or a manual drop. The defect is
 * that the plugin could never notice or recover.
 *
 * DDL note: these tests DROP and recreate a real table. MySQL commits implicitly on
 * DDL, which breaks WP_UnitTestCase's transaction rollback, so teardown restores the
 * schema unconditionally rather than relying on it.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;

/**
 * maybe_upgrade() must verify the schema, not just the version number.
 *
 * @covers \BuddyNext\Core\Installer::maybe_upgrade
 */
class SchemaSelfHealTest extends \WP_UnitTestCase {

	/**
	 * Table used as the canary. Any owned table would do.
	 *
	 * @var string
	 */
	private string $table = '';

	/**
	 * Resolve the prefixed table name.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wpdb;
		parent::setUp();

		$this->table = $wpdb->prefix . 'bn_posts';
		Installer::flush_schema_check();
	}

	/**
	 * Always leave the schema whole, however the test ended.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Installer::flush_schema_check();

		if ( ! $this->table_exists() ) {
			$this->with_real_ddl( static fn() => Installer::run() );
		}

		Installer::flush_schema_check();
		parent::tearDown();
	}

	/**
	 * Whether the canary table is present.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$this->table
			)
		) > 0;
	}

	/**
	 * Drop the canary table, leaving the version option claiming success.
	 *
	 * @return void
	 */
	private function break_the_schema(): void {
		global $wpdb;

		$this->with_real_ddl(
			static function () use ( $wpdb ): void {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bn_posts' );
			}
		);

		Installer::flush_schema_check();

		$this->assertFalse( $this->table_exists(), 'precondition: the table must really be gone' );
	}

	/**
	 * Run a callback with WordPress's temporary-table rewriting switched off.
	 *
	 * WP_UnitTestCase filters `query` to turn CREATE TABLE into CREATE TEMPORARY
	 * TABLE and DROP TABLE into DROP TEMPORARY TABLE, so each test can be rolled
	 * back. That makes real DDL impossible: a plain DROP silently targets a
	 * temporary table that was never created, returns true, and leaves the real
	 * table standing — which is exactly what happened on the first run of this test.
	 * Temporary tables are also invisible to information_schema, so the installer's
	 * re-creation would not be observable either.
	 *
	 * These tests are about real tables existing or not, so the rewriting has to
	 * come off for the duration. tearDown() restores the schema unconditionally,
	 * because DDL commits implicitly and the surrounding rollback cannot.
	 *
	 * @param callable $fn Work to run against the real schema.
	 * @return void
	 */
	private function with_real_ddl( callable $fn ): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		try {
			$fn();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	// ── The complaint ────────────────────────────────────────────────────────────

	/**
	 * A missing table is recreated even though the version says the work is done.
	 *
	 * @return void
	 */
	public function test_a_missing_table_is_recreated_despite_a_current_version(): void {
		$this->break_the_schema();

		// Exactly the state that was reported: the option insists the schema is
		// current while the table behind it does not exist.
		update_option( 'buddynext_schema_version', $this->current_schema_version() );

		$this->with_real_ddl( static fn() => Installer::maybe_upgrade() );

		$this->assertTrue(
			$this->table_exists(),
			'maybe_upgrade() trusted the version number and returned, so a site whose tables never '
			. 'got created could never recover: every load skipped the installer and every post '
			. 'silently wrote nothing.'
		);
	}

	/**
	 * An intact schema is left alone.
	 *
	 * The guard against "fixing" this by re-running the installer on every admin
	 * page load, which would be a dbDelta pass per request.
	 *
	 * @return void
	 */
	public function test_an_intact_schema_is_not_reinstalled(): void {
		update_option( 'buddynext_schema_version', $this->current_schema_version() );

		$queries_before = get_num_queries();
		Installer::maybe_upgrade();
		$queries_after = get_num_queries();

		$this->assertLessThan(
			20,
			$queries_after - $queries_before,
			'A healthy site must stay a cheap no-op. Re-running dbDelta for every owned table on '
			. 'every admin_init would be a real cost paid by every site to protect the rare broken one.'
		);
		$this->assertTrue( $this->table_exists() );
	}

	/**
	 * The check is re-asked after the installer runs, not cached stale.
	 *
	 * Without the flush, a request that healed the schema would still believe it was
	 * broken, and the next caller in that same request would reinstall again.
	 *
	 * @return void
	 */
	public function test_the_check_is_refreshed_after_the_installer_runs(): void {
		$this->break_the_schema();
		update_option( 'buddynext_schema_version', $this->current_schema_version() );

		$this->with_real_ddl( static fn() => Installer::maybe_upgrade() );
		$first = get_num_queries();

		// Second call in the same request: the schema is whole now, so this must be
		// the cheap path.
		$this->with_real_ddl( static fn() => Installer::maybe_upgrade() );
		$second = get_num_queries();

		$this->assertLessThan(
			20,
			$second - $first,
			'the second call in the same request must see the healed schema, not a stale "broken"'
		);
	}

	/**
	 * Read SCHEMA_VERSION without widening its visibility.
	 *
	 * @return int
	 */
	private function current_schema_version(): int {
		$ref = new \ReflectionClass( Installer::class );

		return (int) $ref->getConstant( 'SCHEMA_VERSION' );
	}
}
