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
}
