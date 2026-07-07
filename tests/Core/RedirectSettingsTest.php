<?php
/**
 * Tests for the login/logout redirect resolver.
 *
 * The `login_redirect` filter keeps community members on the front end: a member
 * has no wp-admin to land on, so the default admin bounce is overridden to the
 * configured login destination. Admins keep their destination, and an explicit
 * front-end request is honoured.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;
use BuddyNext\Core\RedirectSettings;

/**
 * Exercises the login_redirect filter's member/admin/explicit-request branches.
 *
 * @covers \BuddyNext\Core\RedirectSettings
 */
class RedirectSettingsTest extends \WP_UnitTestCase {

	/**
	 * Reset the login-destination option between tests.
	 */
	public function tear_down(): void {
		delete_option( RedirectSettings::OPT_LOGIN );
		parent::tear_down();
	}

	/**
	 * A non-admin community member.
	 *
	 * @return \WP_User
	 */
	private function member(): \WP_User {
		return self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
	}

	/**
	 * A site administrator.
	 *
	 * @return \WP_User
	 */
	private function admin(): \WP_User {
		return self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
	}

	/**
	 * A member with no explicit target is pulled off the admin bounce.
	 */
	public function test_member_default_admin_bounce_goes_to_front_end(): void {
		$result = RedirectSettings::filter_login_redirect( admin_url(), '', $this->member() );
		$this->assertSame( PageRouter::activity_url(), $result, 'member with no explicit target lands on the activity feed, not wp-admin' );
	}

	/**
	 * An admin still lands in wp-admin.
	 */
	public function test_admin_keeps_intended_destination(): void {
		$result = RedirectSettings::filter_login_redirect( admin_url(), '', $this->admin() );
		$this->assertSame( admin_url(), $result, 'an admin still lands in wp-admin' );
	}

	/**
	 * A member who requested a front-end page keeps it.
	 */
	public function test_member_explicit_front_end_request_is_honoured(): void {
		$target = home_url( '/spaces/' );
		$result = RedirectSettings::filter_login_redirect( $target, $target, $this->member() );
		$this->assertSame( $target, $result, 'a member who asked for a front-end page keeps it' );
	}

	/**
	 * The configured login destination wins over the built-in default.
	 */
	public function test_member_target_respects_configured_login_option(): void {
		update_option( RedirectSettings::OPT_LOGIN, home_url( '/onboarding/' ) );
		$result = RedirectSettings::filter_login_redirect( admin_url(), '', $this->member() );
		$this->assertSame( home_url( '/onboarding/' ), $result, 'the configured login destination wins over the default' );
	}

	/**
	 * A failed login (WP_Error instead of a user) passes through untouched.
	 */
	public function test_non_wp_user_passthrough(): void {
		$err = new \WP_Error( 'bad', 'nope' );
		$this->assertSame( admin_url(), RedirectSettings::filter_login_redirect( admin_url(), '', $err ), 'a failed login (WP_Error) is passed through untouched' );
	}
}
