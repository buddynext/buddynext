<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * §6 index sweep — the keys must exist on the tables, not just in the schema string.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * The missing indexes, added via schema() + dbDelta rather than a migration.
 *
 * There is no hand-written ALTER anywhere: the KEY is declared in schema() and
 * SCHEMA_VERSION is bumped, so Installer::maybe_upgrade() re-runs dbDelta and dbDelta
 * adds the missing index to the EXISTING table. That is the entire mechanism, and this
 * test exists to prove it actually happens — a KEY that only lives in a PHP string is
 * not an index, and every one of these fixes is worthless if dbDelta quietly declines to
 * apply it.
 *
 * The queries these serve, and what they were doing without them:
 *
 *   link_lookup       bn_posts, on the BRIDGE WRITE PATH: "does a post already exist for
 *                     this link" ran with no usable key at all (every key on the table
 *                     leads with user_id/space_id/privacy), so it full-scanned bn_posts
 *                     on every bridged item.
 *   pending_all       bn_space_members, site-wide pending joins: every key leads with
 *                     user_id or space_id, so "all pending joins" full-scanned the
 *                     biggest table in the schema.
 *   reply_lookup      bn_comments: the correlated EXISTS(parent_id = c.id) that decides
 *                     whether a comment has replies. `thread` has parent_id THIRD, which
 *                     is unusable for it.
 *   user_recent       bn_comments / bn_reactions / bn_shares: the profile Replies, Likes
 *                     and Reshares tabs each ORDER BY created_at over a key that has no
 *                     created_at in it — a filesort of the member's entire history.
 *   following_recent  bn_follows: every secondary key leads with following_id, so the
 *                     "who I follow" direction filesorted.
 *   requester_recent  bn_connections: no created_at on any key, so the ordered
 *   recipient_recent  accepted/pending lists filesorted.
 *   type_id           bn_email_log: the filtered admin sort.
 *
 * @covers \BuddyNext\Core\Installer
 */
class IndexSweepTest extends WP_UnitTestCase {

	/**
	 * The keys this sweep adds, per table.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function expected_keys(): array {
		return array(
			'bn_posts'         => array( 'link_lookup' ),
			'bn_space_members' => array( 'pending_all' ),
			'bn_comments'      => array( 'reply_lookup', 'user_recent' ),
			'bn_follows'       => array( 'following_recent' ),
			'bn_reactions'     => array( 'user_recent' ),
			'bn_shares'        => array( 'user_recent' ),
			'bn_connections'   => array( 'requester_recent', 'recipient_recent' ),
			'bn_email_log'     => array( 'type_id' ),
		);
	}

	/**
	 * Index names actually present on a table, read from the database itself.
	 *
	 * @param string $table Unprefixed table name.
	 * @return array<int, string>
	 */
	private function index_names( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}{$table}", ARRAY_A );

		return array_values(
			array_unique(
				array_map(
					static fn( array $r ): string => (string) ( $r['Key_name'] ?? '' ),
					(array) $rows
				)
			)
		);
	}

	/**
	 * Every new key exists on a freshly installed schema.
	 *
	 * @return void
	 */
	public function test_every_new_index_exists_on_a_fresh_install(): void {
		$this->converge_schema_for_real();

		foreach ( $this->expected_keys() as $table => $keys ) {
			$present = $this->index_names( $table );

			foreach ( $keys as $key ) {
				$this->assertContains(
					$key,
					$present,
					"The index {$key} is missing from {$table} on a FRESH install. It is declared in schema() but the table does not have it."
				);
			}
		}
	}

	/**
	 * THE ONE THAT MATTERS: dbDelta adds the index to an EXISTING table.
	 *
	 * A fresh install proves nothing about the ten thousand sites that already have these
	 * tables. Those go through maybe_upgrade() -> run() -> dbDelta, and the whole premise
	 * of doing this without a migration is that dbDelta will ALTER the existing table to
	 * add a key it does not have. So: drop the index, re-run the installer, and require it
	 * back.
	 *
	 * @return void
	 */
	public function test_dbdelta_adds_a_missing_index_to_an_existing_table(): void {
		global $wpdb;

		$this->converge_schema_for_real();

		// Take an index away, exactly as an older site would have it — the table exists,
		// with data, just without this key.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}bn_posts DROP INDEX link_lookup" );

		$this->assertNotContains(
			'link_lookup',
			$this->index_names( 'bn_posts' ),
			'Precondition: the index should be gone after the DROP.'
		);

		// This is what an upgrading site runs.
		$this->converge_schema_for_real();

		$this->assertContains(
			'link_lookup',
			$this->index_names( 'bn_posts' ),
			'dbDelta did NOT add the missing index to an existing table. The whole index sweep depends on this: without a migration, dbDelta is the only thing that upgrades a site that already has these tables. If this fails, every site out there keeps full-scanning bn_posts and only brand-new installs get the fix.'
		);
	}

	/**
	 * The schema revision moved, so upgrading sites actually re-run dbDelta.
	 *
	 * maybe_upgrade() is a no-op when the stored revision already matches. Adding keys
	 * without bumping the version would leave every existing site untouched — the fix
	 * would ship, be correct, and never run.
	 *
	 * @return void
	 */
	public function test_the_schema_version_was_bumped(): void {
		$ref = new \ReflectionClass( Installer::class );

		$this->assertGreaterThanOrEqual(
			34,
			(int) $ref->getConstant( 'SCHEMA_VERSION' ),
			'SCHEMA_VERSION was not bumped. maybe_upgrade() short-circuits when the stored revision matches, so the new indexes would never be applied to any existing site.'
		);
	}

	/**
	 * Converge the schema for real, without leaving temporary shadows behind.
	 *
	 * WP_UnitTestCase installs a `query` filter that rewrites every `CREATE TABLE`
	 * into `CREATE TEMPORARY TABLE`. That is right for fixtures and wrong here: a
	 * temporary table SHADOWS the real one for the rest of this connection, so every
	 * later statement in the process can fail with "Table definition has changed,
	 * please retry transaction". Measured: leaving the filter on turned 9 such errors
	 * into 203.
	 *
	 * These tests exist to prove dbDelta converges the REAL schema, so the filter
	 * comes off for the duration of the call and goes straight back on.
	 *
	 * @return void
	 */
	private function converge_schema_for_real(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\BuddyNext\Core\Installer::install_schema( true );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
	}
}
