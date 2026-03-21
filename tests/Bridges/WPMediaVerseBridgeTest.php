<?php
/**
 * Tests for the WPMediaVerse bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WPMediaVerse;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\WPMediaVerse
 */
class WPMediaVerseBridgeTest extends \WP_UnitTestCase {

	private WPMediaVerse $bridge;
	private int $sender_id;
	private int $recipient_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->bridge       = new WPMediaVerse();
		$this->bridge->init();
		$this->sender_id    = self::factory()->user->create();
		$this->recipient_id = self::factory()->user->create();
	}

	public function test_buddynext_active_filter_returns_true(): void {
		$result = apply_filters( 'mvs_buddynext_active', false );

		$this->assertTrue( $result );
	}

	public function test_can_send_message_allows_when_not_blocked(): void {
		$result = apply_filters( 'mvs_can_send_message', true, $this->sender_id, $this->recipient_id );

		$this->assertTrue( $result );
	}

	public function test_can_send_message_blocks_when_recipient_blocked_sender(): void {
		global $wpdb;

		// Recipient has blocked sender.
		$wpdb->insert(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $this->recipient_id,
				'blocked_id' => $this->sender_id,
			),
			array( '%d', '%d' )
		);

		$result = apply_filters( 'mvs_can_send_message', true, $this->sender_id, $this->recipient_id );

		$this->assertFalse( $result );
	}

	public function test_can_send_message_does_not_affect_unrelated_pair(): void {
		global $wpdb;

		$third_user = self::factory()->user->create();

		// Block an unrelated pair — should not affect sender/recipient.
		$wpdb->insert(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $third_user,
				'blocked_id' => $this->sender_id,
			),
			array( '%d', '%d' )
		);

		$result = apply_filters( 'mvs_can_send_message', true, $this->sender_id, $this->recipient_id );

		$this->assertTrue( $result );
	}

	public function test_message_sent_creates_notification(): void {
		global $wpdb;

		do_action( 'mvs_message_sent', 1, 10, $this->sender_id, array( $this->recipient_id ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = 'bn.new_message'",
				$this->recipient_id
			)
		);

		$this->assertGreaterThan( 0, $count );
	}

	public function test_message_sent_skips_notification_for_sender(): void {
		global $wpdb;

		// Sender should not receive a notification about their own message.
		do_action( 'mvs_message_sent', 1, 10, $this->sender_id, array( $this->sender_id ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = 'bn.new_message'",
				$this->sender_id
			)
		);

		$this->assertSame( 0, $count );
	}
}
