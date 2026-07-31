<?php
/**
 * Sign in with Apple client-secret builder.
 *
 * Apple is the one OAuth provider whose client secret is not a stored string:
 * it is a short-lived ES256-signed JWT, built from the owner's Team ID, Key ID,
 * Services ID and a downloaded .p8 private key, with a hard 6-month maximum
 * lifetime. Composer is dev-only in this plugin (the runtime autoloader is
 * hand-written and vendor/ never ships), so no JWT library exists at runtime —
 * the token is assembled and signed natively with OpenSSL.
 *
 * The subtle part is the signature format. `openssl_sign()` emits an ECDSA
 * signature as a DER SEQUENCE of two INTEGERs; JOSE (RFC 7518 §3.4) requires
 * the raw 64-byte R||S concatenation instead. The DER integers are
 * variable-length — OpenSSL prepends a zero byte when R or S has its high bit
 * set, and may emit fewer than 32 bytes for small values — so each is
 * canonicalised (strip leading zeros, left-pad to 32 bytes) before joining.
 * Getting this wrong produces secrets Apple rejects only INTERMITTENTLY,
 * whenever the random signature happens to hit a boundary case, which is why
 * the round-trip test verifies the raw signature back through openssl_verify.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Builds (and caches) the ES256 client-secret JWT for Sign in with Apple.
 */
class AppleClientSecret {

	/**
	 * Transient prefix for the cached secret.
	 */
	private const CACHE_PREFIX = 'bn_apple_cs_';

	/**
	 * Cached-secret lifetime: 23 hours against a 24-hour token exp, so a
	 * cached value is never handed out within an hour of its own expiry.
	 */
	private const CACHE_TTL = 23 * HOUR_IN_SECONDS;

	/**
	 * Token lifetime. Far under Apple's 6-month ceiling on purpose: a
	 * short-lived token bounds the damage of a leaked one, and regeneration
	 * is cheap because it is cached.
	 */
	private const TOKEN_TTL = DAY_IN_SECONDS;

