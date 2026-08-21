<?php
/**
 * A schema the server refused must not be recorded as installed.
 *
 * `install_schema()` looped `dbDelta()` inside `suppress_errors( true )` and never
 * looked at the result; `run()` then stamped `buddynext_schema_version`
 * unconditionally. A table that failed to create was therefore recorded as present.
 *
 * The end state reached by a real customer, on MariaDB 5.5: schema version 40, eight
 * of forty-one tables, posting returning HTTP 201 and writing nothing, and no error
 * anywhere in the UI or the log. Support had to reverse-engineer it from the
 * database.
 *
 * Suppressing the ECHO was always right - dbDelta printing HTML into a WP-CLI run or
 * an activation redirect is its own bug. Discarding the RESULT was the mistake, and
 * the two are separable.
 *
 * ## What this file can and cannot reach
 *
 * The WP harness rewrites CREATE TABLE and DROP TABLE into their TEMPORARY variants,
 * so a real CREATE failure cannot be provoked here and `DROP TABLE` on a real table
 * silently does nothing. Learned the hard way on the sibling activation bug, where
 * two tests passed with the bug fully present.
 *
 * So these tests drive the decision points directly - the recorded failure state, the
 * backoff, the reporting - rather than pretending to reproduce a MariaDB 5.5 install.
 *
 * ## One guard is deliberately NOT unit-tested, and it is the headline one
 *
 * "Do not stamp the version when tables are missing" cannot be distinguished from the
 * bug in this harness. On a healthy database nothing is missing, so the guard and an
 * unconditional `true` behave identically - confirmed by mutation, which left every
 * test here green. Provoking a real failure needs a table that will not create, and
 * the harness rewrites the DDL that would arrange one.
 *
 * Two attempts were made and both are instructive rather than salvageable. A filter
 * watching for `CREATE TABLE` matched nothing, because on an established database
 * dbDelta emits DESCRIBE and at most an ALTER. A blunter filter that broke every
 * query naming one table stopped `run()` before it reached the stamp at all, so it
 * proved something other than what it claimed. A test that green-lights the bug is
 * worse than an acknowledged gap, so neither was kept.
 *
 * The end-to-end proof lives with the index fix in this same release: the whole schema
 * built against a COMPACT-row-format server, 39 of 41 tables before and 41 of 41
 * after. That is the condition this guard exists for, exercised against a real server
 * rather than simulated against a cooperative one.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Failed schema creation, and what the site believes afterwards.
 *
 * @covers \BuddyNext\Core\Installer::run
 * @covers \BuddyNext\Core\Installer::missing_tables
 * @covers \BuddyNext\Core\Installer::site_health_schema_test
 */
class SchemaFailureIsNotSuccessTest extends WP_UnitTestCase {

	/**
	 * Clear recorded failure state between tests.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Installer::SCHEMA_FAILURE_OPTION );
	}

	/**
	 * Remove the recorded failure so later tests see a healthy site.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Installer::SCHEMA_FAILURE_OPTION );

		parent::tear_down();
	}

	/**
	 * A complete schema reports nothing missing.
	 *
	 * The control. Without it, every assertion below could pass on a helper that
	 * always returns an empty array.
	 *
	 * @return void
	 */
	public function test_a_healthy_install_reports_no_missing_tables(): void {
		Installer::run();

		$this->assertSame( array(), Installer::missing_tables() );
	}

