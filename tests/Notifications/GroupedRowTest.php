<?php
/**
 * A grouped bell row counts people, and each person's event is checked on its own.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationMessageService;

/**
 * @covers \BuddyNext\Notifications\GroupedItems
 * @covers \BuddyNext\Notifications\NotificationService::create
 * @covers \BuddyNext\Notifications\IntegrationNotificationListener::filter_visible_rows
 */
class GroupedRowTest extends \WP_UnitTestCase {

	private int $recipient;

	/**
	 * Members who reply, keyed by a short name.
	 *
	 * @var array<string,int>
	 */
	private array $people = array();

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->recipient = self::factory()->user->create();
		foreach ( array( 'Aisha', 'Ben', 'Carla' ) as $name ) {
			$this->people[ $name ] = self::factory()->user->create( array( 'display_name' => $name ) );
		}
		add_filter(
			'jetonomy_community_notification_types',
			static function ( array $types ): array {
				$types['reply_to_post'] = array( 'label' => 'Replies to your topics' );
				return $types;
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'jetonomy_community_notification_types' );
		remove_all_filters( 'jetonomy_community_notification_visible' );
		parent::tear_down();
	}

	/**
	 * One person replies: the plugin's payload, topic as the object, the reply as the item.
	 *
	 * @param int $actor    Replier.
	 * @param int $reply_id Reply id.
	 * @param int $topic_id Topic id.
	 */
	private function reply( int $actor, int $reply_id, int $topic_id = 500 ): void {
		$payload = array(
			'recipient_id'    => $this->recipient,
			'type'            => 'reply_to_post',
			'actor_id'        => $actor,
			'object_type'     => 'post',
			'object_id'       => $topic_id,
			'item_type'       => 'reply',
			'item_id'         => $reply_id,
			'message'         => 'Someone replied to "Topic".',
			'message_single'  => '{actor} replied to "Topic".',
			'message_grouped' => '{actor} and {others} replied to "Topic".',
			'url'             => 'https://example.org/t/topic/',
			'group_key'       => 'reply_to_post_' . $topic_id,
		);
		do_action( 'jetonomy_notification_created', 1, $this->recipient, 'reply_to_post', 'post', $topic_id, 'legacy', 'https://example.org/legacy', $payload );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'jetonomy.reply_to_post'", $this->recipient ), ARRAY_A );
	}

