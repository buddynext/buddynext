<?php
/**
 * Tests for the Sign in with Apple ES256 client-secret builder.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\AppleClientSecret;

/**
 * Apple's client secret is a signed JWT, not a stored string, and the failure
 * mode of getting it wrong is INTERMITTENT: openssl emits DER signatures whose
 * integer components vary in length, and a bad DER-to-raw conversion only
 * breaks on the signatures that hit a boundary case. The round-trip test here
 * (raw back to DER, verified with openssl_verify) is the guard against that
 * class of bug — a secret that merely LOOKS like a JWT proves nothing.
 *
 * @covers \BuddyNext\Auth\AppleClientSecret
 */
class AppleClientSecretTest extends \WP_UnitTestCase {

	/**
	 * PEM private key minted for this test run.
	 *
	 * @var string
	 */
	private string $pem = '';

	/**
	 * Matching public key resource for verification.
	 *
	 * @var \OpenSSLAsymmetricKey|null
	 */
	private $public_key = null;

	/**
	 * Mint a fresh EC P-256 key pair, as Apple's .p8 keys are.
	 */
	public function set_up(): void {
		parent::set_up();

		$key = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		if ( false === $key ) {
			$this->markTestSkipped( 'OpenSSL EC support is unavailable on this box.' );
		}

		openssl_pkey_export( $key, $this->pem );
		$details          = openssl_pkey_get_details( $key );
		$this->public_key = openssl_pkey_get_public( (string) $details['key'] );
	}

	/**
	 * The generated secret is a structurally valid ES256 JWT whose signature
	 * verifies against the signing key — the full round trip, so a DER/raw
	 * conversion bug cannot pass.
	 */
	public function test_generates_a_verifiable_es256_jwt(): void {
		$jwt = AppleClientSecret::generate( 'com.example.services', 'TEAM123456', 'KEY1234567', $this->pem );

		$this->assertIsString( $jwt, 'expected a JWT, got: ' . ( is_wp_error( $jwt ) ? $jwt->get_error_message() : gettype( $jwt ) ) );

		$parts = explode( '.', $jwt );
		$this->assertCount( 3, $parts );

		$header = json_decode( AppleClientSecret::b64url_decode( $parts[0] ), true );
		$this->assertSame( 'ES256', $header['alg'] );
		$this->assertSame( 'KEY1234567', $header['kid'] );

		$claims = json_decode( AppleClientSecret::b64url_decode( $parts[1] ), true );
		$this->assertSame( 'TEAM123456', $claims['iss'] );
		$this->assertSame( 'com.example.services', $claims['sub'] );
		$this->assertSame( 'https://appleid.apple.com', $claims['aud'] );
		// Apple rejects secrets living longer than 6 months.
		$this->assertLessThanOrEqual( $claims['iat'] + ( 6 * MONTH_IN_SECONDS ), $claims['exp'] );

		// Round trip: raw R||S back to DER, verified against the public key.
		$raw = AppleClientSecret::b64url_decode( $parts[2] );
		$this->assertSame( 64, strlen( $raw ), 'JOSE ES256 signatures are exactly 64 raw bytes' );

		$der = AppleClientSecret::raw_to_der( $raw );
		$this->assertSame(
			1,
			openssl_verify( $parts[0] . '.' . $parts[1], $der, $this->public_key, OPENSSL_ALGO_SHA256 ),
			'the converted signature must verify against the signing key'
		);
	}

	/**
	 * A second call within the TTL returns the SAME token (cached), and
	 * rotating any credential input busts the cache.
	 */
	public function test_secret_is_cached_and_cache_keys_on_the_inputs(): void {
		$first  = AppleClientSecret::generate( 'com.example.services', 'TEAM123456', 'KEY1234567', $this->pem );
		$second = AppleClientSecret::generate( 'com.example.services', 'TEAM123456', 'KEY1234567', $this->pem );
		$this->assertSame( $first, $second, 'same inputs within the TTL must return the cached token' );

		$rotated = AppleClientSecret::generate( 'com.example.services', 'TEAM123456', 'KEY_ROTATED', $this->pem );
		$this->assertIsString( $rotated );
		$this->assertNotSame( $first, $rotated, 'a rotated key id must bust the cache' );
	}

	/**
	 * Garbage credentials fail closed with a WP_Error, never a fatal.
	 */
	public function test_bad_inputs_return_wp_error(): void {
		$this->assertWPError( AppleClientSecret::generate( '', 'TEAM', 'KEY', $this->pem ) );
		$this->assertWPError( AppleClientSecret::generate( 'cid', 'TEAM', 'KEY', 'not-a-pem-at-all' ) );
	}

	/**
	 * The DER parser refuses malformed input rather than emitting a bogus
	 * signature.
	 */
	public function test_der_to_raw_rejects_malformed_input(): void {
		$this->assertSame( '', AppleClientSecret::der_to_raw( '' ) );
		$this->assertSame( '', AppleClientSecret::der_to_raw( 'definitely not DER' ) );
		$this->assertSame( '', AppleClientSecret::der_to_raw( "\x30\x06\x02\x01\x01" ) ); // Truncated second INTEGER.
	}
}
