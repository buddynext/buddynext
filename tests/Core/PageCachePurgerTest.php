<?php
/**
 * Tests for PageCachePurger.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageCachePurger;
use BuddyNext\Core\PageRouter;

/**
 * A feed change purges the pages that list it (card 10345223677).
 *
 * @covers \BuddyNext\Core\PageCachePurger
 */
class PageCachePurgerTest extends \WP_UnitTestCase {

	/**
	 * URLs handed to the purge action.
	 *
	 * @var string[]
	 */
	private array $purged = array();

	/**
	 * Listen for purges and register the purger.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->purged = array();
		add_action(
			'buddynext_purge_page_cache',
			function ( string $url ): void {
				$this->purged[] = $url;
			}
		);
		( new PageCachePurger() )->register();
	}

	public function test_a_new_post_purges_the_hubs_the_post_and_its_author(): void {
		do_action( 'buddynext_post_created', 42, 7, 'text' );

		$this->assertContains( PageRouter::explore_url(), $this->purged );
		$this->assertContains( PageRouter::activity_url(), $this->purged );
		$this->assertContains( home_url( '/' ), $this->purged );
		$this->assertContains( PageRouter::post_url( 42 ), $this->purged );
		$this->assertContains( PageRouter::profile_url( 7 ), $this->purged );
	}

	public function test_each_url_is_purged_once_per_request(): void {
		do_action( 'buddynext_post_created', 42, 7, 'text' );
		do_action( 'buddynext_post_created', 43, 7, 'text' );

		$this->assertSame( 1, count( array_keys( $this->purged, PageRouter::explore_url(), true ) ), 'A bulk write purges Explore once, not once per post.' );
		$this->assertContains( PageRouter::post_url( 43 ), $this->purged, 'Each post still purges its own page.' );
	}

	public function test_a_system_delete_purges_the_hubs_and_no_empty_url(): void {
		do_action( 'buddynext_post_deleted', 42, 0 );

		$this->assertContains( PageRouter::explore_url(), $this->purged );
		$this->assertContains( PageRouter::post_url( 42 ), $this->purged );
		$this->assertNotContains( '', $this->purged );
	}

	public function test_hiding_and_approving_a_post_purge_its_page(): void {
		do_action( 'buddynext_post_auto_hidden', 50 );
		do_action( 'buddynext_post_approved', 51, 7 );

		$this->assertContains( PageRouter::post_url( 50 ), $this->purged );
		$this->assertContains( PageRouter::post_url( 51 ), $this->purged );
	}

	/**
	 * A comment added, edited or deleted purges the page of the post it is on.
	 * Guests behind a page cache kept seeing the old thread (and count) until the
	 * cache expired, because only post events purged.
	 *
	 * @return void
	 */
	public function test_comment_changes_purge_the_post_page(): void {
		global $wpdb;

		$author = self::factory()->user->create();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $author,
				'content'    => 'A post guests read from the page cache',
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'space_id'   => 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$post_id  = (int) $wpdb->insert_id;
		$comments = buddynext_service( 'comments' );
		$reset    = function (): void {
			$this->purged = array();
			$prop         = new \ReflectionProperty( PageCachePurger::class, 'purged' );
			$prop->setAccessible( true );
			$prop->setValue( null, array() );
		};

		$reset();
		$comment_id = (int) $comments->create( $author, 'post', $post_id, 'First comment' );
		$this->assertGreaterThan( 0, $comment_id );
		$this->assertContains( PageRouter::post_url( $post_id ), $this->purged, 'Creating a comment purges the post page.' );

		$reset();
		$comments->update( $comment_id, $author, 'First comment, edited' );
		$this->assertContains( PageRouter::post_url( $post_id ), $this->purged, 'Editing a comment purges the post page.' );

		$reset();
		$comments->soft_delete( $comment_id, $author );
		$this->assertContains( PageRouter::post_url( $post_id ), $this->purged, 'Deleting a comment purges the post page.' );
	}

	public function test_a_space_post_change_purges_the_space_page(): void {
		do_action( 'buddynext_space_posts_changed', 9 );

		$this->assertContains( PageRouter::space_url( 9 ), $this->purged );
	}

	/**
	 * Renaming a hub flushes the whole page cache (every page links to the hub)
	 * and hands the old and new hub URLs to the per-URL purge. On a page-cached
	 * site the old /members/ was still served as a cached 200 after a rename.
	 *
	 * @return void
	 */
	public function test_renaming_a_hub_flushes_every_cached_page(): void {
		$flushed   = 0;
		$litespeed = 0;
		add_action(
			'buddynext_purge_page_cache_all',
			static function () use ( &$flushed ): void {
				++$flushed;
			}
		);
		add_action(
			'litespeed_purge_all',
			static function () use ( &$litespeed ): void {
				++$litespeed;
			}
		);

		update_option( 'buddynext_slug_people', 'people' );
		update_option( 'buddynext_slug_people', 'community' );

		$this->assertSame( 1, $flushed, 'A hub rename flushes the whole page cache, once per request.' );
		$this->assertSame( 1, $litespeed, 'LiteSpeed is told to purge everything.' );
		$this->assertContains( trailingslashit( home_url( '/people' ) ), $this->purged, 'The old hub URL is purged.' );
		$this->assertContains( trailingslashit( home_url( '/community' ) ), $this->purged, 'The new hub URL is purged.' );
	}

	/**
	 * Saving the same slug again is not a rename and flushes nothing.
	 *
	 * @return void
	 */
	public function test_an_unchanged_hub_slug_flushes_nothing(): void {
		$flushed = 0;
		add_action(
			'buddynext_purge_page_cache_all',
			static function () use ( &$flushed ): void {
				++$flushed;
			}
		);

		( new PageCachePurger() )->on_hub_renamed( 'members', 'members' );

		$this->assertSame( 0, $flushed );
	}
}
