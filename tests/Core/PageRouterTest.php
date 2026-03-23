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
	 * Reset permalink structure after each test to avoid cross-test pollution.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '' );
		parent::tear_down();
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

	// ── request filter ────────────────────────────────────────────────────────

	/**
	 * Request filter sets p to the hub page ID when bn_hub is present.
	 *
	 * @return void
	 */
	public function test_suppress_query_sets_p_when_bn_hub_set(): void {
		update_option( 'buddynext_page_activity', 99 );
		$result = $this->router->suppress_default_query( array( 'bn_hub' => 'feed' ) );
		$this->assertSame( 99, $result['p'] );
		$this->assertSame( 'page', $result['post_type'] );
		$this->assertArrayNotHasKey( 'pagename', $result );
		delete_option( 'buddynext_page_activity' );
	}

	/**
	 * Request filter sets p to -1 when the page option is not configured.
	 *
	 * @return void
	 */
	public function test_suppress_query_sets_p_minus_one_when_page_not_configured(): void {
		delete_option( 'buddynext_page_activity' );
		$result = $this->router->suppress_default_query( array( 'bn_hub' => 'feed' ) );
		$this->assertSame( -1, $result['p'] );
	}

	/**
	 * Non-BuddyNext requests pass through the filter unmodified.
	 *
	 * @return void
	 */
	public function test_suppress_query_does_not_modify_non_bn_requests(): void {
		$vars   = array( 'pagename' => 'about' );
		$result = $this->router->suppress_default_query( $vars );
		$this->assertSame( $vars, $result );
	}

	// ── Hook wiring ───────────────────────────────────────────────────────────

	/**
	 * Calling init() registers the request filter and template_redirect action.
	 *
	 * @return void
	 */
	public function test_init_registers_request_filter_and_template_redirect(): void {
		// Create a fresh router to avoid the already-registered hooks from set_up().
		$router = new PageRouter();
		// Remove any previously registered hooks from set_up() first.
		remove_filter( 'request', array( $router, 'suppress_default_query' ) );
		remove_action( 'template_redirect', array( $router, 'dispatch_hub_template' ) );

		$this->assertFalse(
			has_filter( 'request', array( $router, 'suppress_default_query' ) ),
			'Hook should not be registered before init()'
		);

		$router->init();

		$this->assertNotFalse(
			has_filter( 'request', array( $router, 'suppress_default_query' ) ),
			'request filter must be registered after init()'
		);
		$this->assertNotFalse(
			has_action( 'template_redirect', array( $router, 'dispatch_hub_template' ) ),
			'template_redirect action must be registered after init()'
		);
	}
}
