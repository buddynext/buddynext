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
}
