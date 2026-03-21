<?php
/**
 * Tests for BookmarkService.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\BookmarkService;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\BookmarkService
 */
class BookmarkServiceTest extends \WP_UnitTestCase {

	private BookmarkService $service;
	private PostService $posts;
	private int $alice;
	private int $bob;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts   = new PostService();
		$this->service = new BookmarkService();
		$this->alice   = self::factory()->user->create();
		$this->bob     = self::factory()->user->create();
		$this->post_id = $this->posts->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Bookmarkable post' )
		);
	}

	public function test_bookmark_saves_post(): void {
		$this->service->bookmark( $this->bob, $this->post_id );

		$this->assertTrue( $this->service->is_bookmarked( $this->bob, $this->post_id ) );
	}

	public function test_unbookmark_removes_post(): void {
		$this->service->bookmark( $this->bob, $this->post_id );
		$this->service->unbookmark( $this->bob, $this->post_id );

		$this->assertFalse( $this->service->is_bookmarked( $this->bob, $this->post_id ) );
	}

	public function test_is_bookmarked_false_by_default(): void {
		$this->assertFalse( $this->service->is_bookmarked( $this->bob, $this->post_id ) );
	}

	public function test_duplicate_bookmark_is_safe(): void {
		$this->service->bookmark( $this->bob, $this->post_id );
		$this->service->bookmark( $this->bob, $this->post_id );

		$bookmarks = $this->service->user_bookmarks( $this->bob );
		$this->assertCount( 1, $bookmarks );
	}

	public function test_user_bookmarks_returns_list(): void {
		$post2 = $this->posts->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Another post' )
		);

		$this->service->bookmark( $this->bob, $this->post_id );
		$this->service->bookmark( $this->bob, $post2 );

		$bookmarks = $this->service->user_bookmarks( $this->bob );

		$this->assertContains( $this->post_id, $bookmarks );
		$this->assertContains( $post2, $bookmarks );
	}

	public function test_user_bookmarks_are_private(): void {
		$this->service->bookmark( $this->bob, $this->post_id );

		$alice_bookmarks = $this->service->user_bookmarks( $this->alice );

		$this->assertNotContains( $this->post_id, $alice_bookmarks );
	}
}
