<?php
/**
 * Tests for the Nav panel renderer + the `render` content seam on NavItem.
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavItem;
use BuddyNext\Nav\PanelRenderer;
use BuddyNext\Nav\ResolvedNav;

/**
 * The single SSR-active-panel path: contract validation + resolution rules.
 *
 * @covers \BuddyNext\Nav\PanelRenderer
 * @covers \BuddyNext\Nav\NavItem
 * @covers \BuddyNext\Nav\NavContext
 */
class PanelRendererTest extends \WP_UnitTestCase {

	/**
	 * Build a primary item that echoes a marker when its panel renders.
	 *
	 * @param string      $id        Item id.
	 * @param string      $surface   profile | space.
	 * @param string|null $parent_id Parent id for a sub-tab, else null.
	 * @return NavItem
	 */
	private function panel( string $id, string $surface = 'space', ?string $parent_id = null ): NavItem {
		$item = NavItem::from_array(
			array(
				'id'      => $id,
				'surface' => $surface,
				'layer'   => 'primary',
				'label'   => ucfirst( $id ),
				'parent'  => $parent_id,
				'render'  => static function ( NavContext $c ) use ( $id ) {
					echo 'PANEL:' . $id . ':' . $c->sub; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test marker, literal.
				},
			)
		);
		$this->assertInstanceOf( NavItem::class, $item );
		return $item;
	}

	/**
	 * Capture the rendered output for an active tab.
	 *
	 * @param ResolvedNav $nav    Resolved nav.
	 * @param NavContext  $ctx    Context.
	 * @param string      $active Active primary id.
	 * @return string
	 */
	private function capture( ResolvedNav $nav, NavContext $ctx, string $active ): string {
		ob_start();
		( new PanelRenderer() )->render_panels( $nav, $ctx, $active );
		return (string) ob_get_clean();
	}

	/**
	 * A render callable is accepted and exposed via has_render().
	 */
	public function test_render_callable_is_stored(): void {
		$item = $this->panel( 'about' );
		$this->assertTrue( $item->has_render() );
	}

	/**
	 * A primary item is valid with ONLY a render (no tab/url) — the new content seam.
	 */
	public function test_render_only_primary_is_valid(): void {
		$item = NavItem::from_array(
			array(
				'id'      => 'events',
				'surface' => 'space',
				'layer'   => 'primary',
				'label'   => 'Events',
				'render'  => static function ( NavContext $c ) {}, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			)
		);
		$this->assertInstanceOf( NavItem::class, $item );
	}

	/**
	 * A primary item with neither tab, url, nor render is still dropped.
	 */
	public function test_primary_without_tab_url_or_render_is_dropped(): void {
		$item = NavItem::from_array(
			array(
				'id'      => 'empty',
				'surface' => 'space',
				'layer'   => 'primary',
				'label'   => 'Empty',
			)
		);
		$this->assertNull( $item );
	}

	/**
	 * The active primary's own panel renders.
	 */
	public function test_active_primary_panel_renders(): void {
		$nav = new ResolvedNav( array( 'primary' => array( $this->panel( 'feed' ), $this->panel( 'about' ) ) ) );
		$out = $this->capture( $nav, new NavContext( 'space', 1, 1 ), 'about' );
		$this->assertStringContainsString( 'PANEL:about', $out );
		$this->assertStringNotContainsString( 'PANEL:feed', $out );
	}

	/**
	 * An unknown active tab renders nothing (the surface owns the fallback).
	 */
	public function test_unknown_active_renders_nothing(): void {
		$nav = new ResolvedNav( array( 'primary' => array( $this->panel( 'feed' ) ) ) );
		$out = $this->capture( $nav, new NavContext( 'space', 1, 1 ), 'does-not-exist' );
		$this->assertSame( '', $out );
	}

