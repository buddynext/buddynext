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
	public function test_stamp_dual_writes_table_and_meta(): void {
		$uid = self::factory()->user->create();

		$wrote = ( new PresenceService() )->stamp( $uid );

		$this->assertTrue( $wrote, 'First stamp should write.' );

		$ts = PresenceService::last_active_at( $uid );
		$this->assertGreaterThan( 0, $ts, 'bn_presence row must exist after stamp.' );
		$this->assertEqualsWithDelta( time(), $ts, 5, 'Presence timestamp is ~now.' );

		// Legacy meta still written so unmigrated readers keep working (stage 1).
		$this->assertSame( (string) $ts, (string) get_user_meta( $uid, PresenceService::META_KEY, true ), 'Dual-write: meta must match.' );

		$this->assertTrue( PresenceService::is_online( $uid ) );
		$this->assertContains( $uid, PresenceService::online_ids() );
		$this->assertGreaterThanOrEqual( 1, PresenceService::online_count() );
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
