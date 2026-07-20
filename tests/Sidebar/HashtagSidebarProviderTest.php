<?php
declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;
use BuddyNext\Sidebar\Providers\HashtagSidebarProvider;
use BuddyNext\Sidebar\Surface;
use WP_UnitTestCase;

class HashtagSidebarProviderTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		Surface::reset();
		parent::tearDown();
	}

	public function test_unrelated_surface_returns_list_unchanged(): void {
		$this->assertSame( array(), ( new HashtagSidebarProvider() )->widgets( array(), 'feed' ) );
	}

	public function test_hashtag_surface_returns_three_self_chromed_descriptors(): void {
		Surface::set(
			'hashtag',
			array(
				'hashtag_slug'     => 'design',
				'post_count_total' => 3,
				'first_used_label' => 'Jul 2026',
				'follows_hashtag'  => false,
				'is_logged_in'     => true,
				'related_tags'     => array(),
				'current_user_id'  => 1,
				'following_map'    => array(),
				'top_contributors' => array(),
			)
		);

		$widgets = ( new HashtagSidebarProvider() )->widgets( array(), 'hashtag' );

		$this->assertCount( 3, $widgets );
		$this->assertSame(
			array( 'hashtag-about', 'hashtag-related', 'hashtag-top-contributors' ),
			wp_list_pluck( $widgets, 'id' )
		);
		foreach ( $widgets as $widget ) {
			$this->assertArrayHasKey( 'chrome', $widget );
			$this->assertFalse( $widget['chrome'] );
			$this->assertArrayHasKey( 'surfaces', $widget );
			$this->assertSame( array( 'hashtag' ), $widget['surfaces'] );
		}
	}
}
