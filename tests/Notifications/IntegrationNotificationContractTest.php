<?php
/**
 * The integration notification contract: a plugin passes one payload as the
 * last argument of its own notification hook and BuddyNext shows it.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\IntegrationNotificationListener;
use BuddyNext\Notifications\NotificationMessageService;
use BuddyNext\Notifications\NotificationPrefCatalogue;

/**
 * @covers \BuddyNext\Notifications\IntegrationNotificationListener
 */
class IntegrationNotificationContractTest extends \WP_UnitTestCase {

	private int $recipient;
	private int $actor;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->recipient = self::factory()->user->create();
		$this->actor     = self::factory()->user->create( array( 'display_name' => 'Aisha' ) );
		add_filter( 'jetonomy_community_notification_types', array( $this, 'declare_types' ) );
	}

	/**
	 * Jetonomy has adopted the contract.
	 *
	 * @param array<string,mixed> $types Types.
	 * @return array<string,mixed>
	 */
	public function declare_types( array $types ): array {
		$types['reply_to_post'] = array(
			'label'      => 'Replies to your topics',
			'default_on' => true,
		);
		return $types;
	}

	public function tear_down(): void {
		remove_all_filters( 'jetonomy_community_notification_types' );
		remove_all_filters( 'jetonomy_community_notification_visible' );
		delete_option( 'buddynext_integration_jetonomy_nav' );
		parent::tear_down();
	}

	/**
	 * Fire Jetonomy's real hook: its seven existing arguments, then the payload.
	 *
	 * @param array<string,mixed> $overrides Payload overrides.
	 */
	private function fire( array $overrides = array() ): void {
		$payload = array_merge(
			array(
				'recipient_id'    => $this->recipient,
				'type'            => 'reply_to_post',
				'actor_id'        => $this->actor,
				'object_type'     => 'post',
				'object_id'       => 1153,
				'message'         => 'Aisha replied to "Welcome thread".',
				'message_grouped' => '{actor} and {others} replied to "Welcome thread".',
				'url'             => 'https://example.org/t/welcome/#reply-88',
				'group_key'       => 'reply_to_post_1153',
			),
			$overrides
		);
		do_action( 'jetonomy_notification_created', 991, $this->recipient, 'reply_to_post', 'post', 1153, 'legacy', 'https://example.org/legacy', $payload );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'jetonomy.reply_to_post'", $this->recipient ), ARRAY_A );
	}

	public function test_payload_as_last_argument_creates_one_namespaced_row(): void {
		$this->fire();
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'jetonomy_post', $rows[0]['object_type'] );
		$this->assertSame( 1153, (int) $rows[0]['object_id'] );

		$composed = ( new NotificationMessageService() )->compose( $rows[0] );
		$this->assertSame( 'Aisha replied to "Welcome thread".', $composed['message'] );
		$this->assertSame( 'https://example.org/t/welcome/#reply-88', $composed['url'] );
		$this->assertSame( 'Forums', $composed['label'] );
	}

	public function test_hook_without_payload_writes_nothing(): void {
		do_action( 'jetonomy_notification_created', 991, $this->recipient, 'reply_to_post', 'post', 1153, 'legacy', 'https://example.org/legacy' );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_grouped_rows_use_the_plugins_grouped_sentence(): void {
		$this->fire();
		$second = self::factory()->user->create( array( 'display_name' => 'Ben' ) );
		$this->fire( array( 'actor_id' => $second ) );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 2, (int) $rows[0]['group_count'] );
		$composed = ( new NotificationMessageService() )->compose( $rows[0] );
		$this->assertSame( 'Ben and 1 other replied to "Welcome thread".', $composed['message'] );
	}

	public function test_dropped_for_self_block_and_switched_off_integration(): void {
		$this->fire( array( 'actor_id' => $this->recipient ) );
		buddynext_service( 'blocks' )->block( $this->recipient, $this->actor );
		$this->fire();
		$this->assertCount( 0, $this->rows() );
		buddynext_service( 'blocks' )->unblock( $this->recipient, $this->actor );

		update_option( 'buddynext_integration_jetonomy_nav', '0' );
		$this->fire();
		$this->assertCount( 0, $this->rows() );
	}

	public function test_plugin_answers_visibility_and_removal_deletes(): void {
		$this->fire();
		$service = buddynext_service( 'notifications' );
		$this->assertCount( 1, $this->contract_rows( $service->list_for_user( $this->recipient ) ) );

		add_filter(
			'jetonomy_community_notification_visible',
			static function ( array $visible, int $viewer, array $targets ): array {
				foreach ( $targets as $key => $target ) {
					$visible[ $key ] = 1153 !== $target['object_id'] || 'post' !== $target['object_type'];
				}
				return $visible;
			},
			10,
			3
		);
		$this->assertCount( 0, $this->contract_rows( $service->list_for_user( $this->recipient ) ) );

		do_action( 'jetonomy_community_notification_removed', 'post', 1153 );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_declared_types_get_a_section(): void {
		$this->assertTrue( IntegrationNotificationListener::adopted( 'jetonomy' ) );

		$catalogue = new NotificationPrefCatalogue();
		$all       = $catalogue->all();
		$this->assertSame( 'jetonomy', $all['jetonomy.reply_to_post']['group'] );
		$this->assertFalse( $all['jetonomy.reply_to_post']['can_email'] );
		$this->assertSame( 'Forums', $catalogue->group_label( 'jetonomy' ) );

		$this->fire();
		$this->assertCount( 1, $this->rows() );
	}

	public function test_a_payload_is_ignored_until_the_plugin_declares_its_types(): void {
		remove_all_filters( 'jetonomy_community_notification_types' );
		$this->assertFalse( IntegrationNotificationListener::adopted( 'jetonomy' ) );
		$this->fire();
		$this->assertCount( 0, $this->rows() );
	}

	public function test_renotify_false_refreshes_the_row_quietly(): void {
		$sent = 0;
		$spy  = static function () use ( &$sent ): void {
			++$sent;
		};
		add_action( 'buddynext_notification_created', $spy );
		$this->fire( array( 'renotify' => false, 'message' => 'Best week: 12 points.' ) );
		$service = buddynext_service( 'notifications' );
		$service->mark_all_read( $this->recipient );
		$this->fire( array( 'renotify' => false, 'message' => 'Best week: 30 points.' ) );
		remove_action( 'buddynext_notification_created', $spy );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 1, $sent, 'The repeat must not alert again.' );
		$this->assertSame( 1, (int) $rows[0]['group_count'] );
		$this->assertSame( 1, (int) $rows[0]['is_read'], 'The repeat keeps the read state.' );
		$this->assertSame( 'Best week: 30 points.', json_decode( $rows[0]['data'], true )['message'] );
	}

	/**
	 * @param array<string,mixed> $page list_for_user() result.
	 * @return array<int,array<string,mixed>>
	 */
	private function contract_rows( array $page ): array {
		$items = $page['items'] ?? $page;
		return array_values( array_filter( (array) $items, static fn( $r ) => 'jetonomy.reply_to_post' === ( $r['type'] ?? '' ) ) );
	}
}
