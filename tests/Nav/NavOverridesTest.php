<?php
/**
 * Tests for the front-end applier of Settings → Navigation overrides.
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Nav\NavOverrides;

/**
 * @covers \BuddyNext\Nav\NavOverrides
 */
class NavOverridesTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'buddynext_nav_overrides' );
		parent::tear_down();
	}

	/**
	 * A saved main-scope override for 'explore' must reach the rail item the
	 * same way it already does for every other core row (feed, people, spaces,
	 * …). Before card 10351394819 this key could never be saved in the first
	 * place - NavManager::default_tabs() had no explore entry for the Settings
	 * form to render a row for - so this path, though generic, was unreachable
	 * for explore specifically. Regression guard for that fix.
	 *
	 * @return void
	 */
	public function test_apply_rail_applies_a_saved_explore_override(): void {
		update_option(
			'buddynext_nav_overrides',
			array(
				'explore' => array(
					'hidden' => true,
					'label'  => 'Discover',
					'order'  => 5,
				),
			)
		);

		$items = array(
			array(
				'key'   => 'feed',
				'label' => 'Activity',
				'url'   => '/activity/',
				'icon'  => 'home',
				'show'  => true,
			),
			array(
				'key'   => 'explore',
				'label' => 'Explore',
				'url'   => '/activity/explore/',
				'icon'  => 'globe',
				'show'  => true,
			),
		);

		$result  = ( new NavOverrides() )->apply_rail( $items );
		$explore = null;
		foreach ( $result as $item ) {
			if ( 'explore' === ( $item['key'] ?? '' ) ) {
				$explore = $item;
			}
		}

		$this->assertNotNull( $explore, 'the explore item must survive apply_rail()' );
		$this->assertFalse( $explore['show'], 'a hidden override must hide the explore rail row' );
		$this->assertSame( 'Discover', $explore['label'], 'a label override must relabel the explore rail row' );
		$this->assertSame( 5, $explore['order'], 'an order override must reorder the explore rail row' );
	}

	/**
	 * The route asks this, so "Admins only" closes the page and not only its link.
	 *
	 * @return void
	 */
	public function test_denies_hub_follows_the_visibility_setting(): void {
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$nav    = new NavOverrides();

		$this->assertFalse( $nav->denies_hub( 'spaces' ), 'no override: open to everyone' );

		update_option( 'buddynext_nav_overrides', array( 'spaces' => array( 'visibility' => 'admins' ) ) );
		wp_set_current_user( $member );
		$this->assertTrue( $nav->denies_hub( 'spaces' ), 'a member is denied an admins-only section' );
		$this->assertFalse( $nav->denies_hub( 'people' ), 'other sections stay open' );
		wp_set_current_user( $admin );
		$this->assertFalse( $nav->denies_hub( 'spaces' ), 'an admin is let in' );

		update_option( 'buddynext_nav_overrides', array( 'spaces' => array( 'visibility' => 'cap', 'capability' => 'manage_options' ) ) );
		wp_set_current_user( $member );
		$this->assertTrue( $nav->denies_hub( 'spaces' ), 'a member lacking the capability is denied' );

		update_option( 'buddynext_nav_overrides', array( 'spaces' => array( 'visibility' => 'logged_in' ) ) );
		$this->assertFalse( $nav->denies_hub( 'spaces' ), 'a signed-in member passes logged-in only' );
		wp_set_current_user( 0 );
		$this->assertTrue( $nav->denies_hub( 'spaces' ), 'a guest is denied logged-in only' );
	}

	/**
	 * The phone bottom bar follows the main-scope Visibility, so a section set to
	 * Admins only has no tab there for a member.
	 *
	 * @return void
	 */
	public function test_mobile_bar_follows_main_visibility(): void {
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$items  = array(
			array( 'key' => 'feed', 'label' => 'Activity', 'show' => true ),
			array( 'key' => 'spaces', 'label' => 'Spaces', 'show' => true ),
		);
		update_option( 'buddynext_nav_overrides', array( 'spaces' => array( 'visibility' => 'admins' ) ) );
		$shown = static fn( array $out ): array => array_column( array_filter( $out, static fn( $i ) => false !== ( $i['show'] ?? true ) ), 'key' );

		wp_set_current_user( $member );
		$this->assertSame( array( 'feed' ), array_values( $shown( ( new NavOverrides() )->apply_mobile_items( $items ) ) ) );

		wp_set_current_user( $admin );
		$this->assertSame( array( 'feed', 'spaces' ), array_values( $shown( ( new NavOverrides() )->apply_mobile_items( $items ) ) ) );
	}
}
