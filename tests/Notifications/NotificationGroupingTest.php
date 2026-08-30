<?php
/**
 * Read-time grouping of repeated notifications.
 *
 * Every notification keeps its own row; repeats of the same event are folded
 * together only for display. These tests pin the properties that made read-time
 * the right choice over minting a shared group_key at write time.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationService;

/**
 * @covers \BuddyNext\Notifications\NotificationService::group_page
 * @covers \BuddyNext\Notifications\NotificationService::list_for_user
 */
class NotificationGroupingTest extends \WP_UnitTestCase {

	private NotificationService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new NotificationService();
	}

	/**
	 * Insert a notification row directly, bypassing create()'s listeners.
	 *
	 * @param int    $recipient Recipient.
	 * @param string $type      Type slug.
	 * @param int    $object_id Object.
	 * @param int    $sender    Actor.
	 * @param int    $is_read   Read flag.
	 * @return void
	 */
	private function seed( int $recipient, string $type, int $object_id, int $sender, int $is_read = 0 ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_notifications',
			array(
				'recipient_id' => $recipient,
				'sender_id'    => $sender,
				'type'         => $type,
				'object_type'  => 'post',
				'object_id'    => $object_id,
				'group_key'    => $type . '-' . $object_id . '-' . $sender,
				'is_read'      => $is_read,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%d' )
		);
	}

	/**
	 * Items returned for a viewer.
	 *
	 * @param int $user Recipient.
	 * @return array<int,array<string,mixed>>
	 */
	private function items( int $user ): array {
		return (array) ( $this->service->list_for_user( $user, null, 50 )['items'] ?? array() );
	}

	/**
	 * The reported case: many members acting on ONE object become one entry.
	 */
	public function test_repeats_on_the_same_object_collapse_into_one_entry(): void {
		foreach ( range( 1, 8 ) as $actor ) {
			$this->seed( 700, 'bn.space_join_requested', 42, $actor );
		}

		$items = $this->items( 700 );

		$this->assertCount( 1, $items, 'eight requests on one space must read as one entry' );
		$this->assertSame( 8, $items[0]['group_size'] );
		$this->assertCount( 8, $items[0]['group_ids'] );
		$this->assertCount( 8, $items[0]['group_actors'], 'every distinct actor is kept for the avatar stack' );
	}

	/**
	 * Different objects stay apart — collapsing them would merge unrelated things.
	 */
	public function test_the_same_type_on_different_objects_stays_separate(): void {
		$this->seed( 701, 'bn.new_report', 10, 1 );
		$this->seed( 701, 'bn.new_report', 11, 1 );
		$this->seed( 701, 'bn.new_report', 10, 2 );

		$items = $this->items( 701 );

		$this->assertCount( 2, $items );
		$sizes = array_map( static fn ( array $i ): int => (int) $i['group_size'], $items );
		sort( $sizes );
		$this->assertSame( array( 1, 2 ), $sizes );
	}

	/**
	 * Different types on the SAME object stay apart: a join and a join request
	 * are different things to act on.
	 */
	public function test_different_types_on_one_object_stay_separate(): void {
		$this->seed( 702, 'bn.space_join', 5, 1 );
		$this->seed( 702, 'bn.space_join_requested', 5, 2 );

		$this->assertCount( 2, $this->items( 702 ) );
	}

	/**
	 * A group is unread when ANY member of it is, or the badge would count items
	 * the reader cannot see.
	 */
	public function test_a_group_is_unread_when_any_member_is_unread(): void {
		$this->seed( 703, 'bn.post_reacted', 9, 1, 1 );
		$this->seed( 703, 'bn.post_reacted', 9, 2, 1 );
		$this->seed( 703, 'bn.post_reacted', 9, 3, 0 );

		$items = $this->items( 703 );

		$this->assertCount( 1, $items );
		$this->assertFalse( $items[0]['is_read'] );
	}

	/**
	 * An actor who acted twice on one object is listed once.
	 */
	public function test_a_repeated_actor_appears_once_in_the_group(): void {
		$this->seed( 704, 'bn.post_commented', 3, 55 );
		$this->seed( 704, 'bn.post_commented', 3, 55 );
		$this->seed( 704, 'bn.post_commented', 3, 56 );

		$items = $this->items( 704 );

		$this->assertSame( 3, $items[0]['group_size'], 'every event is still counted' );
		// Newest first: the list is ordered created_at DESC, and the group keeps
		// actors in the order they LAST acted, so 56 (inserted last) leads.
		$this->assertSame( array( 56, 55 ), $items[0]['group_actors'], 'each actor once, most recent first' );
	}

	/**
	 * Grouping is display-only: the rows are all still there.
	 */
	public function test_no_rows_are_destroyed_by_grouping(): void {
		global $wpdb;
		foreach ( range( 1, 6 ) as $actor ) {
			$this->seed( 705, 'bn.space_join', 77, $actor );
		}

		$this->assertCount( 1, $this->items( 705 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d", 705 )
		);
		$this->assertSame( 6, $stored, 'read-time grouping must not merge anything in the database' );
	}

	/**
	 * A type can opt OUT: each occurrence stays its own entry.
	 */
	public function test_an_ungroupable_type_is_left_alone(): void {
		add_filter(
			'buddynext_notification_ungroupable_types',
			static fn (): array => array( 'bn.message' )
		);

		foreach ( range( 1, 4 ) as $actor ) {
			$this->seed( 706, 'bn.message', 12, $actor );
		}

		$this->assertCount( 4, $this->items( 706 ), 'an opted-out type must not collapse' );
	}

	/**
	 * A notification with no object cannot be grouped and passes through whole.
	 */
	public function test_rows_without_an_object_are_never_grouped(): void {
		global $wpdb;
		foreach ( range( 1, 3 ) as $i ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_notifications',
				array(
					'recipient_id' => 707,
					'sender_id'    => $i,
					'type'         => 'bn.system',
					'object_type'  => null,
					'object_id'    => 0,
					'group_key'    => 'sys-' . $i,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s' )
			);
		}

		$this->assertCount( 3, $this->items( 707 ) );
	}
}
