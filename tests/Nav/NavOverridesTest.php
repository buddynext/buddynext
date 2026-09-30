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
}
