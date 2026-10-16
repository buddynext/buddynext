<?php
/**
 * Tests for Feed/FeedCache — page-1 cache + invalidation.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\FeedCache;
use BuddyNext\Feed\FeedListener;

/**
 * @covers \BuddyNext\Feed\FeedCache
 * @covers \BuddyNext\Feed\FeedListener
 */
class FeedCacheTest extends \WP_UnitTestCase {

	private FeedCache $cache;

	public function set_up(): void {
		parent::set_up();
		$this->cache = new FeedCache();
		wp_cache_flush();
	}

	public function tear_down(): void {
		wp_cache_flush();
		parent::tear_down();
	}

	public function test_get_calls_miss_callback_only_once(): void {
		$calls  = 0;
		$miss   = function () use ( &$calls ) {
			++$calls;
			return array(
				'items'       => array( 'a', 'b' ),
				'next_cursor' => null,
			);
		};
		$first  = $this->cache->get( 'home:p1:42:20', FeedCache::GROUP_USER, FeedCache::TTL_HOME_PAGE_1, $miss );
		$second = $this->cache->get( 'home:p1:42:20', FeedCache::GROUP_USER, FeedCache::TTL_HOME_PAGE_1, $miss );

		$this->assertSame( 1, $calls, 'Miss callback should run once; second get hits cache.' );
		$this->assertSame( $first, $second );
	}

	public function test_invalidate_writer_clears_default_page_size_keys(): void {
		$user_id = 42;
		$this->cache->get(
			$this->cache->home_page_1_key( $user_id, 20 ),
			FeedCache::GROUP_USER,
			FeedCache::TTL_HOME_PAGE_1,
			static fn() => array(
				'items'       => array(),
				'next_cursor' => null,
			)
		);
		$this->cache->invalidate_writer( $user_id );

		$found = false;
		wp_cache_get( $this->cache->home_page_1_key( $user_id, 20 ), FeedCache::GROUP_USER, false, $found );
		$this->assertFalse( $found );
	}

	public function test_invalidate_writer_no_op_for_invalid_user_id(): void {
		// Should not throw.
		$this->cache->invalidate_writer( 0 );
		$this->cache->invalidate_writer( -1 );
		$this->assertTrue( true );
	}

	public function test_listener_busts_on_post_created(): void {
		$user_id = 7;
		$this->cache->get(
			$this->cache->home_page_1_key( $user_id, 20 ),
			FeedCache::GROUP_USER,
			FeedCache::TTL_HOME_PAGE_1,
			static fn() => array(
				'items'       => array(),
				'next_cursor' => null,
			)
		);

		( new FeedListener( $this->cache ) )->register();
		do_action( 'buddynext_post_created', 100, $user_id, 'text' );

		$found = false;
		wp_cache_get( $this->cache->home_page_1_key( $user_id, 20 ), FeedCache::GROUP_USER, false, $found );
		$this->assertFalse( $found, 'FeedListener should bust the writer cache on buddynext_post_created.' );

		remove_all_actions( 'buddynext_post_created' );
		remove_all_actions( 'buddynext_post_deleted' );
	}

	public function test_home_page_1_key_is_user_and_size_scoped(): void {
		$a = $this->cache->home_page_1_key( 1, 20 );
		$b = $this->cache->home_page_1_key( 1, 15 );
		$c = $this->cache->home_page_1_key( 2, 20 );
		$this->assertNotSame( $a, $b );
		$this->assertNotSame( $a, $c );
	}

	/**
	 * The member who votes, comments, reacts or edits sees it on reload: every one of
	 * their page-1 feeds (home, space, profile) is rebuilt, not served from the 30s cache.
	 *
	 * @return void
	 */
	public function test_actor_page_1_feeds_rebuild_after_their_interaction(): void {
		$actor = 11;
		$hooks = array(
			'buddynext_poll_voted'        => array( 5, 2, $actor ),
			'buddynext_comment_created'   => array( 9, 'post', 5, $actor ),
			'buddynext_comment_deleted'   => array( 9, $actor ),
			'buddynext_comment_updated'   => array( 9, $actor ),
			'buddynext_comment_restored'  => array( 9, $actor ),
			'buddynext_reaction_added'    => array( 'post', 5, $actor, 'like' ),
			'buddynext_reaction_removed'  => array( 'post', 5, $actor, 'like' ),
			'buddynext_post_updated'      => array( 5, $actor, array() ),
		);
		( new FeedListener( $this->cache ) )->register();

		foreach ( $hooks as $hook => $args ) {
			$keys = array(
				$this->cache->home_page_1_key( $actor, 20 ),
				$this->cache->space_page_1_key( 3, $actor, 20 ),
				$this->cache->profile_page_1_key( 4, $actor, 20 ),
			);
			do_action( $hook, ...$args );
			$this->assertNotSame( $keys[0], $this->cache->home_page_1_key( $actor, 20 ), "$hook: home feed" );
			$this->assertNotSame( $keys[1], $this->cache->space_page_1_key( 3, $actor, 20 ), "$hook: space feed" );
			$this->assertNotSame( $keys[2], $this->cache->profile_page_1_key( 4, $actor, 20 ), "$hook: profile feed" );
		}

		foreach ( array_keys( $hooks ) as $hook ) {
			remove_all_actions( $hook );
		}
		remove_all_actions( 'buddynext_post_created' );
		remove_all_actions( 'buddynext_post_deleted' );
		remove_all_actions( 'buddynext_space_posts_changed' );
		remove_all_actions( 'buddynext_report_created' );
	}
}
