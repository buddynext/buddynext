<?php
/**
 * A user created in Network Admin gets no member sign-up side effects.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

/**
 * Network Admin "Add New User" fires user_register on the main site. That person
 * is not a member yet (they join on their first action), so no welcome email.
 * Run with WP_MULTISITE=1.
 */
class NetworkProvisioningTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		update_option( 'buddynext_reg_mode', 'open' );
		update_option( 'buddynext_email_verify', false );
	}

	public function tear_down(): void {
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	public function test_a_member_signing_up_on_the_main_site_gets_the_welcome_email(): void {
		$user_id = self::factory()->user->create();

		$this->assertNotSame( '', (string) get_user_meta( $user_id, 'bn_welcome_sent', true ), 'Control: a normal sign-up is welcomed.' );
	}

	public function test_a_user_created_in_network_admin_is_not_welcomed(): void {
		set_current_screen( 'users-network' );
		$this->assertTrue( is_network_admin() );

		$user_id = self::factory()->user->create();

		$this->assertSame( '', (string) get_user_meta( $user_id, 'bn_welcome_sent', true ) );
	}
}
