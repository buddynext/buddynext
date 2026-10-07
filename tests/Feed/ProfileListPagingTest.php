<?php
/**
 * Every profile list pages to its last item: Replies, Likes, Scheduled, Pending.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedWindow;
use BuddyNext\Feed\PostService;

/**
 * The profile tabs used to stop at 10-20 items with no way further. Each list
 * now pages by keyset cursor. The fixtures share one timestamp, so a cursor
 * that broke ties on the timestamp alone would skip or repeat rows at a page
 * boundary - the case these tests are built around.
 *
 * @covers \BuddyNext\Feed\PostService::user_replies
 * @covers \BuddyNext\Feed\PostService::user_liked_posts
 * @covers \BuddyNext\Feed\PostService::user_scheduled_posts
 * @covers \BuddyNext\Feed\PostService::user_pending_posts
 * @covers \BuddyNext\Feed\FeedWindow
 */
class ProfileListPagingTest extends \WP_UnitTestCase {

	/**
	 * Items per fixture list.
	 */
	private const COUNT = 7;

	/**
	 * Service under test.
	 *
	 * @var PostService
	 */
	private PostService $service;

	/**
	 * Author of the posts.
	 *
	 * @var int
	 */
	private int $author = 0;

	/**
	 * The member who replies to and likes them.
	 *
	 * @var int
	 */
	private int $member = 0;

	/**
	 * The author's published posts.
	 *
	 * @var int[]
	 */
	private array $posts = array();

	/**
	 * Seven public posts, each replied to and liked by one member, all at one instant.
	 *
	 * @return void
	 */
	public function set_up(): void {
		global $wpdb;
		parent::set_up();
		Installer::run();

		$this->service = new PostService();
		$this->author  = self::factory()->user->create();
		$this->member  = self::factory()->user->create();

		$comments  = new CommentService();
		$reactions = buddynext_service( 'reactions' );
		for ( $i = 0; $i < self::COUNT; $i++ ) {
			wp_set_current_user( $this->author );
			$post_id       = (int) $this->service->create(
				$this->author,
				array(
					'type'    => 'text',
					'content' => 'Paging fixture ' . $i,
					'privacy' => 'public',
				)
			);
			$this->posts[] = $post_id;
			wp_set_current_user( $this->member );
			$comments->create( $this->member, 'post', $post_id, 'Reply ' . $i );
			$reactions->react( $this->member, 'post', $post_id, 'like' );
		}
		wp_set_current_user( 0 );

		// One instant for every row: only the id tie-break orders them.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_comments SET created_at = '2026-01-01 00:00:00' WHERE user_id = %d", $this->member ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_reactions SET created_at = '2026-01-01 00:00:00' WHERE user_id = %d", $this->member ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Walk a list three at a time until the cursor runs out.
	 *
	 * @param callable $page    fn( ?string $cursor ): array{items: array, next_cursor: ?string}.
	 * @param string   $id_key  Row key holding the item id.
	 * @return int[] Ids in the order the pages returned them.
	 */
	private function walk( callable $page, string $id_key ): array {
		$ids    = array();
		$cursor = null;
		for ( $guard = 0; $guard < 10; $guard++ ) {
			$result = $page( $cursor );
			$this->assertLessThanOrEqual( 3, count( $result['items'] ) );
			foreach ( $result['items'] as $row ) {
				$ids[] = (int) $row[ $id_key ];
			}
			$cursor = $result['next_cursor'];
			if ( null === $cursor ) {
				return $ids;
			}
		}
		$this->fail( 'The cursor never ran out.' );
	}

	/**
	 * Assert a walk returned every expected id exactly once.
	 *
	 * @param int[] $expected Expected ids.
	 * @param int[] $ids      Walked ids.
	 * @return void
	 */
	private function assert_complete( array $expected, array $ids ): void {
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'An item appeared on two pages.' );
		$this->assertEqualsCanonicalizing( $expected, $ids, 'An item was skipped.' );
	}

