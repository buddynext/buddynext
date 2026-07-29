<?php
/**
 * Tests for the shared RateLimiter store.
 *
 * Exercises both backends: the DB-transient fallback (no persistent object
 * cache) and the wp_cache_* path (persistent cache present), toggled via
 * wp_using_ext_object_cache()'s setter form.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\RateLimiter;
use WP_UnitTestCase;

/**
 * Verifies the RateLimiter contract on both storage backends.
 *
 * @covers \BuddyNext\Core\RateLimiter
 */
class RateLimiterTest extends WP_UnitTestCase {

	/**
	 * Restore the object-cache flag after each test so toggling does not leak.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_using_ext_object_cache( false );
		wp_cache_flush();
		parent::tear_down();
	}

	/**
	 * Data provider: run every assertion once per backend.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public function backend_provider(): array {
		return array(
			'transient fallback'      => array( false ),
			'persistent object cache' => array( true ),
		);
	}

	/**
	 * Counts read 0 when absent and reflect the stored value otherwise.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param bool $ext_cache Whether to simulate a persistent object cache.
	 * @return void
	 */
	public function test_count_and_set( bool $ext_cache ): void {
		wp_using_ext_object_cache( $ext_cache );

		$key = 'bn_test_count_' . (int) $ext_cache;
		$this->assertSame( 0, RateLimiter::count( $key ), 'absent key reads as 0' );

		RateLimiter::set( $key, 7, 60 );
		$this->assertSame( 7, RateLimiter::count( $key ), 'set value is read back' );
	}

	/**
	 * Hits increment and return the post-increment count.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param bool $ext_cache Whether to simulate a persistent object cache.
	 * @return void
	 */
	public function test_hit_increments( bool $ext_cache ): void {
		wp_using_ext_object_cache( $ext_cache );

		$key = 'bn_test_hit_' . (int) $ext_cache;
		$this->assertSame( 1, RateLimiter::hit( $key, 60 ), 'first hit returns 1' );
		$this->assertSame( 2, RateLimiter::hit( $key, 60 ), 'second hit returns 2' );
		$this->assertSame( 3, RateLimiter::hit( $key, 60 ), 'third hit returns 3' );
		$this->assertSame( 3, RateLimiter::count( $key ), 'count matches the running total' );
	}

	/**
	 * Markers model a boolean cooldown distinct from a 0 counter.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param bool $ext_cache Whether to simulate a persistent object cache.
	 * @return void
	 */
	public function test_marker( bool $ext_cache ): void {
		wp_using_ext_object_cache( $ext_cache );

		$key = 'bn_test_mark_' . (int) $ext_cache;
		$this->assertFalse( RateLimiter::is_marked( $key ), 'absent cooldown reads as not marked' );

		RateLimiter::mark( $key, 60 );
		$this->assertTrue( RateLimiter::is_marked( $key ), 'mark arms the cooldown' );
	}

	/**
	 * Clearing removes a counter so the next read is back to 0.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param bool $ext_cache Whether to simulate a persistent object cache.
	 * @return void
	 */
	public function test_clear( bool $ext_cache ): void {
		wp_using_ext_object_cache( $ext_cache );

		$key = 'bn_test_clear_' . (int) $ext_cache;
		RateLimiter::hit( $key, 60 );
		$this->assertSame( 1, RateLimiter::count( $key ) );

		RateLimiter::clear( $key );
		$this->assertSame( 0, RateLimiter::count( $key ), 'cleared key reads as 0' );
		$this->assertFalse( RateLimiter::is_marked( $key ), 'cleared key is not marked' );
	}

	/*
	 * ── The concurrency fix ────────────────────────────────────────────────────
	 *
	 * The no-object-cache path used to be get_transient() + 1 then
	 * set_transient(): three steps a concurrent burst races past, because every
	 * request reads the same count before any writes. Measured against this
	 * install, 15 simultaneous wrong-password logins ALL returned 401 and none
	 * tripped the 10-per-15-minutes cap; after the fix exactly 10 passed and 5
	 * were refused with 429.
	 *
	 * PHPUnit is single-threaded, so the race itself was verified with real
	 * concurrent HTTP requests rather than here. What these pin is the counter
	 * contract the fix depends on — a counter that miscounts sequentially cannot
	 * be right concurrently either — plus the window and housekeeping semantics
	 * the new table introduced.
	 */