	/**
	 * Site Health is green when the schema is complete.
	 *
	 * @return void
	 */
	public function test_site_health_is_green_on_a_healthy_install(): void {
		Installer::run();

		$result = Installer::site_health_schema_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'buddynext_schema', $result['test'] );
	}

	/**
	 * What support actually reads is recorded: the tables, and the DB's own error.
	 *
	 * Scoped honestly. This asserts the SHAPE of the recorded failure, not the
	 * critical branch of the Site Health report - that branch needs a genuinely
	 * missing table, and this harness cannot produce one (`DROP TABLE` is rewritten
	 * to `DROP TEMPORARY TABLE` and does nothing to a real table). An earlier version
	 * of this test papered over that with `assertContains( $status, [good, critical] )`,
	 * which accepts both answers and therefore tests nothing.
	 *
	 * The critical branch is covered end to end elsewhere: the index fix in this same
	 * release builds the entire schema against a COMPACT-row-format server and counts
	 * what survives - 39 of 41 before, 41 of 41 after.
	 *
	 * @return void
	 */
	public function test_the_recorded_failure_carries_the_tables_and_the_db_error(): void {
		update_option(
			Installer::SCHEMA_FAILURE_OPTION,
			array(
				'missing'      => array( 'bn_spaces', 'bn_invites' ),
				'errors'       => array( 'wp_bn_spaces' => 'Specified key was too long; max key length is 767 bytes' ),
				'attempted_at' => time(),
				'schema'       => 42,
			),
			false
		);

		$failure = get_option( Installer::SCHEMA_FAILURE_OPTION );

		$this->assertSame(
			array( 'bn_spaces', 'bn_invites' ),
			$failure['missing'],
			'Support needs the table NAMES - a count sends them to a database dump.'
		);
		$this->assertStringContainsString(
			'767 bytes',
			$failure['errors']['wp_bn_spaces'],
			"The database's own error is what identifies the cause; the table name alone does not."
		);
	}

	/**
	 * A recent failure stops the installer re-running on every admin page load.
	 *
	 * With tables missing, `schema_intact()` fails on every request, so the full
	 * installer - 41 dbDelta calls plus every migration - ran again on EVERY
	 * admin_init. On the customer's site that was permanent, and experienced simply
	 * as wp-admin being broken-slow with no clue why.
	 *
	 * @return void
	 */
	public function test_a_recent_failure_backs_off(): void {
		$backoff = new \ReflectionMethod( Installer::class, 'schema_failure_is_recent' );

		$this->assertFalse( $backoff->invoke( null ), 'No recorded failure must not back off.' );

		update_option(
			Installer::SCHEMA_FAILURE_OPTION,
			array( 'attempted_at' => time(), 'schema' => 42, 'missing' => array( 'bn_spaces' ) ),
			false
		);

		$this->assertTrue( $backoff->invoke( null ), 'A failure from seconds ago must not re-run the whole installer.' );
	}

	/**
	 * But it retries once the hour is up, so a fixed server heals itself.
	 *
	 * Time-based rather than a give-up flag on purpose: the fix for this is usually
	 * to change the server, and the plugin should notice without anyone knowing about
	 * a hidden option.
	 *
	 * @return void
	 */
	public function test_it_retries_after_an_hour(): void {
		$backoff = new \ReflectionMethod( Installer::class, 'schema_failure_is_recent' );

		update_option(
			Installer::SCHEMA_FAILURE_OPTION,
			array( 'attempted_at' => time() - ( HOUR_IN_SECONDS + 60 ), 'schema' => 42, 'missing' => array( 'bn_spaces' ) ),
			false
		);

		$this->assertFalse( $backoff->invoke( null ) );
	}

	/**
	 * A schema bump is always retried, however recent the failure.
	 *
	 * The previous attempt answered a different question. Backing off across a
	 * version change would strand a site on the release that fixed its problem -
	 * which is exactly what the index fix in this same release does for the customer
	 * who reported it.
	 *
	 * @return void
	 */
	public function test_a_schema_bump_is_always_retried(): void {
		$backoff = new \ReflectionMethod( Installer::class, 'schema_failure_is_recent' );

		update_option(
			Installer::SCHEMA_FAILURE_OPTION,
			array( 'attempted_at' => time(), 'schema' => 1, 'missing' => array( 'bn_spaces' ) ),
			false
		);

		$this->assertFalse( $backoff->invoke( null ), 'A new schema version must be attempted even right after a failure.' );
	}

	/**
	 * A successful run clears any recorded failure.
	 *
	 * Otherwise the Site Health entry and the backoff outlive the problem, and a
	 * healed site keeps reporting itself broken.
	 *
	 * @return void
	 */
	public function test_a_successful_run_clears_the_recorded_failure(): void {
		update_option(
			Installer::SCHEMA_FAILURE_OPTION,
			array( 'attempted_at' => time(), 'schema' => 42, 'missing' => array( 'bn_spaces' ) ),
			false
		);

		Installer::run();

		$this->assertFalse( get_option( Installer::SCHEMA_FAILURE_OPTION ), 'A healthy run left the failure recorded.' );
	}

	/**
	 * The version is stamped when, and only when, the schema is actually there.
	 *
	 * @return void
	 */
	public function test_the_version_is_stamped_on_a_healthy_install(): void {
		delete_option( 'buddynext_schema_version' );

		Installer::run();

		$this->assertNotEmpty( get_option( 'buddynext_schema_version' ), 'A complete install must record its schema version.' );
	}
}
