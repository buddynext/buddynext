<?php
declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;
use BuddyNext\Sidebar\Providers\ExploreSidebarProvider;
use WP_UnitTestCase;

class ExploreSidebarProviderTest extends WP_UnitTestCase {
	public function test_explore_surface_returns_four_self_chromed_descriptors(): void {
		$widgets = ( new ExploreSidebarProvider() )->widgets( array(), 'explore' );

		$this->assertCount( 4, $widgets );
		$this->assertSame(
			array( 'explore-pulse', 'explore-trending-tags', 'explore-people', 'explore-browse' ),
			wp_list_pluck( $widgets, 'id' )
		);
		foreach ( $widgets as $widget ) {
			$this->assertArrayHasKey( 'chrome', $widget );
			$this->assertFalse( $widget['chrome'] );
		}
	}

	public function test_unrelated_surface_returns_list_unchanged(): void {
		$this->assertSame( array(), ( new ExploreSidebarProvider() )->widgets( array(), 'feed' ) );
	}
}
