<?php
/**
 * A pinned comment takes one slot on page one and no other.
 *
 * It was added to every page on top of the paged rows, so "View more" drew it again,
 * page one ran one long and the offsets drifted.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Comments\CommentService::list
 * @covers \BuddyNext\Comments\CommentService::list_for_object
 */
class PinnedCommentPagingTest extends WP_UnitTestCase {

	private const PER_PAGE = 6;

	private int $author;

	private int $post_id;

	private CommentService $comments;

	/** @var int[] */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->author   = self::factory()->user->create();
		$this->comments = new CommentService();
		$this->post_id  = (int) ( new PostService() )->create(
			$this->author,
			array(
				'content' => 'parent',
				'privacy' => 'public',
				'type'    => 'text',
			)
		);
		global $wpdb;
		for ( $i = 0; $i < 14; $i++ ) {
			$id          = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'c' . $i );
			$this->ids[] = $id;
			$wpdb->update( $wpdb->prefix . 'bn_comments', array( 'created_at' => gmdate( 'Y-m-d H:i:s', 1700000000 + $i ) ), array( 'id' => $id ) );
		}
		wp_cache_flush();
	}

	private function pin( int $comment_id ): void {
		update_option( 'bn_pinned_comment_post_' . $this->post_id, $comment_id, false );
	}

	/**
	 * @return array{0:array<int,int[]>,1:int} Ids per page, and the total.
	 */
	private function pages( int $viewer ): array {
		$pages = array();
		$total = 0;
		for ( $page = 1; $page <= 3; $page++ ) {
			$result  = $this->comments->list(
				'post',
				$this->post_id,
				array(
					'per_page'  => self::PER_PAGE,
					'page'      => $page,
					'viewer_id' => $viewer,
				)
			);
			$pages[] = array_map( 'intval', wp_list_pluck( $result['items'], 'id' ) );
			$total   = (int) $result['total'];
		}
		return array( $pages, $total );
	}

	/** Pinned from page one, page two and the last page: the card's own case is page two. */
	public function test_every_comment_appears_once_and_every_page_keeps_its_size(): void {
		foreach ( array( 0, 7, 13 ) as $index ) {
			wp_cache_flush();
			$this->pin( $this->ids[ $index ] );
			[ $pages, $total ] = $this->pages( $this->author );

			$this->assertSame( $this->ids[ $index ], $pages[0][0], 'pinned leads page one' );
			$this->assertCount( self::PER_PAGE, $pages[0] );
			$this->assertCount( self::PER_PAGE, $pages[1] );
			$this->assertCount( 2, $pages[2] );
			$all = array_merge( ...$pages );
			$this->assertCount( 14, array_unique( $all ), 'no comment repeated or lost' );
			$this->assertSame( 14, $total );
			$this->assertNotContains( $this->ids[ $index ], $pages[1], 'the app JSON for page 2 has no pinned id' );
		}
	}

	public function test_no_pin_keeps_plain_chronological_paging(): void {
		[ $pages ] = $this->pages( $this->author );
		$this->assertSame( array_slice( $this->ids, 0, 6 ), $pages[0] );
		$this->assertSame( array_slice( $this->ids, 6, 6 ), $pages[1] );
		$this->assertSame( array_slice( $this->ids, 12 ), $pages[2] );
	}

	public function test_only_the_pinned_comment_carries_the_flag(): void {
		$this->pin( $this->ids[7] );
		$result = $this->comments->list( 'post', $this->post_id, array( 'per_page' => self::PER_PAGE ) );

		$flagged = array_filter( $result['items'], static fn( array $c ): bool => ! empty( $c['pinned'] ) );
		$this->assertSame( array( $this->ids[7] ), array_map( 'intval', array_values( wp_list_pluck( $flagged, 'id' ) ) ) );
	}

	/** A pin left on a comment that is gone, or on another post's comment, must not shift the pages. */
	public function test_a_stale_pin_is_ignored(): void {
		$this->pin( 99999999 );
		[ $pages ] = $this->pages( $this->author );
		$this->assertSame( array_slice( $this->ids, 0, 6 ), $pages[0] );

		$other   = (int) ( new PostService() )->create( $this->author, array( 'content' => 'other', 'privacy' => 'public', 'type' => 'text' ) );
		$foreign = (int) $this->comments->create( $this->author, 'post', $other, 'elsewhere' );
		wp_cache_flush();
		$this->pin( $foreign );
		[ $pages ] = $this->pages( $this->author );
		$this->assertNotContains( $foreign, array_merge( ...$pages ) );
	}

	/** Blocked author: the pin is hidden from this viewer, and nothing else goes missing. */
	public function test_a_pinned_comment_the_viewer_may_not_see_stays_hidden(): void {
		$blocked = self::factory()->user->create();
		$viewer  = self::factory()->user->create();
		$hidden  = (int) $this->comments->create( $blocked, 'post', $this->post_id, 'from a blocked member' );
		buddynext_service( 'blocks' )->block( $viewer, $blocked );
		wp_cache_flush();
		$this->pin( $hidden );

		[ $pages ] = $this->pages( $viewer );
		$all       = array_merge( ...$pages );
		$this->assertNotContains( $hidden, $all );
		$this->assertCount( 14, array_unique( $all ), 'every comment the viewer may see still arrives' );
	}

	/** A thread whose only comment is pinned, and the one-per-page edge. */
	public function test_small_pages_do_not_lose_or_repeat_anything(): void {
		$this->pin( $this->ids[3] );
		$seen = array();
		for ( $page = 1; $page <= 14; $page++ ) {
			$result = $this->comments->list( 'post', $this->post_id, array( 'per_page' => 1, 'page' => $page ) );
			$this->assertCount( 1, $result['items'], 'page ' . $page );
			$seen[] = (int) $result['items'][0]['id'];
		}
		$this->assertCount( 14, array_unique( $seen ) );
	}

	/** Unpinning must not serve the pinned ordering from cache. */
	public function test_pin_and_unpin_take_effect_without_a_cache_flush(): void {
		$first = $this->comments->list( 'post', $this->post_id, array( 'per_page' => self::PER_PAGE ) );
		$this->assertSame( $this->ids[0], (int) $first['items'][0]['id'] );

		$this->pin( $this->ids[7] );
		$pinned = $this->comments->list( 'post', $this->post_id, array( 'per_page' => self::PER_PAGE ) );
		$this->assertSame( $this->ids[7], (int) $pinned['items'][0]['id'] );

		delete_option( 'bn_pinned_comment_post_' . $this->post_id );
		$after = $this->comments->list( 'post', $this->post_id, array( 'per_page' => self::PER_PAGE ) );
		$this->assertSame( $this->ids[0], (int) $after['items'][0]['id'] );
	}
}
