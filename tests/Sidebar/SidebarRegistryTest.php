<?php
declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;
use BuddyNext\Sidebar\SidebarRegistry;
use BuddyNext\Sidebar\Surface;
use WP_UnitTestCase;

class SidebarRegistryTest extends WP_UnitTestCase {
	private function render_for( string $hub ): string {
		ob_start();
		( new SidebarRegistry() )->render( $hub );
		return (string) ob_get_clean();
	}
	public function tear_down(): void {
		remove_all_filters( 'buddynext_sidebar_widgets' );
		remove_all_filters( 'buddynext_sidebar_max_widgets' );
		Surface::reset();
		parent::tear_down();
	}
	private function add( array $w ): void {
		add_filter( 'buddynext_sidebar_widgets', static function ( array $list ) use ( $w ): array {
			$list[] = $w; return $list;
		} );
	}

	public function test_no_widgets_renders_nothing(): void {
		$this->assertSame( '', trim( $this->render_for( 'feed' ) ) );
	}
	public function test_widget_body_wrapped_in_card(): void {
		$this->add( array( 'id' => 'demo', 'title' => 'Demo', 'render' => static fn() => print( '<p class="demo">hi</p>' ) ) );
		$html = $this->render_for( 'feed' );
		$this->assertStringContainsString( 'demo', $html );
		$this->assertStringContainsString( 'Demo', $html );
	}
	public function test_empty_render_self_hides(): void {
		$this->add( array( 'id' => 'blank', 'title' => 'Blank', 'render' => static fn() => null ) );
		$this->assertSame( '', trim( $this->render_for( 'feed' ) ) );
	}
	public function test_surfaces_scope(): void {
		$this->add( array( 'id' => 'p', 'title' => 'P', 'surfaces' => array( 'profile' ), 'render' => static fn() => print( '<i class="p">x</i>' ) ) );
		$this->assertStringNotContainsString( 'class="p"', $this->render_for( 'feed' ) );
		Surface::set( 'profile' );
		$this->assertStringContainsString( 'class="p"', $this->render_for( 'members' ) );
	}
	public function test_hubs_alias_when_no_surfaces(): void {
		$this->add( array( 'id' => 'h', 'title' => 'H', 'hubs' => array( 'feed' ), 'render' => static fn() => print( '<i class="h">x</i>' ) ) );
		$this->assertStringContainsString( 'class="h"', $this->render_for( 'feed' ) );
		$this->assertStringNotContainsString( 'class="h"', $this->render_for( 'people' ) );
	}
	public function test_condition_gate(): void {
		$this->add( array( 'id' => 'g', 'title' => 'G', 'condition' => static fn() => false, 'render' => static fn() => print( '<i class="g">x</i>' ) ) );
		$this->assertStringNotContainsString( 'class="g"', $this->render_for( 'feed' ) );
	}
	public function test_default_false_is_opt_in(): void {
		$this->add( array( 'id' => 'o', 'title' => 'O', 'default' => false, 'render' => static fn() => print( '<i class="o">x</i>' ) ) );
		$this->assertStringNotContainsString( 'class="o"', $this->render_for( 'feed' ) );
	}
	public function test_priority_order(): void {
		$this->add( array( 'id' => 'late', 'title' => 'L', 'priority' => 90, 'render' => static fn() => print( '<i class="late">l</i>' ) ) );
		$this->add( array( 'id' => 'early', 'title' => 'E', 'priority' => 10, 'render' => static fn() => print( '<i class="early">e</i>' ) ) );
		$html = $this->render_for( 'feed' );
		$this->assertLessThan( strpos( $html, 'late' ), strpos( $html, 'early' ) );
	}
	public function test_cap_drops_lowest_priority_overflow(): void {
		add_filter( 'buddynext_sidebar_max_widgets', static fn() => 1 );
		$this->add( array( 'id' => 'keep', 'title' => 'K', 'priority' => 1, 'render' => static fn() => print( '<i class="keep">k</i>' ) ) );
		$this->add( array( 'id' => 'drop', 'title' => 'D', 'priority' => 9, 'render' => static fn() => print( '<i class="drop">d</i>' ) ) );
		$html = $this->render_for( 'feed' );
		$this->assertStringContainsString( 'keep', $html );
		$this->assertStringNotContainsString( 'drop', $html );
	}
}
