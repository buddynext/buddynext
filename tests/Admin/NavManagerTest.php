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
	 * Register() adds the admin_menu hook.
	 */
	public function test_register_adds_admin_menu_hook(): void {
		$this->nav->register();
		// The nav editor is now an AdminHub tab (settings:navigation), not a direct
		// admin_menu/add_submenu hook.
		$this->assertArrayHasKey( 'navigation', \BuddyNext\Admin\AdminHub::get_tabs( 'settings' ) );
	}

	/**
	 * Get_tabs() returns a non-empty array.
	 */
	public function test_get_tabs_returns_array(): void {
		$tabs = $this->nav->get_tabs();
		$this->assertIsArray( $tabs );
		$this->assertNotEmpty( $tabs );
	}

	/**
	 * Get_tabs() includes a 'slug' key in every entry.
	 */
	public function test_get_tabs_entries_have_slug(): void {
		foreach ( $this->nav->get_tabs() as $tab ) {
			$this->assertArrayHasKey( 'slug', $tab );
		}
	}

	/**
	 * Get_tabs() includes a 'label' key in every entry.
	 */
	public function test_get_tabs_entries_have_label(): void {
		foreach ( $this->nav->get_tabs() as $tab ) {
			$this->assertArrayHasKey( 'label', $tab );
		}
	}

	/**
	 * Buddynext_nav_tabs filter can add a custom tab.
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
	 * Buddynext_nav_tabs filter can remove a tab by slug.
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
	 * Get_tabs() returns tabs sorted by 'order' key ascending.
	 */
	public function test_get_tabs_are_sorted_by_order(): void {
		add_filter(
			'buddynext_nav_tabs',
			function (): array {
				return array(
					array(
						'slug'  => 'z-tab',
						'label' => 'Z',
						'order' => 50,
					),
					array(
						'slug'  => 'a-tab',
						'label' => 'A',
						'order' => 10,
					),
					array(
						'slug'  => 'm-tab',
						'label' => 'M',
						'order' => 30,
					),
				);
			}
		);

		$slugs = array_column( $this->nav->get_tabs(), 'slug' );
		$this->assertEquals( array( 'a-tab', 'm-tab', 'z-tab' ), $slugs );

		remove_all_filters( 'buddynext_nav_tabs' );
	}

	/**
	 * Get_active_tab() returns the first tab slug when none is requested.
	 */
	public function test_get_active_tab_returns_first_when_no_request(): void {
		$tabs   = $this->nav->get_tabs();
		$active = $this->nav->get_active_tab();
		$this->assertEquals( $tabs[0]['slug'], $active );
	}

	// ── Slug conflict detection ───────────────────────────────────────────────

	/**
	 * Check_slug_status() returns 'free' for an unclaimed slug.
	 */
	public function test_check_slug_returns_free_for_unused_slug(): void {
		$nav    = new \BuddyNext\Admin\NavManager();
		$result = $nav->check_slug_status( 'my-community', 'feed' );
		$this->assertSame( 'free', $result );
	}

	/**
	 * Check_slug_status() returns 'block' for a reserved WordPress keyword.
	 */
	public function test_check_slug_returns_block_for_reserved_word(): void {
		$nav    = new \BuddyNext\Admin\NavManager();
		$result = $nav->check_slug_status( 'wp-admin', 'feed' );
		$this->assertSame( 'block', $result );
	}

	/**
	 * Check_slug_status() returns 'block' when another BN hub owns the slug.
	 */
	public function test_check_slug_returns_block_for_another_bn_hub_slug(): void {
		update_option( 'buddynext_slug_spaces', 'spaces' );
		$nav    = new \BuddyNext\Admin\NavManager();
		$result = $nav->check_slug_status( 'spaces', 'feed' );
		$this->assertSame( 'block', $result );
		delete_option( 'buddynext_slug_spaces' );
	}

	/**
	 * Check_slug_status() returns 'warn' when an existing WP page uses the slug.
	 */
	public function test_check_slug_returns_warn_for_existing_wp_page(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'about-us',
				'post_status' => 'publish',
			)
		);
		$nav     = new \BuddyNext\Admin\NavManager();
		$result  = $nav->check_slug_status( 'about-us', 'feed' );
		$this->assertSame( 'warn', $result );
		wp_delete_post( $page_id, true );
	}
}
