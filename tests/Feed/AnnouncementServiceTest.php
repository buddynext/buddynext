<?php
/**
 * Tests for PostService announcement helpers.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService::get_announcement
 * @covers \BuddyNext\Feed\PostService::end_announcement
 */
class AnnouncementServiceTest extends \WP_UnitTestCase {

	private PostService $service;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new PostService();

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'         => self::factory()->user->create(),
				'content'         => 'Heads up',
				'status'          => 'published',
				'type'            => 'announcement',
				'is_announcement' => 1,
			)
		);
		$this->post_id = (int) $wpdb->insert_id;
	}

	public function test_get_announcement_returns_row(): void {
		$row = $this->service->get_announcement( $this->post_id );
		$this->assertIsArray( $row );
		$this->assertSame( $this->post_id, (int) $row['id'] );
	}

	public function test_get_announcement_null_for_non_announcement(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array( 'user_id' => self::factory()->user->create(), 'content' => 'x', 'status' => 'published' )
		);
		$plain = (int) $wpdb->insert_id;
		$this->assertNull( $this->service->get_announcement( $plain ) );
		$this->assertNull( $this->service->get_announcement( 999999 ) );
	}

	public function test_end_announcement_sets_expiry(): void {
		$this->assertTrue( $this->service->end_announcement( $this->post_id ) );

		global $wpdb;
		$expiry = $wpdb->get_var(
			$wpdb->prepare( "SELECT site_pin_expires_at FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ) // phpcs:ignore
		);
		$this->assertNotEmpty( $expiry );
	}

	public function test_end_announcement_false_for_missing(): void {
		$this->assertFalse( $this->service->end_announcement( 999999 ) );
	}
}
