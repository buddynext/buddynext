<?php
/**
 * Tests for MembersSidebarProvider.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Core\CacheService;
use BuddyNext\Core\Installer;
use BuddyNext\MemberTypes\MemberTypeService;
use BuddyNext\Realtime\PresenceService;
use BuddyNext\Sidebar\Providers\MembersSidebarProvider;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Sidebar\Providers\MembersSidebarProvider
 */
class MembersSidebarProviderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_cache_flush();
	}

	public function test_unrelated_surface_returns_list_unchanged(): void {
		$this->assertSame( array(), ( new MembersSidebarProvider() )->widgets( array(), 'feed' ) );
	}

	public function test_members_surface_with_no_online_users_omits_online_now_card(): void {
		// Installer::run() always seeds two starter member types (contributor, staff)
		// with show_in_dir=1, so the "By type" card is present even with zero
		// assignments — matches templates/directory/members.php's `$dir_types` gate,
		// which never checked member_count, only `show_in_dir`. Online-now's guard
		// (`! empty( $bn_online_rows )`) DOES depend on presence data, so with no
		// bn_presence rows written, only the by-type descriptor should appear.
		$widgets = ( new MembersSidebarProvider() )->widgets( array(), 'members' );

		$this->assertSame( array( 'members-by-type' ), wp_list_pluck( $widgets, 'id' ) );
	}

	public function test_members_surface_with_seeded_data_returns_both_descriptors(): void {
		// Online-now: a user with a fresh presence heartbeat.
		$online_user = self::factory()->user->create( array( 'display_name' => 'Online Olive' ) );
		PresenceService::write( $online_user, time() );

		// By-type: one directory-visible member type with an assigned member.
		$type_service = new MemberTypeService( new CacheService() );
		$type_id      = $type_service->create(
			array(
				'slug' => 'mentor',
				'name' => 'Mentor',
			)
		);
		$this->assertIsInt( $type_id, 'Fixture member type must be created.' );
		$type_service->assign_type( $online_user, $type_id );

		$widgets = ( new MembersSidebarProvider() )->widgets( array(), 'members' );

		$this->assertCount( 2, $widgets );
		$this->assertSame(
			array( 'members-online-now', 'members-by-type' ),
			wp_list_pluck( $widgets, 'id' )
		);

		$by_id = array();
		foreach ( $widgets as $widget ) {
			$by_id[ $widget['id'] ] = $widget;
		}

		$this->assertStringStartsWith( 'Online now (', (string) $by_id['members-online-now']['title'] );
		$this->assertSame( 'users', $by_id['members-online-now']['icon'] );
		$this->assertArrayNotHasKey( 'chrome', $by_id['members-online-now'] );

		$this->assertSame( 'By type', $by_id['members-by-type']['title'] );
		$this->assertSame( 'tag', $by_id['members-by-type']['icon'] );
		$this->assertArrayNotHasKey( 'chrome', $by_id['members-by-type'] );

		// Neither descriptor sets chrome => false — these are titled cards that
		// use the registry's default chrome (parts/sidebar-card.php wraps them).
		foreach ( $widgets as $widget ) {
			$this->assertArrayNotHasKey( 'chrome', $widget );
		}
	}
}
