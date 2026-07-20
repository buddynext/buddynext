<?php
/**
 * Tests for SpaceSidebarProvider.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Core\Installer;
use BuddyNext\Sidebar\Providers\SpaceSidebarProvider;
use BuddyNext\Sidebar\Surface;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Sidebar\Providers\SpaceSidebarProvider
 */
class SpaceSidebarProviderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_cache_flush();
	}

	public function tear_down(): void {
		Surface::reset();
		parent::tear_down();
	}

	public function test_unrelated_surface_returns_list_unchanged(): void {
		$this->assertSame( array(), ( new SpaceSidebarProvider() )->widgets( array(), 'feed' ) );
	}

	public function test_space_surface_without_context_returns_list_unchanged(): void {
		// No Surface::set() called at all — context() defaults to [].
		$this->assertSame( array(), ( new SpaceSidebarProvider() )->widgets( array(), 'space' ) );
	}

	public function test_space_surface_with_zero_space_id_returns_list_unchanged(): void {
		Surface::set( 'space', array( 'space_id' => 0, 'viewer_id' => 0, 'active_tab' => 'feed' ) );
		$this->assertSame( array(), ( new SpaceSidebarProvider() )->widgets( array(), 'space' ) );
	}

	/**
	 * Creates an open space with an owner and one regular member, returning
	 * both ids and the space id — the fixture shared by the descriptor tests.
	 *
	 * @return array{space_id:int,owner_id:int,member_id:int}
	 */
	private function seed_space(): array {
		$owner    = self::factory()->user->create( array( 'display_name' => 'Sage Owner' ) );
		$space_id = ( new SpaceService() )->create(
			$owner,
			array(
				'name'        => 'Test Space',
				'slug'        => 'test-space',
				'type'        => 'open',
				'description' => 'A space for testing.',
			)
		);
		$this->assertIsInt( $space_id );

		$member = self::factory()->user->create( array( 'display_name' => 'Rocky Regular' ) );
		( new SpaceMemberService() )->join( (int) $space_id, $member );

		return array(
			'space_id'  => (int) $space_id,
			'owner_id'  => $owner,
			'member_id' => $member,
		);
	}

	public function test_space_surface_returns_expected_descriptors_on_feed_tab(): void {
		$fixture = $this->seed_space();
		wp_set_current_user( $fixture['member_id'] );

		Surface::set(
			'space',
			array(
				'space_id'   => $fixture['space_id'],
				'viewer_id'  => $fixture['member_id'],
				'active_tab' => 'feed',
			)
		);

		$widgets = ( new SpaceSidebarProvider() )->widgets( array(), 'space' );
		$ids     = wp_list_pluck( $widgets, 'id' );

		$this->assertContains( 'space-about', $ids );
		$this->assertContains( 'space-owners', $ids );
		$this->assertContains( 'space-members', $ids, 'Members-preview card must render on a non-Members tab.' );

		// Titled cards using the registry's DEFAULT chrome — never chrome => false.
		foreach ( $widgets as $widget ) {
			$this->assertArrayNotHasKey( 'chrome', $widget );
		}

		$by_id = array();
		foreach ( $widgets as $widget ) {
			$by_id[ $widget['id'] ] = $widget;
		}
		$this->assertSame( 'About this space', $by_id['space-about']['title'] );
		$this->assertSame( 'info', $by_id['space-about']['icon'] );
		$this->assertSame( 'Owner', $by_id['space-owners']['title'] );
		$this->assertSame( 'shield', $by_id['space-owners']['icon'] );
	}

	public function test_members_tab_skips_members_preview_card(): void {
		$fixture = $this->seed_space();
		wp_set_current_user( $fixture['member_id'] );

		Surface::set(
			'space',
			array(
				'space_id'   => $fixture['space_id'],
				'viewer_id'  => $fixture['member_id'],
				'active_tab' => 'members',
			)
		);

		$widgets = ( new SpaceSidebarProvider() )->widgets( array(), 'space' );
		$ids     = wp_list_pluck( $widgets, 'id' );

		$this->assertNotContains( 'space-members', $ids, 'Members-preview card must be skipped on the Members tab.' );
		// The rest of the rail still renders on the Members tab.
		$this->assertContains( 'space-about', $ids );
		$this->assertContains( 'space-owners', $ids );
	}

	public function test_private_space_hides_roster_from_non_member(): void {
		$owner    = self::factory()->user->create();
		$space_id = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Private Test Space',
				'slug' => 'private-test-space',
				'type' => 'private',
			)
		);
		$this->assertIsInt( $space_id );

		$member = self::factory()->user->create();
		( new SpaceMemberService() )->join( (int) $space_id, $member );

		$outsider = self::factory()->user->create();
		wp_set_current_user( $outsider );

		Surface::set(
			'space',
			array(
				'space_id'   => (int) $space_id,
				'viewer_id'  => $outsider,
				'active_tab' => 'feed',
			)
		);

		$widgets = ( new SpaceSidebarProvider() )->widgets( array(), 'space' );
		$ids     = wp_list_pluck( $widgets, 'id' );

		// A non-member of a private space still sees who runs it (owner card)…
		$this->assertContains( 'space-owners', $ids );
		// …but never the regular-member roster or the top-contributor list.
		$this->assertNotContains( 'space-members', $ids );
		$this->assertNotContains( 'space-top-contributors', $ids );
	}
}
