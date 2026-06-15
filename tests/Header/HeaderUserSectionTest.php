<?php
/**
 * Tests for the reusable header user section (bell + messages + avatar dropdown).
 *
 * The section is zero-JS theme chrome: it renders nothing for logged-out
 * visitors and, for a logged-in member, emits the notification bell, the avatar
 * (linking to the profile) and a CSS-only dropdown with quick links + log out.
 *
 * @package BuddyNext\Tests\Header
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Header;

use BuddyNext\Header\HeaderUserSection;

/**
 * Verifies the header user section renders correctly and stays zero-JS.
 *
 * @covers \BuddyNext\Header\HeaderUserSection
 */
class HeaderUserSectionTest extends \WP_UnitTestCase {

	/**
	 * Pretty permalinks so PageRouter URLs resolve to non-empty strings.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules();
	}

	/**
	 * Reset the current user after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Logged-out visitors get the guest section (Log In), never the member one;
	 * the per-piece member helpers stay empty so themes can place their own.
	 */
	public function test_logged_out_shows_guest_section_not_member_section(): void {
		wp_set_current_user( 0 );
		$html = HeaderUserSection::render();
		$this->assertStringContainsString( 'bn-header-user-section--guest', $html );
		$this->assertStringContainsString( 'Log In', $html );
		$this->assertStringNotContainsString( 'bn-header-user__dropdown', $html );
		// Per-piece member helpers remain logged-in-only.
		$this->assertSame( '', HeaderUserSection::user_menu() );
		$this->assertSame( '', HeaderUserSection::notification_bell() );
		$this->assertSame( '', HeaderUserSection::messages_link() );
	}

	/**
	 * A logged-in member gets the full section: bell + avatar + dropdown.
	 */
	public function test_render_emits_section_with_bell_and_user_menu(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Maya Lin' ) );
		wp_set_current_user( $user_id );

		$html = HeaderUserSection::render();

		$this->assertStringContainsString( 'bn-header-user-section', $html );
		// The notification bell (server-rendered) is part of the section.
		$this->assertStringContainsString( 'bn-block-notification-bell', $html );
		// The avatar + dropdown.
		$this->assertStringContainsString( 'bn-header-user__avatar', $html );
		$this->assertStringContainsString( 'bn-header-user__dropdown', $html );
	}

	/**
	 * The avatar links to the profile and the dropdown lists the defaults + log out.
	 */
	public function test_user_menu_links_avatar_to_profile_and_lists_logout(): void {
		$user_id = self::factory()->user->create(
			array(
				'display_name'  => 'Maya Lin',
				'user_nicename' => 'maya-lin',
			)
		);
		wp_set_current_user( $user_id );

		$html = HeaderUserSection::user_menu();

		// Avatar links to the member's profile.
		$this->assertStringContainsString( 'maya-lin', $html );
		// Display name shown in the dropdown head — and is a link to the
		// member's profile (clicking your name should open your profile).
		$this->assertMatchesRegularExpression(
			'#<a class="bn-header-user__name" href="[^"]*maya-lin[^"]*"[^>]*>Maya Lin</a>#',
			$html
		);
		// User-specific account links present.
		$this->assertStringContainsString( 'My Profile', $html );
		$this->assertStringContainsString( 'Edit Profile', $html );
		$this->assertStringContainsString( 'Settings', $html );
		// Log out item with the real logout URL.
		$this->assertStringContainsString( 'is-logout', $html );
		$this->assertStringContainsString( 'Log Out', $html );
		$this->assertStringContainsString( 'action=logout', $html );
	}

	/**
	 * A filter can replace the dropdown links, but Log Out is always kept.
	 */
	public function test_user_menu_links_are_filterable_and_logout_is_kept(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$cb = static function (): array {
			return array(
				array(
					'label' => 'Dashboard',
					'url'   => 'https://example.org/dashboard/',
					'icon'  => '',
				),
			);
		};
		add_filter( 'buddynext_header_user_menu_links', $cb, 10, 2 );
		$html = HeaderUserSection::user_menu();
		remove_filter( 'buddynext_header_user_menu_links', $cb, 10 );

		// The injected real menu item replaces the defaults...
		$this->assertStringContainsString( 'Dashboard', $html );
		$this->assertStringContainsString( 'https://example.org/dashboard/', $html );
		$this->assertStringNotContainsString( 'My Profile', $html );
		// ...but Log Out is always appended by the section, never lost.
		$this->assertStringContainsString( 'Log Out', $html );
		$this->assertStringContainsString( 'is-logout', $html );
	}

	/**
	 * Malformed filtered rows are dropped without breaking the markup.
	 */
	public function test_malformed_filtered_links_are_dropped(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$cb = static function (): array {
			return array(
				array(
					'label' => 'No URL',
					'url'   => '',
					'icon'  => '',
				), // dropped: empty URL.
				'not-an-array', // dropped: wrong type.
				array( 'url' => 'https://example.org/x/' ), // dropped: no label.
				array(
					'label' => 'Good',
					'url'   => 'https://example.org/good/',
				), // kept (icon optional).
			);
		};
		add_filter( 'buddynext_header_user_menu_links', $cb );
		$html = HeaderUserSection::user_menu();
		remove_filter( 'buddynext_header_user_menu_links', $cb );

		$this->assertStringContainsString( 'Good', $html );
		$this->assertStringNotContainsString( 'No URL', $html );
		// A malformed list must never break the markup — Log Out still renders.
		$this->assertStringContainsString( 'is-logout', $html );
	}

	/**
	 * Zero-JS contract: the section never injects a script tag.
	 */
	public function test_render_contains_no_script_tags(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$html = HeaderUserSection::render();

		// Zero-JS contract: the section must never inject a <script> tag.
		$this->assertStringNotContainsStringIgnoringCase( '<script', $html );
	}
}
