<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Pins the indexes on the tables that actually get big.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Index coverage on the high-row-count tables.
 *
 * These assert the exact COLUMN SEQUENCE of each index, not just that a name exists, and
 * that is deliberate. dbDelta compares indexes BY NAME: if someone "widens" an index by
 * adding a column while keeping the same name, dbDelta sees a name it already has and
 * does nothing at all. The schema file says the column is indexed, the database disagrees,
 * and every test that only checked `index_exists( 'object_reactions' )` stays green.
 *
 * That is why the widened reaction index is named `object_recent` and not
 * `object_reactions` — a NEW name is the only thing dbDelta will actually act on. Asserting
 * the column list here is what stops the next person quietly renaming it back.
 *
 * Scope note: these cover BuddyNext's OWN tables. Indexes on `wp_users` (display_name,
 * user_registered) are NOT in scope — that is a WordPress core table, we have no precedent
 * for altering it, and it is a policy call rather than a schema edit.
 *
 * @covers \BuddyNext\Core\Installer
 */
class IndexCoverageTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Map an index name to its ordered column list, for one table.
	 *
	 * @param string $table Unprefixed table name.
	 * @return array<string, string[]> Index name => columns, in key order.
	 */
	private function indexes( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}{$table}", ARRAY_A );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}

		foreach ( $map as $name => $cols ) {
			ksort( $cols );
			$map[ $name ] = array_values( $cols );
		}

		return $map;
	}

	/**
	 * Table bn_reactions — one row per like. The largest table in the product.
	 *
	 * @return void
	 */
	public function test_bn_reactions_indexes(): void {
		$idx = $this->indexes( 'bn_reactions' );

		// "Recent reactions on this object" — ORDER BY created_at DESC. Without created_at
		// in the index this filesorts every reaction on a viral post.
		$this->assertSame(
			array( 'object_type', 'object_id', 'created_at' ),
			$idx['object_recent'] ?? array(),
			'object_recent must carry created_at, or the reaction list filesorts.'
		);

		// The analytics funnel does a BARE range: WHERE created_at BETWEEN x AND y. Its
		// leftmost column is created_at, so object_recent CANNOT serve it — it needs its
		// own index. This is the half the card missed.
		$this->assertSame(
			array( 'created_at' ),
			$idx['reaction_created'] ?? array(),
			'A bare created_at range (analytics funnel) cannot use object_recent. It needs a leading created_at index.'
		);

		// Exact leftmost prefix of object_recent — pure write-amplification on the hottest
		// write path in the product.
		$this->assertArrayNotHasKey(
			'object_reactions',
			$idx,
			'object_reactions is fully contained in object_recent. Keeping both means an extra index write on every single like.'
		);
	}

	/**
	 * Table bn_posts — every scheduled_at query in Free AND Pro also filters on status.
	 *
	 * @return void
	 */
	public function test_bn_posts_indexes(): void {
		$idx = $this->indexes( 'bn_posts' );

		$this->assertSame(
			array( 'status', 'scheduled_at' ),
			$idx['status_scheduled'] ?? array(),
			'No index started with status, so the scheduled-post publisher had nothing to seek on.'
		);

		$this->assertSame(
			array( 'created_at' ),
			$idx['post_created'] ?? array(),
			'The analytics funnel bare-ranges bn_posts.created_at; explore (privacy, created_at) cannot serve it.'
		);

		// Not one query in either repo ranges scheduled_at without also constraining
		// status, so a bare (scheduled_at) index is dead weight on the write path.
		$this->assertArrayNotHasKey(
			'scheduled',
			$idx,
			'Every scheduled_at query also filters status, so status_scheduled supersedes the bare index.'
		);
	}

	/**
	 * Table bn_notification_prefs — ~10 rows per member. The digest scan walks it.
	 *
	 * @return void
	 */
	public function test_bn_notification_prefs_digest_scan_index(): void {
		$idx = $this->indexes( 'bn_notification_prefs' );

		// The digest query is WHERE email_freq = %s AND user_id > %d ORDER BY user_id.
		// The PK (user_id, type) serves the keyset and the ordering, but every row past
		// the cursor still gets read and filtered on email_freq. This index seeks the
		// frequency, then ranges the cursor, already in user_id order.
		$this->assertSame(
			array( 'email_freq', 'user_id' ),
			$idx['digest_scan'] ?? array(),
			'The digest scan re-reads every pref row past the cursor without this index.'
		);
	}

	/**
	 * Table bn_follows — one row per follow.
	 *
	 * @return void
	 */
	public function test_bn_follows_indexes(): void {
		$idx = $this->indexes( 'bn_follows' );

		// The sidebar counts followers gained in 7 days: following_id + a created_at range,
		// and NO status predicate. pending_inbox has status wedged between the two, so
		// created_at cannot be used as a range there — MySQL seeks following_id and then
		// row-filters. On a 100k-follower account that is 100k row reads per render.
		$this->assertSame(
			array( 'following_id', 'created_at' ),
			$idx['follower_recent'] ?? array(),
			'status sits between following_id and created_at in pending_inbox, so it cannot serve a status-less range.'
		);

		$this->assertSame(
			array( 'created_at' ),
			$idx['follow_created'] ?? array(),
			'The analytics funnel bare-ranges bn_follows.created_at.'
		);

		$this->assertSame(
			array( 'following_id', 'status', 'created_at' ),
			$idx['pending_inbox'] ?? array(),
			'pending_inbox is still needed for the status-filtered inbox.'
		);

		// (following_id, status) is the exact leftmost prefix of pending_inbox. It was
		// redundant before this change too; nothing on the card noticed.
		$this->assertArrayNotHasKey(
			'following',
			$idx,
			'following (following_id, status) is the exact leftmost prefix of pending_inbox — it never earned its write cost.'
		);
	}
}
