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
}
