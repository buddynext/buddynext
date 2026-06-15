<?php
/**
 * Tests for the render-time menu resolver.
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Nav\MenuRenderer;

/**
 * Verifies `#bn-*` items are resolved per-user and filtered by login state.
 *
 * @covers \BuddyNext\Nav\MenuRenderer
 */
class MenuRendererTest extends \WP_UnitTestCase {

	/**
	 * The renderer under test.
	 *
	 * @var MenuRenderer
	 */
	private MenuRenderer $renderer;

	/**
	 * Pretty permalinks + a fresh renderer per test.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules();
		$this->renderer = new MenuRenderer();
	}

	/**
	 * Reset the current user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Build a fake menu item object with the given URL.
	 *
	 * @param string $url  Item URL (possibly a token).
	 * @param string $title Item title.
	 * @return object
	 */
	private function item( string $url, string $title = 'Item' ): object {
		return (object) array(
			'url'   => $url,
			'title' => $title,
		);
	}

	/**
	 * For a logged-in member: account tokens resolve; auth items are removed.
	 */
	public function test_logged_in_member_sees_resolved_account_items(): void {
		$user_id = self::factory()->user->create( array( 'user_nicename' => 'maya' ) );
		wp_set_current_user( $user_id );

		$items = array(
			$this->item( '#bn-profile', 'My Profile' ),
			$this->item( '#bn-login', 'Log In' ),
			$this->item( 'https://example.org/', 'External' ),
		);

		$out  = $this->renderer->resolve_items( $items );
		$urls = wp_list_pluck( $out, 'url' );

		// Profile token resolved to the member's own profile.
		$this->assertStringContainsString( 'maya', $urls[0] );
		// Auth item dropped for a logged-in member.
		$this->assertNotContains( '#bn-login', $urls );
		// Non-BuddyNext item passes through untouched.
		$this->assertContains( 'https://example.org/', $urls );
		$this->assertCount( 2, $out );
	}

	/**
	 * For a logged-out visitor: only auth items survive (account ones removed).
	 */
	public function test_logged_out_visitor_sees_only_auth_items(): void {
		wp_set_current_user( 0 );

		$items = array(
			$this->item( '#bn-profile', 'My Profile' ),
			$this->item( '#bn-login', 'Log In' ),
			$this->item( '#bn-register', 'Register' ),
		);

		$out  = $this->renderer->resolve_items( $items );
		$urls = wp_list_pluck( $out, 'url' );

		$this->assertCount( 2, $out );
		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( '#bn-', $url, 'tokens must be resolved or dropped' );
		}
	}

	/**
	 * An unknown `#bn-*` token is dropped rather than left as a dead link.
	 */
	public function test_unknown_token_is_dropped(): void {
		wp_set_current_user( self::factory()->user->create() );
		$out = $this->renderer->resolve_items( array( $this->item( '#bn-nope' ) ) );
		$this->assertCount( 0, $out );
	}
}
