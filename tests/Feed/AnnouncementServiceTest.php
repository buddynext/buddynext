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
			array(
				'user_id' => self::factory()->user->create(),
				'content' => 'x',
				'status'  => 'published',
			)
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

	/**
	 * Ending keeps the announcement on record and stops it reading as live.
	 *
	 * Ending from the post card used to clear is_announcement, so the post
	 * vanished from Engagement > Announcements; ending from that screen kept the
	 * flag, so the card still showed the banner and End button.
	 *
	 * @return void
	 */
	public function test_ended_announcement_is_on_record_but_not_live(): void {
		$this->assertSame( 1, (int) $this->service->hydrate( (array) $this->service->get_announcement( $this->post_id ) )['is_announcement'], 'Live before it ends.' );

		$this->assertTrue( buddynext_service( 'feed' )->end_announcement_now( $this->post_id ) );

		$listed = wp_list_pluck( buddynext_service( 'feed' )->list_all_announcements(), 'id' );
		$this->assertContains( (string) $this->post_id, array_map( 'strval', $listed ), 'Still on the Announcements screen.' );

		global $wpdb;
		$row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ), ARRAY_A ); // phpcs:ignore
		$this->assertSame( 0, $this->service->hydrate( $row )['is_announcement'], 'Its card no longer reads as a live announcement.' );
	}
}