	/**
	 * The bell page as the member sees it: partner visibility applied, message composed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function bell(): array {
		$page  = buddynext_service( 'notifications' )->list_for_user( $this->recipient );
		$items = array_values( array_filter( (array) ( $page['items'] ?? $page ), static fn( $r ) => 'jetonomy.reply_to_post' === ( $r['type'] ?? '' ) ) );
		$out   = array();
		foreach ( $items as $item ) {
			$out[] = array_merge( $item, array( 'composed' => ( new NotificationMessageService() )->compose( $item )['message'] ) );
		}
		return $out;
	}

	public function test_one_person_replying_around_others_is_still_one_person(): void {
		$this->reply( $this->people['Aisha'], 11 );
		$this->reply( $this->people['Ben'], 12 );
		$this->reply( $this->people['Aisha'], 13 );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 2, (int) $rows[0]['group_count'], 'Aisha, Ben, Aisha is two people.' );
		$this->assertSame( $this->people['Aisha'], (int) $rows[0]['sender_id'], 'The newest replier is the name shown.' );
		$this->assertSame( 'Aisha and 1 other replied to "Topic".', ( new NotificationMessageService() )->compose( $rows[0] )['message'] );
	}

	public function test_each_event_keeps_the_item_it_came_from(): void {
		$this->reply( $this->people['Aisha'], 11 );
		$this->reply( $this->people['Ben'], 12 );

		$items = json_decode( (string) $this->rows()[0]['data'], true )['items'];
		$this->assertEquals(
			array(
				array( 'a' => $this->people['Ben'], 't' => 'reply', 'i' => 12 ),
				array( 'a' => $this->people['Aisha'], 't' => 'reply', 'i' => 11 ),
			),
			$items,
			'Newest first, one entry per event.'
		);
	}

	public function test_a_refire_of_the_same_event_is_not_counted_twice(): void {
		$this->reply( $this->people['Aisha'], 11 );
		$this->reply( $this->people['Aisha'], 11 );

		$this->assertSame( 1, (int) $this->rows()[0]['group_count'] );
		$this->assertCount( 1, json_decode( (string) $this->rows()[0]['data'], true )['items'] );
	}

	public function test_a_hidden_item_shrinks_the_row_and_the_last_one_removes_it(): void {
		$this->reply( $this->people['Aisha'], 11 );
		$this->reply( $this->people['Ben'], 12 );
		$this->reply( $this->people['Carla'], 13 );
		$this->assertSame( 3, (int) $this->bell()[0]['group_count'] );

		// The plugin hides two of the three replies (trashed, and a banned replier).
		$hidden = array( 12, 13 );
		add_filter(
			'jetonomy_community_notification_visible',
			static function ( array $visible, int $viewer, array $targets ) use ( &$hidden ): array {
				foreach ( $targets as $key => $target ) {
					if ( 'reply' === $target['object_type'] && in_array( $target['object_id'], $hidden, true ) ) {
						$visible[ $key ] = false;
					}
				}
				return $visible;
			},
			10,
			3
		);

		$bell = $this->bell();
		$this->assertCount( 1, $bell );
		$this->assertSame( 1, (int) $bell[0]['group_count'], 'Only Aisha is left.' );
		$this->assertSame( $this->people['Aisha'], (int) $bell[0]['sender_id'] );
		$this->assertSame( 'Aisha replied to "Topic".', $bell[0]['composed'] );

		$hidden = array( 11, 12, 13 );
		$this->assertCount( 0, $this->bell(), 'No visible reply, no row.' );
	}

	public function test_the_newest_replier_being_hidden_hands_the_name_to_the_next_one(): void {
		$this->reply( $this->people['Aisha'], 11 );
		$this->reply( $this->people['Ben'], 12 );

		add_filter(
			'jetonomy_community_notification_visible',
			static function ( array $visible, int $viewer, array $targets ): array {
				foreach ( $targets as $key => $target ) {
					$visible[ $key ] = ! ( 'reply' === $target['object_type'] && 12 === $target['object_id'] );
				}
				return $visible;
			},
			10,
			3
		);

		$bell = $this->bell();
		$this->assertCount( 1, $bell );
		$this->assertSame( $this->people['Aisha'], (int) $bell[0]['sender_id'] );
	}

	public function test_the_plugin_is_asked_per_item_with_the_actor(): void {
		$this->reply( $this->people['Aisha'], 11 );
		$this->reply( $this->people['Ben'], 12 );

		$asked = array();
		add_filter(
			'jetonomy_community_notification_visible',
			static function ( array $visible, int $viewer, array $targets ) use ( &$asked ): array {
				$asked = $targets;
				return $visible;
			},
			10,
			3
		);
		$this->bell();

		$items = array_values( array_filter( $asked, static fn( $t ) => ! empty( $t['item'] ) ) );
		$this->assertCount( 2, $items );
		$this->assertSame( array( 'reply', 12, true ), array( $items[0]['object_type'], $items[0]['object_id'], $items[0]['item'] ) );
		$this->assertSame( $this->people['Ben'], $items[0]['actor_id'] );
	}

	public function test_a_sender_less_event_is_not_an_actor(): void {
		$this->reply( 0, 21 );
		$this->reply( 0, 22 );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertArrayNotHasKey( 'items', json_decode( (string) $rows[0]['data'], true ), 'An anonymous event adds no person to a tally.' );
	}

	public function test_a_long_row_keeps_a_bounded_list_and_still_counts_everyone(): void {
		for ( $n = 1; $n <= 55; $n++ ) {
			$this->reply( self::factory()->user->create(), 1000 + $n );
		}

		$row  = $this->rows()[0];
		$data = json_decode( (string) $row['data'], true );
		$this->assertCount( 50, $data['items'], 'The stored list is capped.' );
		$this->assertSame( 5, (int) $data['more'] );
		$this->assertSame( 55, (int) $row['group_count'], 'Everyone still counts.' );
	}
}
