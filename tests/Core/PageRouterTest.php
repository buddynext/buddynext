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
	 * Request filter blanks slug-based query vars when bn_hub is present so the
	 * default WP_Query resolves to "no posts" — the actual hub template is
	 * rendered later by dispatch_hub_template() during template_redirect.
	 *
	 * @return void
	 */
	public function test_suppress_query_strips_slug_lookups_when_bn_hub_set(): void {
		$result = $this->router->suppress_default_query(
			array(
				'bn_hub'   => 'feed',
				'pagename' => 'activity',
				'name'     => 'activity',
				'page'     => 1,
			)
		);

		$this->assertSame( array( 0 ), $result['post__in'] );
		$this->assertArrayNotHasKey( 'pagename', $result );
		$this->assertArrayNotHasKey( 'name', $result );
		$this->assertArrayNotHasKey( 'page', $result );
	}

	/**
	 * Suppression is independent of whether the hub's page option is set —
	 * the new implementation never consults the option here. The fallback
	 * (-1 / not-found) path is handled by dispatch_hub_template(), not the
	 * request filter.
	 *
	 * @return void
	 */
	public function test_suppress_query_does_not_depend_on_page_option(): void {
		delete_option( 'buddynext_page_activity' );
		$result = $this->router->suppress_default_query( array( 'bn_hub' => 'feed' ) );
		$this->assertSame( array( 0 ), $result['post__in'] );
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

	// ── Shell rendering (inside theme chrome) ─────────────────────────────────

	/**
	 * Render method must not emit DOCTYPE / html / head / body — theme owns them.
	 *
	 * Stubs get_header() / get_footer() output via sentinel filters and
	 * asserts the shell renders between them. dispatch_hub_template() wraps
	 * this same render call with an exit, but exit is untestable in-process;
	 * render_shell_with_theme_chrome() is the extracted-for-tests entry
	 * point that exercises identical output.
	 *
	 * @return void
	 */
	public function test_shell_does_not_emit_doctype_and_wraps_in_theme_chrome(): void {
		$emit_header = static function (): void {
			echo '<!--BN_TEST_THEME_HEADER-->';
		};
		$emit_footer = static function (): void {
			echo '<!--BN_TEST_THEME_FOOTER-->';
		};
		add_action( 'get_header', $emit_header );
		add_action( 'get_footer', $emit_footer );

		// Authenticate so the feed template does not redirect to /login.
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		ob_start();
		$this->router->render_shell_with_theme_chrome( 'feed', 'feed/home.php', array() );
		$output = (string) ob_get_clean();

		wp_set_current_user( 0 );

		remove_action( 'get_header', $emit_header );
		remove_action( 'get_footer', $emit_footer );

		$this->assertStringNotContainsString(
			'<!DOCTYPE',
			$output,
			'PageRouter must not emit its own DOCTYPE; the theme owns the document.'
		);
		$this->assertStringNotContainsString(
			'<html ',
			$output,
			'PageRouter must not emit its own <html> tag.'
		);
		$this->assertStringNotContainsString(
			'<head>',
			$output,
			'PageRouter must not emit its own <head>.'
		);
		$this->assertStringContainsString(
			'<!--BN_TEST_THEME_HEADER-->',
			$output,
			'get_header() must run before the shell.'
		);
		$this->assertStringContainsString(
			'<!--BN_TEST_THEME_FOOTER-->',
			$output,
			'get_footer() must run after the shell.'
		);

		// The shell's .bn-app marker should sit between the two sentinels.
		$header_pos = strpos( $output, '<!--BN_TEST_THEME_HEADER-->' );
		$footer_pos = strpos( $output, '<!--BN_TEST_THEME_FOOTER-->' );
		$shell_pos  = strpos( $output, 'bn-app' );

		$this->assertNotFalse( $header_pos );
		$this->assertNotFalse( $footer_pos );
		$this->assertNotFalse( $shell_pos, '.bn-app marker must be present in output.' );
		$this->assertLessThan( $shell_pos, $header_pos, 'Theme header must precede the shell.' );
		$this->assertLessThan( $footer_pos, $shell_pos, 'Shell must precede the theme footer.' );
	}

	/**
	 * The legacy `buddynext_render_with_theme_chrome` filter has been
	 * removed. Hooking it has no effect: the theme header/footer still
	 * render and the shell still emits no DOCTYPE.
	 *
	 * @return void
	 */
	public function test_legacy_theme_chrome_filter_has_no_effect(): void {
		add_filter( 'buddynext_render_with_theme_chrome', '__return_false' );
		$emit_header = static function (): void {
			echo '<!--BN_TEST_THEME_HEADER-->';
		};
		add_action( 'get_header', $emit_header );

		// Authenticate so the feed template does not redirect to /login.
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		ob_start();
		$this->router->render_shell_with_theme_chrome( 'feed', 'feed/home.php', array() );
		$output = (string) ob_get_clean();

		wp_set_current_user( 0 );

		remove_all_filters( 'buddynext_render_with_theme_chrome' );
		remove_all_actions( 'get_header' );

		$this->assertStringContainsString(
			'<!--BN_TEST_THEME_HEADER-->',
			$output,
			'Theme header must still render — the legacy filter is a no-op.'
		);
		$this->assertStringNotContainsString( '<!DOCTYPE', $output );
	}
}
