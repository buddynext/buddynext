<?php
/**
 * Tests for POST /auth/register/complete — finishing a parked social sign-up.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\PendingSignup;
use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Auth\AuthController
 */
class RegisterCompleteTest extends \WP_Test_REST_TestCase {

	/**
	 * Fresh schema, open registration, terms required (the shipped default).
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		do_action( 'rest_api_init' );

		wp_set_current_user( 0 );
		update_option( 'users_can_register', '1' );
		update_option( 'buddynext_reg_mode', 'open' );
		update_option( 'buddynext_require_terms', '1' );
		// The consent gate binds only when there is a Terms page to consent TO —
		// requiring agreement to a document that does not exist is unenforceable, so
		// RegistrationPolicy stands the gate down without one. Seed the page.
		update_option(
			'buddynext_terms_page_id',
			self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_title' => 'Terms',
				)
			)
		);
	}

	/**
	 * Park a Google profile the way SocialLogin::resolve_user() now does.
	 *
	 * @param string $email Email address.
	 * @return string Pending token.
	 */
	private function park( string $email ): string {
		return PendingSignup::park(
			array(
				'provider'       => 'google',
				'uid'            => 'uid-' . wp_generate_password( 6, false ),
				'email'          => $email,
				'email_verified' => true,
				'name'           => 'Social Person',
				'picture'        => '',
			)
		);
	}

	/**
	 * The happy path: consent is collected, and only NOW is the member created.
	 */
	public function test_completing_a_parked_signup_creates_the_member(): void {
		$email = 'social_' . wp_generate_password( 6, false ) . '@example.com';
		$token = $this->park( $email );

		$this->assertFalse( get_user_by( 'email', $email ), 'no account may exist while parked' );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register/complete' );
		$request->set_param( 'pending_token', $token );
		$request->set_param( 'terms_agreed', true );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['success'] );

		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( \WP_User::class, $user );
		// Created through RegistrationService, so the post-create steps ran.
		$this->assertNotEmpty( get_user_meta( $user->ID, 'bn_privacy_dm', true ) );
		$this->assertSame( 'uid', substr( (string) get_user_meta( $user->ID, 'bn_social_google_id', true ), 0, 3 ) );
	}

	/**
	 * Terms consent is genuinely enforced on this door — it is not a formality.
	 * Social login used to obtain no consent at all.
	 */
	public function test_completing_without_consent_is_rejected_and_creates_nothing(): void {
		$email = 'noconsent_' . wp_generate_password( 6, false ) . '@example.com';
		$token = $this->park( $email );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register/complete' );
		$request->set_param( 'pending_token', $token );
		$request->set_param( 'terms_agreed', false );

		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'terms_agreed', $response->get_data()['data']['fields'] );
		$this->assertFalse( get_user_by( 'email', $email ), 'a refused signup must create nothing' );
	}

	/**
	 * The token is single-use: a replayed submit cannot mint a second member.
	 */
	public function test_the_pending_token_cannot_be_replayed(): void {
		$email = 'replay_' . wp_generate_password( 6, false ) . '@example.com';
		$token = $this->park( $email );

		$first = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register/complete' );
		$first->set_param( 'pending_token', $token );
		$first->set_param( 'terms_agreed', true );
		$this->assertSame( 200, rest_do_request( $first )->get_status() );

		$second = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register/complete' );
		$second->set_param( 'pending_token', $token );
		$second->set_param( 'terms_agreed', true );

		$this->assertSame( 410, rest_do_request( $second )->get_status() );
	}

	/**
	 * An expired or unknown token is a clean dead end, not a fatal.
	 */
	public function test_an_unknown_token_is_rejected(): void {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register/complete' );
		$request->set_param( 'pending_token', 'not-a-real-token' );
		$request->set_param( 'terms_agreed', true );

		$this->assertSame( 410, rest_do_request( $request )->get_status() );
	}

	/**
	 * The access policy is re-checked at completion: the community may have closed
	 * while the sign-up sat parked.
	 */
	public function test_a_community_closed_while_parked_refuses_completion(): void {
		$email = 'closed_' . wp_generate_password( 6, false ) . '@example.com';
		$token = $this->park( $email );

		update_option( 'buddynext_reg_mode', 'closed' );
		update_option( 'users_can_register', '0' );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register/complete' );
		$request->set_param( 'pending_token', $token );
		$request->set_param( 'terms_agreed', true );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertFalse( get_user_by( 'email', $email ) );
	}

	/**
	 * A destination parked with the signup is returned on completion.
	 *
	 * The producer is the app-connect bridge: a first-ever social sign-up
	 * through the app parks here for terms consent, and the bridge URL must
	 * survive the park or the member finishes signup into onboarding while the
	 * app's auth sheet waits forever for an approve screen. Off-host values
	 * must NOT survive - a parked redirect is attacker-reachable input, and
	 * wp_validate_redirect() is the same-origin gate.
	 */
	public function test_a_parked_redirect_to_is_returned_on_completion(): void {
		$bridge = home_url( '/login/connect-app/?app_name=BuddyNext&scheme=buddynextapp' );

		$email = 'social_' . wp_generate_password( 6, false ) . '@example.com';
		$token = PendingSignup::park(
			array(
				'provider'       => 'google',
				'uid'            => 'uid-' . wp_generate_password( 6, false ),
				'email'          => $email,
				'email_verified' => true,
				'name'           => 'Social Person',
				'picture'        => '',
				'redirect_to'    => $bridge,
			)
		);

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register/complete' );
		$request->set_param( 'pending_token', $token );
		$request->set_param( 'terms_agreed', true );

		$data = rest_do_request( $request )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $bridge, html_entity_decode( (string) $data['redirect_to'] ), 'the member must return to the bridge, not onboarding' );
	}

	/**
	 * An off-host parked destination is dropped, not followed.
	 */
	public function test_an_offsite_parked_redirect_is_refused(): void {
		$email = 'social_' . wp_generate_password( 6, false ) . '@example.com';
		$token = PendingSignup::park(
			array(
				'provider'       => 'google',
				'uid'            => 'uid-' . wp_generate_password( 6, false ),
				'email'          => $email,
				'email_verified' => true,
				'name'           => 'Social Person',
				'picture'        => '',
				'redirect_to'    => 'https://evil.example/phish',
			)
		);

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register/complete' );
		$request->set_param( 'pending_token', $token );
		$request->set_param( 'terms_agreed', true );

		$data = rest_do_request( $request )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertStringNotContainsString( 'evil.example', (string) $data['redirect_to'] );
		$this->assertStringStartsWith( home_url(), (string) $data['redirect_to'], 'fallback must be the normal same-origin landing' );
	}
}
