<?php
/**
 * Tests for NotificationService::get().
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationService;

/**
 * @covers \BuddyNext\Notifications\NotificationService::get
 */
class NotificationGetTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_get_returns_hydrated_row(): void {
		$service   = new NotificationService();
		$recipient = self::factory()->user->create();
		$sender    = self::factory()->user->create();

		$id = $service->create(
			array(
				'recipient_id' => $recipient,
				'sender_id'    => $sender,
				'type'         => 'bn.new_follower',
				'object_type'  => 'user',
				'object_id'    => $sender,
			)
		);

		$row = $service->get( $id );
		$this->assertIsArray( $row );
		$this->assertSame( $id, $row['id'] );
		$this->assertSame( $sender, $row['sender_id'] );
		$this->assertSame( 'bn.new_follower', $row['type'] );
		$this->assertFalse( $row['is_read'] );
	}

	public function test_get_returns_null_for_missing(): void {
		$this->assertNull( ( new NotificationService() )->get( 999999 ) );
		$this->assertNull( ( new NotificationService() )->get( 0 ) );
	}

	/**
	 * The hydrated row must carry the decoded `data` payload. Partner-mirrored
	 * (jt.* and suite.*) and data-driven types keep their message/url ONLY in data,
	 * so a hydrate that dropped it forced generic copy + a home_url() link on the
	 * hub and REST. This is the C2.1 regression guard.
	 */
	public function test_get_includes_decoded_data_payload(): void {
		$service   = new NotificationService();
		$recipient = self::factory()->user->create();
		$sender    = self::factory()->user->create();

		$id = $service->create(
			array(
				'recipient_id' => $recipient,
				'sender_id'    => $sender,
				'type'         => 'jt.notification',
				'object_type'  => 'jetonomy_reply',
				'object_id'    => 123,
				'data'         => array(
					'message' => 'Alice replied to your discussion',
					'url'     => 'https://example.test/community/s/help/t/setup/',
				),
			)
		);

		$row = $service->get( $id );
		$this->assertIsArray( $row );
		$this->assertArrayHasKey( 'data', $row );
		$this->assertIsArray( $row['data'], 'data must be decoded to an array, not a raw JSON string' );
		$this->assertSame( 'Alice replied to your discussion', $row['data']['message'] ?? null );
		$this->assertSame( 'https://example.test/community/s/help/t/setup/', $row['data']['url'] ?? null );
	}
}
