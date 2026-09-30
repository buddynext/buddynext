<?php
/**
 * WPMediaVerse's Messages switch is the only one: the Features state and the
 * bell follow it, whatever BuddyNext once stored (card 10344001598).
 *
 * @package BuddyNext\Tests\Messages
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Messages;

use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Messages\MessagesData::entry_enabled
 * @covers \BuddyNext\Core\FeatureRegistry::is_enabled
 * @covers \BuddyNext\Notifications\NotificationService::unread_count
 */
class MessagingSwitchTest extends \WP_UnitTestCase {

	private bool $messaging_on = false;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		add_filter(
			'buddynext_media_service',
			function ( $svc, $key ) {
				if ( 'messaging' !== $key ) {
					return $svc;
				}
				return $this->messaging_on ? new class() {
					public function get_conversations(): array {
						return array();
					}
				} : null;
			},
			10,
			2
		);
	}

	private function notify( int $to, string $type ): void {
		buddynext_service( 'notifications' )->create(
			array( 'recipient_id' => $to, 'sender_id' => self::factory()->user->create(), 'type' => $type, 'object_type' => 'user', 'object_id' => 0 )
		);
	}

	public function test_features_and_bell_follow_the_mediaverse_switch(): void {
		$member = self::factory()->user->create();
		$this->notify( $member, 'bn.new_message' );
		$this->notify( $member, 'bn.mention' );
		$features      = buddynext_service( 'features' );
		$notifications = buddynext_service( 'notifications' );

		// Off in WPMediaVerse, even though BuddyNext's old option says on.
		update_option( 'buddynext_features', array( 'messages' => true ) );
		$this->messaging_on = false;
		wp_cache_flush();
		$this->assertFalse( $features->is_enabled( 'messages' ) );
		$this->assertSame( 1, $notifications->unread_count( $member ), 'message notification left out while off' );
		$types = wp_list_pluck( $notifications->list_for_user( $member )['items'], 'type' );
		$this->assertNotContains( 'bn.new_message', $types );

		// On in WPMediaVerse, even though BuddyNext's old option says off.
		update_option( 'buddynext_features', array( 'messages' => false ) );
		$this->messaging_on = true;
		wp_cache_flush();
		$this->assertTrue( $features->is_enabled( 'messages' ) );
		$this->assertSame( 2, $notifications->unread_count( $member ), 'it comes back when on' );
	}
}
