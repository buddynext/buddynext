<?php
/**
 * One client-IP reader for every auth limit, plus the shared-address Site Health check.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\CoreRegistration;
use BuddyNext\Auth\SocialLogin;
use BuddyNext\Core\Installer;
use BuddyNext\Moderation\SafeguardService;
use BuddyNext\Realtime\PresenceService;

/**
 * REGRESSION (card 10506691515): three of the four REMOTE_ADDR readers ignored
 * the `buddynext_client_ip` filter. Behind a proxy every visitor shared the
 * proxy address, so the lost-password limit, the sign-up limit (5 per hour for
 * the whole site) and the OAuth limit were site-wide, and the sign-up blocklist
 * checked a different address from the login blocklist.
 *
 * @covers \BuddyNext\Moderation\SafeguardService::client_ip
 * @covers \BuddyNext\Moderation\SafeguardService::shared_visitor_ip
 * @covers \BuddyNext\Moderation\SafeguardService::site_health_client_ip_test
 */
class ClientIpSeamTest extends \WP_UnitTestCase {

	/**
	 * Counter the per-request filter uses to hand out distinct addresses.
	 *
	 * @var int
	 */
	private int $n = 0;

	/**
	 * Every request arrives from the proxy address.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		do_action( 'rest_api_init' );
		wp_set_current_user( 0 );
		update_option( 'users_can_register', '1' );
		update_option( 'buddynext_reg_mode', 'open' );
		update_option( 'buddynext_reg_spam_protection', '1' );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$this->n                = 0;
	}

	/**
	 * Restore the server address.
	 */
	public function tear_down(): void {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		parent::tear_down();
	}

	/**
	 * The filter a proxied site adds: a different real visitor on each call.
	 *
	 * @return void
	 */
	private function map_each_request_to_a_new_visitor(): void {
		add_filter(
			'buddynext_client_ip',
			function (): string {
				return '198.51.100.' . ( ++$this->n );
			}
		);
	}

	/**
	 * POST auth/lost-password and return the status.
	 *
	 * @return int
	 */
	private function lost_password(): int {
		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/auth/lost-password' );
		$request->set_param( 'user_login', 'nobody@example.com' );
		return rest_get_server()->dispatch( $request )->get_status();
	}

	/**
	 * Lost-password limit follows the filtered address, not the proxy.
	 */
	public function test_lost_password_limit_is_per_visitor_behind_a_proxy(): void {
		$this->map_each_request_to_a_new_visitor();
		for ( $i = 0; $i < 12; $i++ ) {
			$this->assertSame( 200, $this->lost_password(), 'distinct visitors must not share one bucket' );
		}
	}

