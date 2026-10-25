<?php
/**
 * On a network, the registration fix-it names and changes the network setting.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\CoreRegistration;

/**
 * Core overrides a site's "Anyone can register" with the network's "Allow new
 * registrations", so the old button wrote an option core ignores. Run with WP_MULTISITE=1.
 */
class NetworkRegistrationNoticeTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		update_site_option( 'registration', 'none' );
		update_option( 'buddynext_reg_mode', 'open' );
	}

	public function tear_down(): void {
		delete_option( 'buddynext_reg_mode' );
		update_site_option( 'registration', 'none' );
		parent::tear_down();
	}

	/**
	 * The notice output for a user.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function notice_for( int $user_id ): string {
		wp_set_current_user( $user_id );
		ob_start();
		( new CoreRegistration() )->render_desync_notice();
		return (string) ob_get_clean();
	}

	public function test_the_notice_names_the_network_setting(): void {
		$state = CoreRegistration::desync_state();

		$this->assertNotNull( $state );
		$this->assertStringContainsString( 'Allow new registrations', $state['setting'] );
		$this->assertStringContainsString( 'whole network', $state['action'] );
	}

	public function test_only_a_super_admin_gets_the_button(): void {
		$super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super );
		$site_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertStringContainsString( 'whole network', $this->notice_for( $super ) );
		$this->assertStringContainsString( 'buddynext_reconcile_registration', $this->notice_for( $super ) );
		$this->assertStringNotContainsString( 'buddynext_reconcile_registration', $this->notice_for( $site_admin ) );
		$this->assertStringContainsString( 'Ask a network administrator', $this->notice_for( $site_admin ) );
	}

	public function test_network_signup_follows_the_invite_only_mode(): void {
		( new CoreRegistration() )->register();
		update_site_option( 'registration', 'user' );
		update_option( 'buddynext_reg_mode', 'invite' );
		wp_set_current_user( 0 );

		$result = wpmu_validate_user_signup( 'newperson', 'newperson@example.test' );

		$this->assertContains( 'bn_reg_invite', $result['errors']->get_error_codes(), 'wp-signup.php must not be a way around invite-only' );
	}

	public function test_a_super_admin_adding_a_user_is_not_blocked_by_the_signup_mode(): void {
		( new CoreRegistration() )->register();
		update_site_option( 'registration', 'user' );
		update_option( 'buddynext_reg_mode', 'invite' );
		$super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super );
		wp_set_current_user( $super );

		$result = wpmu_validate_user_signup( 'addedbyadmin', 'addedbyadmin@example.test' );

		$this->assertNotContains( 'bn_reg_invite', $result['errors']->get_error_codes() );
	}

	public function test_opening_accounts_keeps_the_site_creation_half(): void {
		$method = new \ReflectionMethod( CoreRegistration::class, 'network_registration' );

		update_site_option( 'registration', 'none' );
		$this->assertSame( 'user', $method->invoke( null, true ) );
		update_site_option( 'registration', 'blog' );
		$this->assertSame( 'all', $method->invoke( null, true ) );
		update_site_option( 'registration', 'all' );
		$this->assertSame( 'blog', $method->invoke( null, false ) );
		update_site_option( 'registration', 'user' );
		$this->assertSame( 'none', $method->invoke( null, false ) );
	}
}
