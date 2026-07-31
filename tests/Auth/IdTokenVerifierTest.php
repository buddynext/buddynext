<?php
/**
 * Tests for the OIDC id_token verifier.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\AppleClientSecret;
use BuddyNext\Auth\IdTokenVerifier;

/**
 * The id_token is the ONLY thing standing between "Apple says this is
 * user X" and "whoever reached the callback says they are user X" — it
 * travels through the member's browser, so an unverified claim set is an
 * attacker-controlled claim set. Every rejection path here is therefore a
 * security assertion, not an input-validation nicety.
 *
 * @covers \BuddyNext\Auth\IdTokenVerifier
 */
class IdTokenVerifierTest extends \WP_UnitTestCase {

	private const ISSUER   = 'https://appleid.apple.com';
	private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
	private const AUDIENCE = 'com.example.services';
	private const KID      = 'test-kid-1';

	/**
	 * RSA private key for signing test tokens.
	 *
	 * @var \OpenSSLAsymmetricKey
	 */
	private $key;

	/**
	 * Mint an RSA pair and pre-seed the JWKS transient with its public half,
	 * so no network request happens in these tests.
	 */
	public function set_up(): void {
		parent::set_up();

		$key = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
				'private_key_bits' => 2048,
			)
		);
		if ( false === $key ) {
			$this->markTestSkipped( 'OpenSSL RSA support is unavailable on this box.' );
		}
		$this->key = $key;

		$details = openssl_pkey_get_details( $key );
		set_transient(
			'bn_jwks_' . md5( self::JWKS_URL ),
			array(
				array(
					'kty' => 'RSA',
					'kid' => self::KID,
					'n'   => AppleClientSecret::b64url( (string) $details['rsa']['n'] ),
					'e'   => AppleClientSecret::b64url( (string) $details['rsa']['e'] ),
				),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Build a signed RS256 token with the given claims (and optional header
	 * overrides), exactly the shape Apple emits.
	 *
	 * @param array<string, mixed> $claims Claim overrides.
	 * @param array<string, mixed> $header Header overrides.
	 * @return string
	 */
	private function token( array $claims = array(), array $header = array() ): string {
		$header = array_merge(
			array(
				'alg' => 'RS256',
				'kid' => self::KID,
			),
			$header
		);
		$claims = array_merge(
			array(
				'iss'            => self::ISSUER,
				'aud'            => self::AUDIENCE,
				'exp'            => time() + 600,
				'iat'            => time(),
				'sub'            => 'apple-user-001',
				'email'          => 'member@example.test',
				'email_verified' => 'true',
			),
			$claims
		);

		$h = AppleClientSecret::b64url( (string) wp_json_encode( $header ) );
		$c = AppleClientSecret::b64url( (string) wp_json_encode( $claims ) );

		$sig = '';
		openssl_sign( $h . '.' . $c, $sig, $this->key, OPENSSL_ALGO_SHA256 );

		return $h . '.' . $c . '.' . AppleClientSecret::b64url( $sig );
	}

	/**
	 * A valid token yields its claims.
	 */
	public function test_valid_token_returns_claims(): void {
		$claims = IdTokenVerifier::verify( $this->token(), self::ISSUER, self::JWKS_URL, self::AUDIENCE );

		$this->assertIsArray( $claims );
		$this->assertSame( 'apple-user-001', $claims['sub'] );
		$this->assertSame( 'member@example.test', $claims['email'] );
	}

	/**
	 * Every forgery axis is refused: wrong issuer, wrong audience, expiry,
	 * a tampered payload, and an unknown signing key.
	 */
	public function test_forged_and_stale_tokens_are_refused(): void {
		$this->assertWPError(
			IdTokenVerifier::verify( $this->token( array( 'iss' => 'https://evil.example' ) ), self::ISSUER, self::JWKS_URL, self::AUDIENCE ),
			'wrong issuer must fail'
		);

		$this->assertWPError(
			IdTokenVerifier::verify( $this->token( array( 'aud' => 'someone.elses.app' ) ), self::ISSUER, self::JWKS_URL, self::AUDIENCE ),
			'wrong audience must fail'
		);

		$this->assertWPError(
			IdTokenVerifier::verify( $this->token( array( 'exp' => time() - 600 ) ), self::ISSUER, self::JWKS_URL, self::AUDIENCE ),
			'expired token must fail'
		);

		// Tamper: swap the email claim after signing.
		$valid           = $this->token();
		$parts           = explode( '.', $valid );
		$claims          = json_decode( AppleClientSecret::b64url_decode( $parts[1] ), true );
		$claims['email'] = 'attacker@example.test';
		$parts[1]        = AppleClientSecret::b64url( (string) wp_json_encode( $claims ) );
		$this->assertWPError(
			IdTokenVerifier::verify( implode( '.', $parts ), self::ISSUER, self::JWKS_URL, self::AUDIENCE ),
			'a tampered payload must fail signature verification'
		);

		// Unknown kid: the verifier will refetch the JWKS once — block the
		// network so the refetch yields nothing, as a stale/unknown key would.
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );
		$this->assertWPError(
			IdTokenVerifier::verify( $this->token( array(), array( 'kid' => 'unknown-kid' ) ), self::ISSUER, self::JWKS_URL, self::AUDIENCE ),
			'a token signed with an unknown key must fail'
		);
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), 10 );
	}

	/**
	 * The alg header is attacker-controlled input; anything but the expected
	 * algorithm is refused outright ("alg: none" family).
	 */
	public function test_algorithm_confusion_is_refused(): void {
		$this->assertWPError(
			IdTokenVerifier::verify( $this->token( array(), array( 'alg' => 'none' ) ), self::ISSUER, self::JWKS_URL, self::AUDIENCE )
		);
		$this->assertWPError(
			IdTokenVerifier::verify( $this->token( array(), array( 'alg' => 'HS256' ) ), self::ISSUER, self::JWKS_URL, self::AUDIENCE )
		);
	}

	/**
	 * A token just inside the clock-skew leeway still passes.
	 */
	public function test_leeway_tolerates_small_clock_skew(): void {
		$claims = IdTokenVerifier::verify(
			$this->token( array( 'exp' => time() - 30 ) ),
			self::ISSUER,
			self::JWKS_URL,
			self::AUDIENCE
		);
		$this->assertIsArray( $claims, 'a token 30s past exp is within the 60s leeway' );
	}

	/**
	 * Refuse all HTTP during the unknown-kid case.
	 *
	 * @return \WP_Error
	 */
	public function block_http() {
		return new \WP_Error( 'blocked', 'no network in tests' );
	}
}