	/**
	 * Build (or return the cached) client-secret JWT.
	 *
	 * @param string $client_id Apple Services ID (the OAuth client_id).
	 * @param string $team_id   Apple developer Team ID.
	 * @param string $key_id    Key ID of the .p8 signing key.
	 * @param string $p8        PEM contents of the downloaded .p8 private key.
	 * @return string|\WP_Error The JWT, or an error when signing is impossible.
	 */
	public static function generate( string $client_id, string $team_id, string $key_id, string $p8 ) {
		if ( '' === $client_id || '' === $team_id || '' === $key_id || '' === $p8 ) {
			return new \WP_Error( 'bn_apple_secret_incomplete', __( 'Apple sign-in is missing one of its credentials.', 'buddynext' ) );
		}

		// The cache key includes every input, so rotating any credential busts
		// the cached token automatically — no manual flush step for the owner.
		$cache_key = self::CACHE_PREFIX . md5( $team_id . '|' . $key_id . '|' . $client_id . '|' . $p8 );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$key = openssl_pkey_get_private( $p8 );
		if ( false === $key ) {
			return new \WP_Error( 'bn_apple_secret_bad_key', __( 'The Apple .p8 private key could not be read.', 'buddynext' ) );
		}

		$now    = time();
		$header = self::b64url(
			(string) wp_json_encode(
				array(
					'alg' => 'ES256',
					'kid' => $key_id,
				)
			)
		);
		$claims = self::b64url(
			(string) wp_json_encode(
				array(
					'iss' => $team_id,
					'iat' => $now,
					'exp' => $now + self::TOKEN_TTL,
					'aud' => 'https://appleid.apple.com',
					'sub' => $client_id,
				)
			)
		);

		$input = $header . '.' . $claims;
		$der   = '';
		if ( ! openssl_sign( $input, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new \WP_Error( 'bn_apple_secret_sign_failed', __( 'This server could not sign the Apple client secret (OpenSSL EC support missing?).', 'buddynext' ) );
		}

		$raw = self::der_to_raw( $der );
		if ( '' === $raw ) {
			return new \WP_Error( 'bn_apple_secret_sign_failed', __( 'This server could not sign the Apple client secret (OpenSSL EC support missing?).', 'buddynext' ) );
		}

		$jwt = $input . '.' . self::b64url( $raw );
		set_transient( $cache_key, $jwt, self::CACHE_TTL );

		return $jwt;
	}

	/**
	 * Convert a DER-encoded ECDSA signature to the raw 64-byte R||S form.
	 *
	 * DER shape: SEQUENCE { INTEGER r, INTEGER s }. Each INTEGER is
	 * variable-length and may carry a leading zero byte (high-bit padding);
	 * JOSE wants each component as exactly 32 unsigned big-endian bytes.
	 *
	 * @param string $der DER signature from openssl_sign.
	 * @return string 64 raw bytes, or '' when the input does not parse.
	 */
	public static function der_to_raw( string $der ): string {
		$pos = 0;
		$len = strlen( $der );

		// SEQUENCE tag.
		if ( $len < 2 || "\x30" !== $der[ $pos ] ) {
			return '';
		}
		++$pos;

		// SEQUENCE length (short or single-byte-long form; a P-256 signature
		// never exceeds 127 + 2 bytes but a 0x81 prefix is legal DER).
		$seq_len = ord( $der[ $pos ] );
		++$pos;
		if ( 0x81 === $seq_len ) {
			++$pos;
		}

		$components = array();
		for ( $i = 0; $i < 2; $i++ ) {
			if ( $pos + 2 > $len || "\x02" !== $der[ $pos ] ) {
				return '';
			}
			++$pos;
			$int_len = ord( $der[ $pos ] );
			++$pos;
			if ( $pos + $int_len > $len || $int_len < 1 ) {
				return '';
			}
			$component = substr( $der, $pos, $int_len );
			$pos      += $int_len;

			// Canonicalise: strip sign-padding zeros, then left-pad to 32.
			$component = ltrim( $component, "\x00" );
			if ( strlen( $component ) > 32 ) {
				return '';
			}
			$components[] = str_pad( $component, 32, "\x00", STR_PAD_LEFT );
		}

		return $components[0] . $components[1];
	}

	/**
	 * Convert a raw 64-byte R||S signature back to DER (test round-trips only).
	 *
	 * @param string $raw 64-byte raw signature.
	 * @return string DER signature, or '' on malformed input.
	 */
	public static function raw_to_der( string $raw ): string {
		if ( 64 !== strlen( $raw ) ) {
			return '';
		}

		$encode_int = static function ( string $bytes ): string {
			$bytes = ltrim( $bytes, "\x00" );
			if ( '' === $bytes ) {
				$bytes = "\x00";
			}
			// Re-add sign padding when the high bit is set: DER INTEGERs are signed.
			if ( ord( $bytes[0] ) > 0x7f ) {
				$bytes = "\x00" . $bytes;
			}
			return "\x02" . chr( strlen( $bytes ) ) . $bytes;
		};

		$body = $encode_int( substr( $raw, 0, 32 ) ) . $encode_int( substr( $raw, 32 ) );

		$header = strlen( $body ) > 0x7f
			? "\x30\x81" . chr( strlen( $body ) )
			: "\x30" . chr( strlen( $body ) );

		return $header . $body;
	}

	/**
	 * Base64url without padding (RFC 7515).
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function b64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JOSE encoding, not obfuscation.
	}

	/**
	 * Decode base64url (RFC 7515).
	 *
	 * @param string $data Encoded string.
	 * @return string Raw bytes, or '' on invalid input.
	 */
	public static function b64url_decode( string $data ): string {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JOSE decoding, not obfuscation.
		return false === $decoded ? '' : $decoded;
	}
}
