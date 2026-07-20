<?php
/**
 * Tests for MembersSidebarProvider.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Realtime\PresenceService;
use BuddyNext\Sidebar\Providers\MembersSidebarProvider;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Sidebar\Providers\MembersSidebarProvider
 */
class MembersSidebarProviderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_cache_flush();
	}

	public function test_unrelated_surface_returns_list_unchanged(): void {
		$this->assertSame( array(), ( new MembersSidebarProvider() )->widgets( array(), 'feed' ) );
	}

	public function test_no_by_type_widget_it_duplicates_the_toolbar_filter(): void {
		// The directory toolbar already has an "All member types" dropdown, so a
		// "By type" sidebar card would duplicate that facet — the provider must
		// never register one.
		$ids = wp_list_pluck( ( new MembersSidebarProvider() )->widgets( array(), 'members' ), 'id' );
		$this->assertNotContains( 'members-by-type', $ids );
	}

	public function test_online_now_is_a_titled_card_when_a_member_is_present(): void {
		$online_user = self::factory()->user->create( array( 'display_name' => 'Online Olive' ) );
		PresenceService::write( $online_user, time() );

		$widgets = ( new MembersSidebarProvider() )->widgets( array(), 'members' );

		$by_id = array();
		foreach ( $widgets as $widget ) {
			$by_id[ $widget['id'] ] = $widget;
		}

		$this->assertArrayHasKey( 'members-online-now', $by_id, 'A present member yields the online-now card.' );
		$this->assertStringStartsWith( 'Online now (', (string) $by_id['members-online-now']['title'] );
		$this->assertSame( 'users', $by_id['members-online-now']['icon'] );
		// Titled card — uses the registry default chrome (parts/sidebar-card.php).
		$this->assertArrayNotHasKey( 'chrome', $by_id['members-online-now'] );
	}

	public function test_discovery_widgets_are_self_chromed_when_present(): void {
		// People-to-follow / what's-happening are self-chromed (chrome => false).
		// Their data comes from the sidebar_widgets service, which may be empty in
		// the harness — so assert the contract only for any that DO appear.
		$widgets = ( new MembersSidebarProvider() )->widgets( array(), 'members' );
		foreach ( $widgets as $widget ) {
			if ( in_array( $widget['id'], array( 'members-people-to-follow', 'members-whats-happening' ), true ) ) {
				$this->assertArrayHasKey( 'chrome', $widget );
				$this->assertFalse( $widget['chrome'] );
				$this->assertSame( array( 'members' ), $widget['surfaces'] );
			}
		}
		$this->assertTrue( true );
	}
}
