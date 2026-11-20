<?php
/**
 * Tests for PageCachePurger and the profile Articles tab.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageCachePurger;
use BuddyNext\Core\PageRouter;

/**
 * A change to a WordPress post a guest can see on a member's Articles tab purges
 * that member's profile pages (card 10531142088). Guests kept the old list for
 * the cache lifetime: a deleted post still listed, a new one missing, and a post
 * made private or password protected still shown.
 *
 * @covers \BuddyNext\Core\PageCachePurger
 */
class PageCachePurgerArticlesTest extends \WP_UnitTestCase {

	/**
	 * URLs handed to the purge action.
	 *
	 * @var string[]
	 */
	private array $purged = array();

	/**
	 * Post author.
	 *
	 * @var int
	 */
	private int $author = 0;

	/**
	 * Make the Articles tab available, listen for purges, register the purger.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' ) ) {
			define( 'BUDDYPRESS_MEMBER_BLOG_VERSION', 'test' );
		}
		delete_option( 'buddynext_integration_blog_nav' );
		$this->author = self::factory()->user->create( array( 'user_nicename' => 'writer-' . wp_rand() ) );

		( new PageCachePurger() )->register();
		add_action(
			'buddynext_purge_page_cache',
			function ( string $url ): void {
				$this->purged[] = $url;
			}
		);
		$this->reset();
	}

	/**
	 * Leave the purger's per-request memo as a new request finds it.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->reset();
		parent::tear_down();
	}

	/**
	 * Forget this request's purges, as a new request would.
	 *
	 * @return void
	 */
	private function reset(): void {
		$this->purged = array();
		foreach ( array(
			'purged'         => array(),
			'purged_authors' => array(),
			'purged_all'     => false,
		) as $name => $empty ) {
			if ( ! property_exists( PageCachePurger::class, $name ) ) {
				continue;
			}
			$prop = new \ReflectionProperty( PageCachePurger::class, $name );
			$prop->setAccessible( true );
			$prop->setValue( null, $empty );
		}
	}

	/**
	 * The author's Articles tab URL.
	 *
	 * @param int $user_id Author.
	 * @return string
	 */
	private function tab( int $user_id ): string {
		return trailingslashit( PageRouter::profile_url( $user_id ) ) . 'articles/';
	}

