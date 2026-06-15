<?php
/**
 * Tests for the shared user/auth link catalogue.
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Nav\UserLinks;
use BuddyNext\Core\PageRouter;

/**
 * Verifies the catalogue, visibility, and per-user token resolution.
 *
 * @covers \BuddyNext\Nav\UserLinks
 */
class UserLinksTest extends \WP_UnitTestCase {

	/**
	 * Pretty permalinks so profile URLs resolve to non-empty strings.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules();
	}

	/**
	 * Logged-in items include the account set + Log Out, never the auth items.
	 */
	public function test_loggedin_items_are_account_and_logout(): void {
		$tokens = wp_list_pluck( UserLinks::items( UserLinks::LOGGEDIN ), 'token' );
		$this->assertContains( '#bn-profile', $tokens );
		$this->assertContains( '#bn-settings', $tokens );
		$this->assertContains( '#bn-logout', $tokens );
		$this->assertNotContains( '#bn-login', $tokens );
		$this->assertNotContains( '#bn-register', $tokens );
	}

	/**
	 * Logged-out items are exactly Log In + Register.
	 */
	public function test_loggedout_items_are_login_and_register(): void {
		$tokens = wp_list_pluck( UserLinks::items( UserLinks::LOGGEDOUT ), 'token' );
		$this->assertSame( array( '#bn-login', '#bn-register' ), $tokens );
	}

	/**
	 * Visibility is reported per token; unknown tokens report ''.
	 */
	public function test_visibility(): void {
		$this->assertSame( UserLinks::LOGGEDIN, UserLinks::visibility( '#bn-profile' ) );
		$this->assertSame( UserLinks::LOGGEDOUT, UserLinks::visibility( '#bn-login' ) );
		$this->assertSame( '', UserLinks::visibility( '#bn-nope' ) );
	}

	/**
	 * Token detection only matches the `#bn-` prefix.
	 */
	public function test_is_token(): void {
		$this->assertTrue( UserLinks::is_token( '#bn-profile' ) );
		$this->assertTrue( UserLinks::is_token( '#BN-Profile' ) );
		$this->assertFalse( UserLinks::is_token( 'https://example.org/' ) );
		$this->assertFalse( UserLinks::is_token( '#section' ) );
	}

	/**
	 * #bn-profile resolves to each member's own profile (different per user).
	 */
	public function test_profile_token_resolves_per_user(): void {
		$a = self::factory()->user->create( array( 'user_nicename' => 'ada' ) );
		$b = self::factory()->user->create( array( 'user_nicename' => 'bob' ) );

		$url_a = UserLinks::resolve( '#bn-profile', $a );
		$url_b = UserLinks::resolve( '#bn-profile', $b );

		$this->assertSame( PageRouter::profile_url( $a ), $url_a );
		$this->assertStringContainsString( 'ada', $url_a );
		$this->assertStringContainsString( 'bob', $url_b );
		$this->assertNotSame( $url_a, $url_b );
	}

	/**
	 * Logged-in tokens need a member; auth/global tokens resolve regardless.
	 */
	public function test_resolution_edge_cases(): void {
		// A per-user token with no user resolves to nothing (caller drops it).
		$this->assertSame( '', UserLinks::resolve( '#bn-profile', 0 ) );
		// Auth + global tokens always resolve.
		$this->assertNotSame( '', UserLinks::resolve( '#bn-login', 0 ) );
		$this->assertNotSame( '', UserLinks::resolve( '#bn-register', 0 ) );
		$this->assertNotSame( '', UserLinks::resolve( '#bn-settings', 0 ) );
		// Log Out is always a real URL.
		$this->assertStringContainsString( 'action=logout', UserLinks::resolve( '#bn-logout', 1 ) );
		// Unknown token → ''.
		$this->assertSame( '', UserLinks::resolve( '#bn-nope', 1 ) );
	}

	/**
	 * Developers can register their own item (with a resolver) via the filter.
	 */
	public function test_developer_can_register_a_custom_item(): void {
		$cb = static function ( array $items ): array {
			$items[] = array(
				'token'      => '#bn-courses',
				'label'      => 'My Courses',
				'icon'       => 'graduation-cap',
				'visibility' => UserLinks::LOGGEDIN,
				'callback'   => static fn( int $user_id ): string => 'https://example.org/courses/' . $user_id . '/',
			);
			return $items;
		};
		add_filter( 'buddynext_user_links', $cb );

		// Appears in the catalogue + is recognised as a token...
		$this->assertContains( '#bn-courses', wp_list_pluck( UserLinks::items( UserLinks::LOGGEDIN ), 'token' ) );
		$this->assertTrue( UserLinks::is_token( '#bn-courses' ) );
		$this->assertSame( UserLinks::LOGGEDIN, UserLinks::visibility( '#bn-courses' ) );
		// ...and resolves per-user via the developer's callback.
		$this->assertSame( 'https://example.org/courses/42/', UserLinks::resolve( '#bn-courses', 42 ) );

		remove_filter( 'buddynext_user_links', $cb );
	}

	/**
	 * The URL-override filter can rewrite any resolved token.
	 */
	public function test_url_override_filter(): void {
		$cb = static function ( string $url, string $token ): string {
			return '#bn-settings' === $token ? 'https://example.org/my-account/' : $url;
		};
		add_filter( 'buddynext_user_link_url', $cb, 10, 2 );
		$this->assertSame( 'https://example.org/my-account/', UserLinks::resolve( '#bn-settings', 7 ) );
		remove_filter( 'buddynext_user_link_url', $cb, 10 );
	}
}
