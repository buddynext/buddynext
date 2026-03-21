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

	// ── Notification count ────────────────────────────────────────────────────

	/**
	 * Notification count returns null when not cached.
	 */
	public function test_get_notification_count_returns_null_when_missing(): void {
		$this->assertNull( $this->cache->get_notification_count( 99 ) );
	}

	/**
	 * Notification count can be stored and retrieved.
	 */
	public function test_set_and_get_notification_count(): void {
		$this->cache->set_notification_count( 1, 5 );
		$this->assertSame( 5, $this->cache->get_notification_count( 1 ) );
	}

	/**
	 * Invalidating notification count removes the cached value.
	 */
	public function test_invalidate_notification_count_removes_value(): void {
		$this->cache->set_notification_count( 1, 5 );
		$this->cache->invalidate_notification_count( 1 );
		$this->assertNull( $this->cache->get_notification_count( 1 ) );
	}

	// ── Trending hashtags ─────────────────────────────────────────────────────

	/**
	 * Trending hashtags return null when not cached.
	 */
	public function test_get_trending_hashtags_returns_null_when_missing(): void {
		$this->assertNull( $this->cache->get_trending_hashtags() );
	}

	/**
	 * Trending hashtags can be stored and retrieved.
	 */
	public function test_set_and_get_trending_hashtags(): void {
		$tags = array( array( 'id' => 1, 'name' => 'php' ) );
		$this->cache->set_trending_hashtags( $tags );
		$this->assertSame( $tags, $this->cache->get_trending_hashtags() );
	}

	/**
	 * Invalidating trending hashtags removes the cached value.
	 */
	public function test_invalidate_trending_hashtags(): void {
		$this->cache->set_trending_hashtags( array( 'x' ) );
		$this->cache->invalidate_trending_hashtags();
		$this->assertNull( $this->cache->get_trending_hashtags() );
	}

	// ── Space member count ────────────────────────────────────────────────────

	/**
	 * Space member count returns null when not cached.
	 */
	public function test_get_space_member_count_returns_null_when_missing(): void {
		$this->assertNull( $this->cache->get_space_member_count( 7 ) );
	}

	/**
	 * Space member count can be stored and retrieved.
	 */
	public function test_set_and_get_space_member_count(): void {
		$this->cache->set_space_member_count( 7, 42 );
		$this->assertSame( 42, $this->cache->get_space_member_count( 7 ) );
	}

	/**
	 * Invalidating space member count removes the cached value.
	 */
	public function test_invalidate_space_member_count(): void {
		$this->cache->set_space_member_count( 7, 42 );
		$this->cache->invalidate_space_member_count( 7 );
		$this->assertNull( $this->cache->get_space_member_count( 7 ) );
	}

	// ── Follow counts ─────────────────────────────────────────────────────────

	/**
	 * Follow counts return null when not cached.
	 */
	public function test_get_follow_counts_returns_null_when_missing(): void {
		$this->assertNull( $this->cache->get_follow_counts( 3 ) );
	}

	/**
	 * Follow counts can be stored and retrieved.
	 */
	public function test_set_and_get_follow_counts(): void {
		$counts = array( 'followers' => 10, 'following' => 5 );
		$this->cache->set_follow_counts( 3, $counts );
		$this->assertSame( $counts, $this->cache->get_follow_counts( 3 ) );
	}

	/**
	 * Invalidating follow counts removes the cached value.
	 */
	public function test_invalidate_follow_counts(): void {
		$this->cache->set_follow_counts( 3, array( 'followers' => 1, 'following' => 1 ) );
		$this->cache->invalidate_follow_counts( 3 );
		$this->assertNull( $this->cache->get_follow_counts( 3 ) );
	}

	// ── User abilities ────────────────────────────────────────────────────────

	/**
	 * User abilities return null when not cached.
	 */
	public function test_get_user_abilities_returns_null_when_missing(): void {
		$this->assertNull( $this->cache->get_user_abilities( 2 ) );
	}

	/**
	 * User abilities can be stored and retrieved.
	 */
	public function test_set_and_get_user_abilities(): void {
		$abilities = array( 'buddynext-spaces/join-gated' );
		$this->cache->set_user_abilities( 2, $abilities );
		$this->assertSame( $abilities, $this->cache->get_user_abilities( 2 ) );
	}

	/**
	 * Invalidating user abilities removes the cached value.
	 */
	public function test_invalidate_user_abilities(): void {
		$this->cache->set_user_abilities( 2, array( 'x' ) );
		$this->cache->invalidate_user_abilities( 2 );
		$this->assertNull( $this->cache->get_user_abilities( 2 ) );
	}

	// ── Hashtag autocomplete ──────────────────────────────────────────────────

	/**
	 * Hashtag autocomplete returns null when not cached.
	 */
	public function test_get_hashtag_autocomplete_returns_null_when_missing(): void {
		$this->assertNull( $this->cache->get_hashtag_autocomplete( 'ph' ) );
	}

	/**
	 * Hashtag autocomplete can be stored and retrieved.
	 */
	public function test_set_and_get_hashtag_autocomplete(): void {
		$results = array( array( 'id' => 1, 'name' => 'php' ) );
		$this->cache->set_hashtag_autocomplete( 'ph', $results );
		$this->assertSame( $results, $this->cache->get_hashtag_autocomplete( 'ph' ) );
	}

	/**
	 * Invalidating hashtag autocomplete for a query removes that entry.
	 */
	public function test_invalidate_hashtag_autocomplete(): void {
		$this->cache->set_hashtag_autocomplete( 'ph', array( 'x' ) );
		$this->cache->invalidate_hashtag_autocomplete( 'ph' );
		$this->assertNull( $this->cache->get_hashtag_autocomplete( 'ph' ) );
	}
}
