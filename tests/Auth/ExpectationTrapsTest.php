<?php
/**
 * Tests for the admin expectation traps: blocked IPs, blocked email domains.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\RegistrationGuard;
use BuddyNext\Auth\RegistrationPolicy;
use BuddyNext\Core\Installer;

/**
 * Settings that told the owner something untrue.
 *
 * @covers \BuddyNext\Auth\RegistrationGuard
 */
class ExpectationTrapsTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema per case.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_set_current_user( 0 );
		update_option( 'users_can_register', '1' );
		update_option( 'buddynext_reg_mode', 'open' );
		delete_option( 'buddynext_blocked_ips' );
		delete_option( 'buddynext_blocked_email_domains' );
		delete_option( 'buddynext_allowed_domains' );
	}

	/**
	 * Blocking an IP must stop them REGISTERING. The setting only ever stopped
	 * posting — its own error string says "Posting from your network is not
	 * allowed" — so a blocklisted IP could still create an account and still sign
	 * in, which is not what anyone means when they block an IP.
	 */
	public function test_a_blocked_ip_cannot_register(): void {
		update_option( 'buddynext_blocked_ips', "198.51.100.7\n" );

		$guard = new RegistrationGuard();

		$blocked = $guard->check(
			array(
				'source' => RegistrationPolicy::SOURCE_FORM,
				'email'  => 'someone@example.com',
				'ip'     => '198.51.100.7',
				'token'  => RegistrationGuard::issue_token(),
			)
		);

		$this->assertWPError( $blocked );
		$this->assertSame( 'bn_reg_ip', $blocked->get_error_code() );
	}

	/**
	 * And check_ip() is the same decision, exposed for the login path.
	 */
	public function test_a_blocked_ip_is_refused_at_login_too(): void {
		update_option( 'buddynext_blocked_ips', "198.51.100.7\n" );

		$guard = new RegistrationGuard();

		$this->assertWPError( $guard->check_ip( '198.51.100.7' ) );
		$this->assertTrue( $guard->check_ip( '203.0.113.1' ), 'an unlisted IP must pass' );
	}

	/**
	 * There was NO email-domain blocklist at all — only an allowlist. An owner who
	 * wanted to shut out one abusive domain had to enumerate every permitted domain
	 * on earth instead.
	 */
	public function test_an_owner_can_block_a_single_abusive_domain(): void {
		update_option( 'buddynext_blocked_email_domains', "abusive.example\n" );

		$guard = new RegistrationGuard();

		$blocked = $guard->check_domain( 'someone@abusive.example' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'bn_reg_domain', $blocked->get_error_code() );

		// Everyone else still gets in — no allowlist required.
		$this->assertTrue( $guard->check_domain( 'someone@gmail.com' ) );
	}

	/**
	 * The blocklist is case-insensitive and tolerates a leading @.
	 */
	public function test_the_blocklist_is_forgiving_about_formatting(): void {
		update_option( 'buddynext_blocked_email_domains', "@Abusive.Example\n" );

		$this->assertWPError( ( new RegistrationGuard() )->check_domain( 'someone@abusive.example' ) );
	}
}
