<?php
/**
 * OIDC id_token verifier.
 *
 * Providers whose identity arrives as a signed JWT rather than from a userinfo
 * endpoint (Apple has NO userinfo endpoint at all) hand us claims we must not
 * trust until the signature checks out against the provider's published JWKS.
 * Skipping that verification would let anyone who can reach the callback mint
 * an "identity" of their choosing — the token came through the member's
 * browser, not over the server-to-server channel.
 *
 * Provider-agnostic on purpose: issuer, JWKS URL and audience all come from
 * the provider definition, so a third-party OIDC provider registered through
 * the buddynext_oauth_providers filter gets the same verification for free.
 * Apple signs id_tokens with RS256, which is what this implements.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Validates an OIDC id_token (RS256) against a provider's JWKS.
 */
class IdTokenVerifier {

	/**
	 * Transient prefix for cached JWKS documents.
	 */
	private const JWKS_CACHE_PREFIX = 'bn_jwks_';

	/**
	 * JWKS cache lifetime. On a kid miss the set is refetched once regardless
	 * (key rotation), so a long TTL costs nothing in correctness.
	 */
	private const JWKS_CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Clock-skew leeway for exp/iat checks, in seconds.
	 */
	private const LEEWAY = 60;

	/**
	 * Verify an id_token and return its claims.
	 *
	 * @param string $jwt      The raw id_token.
	 * @param string $issuer   Expected `iss` claim, exact match.
	 * @param string $jwks_url The provider's JWKS endpoint.
	 * @param string $audience Expected `aud` claim (our client_id), exact match.
	 * @return array<string, mixed>|\WP_Error Validated claims, or an error.
	 */
	public static function verify( string $jwt, string $issuer, string $jwks_url, string $audience ) {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return new \WP_Error( 'bn_idtoken_malformed', __( 'The identity token is malformed.', 'buddynext' ) );
		}

		$header = json_decode( AppleClientSecret::b64url_decode( $parts[0] ), true );
		$claims = json_decode( AppleClientSecret::b64url_decode( $parts[1] ), true );
		$sig    = AppleClientSecret::b64url_decode( $parts[2] );

		if ( ! is_array( $header ) || ! is_array( $claims ) || '' === $sig ) {
			return new \WP_Error( 'bn_idtoken_malformed', __( 'The identity token is malformed.', 'buddynext' ) );
		}

		if ( 'RS256' !== (string) ( $header['alg'] ?? '' ) ) {
			// The alg header is attacker-controlled; only the algorithm the
			// provider actually uses is acceptable. Accepting whatever the
			// token claims (the classic "alg: none" family of bugs) would
			// defeat the whole verification.
			return new \WP_Error( 'bn_idtoken_alg', __( 'The identity token uses an unsupported algorithm.', 'buddynext' ) );
		}

		$kid = (string) ( $header['kid'] ?? '' );
		$pem = self::public_key_for( $jwks_url, $kid );
		if ( '' === $pem ) {
			return new \WP_Error( 'bn_idtoken_no_key', __( 'The identity token signing key could not be resolved.', 'buddynext' ) );
		}

		$verified = openssl_verify( $parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return new \WP_Error( 'bn_idtoken_bad_signature', __( 'The identity token signature is invalid.', 'buddynext' ) );
		}

		if ( (string) ( $claims['iss'] ?? '' ) !== $issuer ) {
			return new \WP_Error( 'bn_idtoken_bad_issuer', __( 'The identity token was issued by the wrong party.', 'buddynext' ) );
		}

		// aud may be a string or an array per OIDC; accept either shape.
		$aud = $claims['aud'] ?? '';
		$aud = is_array( $aud ) ? array_map( 'strval', $aud ) : array( (string) $aud );
		if ( ! in_array( $audience, $aud, true ) ) {
			return new \WP_Error( 'bn_idtoken_bad_audience', __( 'The identity token was issued for a different application.', 'buddynext' ) );
		}

		$now = time();
		if ( isset( $claims['exp'] ) && (int) $claims['exp'] < ( $now - self::LEEWAY ) ) {
			return new \WP_Error( 'bn_idtoken_expired', __( 'The identity token has expired.', 'buddynext' ) );
		}
		if ( isset( $claims['iat'] ) && (int) $claims['iat'] > ( $now + self::LEEWAY ) ) {
			return new \WP_Error( 'bn_idtoken_not_yet_valid', __( 'The identity token is not valid yet.', 'buddynext' ) );
		}

