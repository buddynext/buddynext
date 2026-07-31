<?php
/**
 * Tests for the Apple provider definition and its capability flags.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\SocialLogin;
use BuddyNext\Core\Installer;

/**
 * Apple joined the provider set as CAPABILITY FLAGS on the definition, not as
 * if-apple branches in the flow — these tests pin the flags and the readiness
 * rules they drive, so a refactor that special-cases the provider name (or
 * silently drops a flag) fails here by name.
 *
 * @covers \BuddyNext\Auth\SocialLogin
 */
class SocialLoginAppleTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Full, valid Apple settings for tests.
	 *
	 * @return array<string, mixed>
	 */
	private function apple_settings(): array {
		return array(
			'enabled'     => true,
			'client_id'   => 'com.example.services',
			'team_id'     => 'TEAM123456',
			'key_id'      => 'KEY1234567',
			'private_key' => "-----BEGIN PRIVATE KEY-----\nMIG...\n-----END PRIVATE KEY-----",
		);
	}

	/**
	 * The definition carries the capability flags the flow depends on.
	 */
	public function test_apple_definition_shape(): void {
		$defs = SocialLogin::get_providers();

		$this->assertArrayHasKey( 'apple', $defs );
		$apple = $defs['apple'];

		$this->assertSame( 'post', $apple['callback_method'], 'Apple returns by cross-site form_post' );
		$this->assertSame( 'form_post', $apple['response_mode'] );
		$this->assertSame( 'id_token', $apple['identity'], 'Apple has no userinfo endpoint' );
		$this->assertSame( '', $apple['userinfo'] );
		$this->assertSame( 'jwt_es256', $apple['secret_source'] );
		$this->assertSame( 'https://appleid.apple.com', $apple['issuer'] );
		$this->assertNotEmpty( $apple['jwks'] );

		// The name never appears in the id_token; it arrives once via the
		// first-auth `user` field, so the claim map must NOT promise one.
		$this->assertNull( $apple['map']['name'] );

		// Four credentials, including the write-only .p8.
		$this->assertSame(
			array( 'client_id', 'team_id', 'key_id', 'private_key' ),
			array_keys( $apple['credentials'] )
		);
		$this->assertTrue( (bool) $apple['credentials']['private_key']['secret'] );
	}

	/**
	 * Readiness derives from the credentials descriptor: all four fields, not
	 * the classic pair — and a form_post provider is never ready on plain HTTP,
	 * because its SameSite=None state cookie requires Secure.
	 */
	public function test_apple_readiness_requires_all_credentials_and_https(): void {
		$settings = $this->apple_settings();

		// All four fields present, but this test site is plain HTTP → not ready.
		update_option( 'buddynext_social_login', array( 'apple' => $settings ) );
		$this->assertNotContains(
			'apple',
			array_column( SocialLogin::ready_providers(), 'id' ),
			'a form_post provider must not be ready without HTTPS'
		);

		// Simulate HTTPS the way core detects it.
		$_SERVER['HTTPS'] = 'on';

		$this->assertContains(
			'apple',
			array_column( SocialLogin::ready_providers(), 'id' ),
			'fully configured + HTTPS = ready'
		);

		// Any one missing credential (the .p8) breaks readiness.
		unset( $settings['private_key'] );
		update_option( 'buddynext_social_login', array( 'apple' => $settings ) );
		$this->assertNotContains( 'apple', array_column( SocialLogin::ready_providers(), 'id' ) );

		unset( $_SERVER['HTTPS'] );
	}

	/**
	 * The classic providers' readiness is untouched by the descriptor change:
	 * google with id + secret is ready exactly as before, on HTTP too.
	 */
	public function test_classic_provider_readiness_is_unchanged(): void {
		update_option(
			'buddynext_social_login',
			array(
				'google' => array(
					'enabled'       => true,
					'client_id'     => 'gid',
					'client_secret' => 'gsecret',
				),
			)
		);

		$this->assertContains( 'google', array_column( SocialLogin::ready_providers(), 'id' ) );
	}

	/**
	 * ready_providers() is the external readiness surface (the app config
	 * reads it); nothing configured means an empty list, not an error.
	 */
	public function test_ready_providers_empty_by_default(): void {
		delete_option( 'buddynext_social_login' );
		$this->assertSame( array(), SocialLogin::ready_providers() );
	}

	/**
	 * SOURCE INVARIANTS (unhookable behaviour, asserted at the source level,
	 * same technique as SocialLoginPolicyTest):
	 *
	 *  1. The flow contains no provider-name special-casing — Apple's
	 *     behaviour must live in the definition flags, or a third-party
	 *     provider with the same flags silently diverges.
	 *  2. Lax remains the DEFAULT state-cookie SameSite; None is scoped to
	 *     the form_post branch. Loosening the default would weaken the CSRF
	 *     posture of every GET-callback provider.
	 */
	public function test_source_invariants(): void {
		$src = (string) file_get_contents( BUDDYNEXT_DIR . 'includes/Auth/SocialLogin.php' );

		$this->assertStringNotContainsString(
			"'apple' ===",
			$src,
			'no if-apple branches: capability flags on the definition, read generically'
		);
		$this->assertStringNotContainsString(
			'"apple" ===',
			$src,
			'no if-apple branches: capability flags on the definition, read generically'
		);

		$this->assertStringContainsString(
			"'post' === \$callback_method ? 'None' : 'Lax'",
			$src,
			'SameSite=None must remain scoped to form_post providers, Lax the default'
		);
	}
}
