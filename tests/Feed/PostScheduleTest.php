<?php
/**
 * Tests for PostService scheduled-post status seam.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService::set_schedule
 * @covers \BuddyNext\Feed\PostService::clear_schedule
 * @covers \BuddyNext\Feed\PostService::mark_published
 * @covers \BuddyNext\Feed\PostService::get_posts_by_status
 */
class PostScheduleTest extends \WP_UnitTestCase {

	private PostService $service;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new PostService();

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array( 'user_id' => self::factory()->user->create(), 'content' => 'x', 'status' => 'draft' )
		);
		$this->post_id = (int) $wpdb->insert_id;
	}

	private function field( string $c ) {
		global $wpdb;
		return $wpdb->get_var(
			$wpdb->prepare( "SELECT {$c} FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ) // phpcs:ignore
		);
	}

	public function test_set_schedule(): void {
		$this->assertTrue( $this->service->set_schedule( $this->post_id, '2030-01-01 00:00:00' ) );
		$this->assertSame( 'scheduled', $this->field( 'status' ) );
		$this->assertSame( '2030-01-01 00:00:00', $this->field( 'scheduled_at' ) );
	}

	public function test_clear_schedule(): void {
		$this->service->set_schedule( $this->post_id, '2030-01-01 00:00:00' );
		$this->assertTrue( $this->service->clear_schedule( $this->post_id ) );
		$this->assertSame( 'draft', $this->field( 'status' ) );
		$this->assertNull( $this->field( 'scheduled_at' ) );
	}

	public function test_mark_published(): void {
		$this->service->set_schedule( $this->post_id, '2030-01-01 00:00:00' );
		$this->assertTrue( $this->service->mark_published( $this->post_id ) );
		$this->assertSame( 'published', $this->field( 'status' ) );
	}

	public function test_get_posts_by_status_is_capped(): void {
		$this->service->set_schedule( $this->post_id, '2030-01-01 00:00:00' );
		$rows = $this->service->get_posts_by_status( 'scheduled', 50 );
		$this->assertNotEmpty( $rows );
		$this->assertSame( $this->post_id, (int) $rows[0]['id'] );
		$this->assertLessThanOrEqual( 50, count( $rows ) );
	}
}
