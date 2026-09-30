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

	public function test_a_space_post_change_purges_the_space_page(): void {
		do_action( 'buddynext_space_posts_changed', 9 );

		$this->assertContains( PageRouter::space_url( 9 ), $this->purged );
	}
}
