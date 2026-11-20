<?php
/**
 * Sign-in keeps working when a security plugin blocks REST for logged-out visitors.
 *
 * All In One Security ("Disallow unauthorized REST requests"), Wordfence, Solid
 * Security, Disable REST API and host rules can refuse /wp-json/ for guests. The
 * login screen then showed the blocker's text, or "That email, username or password
 * is not right", and nobody could sign in. And a form that submitted before the
 * store loaded went out as a GET, reloading blank with the password in the URL.
 *
 * The contract tested here:
 *  - every auth form is method="post", so nothing typed reaches a URL;
 *  - the login and lost-password forms post to wp-login.php with core's field
 *    names, which is where the store hands them when REST is blocked;
 *  - a 401/403 from a BuddyNext auth route carries data.buddynext, so the store
 *    can tell its own refusal from a blocker's;
 *  - Site Health names the block.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\AuthController;
use BuddyNext\Auth\AuthRestHealth;
use BuddyNext\Auth\PendingSignup;
use BuddyNext\Core\Installer;
use WP_Error;
use WP_REST_Request;

/**
 * Blocked-REST fallback contract.
 */
class BlockedRestFallbackTest extends \WP_UnitTestCase {

	/**
	 * Boot the schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		update_option( 'users_can_register', 1 );
	}

	/**
	 * Clear request globals.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['key'], $_GET['login'], $_GET['bn_pending'] );
		parent::tear_down();
	}

	/**
	 * Render an auth template.
	 *
	 * @param string $template File under templates/auth/.
	 * @return string
	 */
	private function render( string $template ): string {
		ob_start();
		require BUDDYNEXT_DIR . 'templates/auth/' . $template;
		return (string) ob_get_clean();
	}

	/**
	 * Opening tags of the auth forms in some markup.
	 *
	 * @param string $html Markup.
	 * @return string[]
	 */
	private function forms( string $html ): array {
		preg_match_all( '#<form\b[^>]*class="bn-auth-form"[^>]*>#s', $html, $m );
		return $m[0];
	}

	/**
	 * Every auth screen, in every mode that renders a form.
	 *
	 * @return array<string,string>
	 */
	private function screens(): array {
		$screens = array(
			'login'   => $this->render( 'login.php' ),
			'signup'  => $this->render( 'signup.php' ),
			'request' => $this->render( 'reset.php' ),
		);

		$user           = self::factory()->user->create_and_get();
		$_GET['key']    = get_password_reset_key( $user );
		$_GET['login']  = $user->user_login;
		$screens['set'] = $this->render( 'reset.php' );
		unset( $_GET['key'], $_GET['login'] );

		update_option( 'buddynext_require_terms', 1 );
		update_option( 'buddynext_terms_page_id', self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		$_GET['bn_pending']  = PendingSignup::park(
			array(
				'provider' => 'google',
				'uid'      => 'u-1',
				'email'    => 'parked@example.test',
				'name'     => 'Parked',
			)
		);
		$screens['complete'] = $this->render( 'complete.php' );

		return $screens;
	}

	/**
	 * No auth form can submit as a GET (credentials in the URL and access logs).
	 *
	 * @return void
	 */
	public function test_every_auth_form_posts(): void {
		$count = 0;
		foreach ( $this->screens() as $screen => $html ) {
			$forms = $this->forms( $html );
			$this->assertNotEmpty( $forms, "The {$screen} screen rendered no auth form." );
			foreach ( $forms as $form ) {
				++$count;
				$this->assertMatchesRegularExpression( '#\bmethod="post"#', $form, "A {$screen} form can submit as a GET: {$form}" );
			}
		}
		// Login (password + two-step), signup, request, set, complete.
		$this->assertGreaterThanOrEqual( 6, $count );
	}

	/**
	 * The fallback never builds a URL that carries a credential.
	 *
	 * The stores hand a blocked request to a POST form (form.submit()) or, for the
	 * set-new-password step, to core's reset screen by its emailed key. Neither may
	 * put user, password, log or pwd into a URL.
	 *
	 * @return void
	 */
	public function test_fallback_never_puts_credentials_in_a_url(): void {
		$files = array_merge( glob( BUDDYNEXT_DIR . 'assets/js/auth/*.js' ), array( BUDDYNEXT_DIR . 'assets/js/shell/rest-client.js' ) );
		foreach ( $files as $file ) {
			$js = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
			$this->assertDoesNotMatchRegularExpression( '#[?&](user|password|pwd|log)=#', $js, basename( $file ) . ' builds a URL with a credential.' );
			$this->assertDoesNotMatchRegularExpression( '#URLSearchParams#', $js, basename( $file ) . ' builds a query string; credentials must only travel in a POST body.' );
		}

		$screens = $this->screens();
		$this->assertMatchesRegularExpression( '#&quot;coreResetUrl&quot;|"coreResetUrl"#', $screens['set'] );
		preg_match( '#coreResetUrl[^,]*#', html_entity_decode( $screens['set'] ), $m );
		$this->assertStringContainsString( 'action=rp', stripslashes( $m[0] ?? '' ) );
		$this->assertDoesNotMatchRegularExpression( '#[?&](password|pwd|pass1)=#', stripslashes( $m[0] ?? '' ) );
	}

	/**
	 * The login form is a working wp-login.php form on its own.
	 *
	 * @return void
	 */
	public function test_login_form_posts_core_fields_to_wp_login(): void {
		$_GET['redirect_to'] = home_url( '/members/' );
		$html                = $this->render( 'login.php' );
		unset( $_GET['redirect_to'] );

		$form = $this->forms( $html )[0];
		$this->assertStringContainsString( 'action="' . esc_url( site_url( 'wp-login.php', 'login_post' ) ) . '"', $form );
		$this->assertMatchesRegularExpression( '#id="bn-login-user"\s+name="log"#', $html );
		$this->assertMatchesRegularExpression( '#id="bn-login-password"\s+name="pwd"#', $html );
		$this->assertMatchesRegularExpression( '#name="rememberme"\s+value="forever"#', $html );
		$this->assertStringContainsString( 'name="redirect_to" value="' . esc_url( home_url( '/members/' ) ) . '"', $html );
	}

	/**
	 * The lost-password request form is core's lost-password form.
	 *
	 * @return void
	 */
	public function test_lost_password_form_posts_to_core(): void {
		$html = $this->render( 'reset.php' );
		$form = $this->forms( $html )[0];

		$this->assertStringContainsString( 'action="' . esc_url( site_url( 'wp-login.php?action=lostpassword', 'login_post' ) ) . '"', $form );
		$this->assertStringContainsString( 'name="user_login"', $html );
	}

	/**
	 * A refusal from a BuddyNext auth route is marked; a blocker's never runs this filter.
	 *
	 * @return void
	 */
	public function test_own_refusal_is_marked(): void {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/login' );
		$request->set_body_params(
			array(
				'user'     => 'nobody-here',
				'password' => 'wrong',
			)
		);
		// A fresh server, so rest_api_init wires the routes and their filter in this test.
		$GLOBALS['wp_rest_server'] = null;
		$response                  = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['data']['buddynext'] ?? false, 'A BuddyNext refusal must say it is BuddyNext\'s.' );

		// Another namespace, and a non-refusal status, are left alone.
		$other = AuthController::mark_own_refusal( new WP_Error( 'x', 'x', array( 'status' => 403 ) ), null, new WP_REST_Request( 'POST', '/wp/v2/posts' ) );
		$this->assertArrayNotHasKey( 'buddynext', (array) $other->get_error_data() );
		$bad = AuthController::mark_own_refusal( new WP_Error( 'y', 'y', array( 'status' => 400 ) ), null, new WP_REST_Request( 'POST', '/buddynext/v1/auth/login' ) );
		$this->assertArrayNotHasKey( 'buddynext', (array) $bad->get_error_data() );
	}

