<?php
/**
 * The login / sign-up panel defaults follow the COMMUNITY identity (Community
 * Name / Description), not the raw WordPress site title/tagline.
 *
 * Regression guard for the auth panel showing the WP blog name instead of the
 * Community Name on the public login screen.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use WP_UnitTestCase;

class AuthPanelIdentityTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'buddynext_site_name' );
		delete_option( 'buddynext_description' );
		parent::tear_down();
	}

	public function test_heading_default_uses_the_community_name(): void {
		update_option( 'buddynext_site_name', 'Acme Makers Club' );

		$defaults = buddynext_auth_panel_defaults();

		$this->assertSame( 'Acme Makers Club', $defaults['buddynext_auth_panel_heading'], 'The panel heading follows the Community Name.' );
	}

	public function test_tagline_default_uses_the_community_description(): void {
		update_option( 'buddynext_description', 'Where makers meet.' );

		$defaults = buddynext_auth_panel_defaults();

		$this->assertSame( 'Where makers meet.', $defaults['buddynext_auth_panel_tagline'], 'The panel tagline follows the Community Description.' );
	}

	public function test_heading_falls_back_to_the_blog_name_when_no_community_name(): void {
		delete_option( 'buddynext_site_name' );
		update_option( 'blogname', 'Fallback Blog' );

		$defaults = buddynext_auth_panel_defaults();

		$this->assertSame( 'Fallback Blog', $defaults['buddynext_auth_panel_heading'], 'With no Community Name, the heading is the blog name.' );
	}
}
