<?php
/**
 * Tests for the PageRouter rewrite-rule and hook model.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;

/**
 * Unit tests for PageRouter.
 *
 * @covers \BuddyNext\Core\PageRouter
 */
class PageRouterTest extends \WP_UnitTestCase {

	/**
	 * PageRouter instance under test.
	 *
	 * @var PageRouter
	 */
	private PageRouter $router;

	/**
	 * Enable pretty permalinks and register rewrites before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Enable pretty permalinks so wp_rewrite_rules() returns an array.
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		$this->router = new PageRouter();
		$this->router->register_rewrites();
		flush_rewrite_rules();
	}

	/**
	 * Activity hub base rule must target bn_hub=feed, never pagename.
	 *
	 * @return void
	 */
	public function test_activity_rule_uses_bn_hub_not_pagename(): void {
		global $wp_rewrite;
		$rules = $wp_rewrite->wp_rewrite_rules();
		$found = false;
		foreach ( $rules as $pattern => $target ) {
			if ( str_contains( $target, 'bn_hub=feed' ) ) {
				$found = true;
				$this->assertStringNotContainsString( 'pagename', $target );
				break;
			}
		}
		$this->assertTrue( $found, 'Expected a rewrite rule with bn_hub=feed' );
	}

	/**
	 * People hub base rule must target bn_hub=people, never pagename.
	 *
	 * @return void
	 */
	public function test_people_rule_uses_bn_hub_not_pagename(): void {
		global $wp_rewrite;
		$rules = $wp_rewrite->wp_rewrite_rules();
		$found = false;
		foreach ( $rules as $pattern => $target ) {
			if ( str_contains( $target, 'bn_hub=people' ) ) {
				$found = true;
				$this->assertStringNotContainsString( 'pagename', $target );
				break;
			}
		}
		$this->assertTrue( $found, 'Expected a rewrite rule with bn_hub=people' );
	}

	/**
	 * PageRouter::is_bn_route() returns false when bn_hub query var is empty.
	 *
	 * @return void
	 */
	public function test_is_bn_route_returns_false_without_bn_hub(): void {
		set_query_var( 'bn_hub', '' );
		$this->assertFalse( PageRouter::is_bn_route() );
	}

	/**
	 * PageRouter::is_bn_route() returns true when bn_hub query var carries a hub name.
	 *
	 * @return void
	 */
	public function test_is_bn_route_returns_true_when_bn_hub_set(): void {
		set_query_var( 'bn_hub', 'feed' );
		$this->assertTrue( PageRouter::is_bn_route() );
		set_query_var( 'bn_hub', '' );
	}

	/**
	 * PageRouter::activity_url() includes the slug stored in buddynext_slug_activity.
	 *
	 * @return void
	 */
	public function test_activity_url_uses_slug_option(): void {
		update_option( 'buddynext_slug_activity', 'activity' );
		$url = PageRouter::activity_url();
		$this->assertStringContainsString( '/activity/', $url );
	}

	/**
	 * PageRouter::activity_url() reflects a custom slug when the option is changed.
	 *
	 * @return void
	 */
	public function test_activity_url_uses_custom_slug_when_changed(): void {
		update_option( 'buddynext_slug_activity', 'feed' );
		$url = PageRouter::activity_url();
		$this->assertStringContainsString( '/feed/', $url );
		update_option( 'buddynext_slug_activity', 'activity' );
	}

	/**
	 * PageRouter::profile_url() builds a URL containing the user's nicename.
	 *
	 * @return void
	 */
	public function test_profile_url_uses_user_nicename(): void {
		$user_id = self::factory()->user->create( array( 'user_nicename' => 'testuser' ) );
		$url     = PageRouter::profile_url( $user_id );
		$this->assertStringContainsString( 'testuser', $url );
	}
}
