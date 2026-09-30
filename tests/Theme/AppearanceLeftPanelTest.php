<?php
/**
 * Tests for the Reign "Left Panel" suppression on BuddyNext hub pages.
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

use BuddyNext\Theme\Appearance;

/**
 * Card 10348288795: a site with the BuddyNext desktop rail turned OFF was left with
 * no left navigation at all on any hub, because this filter suppressed Reign's panel
 * unconditionally instead of only while BuddyNext's own rail actually renders there.
 *
 * @covers \BuddyNext\Theme\Appearance::suppress_theme_left_panel_on_hub
 */
class AppearanceLeftPanelTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var Appearance
	 */
	private Appearance $appearance;

	/**
	 * Create a fresh Appearance instance and reset the rail options before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->appearance = new Appearance();
		delete_option( 'buddynext_enable_community_nav' );
		delete_option( 'buddynext_enable_community_rail' );
		set_query_var( 'bn_hub', '' );
	}

	/**
	 * Reset the query var after each test so it never leaks into another test's request.
	 */
	public function tear_down(): void {
		set_query_var( 'bn_hub', '' );
		parent::tear_down();
	}

	/**
	 * Off a BN hub, the theme's own setting is always returned untouched.
	 */
	public function test_off_hub_page_keeps_the_stored_value(): void {
		$this->assertTrue( $this->appearance->suppress_theme_left_panel_on_hub( true ) );
		$this->assertSame( 'on', $this->appearance->suppress_theme_left_panel_on_hub( 'on' ) );
	}

	/**
	 * On a hub, while the BN rail renders (the default), the theme panel is suppressed
	 * so the two left navigations never overlap.
	 */
	public function test_hub_page_suppresses_the_panel_while_the_rail_is_on(): void {
		set_query_var( 'bn_hub', 'feed' );

		$this->assertFalse( $this->appearance->suppress_theme_left_panel_on_hub( true ) );
	}

	/**
	 * On a hub, with the rail switched off, the theme's own panel setting must come
	 * through untouched — otherwise the site has no left navigation on any hub.
	 */
	public function test_hub_page_keeps_the_panel_when_the_rail_is_off(): void {
		update_option( 'buddynext_enable_community_rail', '0' );
		set_query_var( 'bn_hub', 'feed' );

		$this->assertTrue( $this->appearance->suppress_theme_left_panel_on_hub( true ) );
	}

	/**
	 * The master community-nav switch also turns the rail off; the panel setting must
	 * come through in that case too.
	 */
	public function test_hub_page_keeps_the_panel_when_community_nav_is_off(): void {
		update_option( 'buddynext_enable_community_nav', '0' );
		set_query_var( 'bn_hub', 'feed' );

		$this->assertTrue( $this->appearance->suppress_theme_left_panel_on_hub( true ) );
	}
}
