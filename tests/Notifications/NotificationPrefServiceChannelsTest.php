<?php
/**
 * Tests for NotificationPrefService channel + space-pref helpers.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationPrefService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Notifications\NotificationPrefService::get_channel_prefs
 * @covers \BuddyNext\Notifications\NotificationPrefService::set_channel_prefs
 * @covers \BuddyNext\Notifications\NotificationPrefService::list_space_notification_prefs
 */
class NotificationPrefServiceChannelsTest extends \WP_UnitTestCase {

	private NotificationPrefService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new NotificationPrefService();
	}

	public function test_channel_prefs_partial_update_round_trip(): void {
		$user = self::factory()->user->create();

		$this->service->set_channel_prefs( $user, array( 'email' => false ) );
		$this->assertFalse( $this->service->get_channel_prefs( $user )['email'] );

		// A second partial update must not clobber the first key.
		$this->service->set_channel_prefs( $user, array( 'push' => false ) );
		$stored = $this->service->get_channel_prefs( $user );
		$this->assertFalse( $stored['email'] );
		$this->assertFalse( $stored['push'] );
	}

	public function test_get_channel_prefs_empty_by_default(): void {
		$user = self::factory()->user->create();
		$this->assertSame( array(), $this->service->get_channel_prefs( $user ) );
	}

	public function test_list_space_notification_prefs(): void {
		$owner    = self::factory()->user->create();
		$member   = self::factory()->user->create();
		$space_id = (int) ( new SpaceService() )->create(
			$owner,
			array( 'name' => 'Notify Space', 'slug' => 'notify-space', 'type' => 'open' )
		);
		( new SpaceMemberService() )->join( $space_id, $member );

		$prefs = $this->service->list_space_notification_prefs( $member );
		$this->assertNotEmpty( $prefs );
		$this->assertSame( $space_id, $prefs[0]['space_id'] );
		$this->assertSame( 'Notify Space', $prefs[0]['name'] );
		$this->assertSame( 'all', $prefs[0]['pref'] );
	}
}
