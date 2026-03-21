<?php
/**
 * Tests for the BuddyNext admin navigation manager.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\NavManager;

/**
 * Verifies hook-based tab management via the buddynext_nav_tabs filter.
 *
 * @covers \BuddyNext\Admin\NavManager
 */
class NavManagerTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var NavManager
	 */
	private NavManager $nav;

	/**
	 * Create a fresh instance before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->nav = new NavManager();
	}

	/**
	 * register() adds the admin_menu hook.
	 */
	public function test_register_adds_admin_menu_hook(): void {
		$this->nav->register();
		$this->assertNotFalse(
			has_action( 'admin_menu', array( $this->nav, 'add_submenu' ) )
		);
	}

	/**
	 * get_tabs() returns a non-empty array.
	 */
	public function test_get_tabs_returns_array(): void {
		$tabs = $this->nav->get_tabs();
		$this->assertIsArray( $tabs );
		$this->assertNotEmpty( $tabs );
	}

	/**
	 * get_tabs() includes a 'slug' key in every entry.
	 */
	public function test_get_tabs_entries_have_slug(): void {
		foreach ( $this->nav->get_tabs() as $tab ) {
			$this->assertArrayHasKey( 'slug', $tab );
		}
	}

	/**
	 * get_tabs() includes a 'label' key in every entry.
	 */
	public function test_get_tabs_entries_have_label(): void {
		foreach ( $this->nav->get_tabs() as $tab ) {
			$this->assertArrayHasKey( 'label', $tab );
		}
	}

	/**
	 * buddynext_nav_tabs filter can add a custom tab.
	 */
	public function test_filter_can_add_tab(): void {
		add_filter(
			'buddynext_nav_tabs',
			function ( array $tabs ): array {
				$tabs[] = array(
					'slug'  => 'test-tab',
					'label' => 'Test Tab',
					'order' => 99,
				);
				return $tabs;
			}
		);

		$slugs = array_column( $this->nav->get_tabs(), 'slug' );
		$this->assertContains( 'test-tab', $slugs );

		remove_all_filters( 'buddynext_nav_tabs' );
	}

	/**
	 * buddynext_nav_tabs filter can remove a tab by slug.
	 */
	public function test_filter_can_remove_tab(): void {
		$tabs = $this->nav->get_tabs();
		if ( empty( $tabs ) ) {
			$this->markTestSkipped( 'No default tabs to remove.' );
		}
		$first_slug = $tabs[0]['slug'];

		add_filter(
			'buddynext_nav_tabs',
			function ( array $tabs ) use ( $first_slug ): array {
				return array_values(
					array_filter(
						$tabs,
						fn( $t ) => $t['slug'] !== $first_slug
					)
				);
			}
		);

		$slugs = array_column( $this->nav->get_tabs(), 'slug' );
		$this->assertNotContains( $first_slug, $slugs );

		remove_all_filters( 'buddynext_nav_tabs' );
	}

	/**
	 * get_tabs() returns tabs sorted by 'order' key ascending.
	 */
	public function test_get_tabs_are_sorted_by_order(): void {
		add_filter(
			'buddynext_nav_tabs',
			function (): array {
				return array(
					array( 'slug' => 'z-tab', 'label' => 'Z', 'order' => 50 ),
					array( 'slug' => 'a-tab', 'label' => 'A', 'order' => 10 ),
					array( 'slug' => 'm-tab', 'label' => 'M', 'order' => 30 ),
				);
			}
		);

		$slugs = array_column( $this->nav->get_tabs(), 'slug' );
		$this->assertEquals( array( 'a-tab', 'm-tab', 'z-tab' ), $slugs );

		remove_all_filters( 'buddynext_nav_tabs' );
	}

	/**
	 * get_active_tab() returns the first tab slug when none is requested.
	 */
	public function test_get_active_tab_returns_first_when_no_request(): void {
		$tabs   = $this->nav->get_tabs();
		$active = $this->nav->get_active_tab();
		$this->assertEquals( $tabs[0]['slug'], $active );
	}
}
