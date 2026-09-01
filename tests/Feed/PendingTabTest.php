<?php
/**
 * The owner-only "Pending" profile tab and the service methods behind it.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService::user_pending_posts
 * @covers \BuddyNext\Feed\PostService::user_pending_count
 */
class PendingTabTest extends \WP_UnitTestCase {

	private PostService $service;
	private int $author;
	private int $other;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new PostService();
		$this->author  = self::factory()->user->create();
		$this->other   = self::factory()->user->create();
	}

	/**
	 * Write a row directly: create() routes through pre-moderation, which is
	 * filter-driven, and this is testing the read side.
	 *
	 * @param int    $user_id Author.
	 * @param string $status  Post status.
	 * @return int
	 */
	private function seed_post( int $user_id, string $status ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $user_id,
				'type'       => 'text',
				'content'    => 'Post in status ' . $status,
				'status'     => $status,
				'privacy'    => 'public',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function test_pending_count_only_counts_this_author_and_only_pending(): void {
		$this->seed_post( $this->author, 'pending' );
		$this->seed_post( $this->author, 'pending' );
		$this->seed_post( $this->author, 'published' );
		$this->seed_post( $this->author, 'scheduled' );
		$this->seed_post( $this->other, 'pending' );

		$this->assertSame( 2, $this->service->user_pending_count( $this->author ) );
		$this->assertSame( 1, $this->service->user_pending_count( $this->other ) );
	}

	public function test_pending_posts_returns_only_this_authors_held_posts(): void {
		$mine = $this->seed_post( $this->author, 'pending' );
		$this->seed_post( $this->author, 'published' );
		$this->seed_post( $this->other, 'pending' );

		$rows = $this->service->user_pending_posts( $this->author, 20 );

		$this->assertCount( 1, $rows );
		$this->assertSame( $mine, (int) $rows[0]['id'] );
	}

	public function test_pending_posts_are_hydrated_like_the_scheduled_tab(): void {
		// The panel feeds these straight to post-card.php, which reads the
		// hydrated shape - a raw row would render a broken card.
		$this->seed_post( $this->author, 'pending' );

		$rows = $this->service->user_pending_posts( $this->author, 20 );

		$this->assertNotEmpty( $rows );
		$this->assertSame( 'pending', $rows[0]['status'] );
		// The columns post-card.php reads. get_pending_by_author() (the REST
		// shape) selects six columns and would render a broken card here.
		foreach ( array( 'id', 'user_id', 'content', 'privacy', 'media_ids', 'reaction_count', 'created_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $rows[0] );
		}
	}

	public function test_no_pending_posts_is_an_empty_list_not_an_error(): void {
		$this->assertSame( array(), $this->service->user_pending_posts( $this->author, 20 ) );
		$this->assertSame( 0, $this->service->user_pending_count( $this->author ) );
	}
}
