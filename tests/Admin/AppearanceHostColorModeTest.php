<?php
/**
 * The Default theme field warns when the host theme owns light/dark mode.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AppearanceTab;

class AppearanceHostColorModeTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'template' );
		parent::tear_down();
	}

	public function test_a_theme_that_sets_its_own_mode_is_flagged(): void {
		add_filter( 'template', static fn() => 'buddyx' );
		$this->assertTrue( AppearanceTab::host_theme_sets_color_mode() );
	}

	public function test_a_theme_without_a_mode_system_is_not_flagged(): void {
		add_filter( 'template', static fn() => 'twentytwentyfive' );
		$this->assertFalse( AppearanceTab::host_theme_sets_color_mode() );
	}
}