	/**
	 * Every hit returns a distinct, strictly increasing value. A burst bypasses
	 * a cap precisely by returning the same number twice.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param bool $ext_cache Whether to simulate a persistent object cache.
	 * @return void
	 */
	public function test_hits_never_repeat_a_count( bool $ext_cache ): void {
		wp_using_ext_object_cache( $ext_cache );

		$key  = 'bn_test_seq_' . (int) $ext_cache;
		$seen = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$seen[] = RateLimiter::hit( $key, 60 );
		}

		$this->assertSame( range( 1, 10 ), $seen, 'hit() did not return a strictly increasing sequence.' );
		$this->assertSame( count( $seen ), count( array_unique( $seen ) ), 'Two hits reported the same count.' );

		RateLimiter::clear( $key );
	}

	/**
	 * One key's budget must not throttle another. A shared counter would look
	 * like working rate limiting while locking out innocent members.
	 *
	 * @dataProvider backend_provider
	 *
	 * @param bool $ext_cache Whether to simulate a persistent object cache.
	 * @return void
	 */
	public function test_counters_are_isolated_per_key( bool $ext_cache ): void {
		wp_using_ext_object_cache( $ext_cache );

		$a = 'bn_test_iso_a_' . (int) $ext_cache;
		$b = 'bn_test_iso_b_' . (int) $ext_cache;

		RateLimiter::hit( $a, 60 );
		RateLimiter::hit( $a, 60 );
		RateLimiter::hit( $b, 60 );

		$this->assertSame( 2, RateLimiter::count( $a ) );
		$this->assertSame( 1, RateLimiter::count( $b ) );

		RateLimiter::clear( $a );
		RateLimiter::clear( $b );
	}

	/**
	 * A lapsed window RESTARTS rather than continuing. Without the reset, a
	 * member whose window expired would be refused on their first attempt of the
	 * next one — the counter would only ever climb.
	 *
	 * Table-backed path only; the object cache expires the key by TTL instead.
	 *
	 * @return void
	 */
	public function test_an_expired_window_restarts_the_count(): void {
		global $wpdb;

		wp_using_ext_object_cache( false );

		$key = 'bn_test_window_' . wp_generate_password( 8, false );
		RateLimiter::hit( $key, 60 );
		RateLimiter::hit( $key, 60 );
		$this->assertSame( 2, RateLimiter::count( $key ) );

		// Age the row past its window instead of sleeping.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_rate_limits SET expires_at = %s WHERE rl_key = %s",
				gmdate( 'Y-m-d H:i:s', time() - 10 ),
				$key
			)
		);

		$this->assertSame( 0, RateLimiter::count( $key ), 'An expired window still reported its old count.' );
		$this->assertSame( 1, RateLimiter::hit( $key, 60 ), 'The first hit of a fresh window did not restart at 1.' );

		RateLimiter::clear( $key );
	}

	/**
	 * Housekeeping removes expired rows and keeps live ones. Purging a live
	 * counter would hand an attacker a fresh budget every time cron ran.
	 *
	 * @return void
	 */
	public function test_purging_keeps_live_counters(): void {
		global $wpdb;

		wp_using_ext_object_cache( false );

		$live  = 'bn_test_live_' . wp_generate_password( 8, false );
		$stale = 'bn_test_stale_' . wp_generate_password( 8, false );

		RateLimiter::hit( $live, 600 );
		RateLimiter::hit( $stale, 600 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_rate_limits SET expires_at = %s WHERE rl_key = %s",
				gmdate( 'Y-m-d H:i:s', time() - 10 ),
				$stale
			)
		);

		RateLimiter::purge_expired();

		$this->assertSame( 1, RateLimiter::count( $live ), 'Purging removed a live counter.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_rate_limits WHERE rl_key = %s", $stale )
		);
		$this->assertSame( 0, $rows, 'An expired row survived the purge.' );

		RateLimiter::clear( $live );
	}
}
