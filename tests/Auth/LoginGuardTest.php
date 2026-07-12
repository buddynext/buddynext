<?php
/**
 * Tests for blocked-IP enforcement on sign-in.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\SessionIssuer;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Auth\LoginGuard
 */
class LoginGuardTest extends WP_UnitTestCase {

	private const BLOCKED = '203.0.113.77';
	private const CLEAN   = '198.51.100.9';

	private int $user_id;
	private string $password = 'Pr0be-IP-P@ss!';
	private string $client_ip;

	/**
	 * Seed a member and pin the resolved client IP.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'bnipprobe',
				'user_pass'  => $this->password,
				'role'       => 'subscriber',
			)
		);

		update_option( 'buddynext_blocked_ips', self::BLOCKED );

		$this->client_ip = self::CLEAN;
		add_filter( 'buddynext_client_ip', fn() => $this->client_ip, 99 );
	}

	/**
	 * The password door: wp-login.php, REST /auth/login and XML-RPC all run
	 * wp_authenticate(), so all three are covered by this one assertion.
	 *
	 * @return void
	 */
	public function test_password_login_is_refused_from_a_blocked_ip(): void {
		$this->client_ip = self::BLOCKED;

		$result = wp_authenticate( 'bnipprobe', $this->password );

		$this->assertWPError( $result );
		$this->assertSame( 'bn_blocked_ip', $result->get_error_code() );
	}

	/**
	 * The session door. SessionIssuer does NOT run the `authenticate` chain — it
	 * replays only `wp_authenticate_user`. Social login and two-factor completion
	 * mint their sessions through it, so a guard bound only to `authenticate`
	 * would leave both wide open. This test is what stops that binding from being
	 * quietly dropped.
	 *
	 * @return void
	 */
	public function test_session_issuer_is_refused_from_a_blocked_ip(): void {
		$this->client_ip = self::BLOCKED;

		$result = ( new SessionIssuer() )->start( $this->user_id, false );

		$this->assertWPError( $result );
		$this->assertSame( 'bn_blocked_ip', $result->get_error_code() );
	}

	/**
	 * A clean address still signs in — the guard refuses a network, not everyone.
	 *
	 * @return void
	 */
	public function test_login_from_a_clean_ip_still_succeeds(): void {
		$this->client_ip = self::CLEAN;

		$result = wp_authenticate( 'bnipprobe', $this->password );

		$this->assertNotWPError( $result );
		$this->assertSame( $this->user_id, $result->ID );
	}

	/**
	 * An empty blocklist blocks nobody.
	 *
	 * @return void
	 */
	public function test_empty_blocklist_blocks_nobody(): void {
		delete_option( 'buddynext_blocked_ips' );
		$this->client_ip = self::BLOCKED;

		$result = wp_authenticate( 'bnipprobe', $this->password );

		$this->assertNotWPError( $result );
	}

	/**
	 * The owner cannot block their own address. Admins are not exempt from the
	 * blocklist, so saving your own IP would lock you out of wp-login.php on your
	 * own site — the mistake is made impossible at save time instead.
	 *
	 * @return void
	 */
	public function test_owner_cannot_block_their_own_address(): void {
		$this->client_ip = self::CLEAN;

		$saved = \BuddyNext\Admin\Settings::sanitize_ip_list(
			self::BLOCKED . "\n" . self::CLEAN . "\n192.0.2.5"
		);

		$this->assertStringNotContainsString( self::CLEAN, $saved );
		$this->assertStringContainsString( self::BLOCKED, $saved );
		$this->assertStringContainsString( '192.0.2.5', $saved );
	}
}
