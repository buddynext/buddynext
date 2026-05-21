<?php
/**
 * Tests for Sidebar/WidgetService — caching + data shape.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Core\Installer;
use BuddyNext\Sidebar\WidgetCache;
use BuddyNext\Sidebar\WidgetService;

/**
 * @covers \BuddyNext\Sidebar\WidgetService
 * @covers \BuddyNext\Sidebar\WidgetCache
 */
class WidgetServiceTest extends \WP_UnitTestCase {

	private WidgetCache $cache;
	private WidgetService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->cache   = new WidgetCache();
		$this->service = new WidgetService( $this->cache );
		wp_cache_flush();
	}

	public function tear_down(): void {
		wp_cache_flush();
		parent::tear_down();
	}

	public function test_trending_hashtags_returns_array_even_when_empty(): void {
		$rows = $this->service->trending_hashtags( 5 );
		$this->assertIsArray( $rows );
	}

	public function test_trending_hashtags_caps_limit_to_20(): void {
		$rows = $this->service->trending_hashtags( 9999 );
		$this->assertLessThanOrEqual( 20, count( $rows ) );
	}

	public function test_suggested_follows_returns_empty_for_guest(): void {
		$this->assertSame( array(), $this->service->suggested_follows( 0, 3 ) );
	}

	public function test_joined_spaces_returns_array_for_guest(): void {
		$rows = $this->service->joined_spaces( 0, 4 );
		$this->assertIsArray( $rows );
	}

	public function test_invalidate_trending_bursts_default_limit_key(): void {
		// Seed the cache then invalidate; second call must re-query (we
		// can't easily assert a query count without a hook, but we can
		// verify that wp_cache_get returns false after invalidation).
		$this->service->trending_hashtags( 5 );
		$this->cache->invalidate_trending();
		$found = false;
		wp_cache_get( 'trending:5', WidgetCache::GROUP_GLOBAL, false, $found );
		$this->assertFalse( $found, 'invalidate_trending should evict the default-limit key.' );
	}

	public function test_invalidate_user_bursts_user_keys(): void {
		$uid = self::factory()->user->create();
		$this->service->suggested_follows( $uid, 3 );
		$this->service->joined_spaces( $uid, 4 );
		$this->cache->invalidate_user( $uid );

		$found = false;
		wp_cache_get( 'suggested:' . $uid . ':3', WidgetCache::GROUP_USER, false, $found );
		$this->assertFalse( $found );

		$found = false;
		wp_cache_get( 'spaces:' . $uid . ':4', WidgetCache::GROUP_USER, false, $found );
		$this->assertFalse( $found );
	}
}
