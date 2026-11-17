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
	/**
	 * Browse lists only categories that hold a space, and an empty one does not
	 * use up one of the six slots (card 10516698599).
	 */
	public function test_browse_skips_categories_with_no_spaces(): void {
		\BuddyNext\Core\Installer::run();
		$categories = new \BuddyNext\Spaces\SpaceCategoryService();
		$owner      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$empty      = $categories->create( array( 'name' => 'Aaa Empty Category' ) );
		$filled     = $categories->create( array( 'name' => 'Zzz Filled Category' ) );
		( new \BuddyNext\Spaces\SpaceService() )->create(
			$owner,
			array(
				'name'        => 'Space in a category',
				'slug'        => 'space-in-cat-' . wp_rand( 1000, 9999 ),
				'type'        => 'open',
				'category_id' => (int) $filled,
			)
		);

		$browse = null;
		foreach ( ( new ExploreSidebarProvider() )->widgets( array(), 'explore' ) as $widget ) {
			if ( 'explore-browse' === $widget['id'] ) {
				$browse = $widget;
			}
		}
		ob_start();
		( $browse['render'] )();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Zzz Filled Category', $html );
		$this->assertStringNotContainsString( 'Aaa Empty Category', $html );
	}
}
