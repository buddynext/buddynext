<?php
/**
 * Tests for the WordPress core registration form under BuddyNext's policy.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\CoreRegistration;
use BuddyNext\Core\Installer;

/**
 * BuddyNext force-enabled wp-login.php?action=register and protected none of it,
 * while also overwriting the owner's own users_can_register flag on every save.
 *
 * @covers \BuddyNext\Auth\CoreRegistration
 */
class CoreRegistrationTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema per case, with the policy wired.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_set_current_user( 0 );
		( new CoreRegistration() )->register();
	}

	/**
	 * We are a plugin, not a SaaS: an owner who closes registration must find it
	 * still closed. `closed` used to be unreachable in the UI, so every save of
	 * our own tab forced users_can_register back to 1 — an owner literally could
	 * not turn registration off, and turning it off in Settings -> General was
	 * silently undone by us.
	 */
	public function test_closed_mode_disables_core_registration_and_stays_disabled(): void {
		update_option( 'buddynext_reg_mode', 'closed' );
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );

		// Saving the tab again must not silently re-open it.
		update_option( 'buddynext_reg_mode', 'closed' );
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );
	}

	/**
	 * The other modes still mirror through, so registration works out of the box.
	 */
	public function test_open_mode_enables_core_registration(): void {
		update_option( 'buddynext_reg_mode', 'open' );
		$this->assertSame( '1', (string) get_option( 'users_can_register' ) );
	}

	/**
	 * The core form is off by default — BuddyNext is the one front door.
	 */
	public function test_core_form_is_not_allowed_by_default(): void {
		$this->assertFalse( CoreRegistration::is_allowed() );
	}

	/**
	 * When the owner re-enables the core form, their allowlist still binds on it.
	 * Choosing a different signup UI must never mean opting out of the access
	 * policy and spam protection they configured.
	 */
	public function test_core_form_enforces_the_allowed_domain_allowlist(): void {
		update_option( 'buddynext_reg_mode', 'open' );
		update_option( 'users_can_register', '1' );
		update_option( CoreRegistration::OPT_ALLOW, '1' );
		update_option( 'buddynext_allowed_domains', "acme.com\n" );

		$rejected = apply_filters( 'registration_errors', new \WP_Error(), 'someone', 'someone@gmail.com' );
		$this->assertTrue( $rejected->has_errors() );
		$this->assertNotEmpty( $rejected->get_error_message( 'bn_reg_domain' ) );

		$allowed = apply_filters( 'registration_errors', new \WP_Error(), 'someone', 'someone@acme.com' );
		$this->assertFalse( $allowed->has_errors(), 'an allowed domain must pass' );
	}

	/**
	 * And a closed community rejects the core form too.
	 */
	public function test_core_form_rejects_when_registration_is_closed(): void {
		update_option( CoreRegistration::OPT_ALLOW, '1' );
		update_option( 'buddynext_reg_mode', 'closed' );

		$errors = apply_filters( 'registration_errors', new \WP_Error(), 'someone', 'someone@example.com' );

		$this->assertTrue( $errors->has_errors() );
		$this->assertNotEmpty( $errors->get_error_message( 'bn_reg_closed' ) );
	}
}
