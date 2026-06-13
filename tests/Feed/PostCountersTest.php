<?php
/**
 * Tests for PostService bn_posts counter helpers.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService::increment_counter
 * @covers \BuddyNext\Feed\PostService::decrement_counter
 * @covers \BuddyNext\Feed\PostService::get_author_id
 */
class PostCountersTest extends \WP_UnitTestCase {

	private PostService $service;
	private int $post_id;
	private int $author;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new PostService();
		$this->author  = self::factory()->user->create();

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id' => $this->author,
				'content' => 'x',
				'status'  => 'published',
			)
		);
		$this->post_id = (int) $wpdb->insert_id;
	}

	private function col( string $c ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT {$c} FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ) // phpcs:ignore
		);
	}

	public function test_increment_and_decrement_comment_count(): void {
		$this->service->increment_counter( $this->post_id, 'comment_count' );
		$this->service->increment_counter( $this->post_id, 'comment_count' );
		$this->assertSame( 2, $this->col( 'comment_count' ) );
		$this->service->decrement_counter( $this->post_id, 'comment_count' );
		$this->assertSame( 1, $this->col( 'comment_count' ) );
	}

	public function test_decrement_never_goes_negative(): void {
		$this->service->decrement_counter( $this->post_id, 'reaction_count' );
		$this->assertSame( 0, $this->col( 'reaction_count' ) );
	}

	public function test_rejects_unknown_column(): void {
		$this->service->increment_counter( $this->post_id, 'evil; DROP TABLE' );
		$this->assertSame( 0, $this->col( 'comment_count' ) );
	}

	public function test_get_author_id(): void {
		$this->assertSame( $this->author, $this->service->get_author_id( $this->post_id ) );
		$this->assertSame( 0, $this->service->get_author_id( 999999 ) );
	}
}
