<?php
/**
 * BookmarkService's per-request memo must not outlive a write.
 *
 * bookmarked_among() memoises its answers for the request. That memo used to be
 * a function static, which bookmark() and unbookmark() had no way to reach - so
 * anything that asked about a bookmark, wrote it, and asked again got the stale
 * answer from before the write.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\BookmarkService;

/**
 * @covers \BuddyNext\Feed\BookmarkService::bookmark
 * @covers \BuddyNext\Feed\BookmarkService::unbookmark
 * @covers \BuddyNext\Feed\BookmarkService::bookmarked_among
 */
class BookmarkMemoTest extends \WP_UnitTestCase {

	private BookmarkService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new BookmarkService();
	}

	/**
	 * Ask -> write -> ask. The second answer must reflect the write.
	 *
	 * This is the exact sequence the BuddyPress bookmark importer runs to
	 * confirm each row landed. With the stale memo every successful import was
	 * reported as refused while the rows were in fact written - a migration that
	 * looked like it had failed and had not.
	 */
	public function test_is_bookmarked_reflects_a_write_made_in_the_same_request(): void {
		$user = 501;
		$post = 601;

		$this->assertFalse(
			$this->service->is_bookmarked( $user, $post ),
			'Nothing bookmarked yet - this call is what seeds the memo.'
		);

		$this->service->bookmark( $user, $post );

		$this->assertTrue(
			$this->service->is_bookmarked( $user, $post ),
			'The write must invalidate the memoised "not bookmarked".'
		);
	}

	/**
	 * The same, in reverse: a removal must not keep reading as present.
	 */
	public function test_is_bookmarked_reflects_a_removal_made_in_the_same_request(): void {
		$user = 502;
		$post = 602;

		$this->service->bookmark( $user, $post );
		$this->assertTrue( $this->service->is_bookmarked( $user, $post ) );

		$this->service->unbookmark( $user, $post );

		$this->assertFalse(
			$this->service->is_bookmarked( $user, $post ),
			'The removal must invalidate the memoised "bookmarked".'
		);
	}

	/**
	 * Forgetting one pair must not discard the rest of the memo, which is the
	 * whole reason the memo exists.
	 */
	public function test_a_write_only_forgets_the_pair_it_touched(): void {
		$user  = 503;
		$kept  = 603;
		$fresh = 604;

		$this->service->bookmark( $user, $kept );
		$this->assertSame( array( $kept => true ), $this->service->bookmarked_among( $user, array( $kept ) ) );

		$this->service->bookmark( $user, $fresh );

		$this->assertSame(
			array(
				$kept  => true,
				$fresh => true,
			),
			$this->service->bookmarked_among( $user, array( $kept, $fresh ) )
		);
	}

	/**
	 * The memo is per member: one member's bookmark must never answer for
	 * another's.
	 */
	public function test_the_memo_does_not_leak_across_members(): void {
		$post = 605;

		$this->service->bookmark( 504, $post );

		$this->assertTrue( $this->service->is_bookmarked( 504, $post ) );
		$this->assertFalse( $this->service->is_bookmarked( 505, $post ) );
	}
}