	/**
	 * A parent that owns no panel falls through to its FIRST child (Portfolio rule).
	 */
	public function test_parent_without_panel_renders_first_child(): void {
		$parent = NavItem::from_array(
			array(
				'id'      => 'portfolio',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Portfolio',
				'tab'     => 'jobs',
			)
		);
		$this->assertInstanceOf( NavItem::class, $parent );
		$this->assertFalse( $parent->has_render() );
		$parent->children = array( $this->panel( 'jobs', 'profile', 'portfolio' ), $this->panel( 'listings', 'profile', 'portfolio' ) );

		$nav = new ResolvedNav( array( 'primary' => array( $parent ) ) );
		$out = $this->capture( $nav, new NavContext( 'profile', 5, 5 ), 'portfolio' );
		$this->assertStringContainsString( 'PANEL:jobs', $out );
		$this->assertStringNotContainsString( 'PANEL:listings', $out );
	}

	/**
	 * An active sub-tab (ctx->sub) wins over the parent and renders the matching child.
	 */
	public function test_active_sub_tab_renders_that_child(): void {
		$parent           = NavItem::from_array(
			array(
				'id'      => 'portfolio',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Portfolio',
				'tab'     => 'jobs',
			)
		);
		$parent->children = array( $this->panel( 'jobs', 'profile', 'portfolio' ), $this->panel( 'listings', 'profile', 'portfolio' ) );

		$ctx = new NavContext( 'profile', 5, 5, '', array(), 'listings' );
		$nav = new ResolvedNav( array( 'primary' => array( $parent ) ) );
		$out = $this->capture( $nav, $ctx, 'portfolio' );
		$this->assertStringContainsString( 'PANEL:listings:listings', $out );
		$this->assertStringNotContainsString( 'PANEL:jobs', $out );
	}

	/**
	 * A sub-nav CHILD reached directly by the active id (no ctx->sub) renders that
	 * child — e.g. a profile hero metric pill links straight to /members/x/followers/,
	 * where `followers` is a child of the `network` parent, not a top-level tab.
	 */
	public function test_active_child_id_renders_that_child(): void {
		$parent           = NavItem::from_array(
			array(
				'id'      => 'network',
				'surface' => 'profile',
				'layer'   => 'primary',
				'label'   => 'Network',
				'tab'     => 'connections',
			)
		);
		$parent->children = array(
			$this->panel( 'connections', 'profile', 'network' ),
			$this->panel( 'followers', 'profile', 'network' ),
		);

		$nav = new ResolvedNav( array( 'primary' => array( $parent ) ) );
		$out = $this->capture( $nav, new NavContext( 'profile', 5, 5 ), 'followers' );
		$this->assertStringContainsString( 'PANEL:followers', $out );
		$this->assertStringNotContainsString( 'PANEL:connections', $out );
	}

	/**
	 * A metric-layer panel renders when the active id matches it and a top-level
	 * primary does not — the fallback that lets a pure metric pill own a panel.
	 */
	public function test_active_metric_panel_renders(): void {
		$metric = NavItem::from_array(
			array(
				'id'      => 'followers',
				'surface' => 'profile',
				'layer'   => 'metric',
				'label'   => 'Followers',
				'render'  => static function ( NavContext $c ) {
					echo 'PANEL:metric:' . $c->sub; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test marker.
				},
			)
		);
		$this->assertInstanceOf( NavItem::class, $metric );

		$nav = new ResolvedNav( array( 'metric' => array( $metric ) ) );
		$out = $this->capture( $nav, new NavContext( 'profile', 5, 5 ), 'followers' );
		$this->assertStringContainsString( 'PANEL:metric', $out );
	}

	/**
	 * NavContext->sub defaults to empty and round-trips through the constructor.
	 */
	public function test_context_sub_defaults_empty(): void {
		$this->assertSame( '', ( new NavContext( 'space' ) )->sub );
		$this->assertSame( 'upcoming', ( new NavContext( 'space', 1, 1, '', array(), 'upcoming' ) )->sub );
	}
}
