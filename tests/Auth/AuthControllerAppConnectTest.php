<?php
/**
 * Tests for the native-app connect bridge mint endpoint.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\App\AppConnectService;
use BuddyNext\Core\Installer;

/**
 * POST /auth/app-connect hands out LIVE CREDENTIALS, so every gate on it is a
 * security boundary: the session, the scheme allowlist, the one-time bridge
 * token, and the per-user rate limit. Each is exercised here, including the
 * replay case — a consumed token re-minting would turn one leaked URL into an
 * unlimited credential mint.
 *
 * @covers \BuddyNext\Auth\AuthController::connect_app
 * @covers \BuddyNext\App\AppConnectService
 */
class AuthControllerAppConnectTest extends \WP_UnitTestCase {

	/**
	 * Member completing the bridge.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * Fresh schema + a signed-in member, as the approve screen has.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user_id );

		// Core gates Application Passwords on HTTPS (or a 'local' environment
		// type), and the test harness is plain HTTP in 'production'. Real
		// bridge deployments are HTTPS; make the harness match so the tests
		// exercise the bridge rather than core's transport gate.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Build the request the approve screen sends.
	 *
	 * @param array<string, mixed> $overrides Param overrides.
	 * @return \WP_REST_Request
	 */
	private function request( array $overrides = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/auth/app-connect' );
		$params  = array_merge(
			array(
				'scheme'       => 'buddynextapp',
				'bridge_token' => AppConnectService::issue_bridge_token(),
				'app_name'     => 'BuddyNext',
				'app_id'       => '3f1d2f66-58f2-4b6a-9f3e-9adcae30c8aa',
				'state'        => 'app-nonce-1',
			),
			$overrides
		);
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * Happy path: the response carries the WP-core-shaped credential fields
	 * and a deep link on the requested scheme with the state echoed, a row
	 * exists, and the response is marked uncacheable.
	 */
	public function test_mints_and_returns_the_core_shaped_deep_link(): void {
		$response = rest_get_server()->dispatch( $this->request() );

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( home_url(), $data['site_url'] );
		$this->assertNotEmpty( $data['uuid'] );

		$user = get_userdata( $this->user_id );
		$this->assertSame( $user->user_login, $data['user_login'] );

		$this->assertStringStartsWith( 'buddynextapp://auth?', $data['deep_link'] );
		$this->assertStringContainsString( 'user_login=', $data['deep_link'] );
		$this->assertStringContainsString( 'password=', $data['deep_link'] );
		$this->assertStringContainsString( 'state=app-nonce-1', $data['deep_link'], 'the app state must be echoed so the app can reject redirects it never started' );

		$rows = \WP_Application_Passwords::get_user_application_passwords( $this->user_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'BuddyNext', $rows[0]['name'] );

		$headers = $response->get_headers();
		$this->assertSame( 'no-store', $headers['Cache-Control'], 'a live credential must never be cacheable' );
	}

	/**
	 * Anonymous requests never reach the handler.
	 */
	public function test_anonymous_request_is_refused(): void {
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( $this->request() );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $this->user_id ), 'nothing may be minted' );
	}

	/**
	 * Only allowlisted schemes may receive a credential; shape violations and
	 * unlisted schemes are both 400. A filter-added scheme is accepted.
	 */
	public function test_scheme_allowlist(): void {
		foreach ( array( 'javascript', 'https', 'someoneelsesapp', 'BAD SCHEME' ) as $bad ) {
			$response = rest_get_server()->dispatch( $this->request( array( 'scheme' => $bad ) ) );
			$this->assertSame( 400, $response->get_status(), $bad . ' must be refused' );
			$this->assertSame( 'bn_app_bad_scheme', $response->get_data()['code'] );
		}
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $this->user_id ) );

		add_filter( 'buddynext_app_connect_schemes', array( $this, 'add_scheme' ) );
		$response = rest_get_server()->dispatch( $this->request( array( 'scheme' => 'jetonomyapp' ) ) );
		remove_filter( 'buddynext_app_connect_schemes', array( $this, 'add_scheme' ) );

		$this->assertSame( 201, $response->get_status(), 'a filter-registered scheme is how sibling apps join the bridge' );
		$this->assertStringStartsWith( 'jetonomyapp://auth?', $response->get_data()['deep_link'] );
	}

	/**
	 * The bridge token is single-use: a replay is 410 and — the part that
	 * matters — mints NOTHING.
	 */
	public function test_bridge_token_is_single_use(): void {
		$token = AppConnectService::issue_bridge_token();

		$first = rest_get_server()->dispatch( $this->request( array( 'bridge_token' => $token ) ) );
		$this->assertSame( 201, $first->get_status() );

		$replay = rest_get_server()->dispatch( $this->request( array( 'bridge_token' => $token ) ) );
		$this->assertSame( 410, $replay->get_status() );
		$this->assertSame( 'bn_app_bridge_expired', $replay->get_data()['code'] );

		$this->assertCount(
			1,
			\WP_Application_Passwords::get_user_application_passwords( $this->user_id ),
			'the replay must not mint a second credential'
		);
	}

	/**
	 * A token issued in one member's approve screen cannot authorise a mint
	 * for a different member.
	 */
	public function test_bridge_token_is_bound_to_its_member(): void {
		$token = AppConnectService::issue_bridge_token(); // Issued for $this->user_id.

		$other = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $other );

		$response = rest_get_server()->dispatch( $this->request( array( 'bridge_token' => $token ) ) );
		$this->assertSame( 410, $response->get_status() );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $other ) );
	}

	/**
	 * Reconnecting the same device REPLACES its credential instead of
	 * stacking rows: same app_id, one row, and the old secret is dead.
	 */
	public function test_reconnect_replaces_the_device_credential(): void {
		$first = rest_get_server()->dispatch( $this->request() )->get_data();
		rest_get_server()->dispatch( $this->request() );
		rest_get_server()->dispatch( $this->request() );

		$rows = \WP_Application_Passwords::get_user_application_passwords( $this->user_id );
		$this->assertCount( 1, $rows, 'three connects of one device must leave exactly one credential row' );
		$this->assertNotSame( $first['uuid'], $rows[0]['uuid'], 'the surviving row is the newest mint' );
	}

	/**
	 * The per-user mint cap holds.
	 */
	public function test_rate_limit_caps_minting(): void {
		$last = null;
		for ( $i = 0; $i < 6; $i++ ) {
			$last = rest_get_server()->dispatch( $this->request() );
		}

		$this->assertSame( 429, $last->get_status() );
		$this->assertSame( 'bn_auth_rate_limited', $last->get_data()['code'] );
	}

	/**
	 * Filter callback: register a sibling app's scheme.
	 *
	 * @param array<int, string> $schemes Allowed schemes.
	 * @return array<int, string>
	 */
	public function add_scheme( array $schemes ): array {
		$schemes[] = 'jetonomyapp';
		return $schemes;
	}
}
