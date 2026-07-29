<?php
/**
 * Sidebar invalidation has to clear the key the service actually reads.
 *
 * invalidate_user() deleted `suggested-v2:{user}:3` while suggested_follows()
 * cached under `suggested-pool-v3:{user}:12`. Nothing failed: wp_cache_delete()
 * on a key that does not exist is a successful no-op, so following or blocking
 * someone cleared nothing and they kept appearing in Suggested People until the
 * 300s TTL expired. On the default non-persistent object cache it is invisible
 * (the cache dies with the request); on any site running Redis or Memcached it
 * is a five-minute stale widget after every follow, unfollow and block.
 *
 * Two mismatches at once is what hid it: the prefix was stale AND the suffix was
 * the display limit where the real key carries the derived POOL size.
 *
 * These tests assert the INVARIANT - "after invalidation, the entry the service
 * reads is gone" - rather than any literal key string, so they keep holding
 * through the next rename, which is precisely the event that broke it.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Sidebar\WidgetCache;
use BuddyNext\Sidebar\WidgetService;

/**
 * Cache key agreement between the read path and the invalidation path.
 *
 * @covers \BuddyNext\Sidebar\WidgetCache::invalidate_user
 * @covers \BuddyNext\Sidebar\WidgetCache::key_suggested
 */
class WidgetCacheInvalidationTest extends \WP_UnitTestCase {

	/**
	 * Viewer whose sidebar is cached.
	 *
	 * @var int
	 */
	private $viewer = 0;

	/**
	 * Cache layer under test.
	 *
	 * @var WidgetCache
	 */
	private $cache;

	/**
	 * Service that populates the cache.
	 *
	 * @var WidgetService
	 */
	private $service;

	/**
	 * A viewer and a few other members to suggest.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->viewer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		for ( $i = 0; $i < 8; $i++ ) {
			self::factory()->user->create( array( 'role' => 'subscriber' ) );
		}

		$this->cache   = new WidgetCache();
		$this->service = new WidgetService( $this->cache );
	}

	/**
	 * Is the suggested-follows entry the SERVICE reads currently present?
	 *
	 * Asks through the same key builder the service uses, so this cannot drift
	 * from the thing under test.
	 *
	 * @param int $limit Display limit.
	 * @return bool
	 */
	private function suggested_cached( int $limit = 3 ): bool {
		$found = false;
		wp_cache_get( WidgetCache::key_suggested( $this->viewer, $limit ), WidgetCache::GROUP_USER, false, $found );
		return (bool) $found;
	}

	/**
	 * Baseline: reading the widget populates the cache. Without this the
	 * invalidation assertions below could pass against an entry that was never
	 * written in the first place.
	 *
	 * @return void
	 */
	public function test_reading_suggested_follows_populates_the_cache(): void {
		$this->service->suggested_follows( $this->viewer, 3 );

		$this->assertTrue(
			$this->suggested_cached(),
			'suggested_follows() did not cache anything, so this test proves nothing about invalidation.'
		);
	}

	/**
	 * The regression: invalidation must clear the entry the service reads.
	 *
	 * @return void
	 */
	public function test_invalidate_user_clears_the_suggested_follows_entry(): void {
		$this->service->suggested_follows( $this->viewer, 3 );
		$this->assertTrue( $this->suggested_cached(), 'Cache was not warm before invalidation.' );

		$this->cache->invalidate_user( $this->viewer );

		$this->assertFalse(
			$this->suggested_cached(),
			'invalidate_user() left the suggested-follows entry in place - it is deleting a key nothing reads.'
		);
	}

	/**
	 * The specific shape of the bug: the key the old code deleted is NOT the key
	 * the service uses. Deleting the v2 key must leave the real entry untouched.
	 *
	 * This is the mutation guard. Without it the test above would still pass if
	 * someone "fixed" invalidation by deleting both the old and new keys, leaving
	 * the underlying two-sources-of-truth problem in place.
	 *
	 * @return void
	 */
	public function test_the_old_v2_key_was_never_the_one_in_use(): void {
		$this->service->suggested_follows( $this->viewer, 3 );

		wp_cache_delete( 'suggested-v2:' . $this->viewer . ':3', WidgetCache::GROUP_USER );

		$this->assertTrue(
			$this->suggested_cached(),
			'The legacy v2 key still addresses the live entry, so the premise of this fix is wrong.'
		);
	}

	/**
	 * The key is keyed by POOL size, not display limit - the second half of the
	 * mismatch. A key built from the raw limit must not address the entry.
	 *
	 * @return void
	 */
	public function test_the_key_carries_the_pool_size_not_the_display_limit(): void {
		$this->assertSame( 12, WidgetCache::pool_size( 3 ), 'Pool derivation changed; the key suffix changes with it.' );

		$this->service->suggested_follows( $this->viewer, 3 );

		wp_cache_delete( 'suggested-pool-v3:' . $this->viewer . ':3', WidgetCache::GROUP_USER );

		$this->assertTrue(
			$this->suggested_cached(),
			'A display-limit-suffixed key addressed the entry; the pool indirection is not what it claims.'
		);
	}

	/**
	 * The joined-spaces entry is invalidated by the same call. It was already
	 * correct before this fix, so this guards against the refactor breaking the
	 * key that was working.
	 *
	 * @return void
	 */
	public function test_invalidate_user_also_clears_joined_spaces(): void {
		$this->service->joined_spaces( $this->viewer, 4 );

		$found = false;
		wp_cache_get( WidgetCache::key_spaces( $this->viewer, 4 ), WidgetCache::GROUP_USER, false, $found );
		$this->assertTrue( (bool) $found, 'joined_spaces() did not cache.' );

		$this->cache->invalidate_user( $this->viewer );

		$found = false;
		wp_cache_get( WidgetCache::key_spaces( $this->viewer, 4 ), WidgetCache::GROUP_USER, false, $found );
		$this->assertFalse( (bool) $found, 'The joined-spaces key stopped being invalidated.' );
	}

	/**
	 * Invalidating one member must not clear another's sidebar.
	 *
	 * @return void
	 */
	public function test_invalidation_is_scoped_to_the_one_member(): void {
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->service->suggested_follows( $this->viewer, 3 );
		$this->service->suggested_follows( $other, 3 );

		$this->cache->invalidate_user( $this->viewer );

		$found = false;
		wp_cache_get( WidgetCache::key_suggested( $other, 3 ), WidgetCache::GROUP_USER, false, $found );
		$this->assertTrue( (bool) $found, 'Invalidating one member cleared another member\'s cached sidebar.' );
	}
}
