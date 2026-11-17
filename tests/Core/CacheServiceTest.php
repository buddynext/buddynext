<?php
/**
 * Tests for the CacheService wp_cache_* wrappers.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CacheService;

/**
 * Verifies that CacheService reads, writes and invalidates each cache key.
 *
 * @covers \BuddyNext\Core\CacheService
 */
class CacheServiceTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var CacheService
	 */
	private CacheService $cache;

	/**
	 * Create a fresh CacheService before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->cache = new CacheService();
	}

	// ── remember() ────────────────────────────────────────────────────────────

	/**
	 * Remember computes and stores value on cache miss.
	 */
	public function test_remember_computes_and_stores_on_miss(): void {
		$called = 0;
		$result = $this->cache->remember(
			'test_key',
			60,
			static function () use ( &$called ): string {
				++$called;
				return 'computed';
			}
		);

		$this->assertSame( 'computed', $result );
		$this->assertSame( 1, $called );
	}

	/**
	 * Remember returns cached value without calling callback.
	 */
	public function test_remember_returns_cached_value_without_calling_callback(): void {
		$this->cache->set( 'test_key2', 'cached', 60 );
		$called = 0;
		$result = $this->cache->remember(
			'test_key2',
			60,
			static function () use ( &$called ): string {
				++$called;
				return 'should-not-be-called';
			}
		);

		$this->assertSame( 'cached', $result );
		$this->assertSame( 0, $called );
	}

	// ── forget_group() ─────────────────────────────────────────────────────────

	/**
	 * Forget group does not throw.
	 */
	public function test_forget_group_does_not_throw(): void {
		$this->cache->set( 'key_a', 'value_a', 60 );
		// forget_group() either flushes (if wp_cache_flush_group exists) or is a no-op.
		// Either way it must not throw.
		$this->cache->forget_group( 'buddynext' );
		$this->addToAssertionCount( 1 ); // explicit assertion that we got here.
	}

	/**
	 * A family salt is stable until a write, then moves, and never comes back after it is lost.
	 */
	public function test_last_changed_salt_moves_on_write_and_never_repeats(): void {
		$first = CacheService::last_changed( 'buddynext_test', 'thread:post:1' );
		$this->assertSame( $first, CacheService::last_changed( 'buddynext_test', 'thread:post:1' ), 'stable between writes' );
		$this->assertNotSame( $first, CacheService::last_changed( 'buddynext_test', 'thread:post:2' ), 'families are independent' );

		$second = CacheService::set_last_changed( 'buddynext_test', 'thread:post:1' );
		$this->assertNotSame( $first, $second, 'a write moves the salt' );
		$this->assertSame( $second, CacheService::last_changed( 'buddynext_test', 'thread:post:1' ) );

		wp_cache_delete( 'last_changed:thread:post:1', 'buddynext_test' );
		$third = CacheService::last_changed( 'buddynext_test', 'thread:post:1' );
		$this->assertNotContains( $third, array( $first, $second ), 'an evicted salt is replaced by a new one, never an old one' );
	}
}
