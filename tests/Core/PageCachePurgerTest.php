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

	public function test_a_hashtag_page_is_purged_when_a_posts_tags_are_indexed(): void {
		do_action( 'buddynext_hashtags_synced', 'post', 55, array( 'weekendrun', 'hills' ) );

		$this->assertContains( PageRouter::hashtag_feed_url( 'weekendrun' ), $this->purged );
		$this->assertContains( PageRouter::hashtag_feed_url( 'hills' ), $this->purged );
	}

	public function test_a_comments_tags_do_not_purge_hashtag_pages(): void {
		do_action( 'buddynext_hashtags_synced', 'comment', 56, array( 'onlyincomment' ) );

		$this->assertNotContains( PageRouter::hashtag_feed_url( 'onlyincomment' ), $this->purged );
	}

	private function forget_purges(): void {
		$this->purged = array();
		$prop         = new \ReflectionProperty( PageCachePurger::class, 'purged' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function test_deleting_a_post_purges_the_hashtag_pages_it_was_on(): void {
		$tags = buddynext_service( 'hashtags' );
		$tags->sync( 'post', 901, array( 'kept', 'gone' ) );
		$this->forget_purges();

		// What deleting a post does to its tags: sync with none. The pages that listed it are refreshed.
		$tags->sync( 'post', 901, array() );

		$this->assertContains( PageRouter::hashtag_feed_url( 'kept' ), $this->purged );
		$this->assertContains( PageRouter::hashtag_feed_url( 'gone' ), $this->purged );
	}

	public function test_editing_a_tag_away_purges_the_page_it_left_and_the_one_it_joined(): void {
		$tags = buddynext_service( 'hashtags' );
		$tags->sync( 'post', 902, array( 'oldtag' ) );
		$this->forget_purges();

		$tags->sync( 'post', 902, array( 'newtag' ) );

		$this->assertContains( PageRouter::hashtag_feed_url( 'oldtag' ), $this->purged, 'The tag it lost.' );
		$this->assertContains( PageRouter::hashtag_feed_url( 'newtag' ), $this->purged, 'The tag it gained.' );
	}

	public function test_a_privacy_change_purges_the_hashtag_pages_of_the_post(): void {
		buddynext_service( 'hashtags' )->sync( 'post', 903, array( 'privatised' ) );
		$this->forget_purges();

		do_action( 'buddynext_post_updated', 903, 1, array( 'privacy' => 'private' ) );

		$this->assertContains( PageRouter::hashtag_feed_url( 'privatised' ), $this->purged );
	}

	public function test_a_space_changing_type_purges_the_hashtag_pages_of_its_posts(): void {
		global $wpdb;
		$space = (int) buddynext_service( 'spaces' )->create( self::factory()->user->create(), array( 'name' => 'Tag space', 'slug' => 'tag-space-' . wp_rand( 10000, 99999 ), 'type' => 'open' ) );
		$post  = (int) buddynext_service( 'post_service' )->create( 1, array( 'type' => 'text', 'content' => 'in a space', 'space_id' => $space, 'privacy' => 'public' ) );
		buddynext_service( 'hashtags' )->sync( 'post', $post, array( 'spacetag' ) );
		$this->forget_purges();

		do_action( 'buddynext_space_updated', $space, 1, array( 'type' => 'private' ), 'open' );

		$this->assertContains( PageRouter::hashtag_feed_url( 'spacetag' ), $this->purged );
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
	 * A space's article cards are listed on its blog/ page, so that page is purged
	 * with the space home; an unknown space purges no made-up blog URL.
	 *
	 * @return void
	 */
	public function test_a_space_post_change_purges_the_space_blog_page(): void {
		$space_id = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			self::factory()->user->create(),
			array(
				'name' => 'Purge blog',
				'slug' => 'purge-blog-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		$this->assertGreaterThan( 0, $space_id );

		do_action( 'buddynext_space_posts_changed', $space_id );
		$this->assertContains( PageRouter::space_url( $space_id ), $this->purged );
		$this->assertContains( PageRouter::space_url( $space_id ) . 'blog/', $this->purged );

		$this->purged = array();
		do_action( 'buddynext_space_posts_changed', 999999 );
		$this->assertNotContains( PageRouter::spaces_url() . 'blog/', $this->purged );
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

	/**
	 * Saving an owner setting flushes the page cache; a runtime flag does not.
	 *
	 * Guests are served a cached page, so a changed appearance, width or name must
	 * reach them when the owner saves, not when the cache expires.
	 *
	 * @return void
	 */
	public function test_saving_an_owner_setting_flushes_the_page_cache_but_a_runtime_flag_does_not(): void {
		$reset = new \ReflectionProperty( PageCachePurger::class, 'purged_all' );
		$reset->setAccessible( true );
		$reset->setValue( null, false );

		$flushed = 0;
		add_action(
			'buddynext_purge_page_cache_all',
			static function () use ( &$flushed ): void {
				++$flushed;
			}
		);

		register_setting( 'buddynext_appearance', 'buddynext_probe_owner_setting', array( 'type' => 'string' ) );

		update_option( 'buddynext_probe_runtime_flag', 'a' );
		update_option( 'buddynext_probe_runtime_flag', 'b' );
		$this->assertSame( 0, $flushed, 'a flag the plugin writes by itself is not an owner change' );

		update_option( 'buddynext_probe_owner_setting', 'dark' );
		$this->assertSame( 1, $flushed, 'an owner setting reaches every cached guest page' );

		unregister_setting( 'buddynext_appearance', 'buddynext_probe_owner_setting' );

		// The Appearance screen saves its own options rather than registering them.
		$reset->setValue( null, false );
		update_option( 'buddynext_default_theme', 'dark' );
		$this->assertSame( 2, $flushed, 'saving the default appearance reaches guests too' );
	}
}