	/**
	 * Without the filter the proxy address is still limited (fail-closed on one address).
	 */
	public function test_lost_password_limit_still_applies_to_one_address(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->lost_password();
		}
		$this->assertSame( 429, $this->lost_password() );
	}

	/**
	 * Sign-up limit and sign-up blocklist read the filtered address.
	 */
	public function test_signup_guard_reads_the_filtered_address(): void {
		add_filter( 'buddynext_register_rate_limit', static fn() => 2 );
		$core = new CoreRegistration();

		$this->map_each_request_to_a_new_visitor();
		for ( $i = 0; $i < 4; $i++ ) {
			$errors = $core->apply_policy( new \WP_Error(), 'person' . $i, 'person' . $i . '@example.com' );
			$this->assertNotContains( 'bn_reg_rate', $errors->get_error_codes(), 'the whole site must not share 2 sign-ups' );
		}

		remove_all_filters( 'buddynext_client_ip' );
		update_option( 'buddynext_blocked_ips', '203.0.113.50' );
		add_filter( 'buddynext_client_ip', static fn() => '203.0.113.50' );
		$errors = $core->apply_policy( new \WP_Error(), 'blocked', 'blocked@example.com' );
		$this->assertContains( 'bn_reg_ip', $errors->get_error_codes(), 'the sign-up blocklist checks the same address as the login blocklist' );
	}

	/**
	 * Call SocialLogin's private OAuth limiter, turning its redirect into an exception.
	 *
	 * @param int $times Calls.
	 * @return bool True when the limiter bailed.
	 */
	private function oauth_limited( int $times ): bool {
		$social = new SocialLogin();
		$method = new \ReflectionMethod( SocialLogin::class, 'rate_limit' );
		$method->setAccessible( true );
		$throw = static function (): void {
			throw new \RuntimeException( 'bailed' );
		};
		add_filter( 'wp_redirect', $throw );
		try {
			for ( $i = 0; $i < $times; $i++ ) {
				$method->invoke( $social );
			}
		} catch ( \RuntimeException $e ) {
			return true;
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}
		return false;
	}

	/**
	 * OAuth limit is per visitor, and an unresolvable address no longer shares md5('').
	 */
	public function test_oauth_limit_uses_the_resolver_and_fails_open_without_an_address(): void {
		$this->map_each_request_to_a_new_visitor();
		$this->assertFalse( $this->oauth_limited( 20 ), 'distinct visitors must not share the OAuth bucket' );

		remove_all_filters( 'buddynext_client_ip' );
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertFalse( $this->oauth_limited( 20 ), 'no address must not mean one shared bucket for everyone' );

		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$this->assertTrue( $this->oauth_limited( 20 ), 'one real address is still limited' );
	}

	/**
	 * The old duplicate resolver is gone.
	 */
	public function test_auth_controller_has_no_second_resolver(): void {
		$this->assertFalse( method_exists( \BuddyNext\Auth\AuthController::class, 'client_ip' ) );
	}

	/**
	 * Seed members who signed in from the given addresses and are recently active.
	 *
	 * @param string[] $ips One address per member.
	 * @return int[] User ids.
	 */
	private function members_from( array $ips ): array {
		$ids = array();
		foreach ( $ips as $ip ) {
			$id                     = self::factory()->user->create();
			$_SERVER['REMOTE_ADDR'] = $ip;
			\WP_Session_Tokens::get_instance( $id )->create( time() + DAY_IN_SECONDS );
			PresenceService::write( $id, time() );
			$ids[] = $id;
		}
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		return $ids;
	}

	/**
	 * Five members on one address is a proxy; one different address is not.
	 */
	public function test_shared_visitor_ip_detects_one_address(): void {
		$shared = $this->members_from( array_fill( 0, 5, '10.0.0.1' ) );
		$this->assertSame( '10.0.0.1', SafeguardService::shared_visitor_ip( $shared ) );

		$mixed = $this->members_from( array( '10.0.0.1', '10.0.0.1', '10.0.0.1', '10.0.0.1', '198.51.100.9' ) );
		$this->assertSame( '', SafeguardService::shared_visitor_ip( $mixed ) );

		$this->assertSame( '', SafeguardService::shared_visitor_ip( array_slice( $shared, 0, 4 ) ), 'four members is too few to judge' );
	}

	/**
	 * Site Health warns in plain words, and stands down once a filter maps the address.
	 */
	public function test_site_health_result(): void {
		$this->members_from( array_fill( 0, 6, '10.0.0.1' ) );

		$result = SafeguardService::site_health_client_ip_test();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'buddynext_client_ip', $result['test'] );
		$this->assertStringContainsString( '10.0.0.1', $result['description'] );
		$this->assertStringContainsString( 'Cloudflare', $result['description'] );
		$this->assertStringContainsString( 'mod_remoteip', $result['description'] );

		add_filter( 'buddynext_client_ip', static fn( $ip ) => $ip );
		$this->assertSame( 'good', SafeguardService::site_health_client_ip_test()['status'] );
	}

	/**
	 * A passing result says what was found. It used to claim "Recent members signed
	 * in from different addresses" when it had skipped the check or had too few
	 * sign-ins to judge.
	 */
	public function test_site_health_passing_result_says_what_was_checked(): void {
		$few = SafeguardService::site_health_client_ip_test();
		$this->assertSame( 'good', $few['status'] );
		$this->assertStringNotContainsString( 'different addresses', $few['description'], 'No sign-ins were compared.' );
		$this->assertStringContainsString( 'too few', $few['description'] );

		$this->members_from( array( '10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4', '10.0.0.5' ) );
		$this->assertStringContainsString( 'different addresses', SafeguardService::site_health_client_ip_test()['description'] );

		add_filter( 'buddynext_client_ip', static fn( $ip ) => $ip );
		$mapped = SafeguardService::site_health_client_ip_test();
		$this->assertStringNotContainsString( 'different addresses', $mapped['description'], 'The check was skipped.' );
		$this->assertStringContainsString( 'buddynext_client_ip', $mapped['description'] );
	}

	/**
	 * The test is registered with Site Health.
	 */
	public function test_site_health_registers(): void {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'buddynext_client_ip', $tests['direct'] );
	}
}
