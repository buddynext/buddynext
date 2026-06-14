<?php
/**
 * Tests for the shared integration feed-activity helper.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\IntegrationActivity;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Feed\IntegrationActivity
 */
class IntegrationActivityTest extends \WP_UnitTestCase {

	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->member_id = self::factory()->user->create();
	}

	public function test_publish_creates_a_link_post(): void {
		global $wpdb;

		$url = 'https://example.test/discussions/55/';
		$id  = IntegrationActivity::publish( $this->member_id, 'started a discussion', $url, 'Welcome thread' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT user_id, type, link_url FROM {$wpdb->prefix}bn_posts WHERE id = %d", $id ),
			ARRAY_A
		);
		$this->assertSame( (int) $this->member_id, (int) $row['user_id'] );
		$this->assertSame( 'link', $row['type'] );
		$this->assertSame( $url, $row['link_url'] );
	}

	public function test_publish_is_idempotent(): void {
		$url = 'https://example.test/discussions/56/';
		$first  = IntegrationActivity::publish( $this->member_id, 'started a discussion', $url );
		$second = IntegrationActivity::publish( $this->member_id, 'started a discussion', $url );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $second, 'a second identical card is not created' );
	}

	public function test_publish_rejects_invalid_input(): void {
		$this->assertInstanceOf( \WP_Error::class, IntegrationActivity::publish( 0, 'x', 'https://x/' ) );
		$this->assertInstanceOf( \WP_Error::class, IntegrationActivity::publish( $this->member_id, 'x', '' ) );
	}

	public function test_remove_deletes_the_card(): void {
		global $wpdb;

		$url = 'https://example.test/discussions/77/';
		IntegrationActivity::publish( $this->member_id, 'started a discussion', $url );

		$removed = IntegrationActivity::remove( $url );
		$this->assertGreaterThan( 0, $removed );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE link_url = %s", $url )
		);
		$this->assertSame( 0, $count );
	}
}
