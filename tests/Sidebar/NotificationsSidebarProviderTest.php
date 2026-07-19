<?php
/**
 * Tests for NotificationsSidebarProvider — the notifications surface carries
 * six self-chromed sidecards rebuilt from raw context, with "this week"
 * stats and the muted list gated on a logged-in viewer.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Sidebar\Providers\NotificationsSidebarProvider;
use BuddyNext\Sidebar\Surface;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Sidebar\Providers\NotificationsSidebarProvider
 */
class NotificationsSidebarProviderTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Surface::reset();
		parent::tear_down();
	}

	/**
	 * Pluck descriptor ids.
	 *
	 * @param array<int,array<string,mixed>> $widgets Descriptors.
	 * @return array<int,string>
	 */
	private function ids( array $widgets ): array {
		return array_map( static fn( array $w ): string => (string) $w['id'], $widgets );
	}

	/**
	 * Sets Surface context matching the shape templates/notifications/index.php
	 * passes via $sidebar_data.
	 *
	 * @param array<string,mixed> $overrides Context overrides.
	 * @return void
	 */
	private function set_context( array $overrides = array() ): void {
		Surface::set(
			'notifications',
			array_merge(
				array(
					'active_filter'   => '',
					'total_unread'    => 2,
					'reaction_unread' => 0,
					'comment_unread'  => 1,
					'mention_unread'  => 1,
					'follow_unread'   => 0,
					'space_unread'    => 0,
					'message_unread'  => 0,
					'recent_actors'   => array(),
				),
				$overrides
			)
		);
	}

	public function test_non_notifications_surface_short_circuits(): void {
		$this->assertSame( array(), ( new NotificationsSidebarProvider() )->widgets( array(), 'feed' ) );
	}

	public function test_logged_in_viewer_gets_all_six_descriptors(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$this->set_context();
		$widgets = ( new NotificationsSidebarProvider() )->widgets( array(), 'notifications' );
		$ids     = $this->ids( $widgets );

		$this->assertContains( 'notif-quick-filters', $ids );
		$this->assertContains( 'notif-by-type', $ids );
		$this->assertContains( 'notif-recent-actors', $ids );
		$this->assertContains( 'notif-prefs', $ids );
		$this->assertContains( 'notif-this-week', $ids );
		$this->assertContains( 'notif-muted', $ids );

		foreach ( $widgets as $w ) {
			$this->assertArrayHasKey( 'chrome', $w );
			$this->assertFalse( $w['chrome'], "Widget '{$w['id']}' must be self-chromed (chrome => false)." );
			$this->assertSame( array( 'notifications' ), $w['surfaces'] );
		}
	}

	public function test_logged_out_viewer_never_sees_this_week_or_muted(): void {
		wp_set_current_user( 0 );

		$this->set_context();
		$widgets = ( new NotificationsSidebarProvider() )->widgets( array(), 'notifications' );
		$ids     = $this->ids( $widgets );

		$this->assertContains( 'notif-quick-filters', $ids );
		$this->assertContains( 'notif-by-type', $ids );
		$this->assertContains( 'notif-recent-actors', $ids );
		$this->assertContains( 'notif-prefs', $ids );
		$this->assertNotContains( 'notif-this-week', $ids, 'A logged-out viewer must never see personal "this week" stats.' );
		$this->assertNotContains( 'notif-muted', $ids, 'A logged-out viewer must never see the muted-list widget.' );
	}
}
