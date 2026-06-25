<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests for the bn_presence migration (change-index item F, stage 1).
 *
 * Stage 1 is additive: PresenceService::stamp() dual-writes the legacy
 * bn_last_active user_meta AND the new indexed bn_presence table, and the new
 * read API (online_ids / online_count / is_online / last_active_at) reads the
 * table. Readers are NOT switched yet (stage 2). These tests pin the additive
 * behaviour so stage 2 can swap readers with confidence.
 *
 * @package BuddyNext\Tests\Realtime
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Realtime;

use BuddyNext\Realtime\PresenceService;
use WP_UnitTestCase;

/**
 * Presence dual-write + indexed read API.
 */
class PresenceServiceTest extends WP_UnitTestCase {

	/**
	 * Clear the bn_presence table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bn_presence" );
	}

	/**
	 * Dual-write: stamp() writes the indexed table AND the legacy meta.
	 *
	 * @return void
	 */
	public function test_stamp_writes_table_only_not_legacy_meta(): void {
		$uid = self::factory()->user->create();

		$wrote = ( new PresenceService() )->stamp( $uid );

		$this->assertTrue( $wrote, 'First stamp should write.' );

		$ts = PresenceService::last_active_at( $uid );
		$this->assertGreaterThan( 0, $ts, 'bn_presence row must exist after stamp.' );
		$this->assertEqualsWithDelta( time(), $ts, 5, 'Presence timestamp is ~now.' );

		// F-phase4: the legacy bn_last_active dual-write is gone — presence lives
		// only in the indexed table now. The meta must NOT be written.
		$this->assertSame( '', (string) get_user_meta( $uid, PresenceService::META_KEY, true ), 'Legacy meta must no longer be written.' );

		$this->assertTrue( PresenceService::is_online( $uid ) );
		$this->assertContains( $uid, PresenceService::online_ids() );
		$this->assertGreaterThanOrEqual( 1, PresenceService::online_count() );
	}

	/**
	 * The v9 migration deletes any legacy bn_last_active user_meta left from a
	 * pre-migration install, while presence still resolves from the table.
	 *
	 * @return void
	 */
	public function test_migration_drops_legacy_meta(): void {
		$uid = self::factory()->user->create();

		// Simulate a pre-migration install: a stale meta row + a real presence row.
		update_user_meta( $uid, PresenceService::META_KEY, time() );
		PresenceService::write( $uid, time() );
		$this->assertNotSame( '', (string) get_user_meta( $uid, PresenceService::META_KEY, true ), 'Seeded legacy meta exists.' );

		// Force the upgrade path to re-run end to end.
		update_option( 'buddynext_schema_version', 8 );
		\BuddyNext\Core\Installer::maybe_upgrade();

		$this->assertSame( '', (string) get_user_meta( $uid, PresenceService::META_KEY, true ), 'Legacy meta deleted by the v9 migration.' );
		$this->assertTrue( PresenceService::is_online( $uid ), 'Presence still resolves from bn_presence after the cleanup.' );
		$this->assertSame( 9, (int) get_option( 'buddynext_schema_version' ), 'Schema version advanced to 9.' );
	}

	/**
	 * Recent-online IDs are the most-recently-active users, newest first,
	 * bounded by the limit and excluding users outside the window.
	 *
	 * @return void
	 */
	public function test_recent_online_ids_is_bounded_and_ordered(): void {
		$old    = self::factory()->user->create();
		$mid    = self::factory()->user->create();
		$newest = self::factory()->user->create();
		$stale  = self::factory()->user->create();

		$now = time();
		PresenceService::write( $old, $now - 200 );
		PresenceService::write( $mid, $now - 100 );
		PresenceService::write( $newest, $now - 1 );
		PresenceService::write( $stale, $now - 10000 ); // Outside the 300s window.

		$top2 = PresenceService::recent_online_ids( 2 );
		$this->assertSame( array( $newest, $mid ), $top2, 'Returns the 2 most-recently-active, newest first.' );
		$this->assertNotContains( $stale, PresenceService::recent_online_ids( 10 ), 'Stale users are excluded.' );
	}

	/**
	 * Upsert via write() only ever advances last_active (GREATEST).
	 *
	 * @return void
	 */
	public function test_write_upsert_only_advances(): void {
		$uid = self::factory()->user->create();

		PresenceService::write( $uid, 1000 );
		PresenceService::write( $uid, 500 ); // Older — must NOT regress.
		$this->assertSame( 1000, PresenceService::last_active_at( $uid ) );

		PresenceService::write( $uid, 2000 ); // Newer — advances.
		$this->assertSame( 2000, PresenceService::last_active_at( $uid ) );
	}

	/**
	 * The online window excludes users whose last activity is outside it.
	 *
	 * @return void
	 */
	public function test_online_window_excludes_stale(): void {
		$fresh = self::factory()->user->create();
		$stale = self::factory()->user->create();

		PresenceService::write( $fresh, time() - 60 );    // 1 min ago — online.
		PresenceService::write( $stale, time() - 600 );   // 10 min ago — offline.

		$ids = PresenceService::online_ids( 300 );
		$this->assertContains( $fresh, $ids );
		$this->assertNotContains( $stale, $ids );
		$this->assertTrue( PresenceService::is_online( $fresh, 300 ) );
		$this->assertFalse( PresenceService::is_online( $stale, 300 ) );
	}

	/**
	 * Throttling: a second stamp() within the window is suppressed; the row persists.
	 *
	 * @return void
	 */
	public function test_stamp_throttled_within_window(): void {
		$uid = self::factory()->user->create();
		$svc = new PresenceService();

		$this->assertTrue( $svc->stamp( $uid ), 'First stamp writes.' );
		$this->assertFalse( $svc->stamp( $uid ), 'Second stamp within 60s is throttled.' );
		$this->assertGreaterThan( 0, PresenceService::last_active_at( $uid ) );
	}

	/**
	 * Invalid user ids are rejected by both stamp() and write().
	 *
	 * @return void
	 */
	public function test_invalid_user_rejected(): void {
		$this->assertFalse( ( new PresenceService() )->stamp( 0 ) );
		PresenceService::write( 0, time() );
		$this->assertSame( 0, PresenceService::last_active_at( 0 ) );
		$this->assertSame( 0, PresenceService::online_count() );
	}
}
