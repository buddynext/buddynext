<?php
/**
 * Tests for NotificationService.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationService;

/**
 * @covers \BuddyNext\Notifications\NotificationService
 */
class NotificationServiceTest extends \WP_UnitTestCase {

	private NotificationService $service;
	private int $recipient_id;
	private int $sender_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service      = new NotificationService();
		$this->recipient_id = self::factory()->user->create();
		$this->sender_id    = self::factory()->user->create();
	}

	public function test_create_returns_id(): void {
		$id = $this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
				'object_type'  => 'user',
				'object_id'    => $this->sender_id,
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_unread_count_increments_on_create(): void {
		$before = $this->service->unread_count( $this->recipient_id );

		$this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
			)
		);

		$this->assertSame( $before + 1, $this->service->unread_count( $this->recipient_id ) );
	}

	public function test_mark_read_decrements_unread_count(): void {
		$notif_id = $this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
			)
		);

		$this->service->mark_read( $notif_id, $this->recipient_id );

		$this->assertSame( 0, $this->service->unread_count( $this->recipient_id ) );
	}

	public function test_mark_read_is_owner_only(): void {
		$notif_id = $this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
			)
		);

		$other_user = self::factory()->user->create();
		$result     = $this->service->mark_read( $notif_id, $other_user );

		$this->assertWPError( $result );
	}

	public function test_mark_all_read_clears_unread(): void {
		$this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
			)
		);
		$this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.post_liked',
			)
		);

		$this->service->mark_all_read( $this->recipient_id );

		$this->assertSame( 0, $this->service->unread_count( $this->recipient_id ) );
	}

	public function test_list_returns_notifications(): void {
		$this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
			)
		);

		$result = $this->service->list_for_user( $this->recipient_id );

		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'next_cursor', $result );
		$this->assertNotEmpty( $result['items'] );
	}

	public function test_list_item_shape(): void {
		$this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
				'object_type'  => 'user',
				'object_id'    => $this->sender_id,
			)
		);

		$result = $this->service->list_for_user( $this->recipient_id );
		$item   = $result['items'][0];

		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'type', $item );
		$this->assertArrayHasKey( 'is_read', $item );
		$this->assertArrayHasKey( 'created_at', $item );
		$this->assertFalse( $item['is_read'] );
	}

	public function test_grouped_notification_increments_count(): void {
		global $wpdb;

		// Two follow notifications with same group_key should merge.
		$this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
				'group_key'    => "follows_{$this->recipient_id}",
			)
		);

		$another_sender = self::factory()->user->create();
		$this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $another_sender,
				'type'         => 'bn.new_follower',
				'group_key'    => "follows_{$this->recipient_id}",
			)
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND group_key = %s",
				$this->recipient_id,
				"follows_{$this->recipient_id}"
			)
		);

		// Group merges: should have only 1 row with group_count = 2.
		$this->assertSame( 1, $count );
	}

	/**
	 * @covers \BuddyNext\Notifications\NotificationService::mark_unread
	 */
	public function test_mark_unread_restores_unread_state_and_checks_owner(): void {
		$id = $this->service->create(
			array(
				'recipient_id' => $this->recipient_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
				'object_type'  => 'user',
				'object_id'    => $this->sender_id,
			)
		);

		$this->service->mark_read( $id, $this->recipient_id );
		$this->assertSame( 0, count( $this->service->list_for_user( $this->recipient_id, null, 20, 'unread' )['items'] ) );

		$this->assertTrue( $this->service->mark_unread( $id, $this->recipient_id ) );
		$this->assertSame( 1, count( $this->service->list_for_user( $this->recipient_id, null, 20, 'unread' )['items'] ) );

		// A non-owner cannot mark it unread.
		$this->assertInstanceOf( \WP_Error::class, $this->service->mark_unread( $id, $this->sender_id ) );
	}

	/**
	 * @covers \BuddyNext\Notifications\NotificationService::list_for_user
	 */
	public function test_list_for_user_filter_partitions_read_state(): void {
		$a = $this->service->create( array( 'recipient_id' => $this->recipient_id, 'sender_id' => $this->sender_id, 'type' => 'bn.new_follower', 'object_type' => 'user', 'object_id' => 1, 'group_key' => 'k_a' ) );
		$this->service->create( array( 'recipient_id' => $this->recipient_id, 'sender_id' => $this->sender_id, 'type' => 'bn.new_follower', 'object_type' => 'user', 'object_id' => 2, 'group_key' => 'k_b' ) );
		$this->service->mark_read( $a, $this->recipient_id );

		$unread = $this->service->list_for_user( $this->recipient_id, null, 20, 'unread' )['items'];
		$read   = $this->service->list_for_user( $this->recipient_id, null, 20, 'read' )['items'];
		$all    = $this->service->list_for_user( $this->recipient_id, null, 20, 'all' )['items'];

		$this->assertCount( 1, $read );
		$this->assertSame( count( $all ), count( $unread ) + count( $read ), 'unread + read must partition all' );
	}
}
