<?php
declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;
use BuddyNext\Sidebar\Surface;
use WP_UnitTestCase;

class SurfaceTest extends WP_UnitTestCase {
	public function tear_down(): void { Surface::reset(); parent::tear_down(); }

	public function test_defaults_to_hub_fallback_when_unset(): void {
		$this->assertSame( 'feed', Surface::current( 'feed' ) );
	}
	public function test_set_overrides_fallback(): void {
		Surface::set( 'explore' );
		$this->assertSame( 'explore', Surface::current( 'feed' ) );
	}
	public function test_reset_clears(): void {
		Surface::set( 'explore' );
		Surface::reset();
		$this->assertSame( 'profile', Surface::current( 'profile' ) );
	}

	public function test_context_defaults_to_empty_array(): void {
		$this->assertSame( array(), Surface::context() );
	}

	public function test_set_stores_context_alongside_slug(): void {
		Surface::set( 'space', array( 'space_id' => 7 ) );
		$this->assertSame( 'space', Surface::current() );
		$this->assertSame( array( 'space_id' => 7 ), Surface::context() );
	}

	public function test_set_without_context_defaults_to_empty_array(): void {
		Surface::set( 'explore' );
		$this->assertSame( array(), Surface::context() );
	}

	public function test_reset_clears_context(): void {
		Surface::set( 'space', array( 'space_id' => 7 ) );
		Surface::reset();
		$this->assertSame( array(), Surface::context() );
	}
}