	/**
	 * Every reply is reachable, once.
	 *
	 * @return void
	 */
	public function test_replies_page_to_the_last_reply(): void {
		global $wpdb;
		$expected = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_comments WHERE user_id = %d", $this->member ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$ids = $this->walk( fn( ?string $c ): array => $this->service->user_replies( $this->member, 3, 0, $c ), 'id' );

		$this->assertCount( self::COUNT, $expected );
		$this->assert_complete( $expected, $ids );
	}

	/**
	 * Every liked post is reachable, once.
	 *
	 * @return void
	 */
	public function test_likes_page_to_the_last_like(): void {
		$ids = $this->walk( fn( ?string $c ): array => $this->service->user_liked_posts( $this->member, 3, 0, $c ), 'id' );

		$this->assert_complete( $this->posts, $ids );
	}

	/**
	 * Scheduled posts page soonest first, ties broken by id.
	 *
	 * @return void
	 */
	public function test_scheduled_posts_page_soonest_first(): void {
		global $wpdb;
		$this->set_status( 'scheduled' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_posts SET scheduled_at = '2030-01-01 00:00:00' WHERE user_id = %d", $this->author ) );

		$ids = $this->walk( fn( ?string $c ): array => $this->service->user_scheduled_posts( $this->author, 3, $c ), 'id' );

		$this->assertSame( $this->posts, $ids, 'Soonest first, then by id.' );
	}

	/**
	 * Pending posts page newest first, ties broken by id.
	 *
	 * @return void
	 */
	public function test_pending_posts_page_to_the_last(): void {
		$this->set_status( 'pending' );

		$ids = $this->walk( fn( ?string $c ): array => $this->service->user_pending_posts( $this->author, 3, $c ), 'id' );

		$this->assertSame( array_reverse( $this->posts ), $ids );
	}

	/**
	 * A garbled cursor reads as the first page, never as an error.
	 *
	 * @return void
	 */
	public function test_invalid_cursor_reads_as_the_first_page(): void {
		$first = $this->service->user_liked_posts( $this->member, 3, 0, null );
		$bad   = $this->service->user_liked_posts( $this->member, 3, 0, '%%%not-a-cursor' );

		$this->assertSame( wp_list_pluck( $first['items'], 'id' ), wp_list_pluck( $bad['items'], 'id' ) );
	}

	/**
	 * The link rule under the list: grow, then continue from the cursor, then stop.
	 *
	 * @return void
	 */
	public function test_window_links_grow_then_continue_then_stop(): void {
		$_GET['shown'] = '31';
		$window        = FeedWindow::read( 15 );
		unset( $_GET['shown'] );
		$this->assertSame( 45, $window['shown'], 'Clamped up to whole pages.' );

		$links = FeedWindow::links( 'https://example.test/members/a/likes/', $window, 'abc' );
		$this->assertStringContainsString( 'shown=60', $links['more_url'] );
		$this->assertSame( '', $links['next_url'] );

		$at_ceiling = array_merge( $window, array( 'shown' => $window['max'] ) );
		$links      = FeedWindow::links( 'https://example.test/members/a/likes/', $at_ceiling, 'abc' );
		$this->assertSame( '', $links['more_url'] );
		$this->assertStringContainsString( 'cursor=abc', $links['next_url'] );
		$this->assertStringNotContainsString( 'shown=', $links['next_url'] );

		$this->assertSame(
			array(
				'more_url' => '',
				'next_url' => '',
			),
			FeedWindow::links( 'https://example.test/members/a/likes/', $window, null )
		);
	}

	/**
	 * Set every fixture post to a status.
	 *
	 * @param string $status Post status.
	 * @return void
	 */
	private function set_status( string $status ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_posts SET status = %s, created_at = '2026-01-01 00:00:00' WHERE user_id = %d", $status, $this->author ) );
	}
}
