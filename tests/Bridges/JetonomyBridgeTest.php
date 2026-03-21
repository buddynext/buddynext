<?php
/**
 * Tests for Jetonomy bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\Jetonomy;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\Jetonomy
 */
class JetonomyBridgeTest extends \WP_UnitTestCase {

	private Jetonomy $bridge;
	private int $user_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->bridge    = new Jetonomy();
		$this->bridge->init();
		$this->user_id   = self::factory()->user->create();
		$this->author_id = self::factory()->user->create();
	}

	public function test_reply_created_creates_notification(): void {
		global $wpdb;

		// Simulate jetonomy_after_create_reply: reply_id, post_id, author_id, parent_user_id.
		do_action( 'jetonomy_after_create_reply', 5, 10, $this->user_id, $this->author_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = 'jt.discussion_reply'",
				$this->author_id
			)
		);

		$this->assertGreaterThan( 0, $count );
	}

	public function test_post_created_indexes_in_search(): void {
		global $wpdb;

		do_action( 'jetonomy_after_create_post', 20, $this->user_id, 'Test Discussion Title', 'Body content here.' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index
				 WHERE object_type = 'discussion' AND object_id = %d",
				20
			)
		);

		$this->assertSame( 1, $count );
	}

	public function test_reply_does_not_notify_replier(): void {
		global $wpdb;

		// Replier = author → no self-notification.
		do_action( 'jetonomy_after_create_reply', 5, 10, $this->user_id, $this->user_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = 'jt.discussion_reply'",
				$this->user_id
			)
		);

		$this->assertSame( 0, $count );
	}
}
