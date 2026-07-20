<?php
declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;
use BuddyNext\Sidebar\Providers\FeedSidebarProvider;
use WP_UnitTestCase;

class FeedSidebarProviderTest extends WP_UnitTestCase {
	public function test_feed_surface_returns_four_self_chromed_descriptors(): void {
		$widgets = ( new FeedSidebarProvider() )->widgets( array(), 'feed' );

		$this->assertCount( 4, $widgets );
		$this->assertSame(
			array( 'greeting-streak', 'trending-topics', 'people-to-follow', 'your-spaces' ),
			wp_list_pluck( $widgets, 'id' )
		);
		foreach ( $widgets as $widget ) {
			$this->assertArrayHasKey( 'chrome', $widget );
			$this->assertFalse( $widget['chrome'] );
		}
	}

	public function test_unrelated_surface_returns_list_unchanged(): void {
		$this->assertSame( array(), ( new FeedSidebarProvider() )->widgets( array(), 'members' ) );
	}

	public function test_greeting_streak_and_people_to_follow_carry_callable_condition(): void {
		$widgets = ( new FeedSidebarProvider() )->widgets( array(), 'feed' );
		$by_id   = array();
		foreach ( $widgets as $widget ) {
			$by_id[ $widget['id'] ] = $widget;
		}

		$this->assertArrayHasKey( 'condition', $by_id['greeting-streak'] );
		$this->assertIsCallable( $by_id['greeting-streak']['condition'] );

		$this->assertArrayHasKey( 'condition', $by_id['people-to-follow'] );
		$this->assertIsCallable( $by_id['people-to-follow']['condition'] );
	}
}