	/**
	 * Stub the Site Health guest request.
	 *
	 * @param array<string,mixed>|WP_Error $reply Fake HTTP reply.
	 * @return array<string,mixed>
	 */
	private function health_with( $reply ): array {
		$sent = null;
		$stub = static function ( $pre, $args, $url ) use ( $reply, &$sent ) {
			$sent = array( $args, $url );
			return $reply;
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );
		$result = AuthRestHealth::run();
		remove_filter( 'pre_http_request', $stub, 10 );

		$this->assertSame( rest_url( 'buddynext/v1/auth/login' ), $sent[1] );
		$this->assertSame( '{}', $sent[0]['body'], 'The health check must never send credentials.' );
		$this->assertArrayNotHasKey( 'cookies', array_filter( $sent[0] ) );

		return $result;
	}

	/**
	 * The wp-login.php two-factor screen tells the member why the code is asked for again, only when
	 * the login store tagged the hand-over.
	 */
	public function test_two_factor_screen_explains_the_second_code_prompt(): void {
		unset( $_POST['bn_signin_notice'] );
		$this->assertSame( '', \BuddyNext\Auth\TwoFactorLoginGuard::blocked_notice() );

		$_POST['bn_signin_notice'] = 'something-else';
		$this->assertSame( '', \BuddyNext\Auth\TwoFactorLoginGuard::blocked_notice() );

		$_POST['bn_signin_notice'] = 'blocked';
		$this->assertStringContainsString( 'security settings blocked', \BuddyNext\Auth\TwoFactorLoginGuard::blocked_notice() );
		unset( $_POST['bn_signin_notice'] );
	}

	/**
	 * A route that answers asks for the missing fields: healthy.
	 *
	 * @return void
	 */
	public function test_health_passes_when_route_answers(): void {
		$result = $this->health_with(
			array(
				'response' => array( 'code' => 400 ),
				'body'     => wp_json_encode( array( 'code' => 'rest_missing_callback_param' ) ),
				'headers'  => array(),
			)
		);
		$this->assertSame( 'good', $result['status'] );
	}

	/**
	 * A blocker's refusal is critical and names the plugin's allow-list step.
	 *
	 * @return void
	 */
	public function test_health_names_the_blocker(): void {
		update_option( 'active_plugins', array( 'all-in-one-wp-security-and-firewall/wp-security.php' ) );
		$result = $this->health_with(
			array(
				'response' => array( 'code' => 403 ),
				'body'     => wp_json_encode( array( 'code' => 'wp_die' ) ),
				'headers'  => array(),
			)
		);
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'All In One Security', $result['description'] );

		update_option( 'active_plugins', array() );
		$this->assertSame( '', AuthRestHealth::blocker() );
		$this->assertStringContainsString( 'host', AuthRestHealth::allow_step( '' ) );
	}

	/**
	 * A loopback failure is not reported as a block.
	 *
	 * @return void
	 */
	public function test_health_unknown_when_loopback_fails(): void {
		$result = $this->health_with( new WP_Error( 'http_request_failed', 'cURL error 7' ) );
		$this->assertSame( 'recommended', $result['status'] );
	}
}