	/**
	 * A published post by the author, with this request's purges forgotten.
	 *
	 * @param array<string,mixed> $args Post args.
	 * @return int
	 */
	private function published( array $args = array() ): int {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_author' => $this->author,
					'post_status' => 'publish',
				),
				$args
			)
		);
		$this->reset();
		return $id;
	}

	/**
	 * Assert the author's profile and Articles tab were purged.
	 *
	 * @param int    $user_id Author.
	 * @param string $message Why.
	 * @return void
	 */
	private function assertAuthorPurged( int $user_id, string $message ): void {
		$this->assertContains( $this->tab( $user_id ), $this->purged, $message );
		$this->assertContains( PageRouter::profile_url( $user_id ), $this->purged, $message );
	}

	public function test_publishing_a_post_purges_the_authors_articles_tab(): void {
		self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_status' => 'publish',
			)
		);

		$this->assertAuthorPurged( $this->author, 'A new post reaches the guest Articles tab.' );
	}

	public function test_making_a_post_private_purges_the_tab(): void {
		$id = $this->published();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'private',
			)
		);

		$this->assertAuthorPurged( $this->author, 'A post made private leaves the guest Articles tab.' );
	}

	public function test_unpublishing_to_draft_purges_the_tab(): void {
		$id = $this->published();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);

		$this->assertAuthorPurged( $this->author, 'A post moved back to draft leaves the guest Articles tab.' );
	}

	public function test_adding_a_password_purges_the_tab(): void {
		$id = $this->published();
		wp_update_post(
			array(
				'ID'            => $id,
				'post_password' => 'secret',
			)
		);

		$this->assertAuthorPurged( $this->author, 'A password-protected post leaves the guest Articles tab.' );
	}

	public function test_trashing_a_post_purges_the_tab(): void {
		$id = $this->published();
		wp_trash_post( $id );

		$this->assertAuthorPurged( $this->author, 'A trashed post leaves the guest Articles tab.' );
	}

	public function test_deleting_a_published_post_purges_the_tab(): void {
		$id = $this->published();
		wp_delete_post( $id, true );

		$this->assertAuthorPurged( $this->author, 'A deleted post leaves the guest Articles tab.' );
	}

	public function test_changing_the_author_purges_both_authors(): void {
		$id  = $this->published();
		$new = self::factory()->user->create( array( 'user_nicename' => 'new-writer-' . wp_rand() ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $new,
			)
		);

		$this->assertAuthorPurged( $this->author, 'The old author loses the post.' );
		$this->assertAuthorPurged( $new, 'The new author gains the post.' );
	}

	public function test_a_draft_save_purges_nothing(): void {
		$id = self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_status' => 'draft',
			)
		);
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Still a draft',
			)
		);

		$this->assertNotContains( $this->tab( $this->author ), $this->purged, 'A draft is on no guest page.' );
	}

	public function test_a_post_type_the_tab_does_not_list_purges_nothing(): void {
		self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);

		$this->assertNotContains( $this->tab( $this->author ), $this->purged );
	}

	public function test_nothing_is_purged_when_the_articles_tab_is_off(): void {
		update_option( 'buddynext_integration_blog_nav', '0' );
		$this->reset();
		self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_status' => 'publish',
			)
		);

		$this->assertNotContains( $this->tab( $this->author ), $this->purged );
	}

	public function test_the_paged_tab_urls_are_purged(): void {
		add_filter( 'buddynext_profile_articles_per_page', static fn(): int => 2 );
		self::factory()->post->create_many(
			4,
			array(
				'post_author' => $this->author,
				'post_status' => 'publish',
			)
		);
		$this->reset();
		self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_status' => 'publish',
			)
		);

		// 5 posts at 2 a page = 3 pages, plus the page a removal leaves behind.
		$this->assertContains( $this->tab( $this->author ) . 'page/2/', $this->purged );
		$this->assertContains( $this->tab( $this->author ) . 'page/3/', $this->purged );
		$this->assertContains( $this->tab( $this->author ) . 'page/4/', $this->purged );
	}

	/**
	 * A bulk change purges each author's pages once, however many posts change.
	 *
	 * @return void
	 */
	public function test_a_bulk_change_purges_each_authors_pages_once(): void {
		$one_author = self::factory()->post->create_many(
			500,
			array(
				'post_author' => $this->author,
				'post_status' => 'publish',
			)
		);
		$this->reset();
		foreach ( $one_author as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'private',
				)
			);
		}
		$tab_urls = array_filter( $this->purged, fn( string $url ): bool => 0 === strpos( $url, PageRouter::profile_url( $this->author ) ) );
		// Profile + tab + page/2..20 (500 posts at 10 a page, capped at 20 pages).
		$this->assertCount( 21, $tab_urls, '500 posts by one author purge that author\'s pages once.' );
		$this->assertSame( $tab_urls, array_unique( $tab_urls ) );

		$authors = self::factory()->user->create_many( 50 );
		$ids     = array();
		foreach ( $authors as $user_id ) {
			foreach ( self::factory()->post->create_many(
				10,
				array(
					'post_author' => $user_id,
					'post_status' => 'publish',
				)
			) as $id ) {
				$ids[] = $id;
			}
		}
		$this->reset();
		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$tabs = array_filter( $this->purged, static fn( string $url ): bool => (bool) preg_match( '#/articles/$#', $url ) );
		$this->assertCount( 50, $tabs, '500 posts by 50 authors purge 50 Articles tabs, one each.' );
		$this->assertSame( count( $this->purged ), count( array_unique( $this->purged ) ), 'No URL is purged twice.' );
	}
}