		return $claims;
	}

	/**
	 * Resolve the PEM public key for a kid from a JWKS endpoint.
	 *
	 * The JWKS document is cached; an unknown kid triggers exactly one live
	 * refetch, because an unknown kid usually means the provider rotated keys
	 * and the cache is stale — NOT refetching would break every sign-in for
	 * the remainder of the TTL.
	 *
	 * @param string $jwks_url JWKS endpoint.
	 * @param string $kid      Key id from the token header.
	 * @return string PEM public key, or '' when unresolvable.
	 */
	private static function public_key_for( string $jwks_url, string $kid ): string {
		if ( '' === $kid ) {
			return '';
		}

		$cache_key = self::JWKS_CACHE_PREFIX . md5( $jwks_url );

		$keys = get_transient( $cache_key );
		$pem  = is_array( $keys ) ? self::pem_from_set( $keys, $kid ) : '';
		if ( '' !== $pem ) {
			return $pem;
		}

		// Cache miss or rotated kid: fetch live, once.
		$res = wp_remote_get(
			$jwks_url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$set  = is_array( $body ) && isset( $body['keys'] ) && is_array( $body['keys'] ) ? $body['keys'] : array();
		if ( empty( $set ) ) {
			return '';
		}

		set_transient( $cache_key, $set, self::JWKS_CACHE_TTL );

		return self::pem_from_set( $set, $kid );
	}

	/**
	 * Find a kid in a JWKS key set and build its PEM.
	 *
	 * @param array<int, array<string, mixed>> $set JWKS "keys" array.
	 * @param string                           $kid Key id to find.
	 * @return string PEM, or '' when absent.
	 */
	private static function pem_from_set( array $set, string $kid ): string {
		foreach ( $set as $jwk ) {
			if ( ! is_array( $jwk ) || (string) ( $jwk['kid'] ?? '' ) !== $kid ) {
				continue;
			}
			if ( 'RSA' !== (string) ( $jwk['kty'] ?? '' ) ) {
				continue;
			}
			return self::rsa_pem( (string) ( $jwk['n'] ?? '' ), (string) ( $jwk['e'] ?? '' ) );
		}
		return '';
	}

	/**
	 * Assemble a PEM public key from JWK RSA components.
	 *
	 * Builds the DER SubjectPublicKeyInfo by hand (RFC 3280): the rsaEncryption
	 * OID wrapping a BIT STRING holding the RSAPublicKey SEQUENCE of modulus
	 * and exponent. openssl consumes the result directly.
	 *
	 * @param string $n Base64url modulus.
	 * @param string $e Base64url exponent.
	 * @return string PEM, or '' on malformed input.
	 */
	public static function rsa_pem( string $n, string $e ): string {
		$modulus  = AppleClientSecret::b64url_decode( $n );
		$exponent = AppleClientSecret::b64url_decode( $e );
		if ( '' === $modulus || '' === $exponent ) {
			return '';
		}

		$encode_len = static function ( int $length ): string {
			if ( $length < 0x80 ) {
				return chr( $length );
			}
			$bytes = ltrim( pack( 'N', $length ), "\x00" );
			return chr( 0x80 | strlen( $bytes ) ) . $bytes;
		};

		$encode_int = static function ( string $bytes ) use ( $encode_len ): string {
			// DER INTEGERs are signed: pad when the high bit is set.
			if ( '' !== $bytes && ord( $bytes[0] ) > 0x7f ) {
				$bytes = "\x00" . $bytes;
			}
			return "\x02" . $encode_len( strlen( $bytes ) ) . $bytes;
		};

		$rsa_key = $encode_int( $modulus ) . $encode_int( $exponent );
		$rsa_key = "\x30" . $encode_len( strlen( $rsa_key ) ) . $rsa_key;

		// rsaEncryption OID 1.2.840.113549.1.1.1 + NULL params.
		$algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

		$bit_string = "\x03" . $encode_len( strlen( $rsa_key ) + 1 ) . "\x00" . $rsa_key;
		$spki       = "\x30" . $encode_len( strlen( $algo . $bit_string ) ) . $algo . $bit_string;

		return "-----BEGIN PUBLIC KEY-----\n"
			. chunk_split( base64_encode( $spki ), 64, "\n" ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM encoding, not obfuscation.
			. '-----END PUBLIC KEY-----';
	}
}
