<?php
/**
 * Checks a signed package manifest against a downloaded zip. Pure PHP: no WordPress calls, so it is unit-tested
 * on its own (tests/verifier-test.php).
 *
 * @package PackageSignatures
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manifest format, version 1 (JSON object, signed as the exact bytes delivered):
 *   v         1
 *   product   product slug, must match the registration
 *   version   the release version
 *   file      zip file name (informational)
 *   size      zip size in bytes
 *   sha256    lowercase hex SHA-256 of the zip
 *   key_id    which public key signed it
 *   signed_at ISO 8601 UTC
 *
 * Bundle (what the store sends in the X-Package-Signature header, base64 encoded, or serves at the fallback URL):
 *   { "manifest": "<base64 of the manifest bytes>", "signature": "<base64 Ed25519 detached signature>" }
 */
class Package_Signatures_Verifier {

	const MAX_BUNDLE_BYTES = 8192;

	/**
	 * Verifies a bundle for a downloaded zip.
	 *
	 * @param string $bundle Bundle JSON.
	 * @param array  $keys   key_id => base64 Ed25519 public key.
	 * @param string $file   Path to the downloaded zip.
	 * @param array  $expect product (required), version (offered, optional), installed (optional).
	 * @return array { ok: bool, reason: string, manifest?: array }
	 */
	public static function verify( $bundle, array $keys, $file, array $expect ) {
		if ( ! is_string( $bundle ) || '' === $bundle ) {
			return self::fail( 'no_signature' );
		}
		if ( strlen( $bundle ) > self::MAX_BUNDLE_BYTES ) {
			return self::fail( 'signature_too_large' );
		}
		$b = json_decode( $bundle, true );
		if ( ! is_array( $b ) || ! isset( $b['manifest'], $b['signature'] ) || ! is_string( $b['manifest'] ) || ! is_string( $b['signature'] ) ) {
			return self::fail( 'bad_bundle' );
		}
		$manifest_bytes = base64_decode( $b['manifest'], true );
		$signature      = base64_decode( $b['signature'], true );
		if ( false === $manifest_bytes || false === $signature || 64 !== strlen( $signature ) ) {
			return self::fail( 'bad_bundle' );
		}
		$m = json_decode( $manifest_bytes, true );
		if ( ! is_array( $m ) || 1 !== ( $m['v'] ?? null ) ) {
			return self::fail( 'bad_manifest' );
		}
		$key_id = (string) ( $m['key_id'] ?? '' );
		if ( ! isset( $keys[ $key_id ] ) ) {
			return self::fail( 'unknown_key' );
		}
		$public = base64_decode( (string) $keys[ $key_id ], true );
		if ( false === $public || 32 !== strlen( $public ) ) {
			return self::fail( 'bad_public_key' );
		}
		if ( ! self::signature_ok( $signature, $manifest_bytes, $public ) ) {
			return self::fail( 'bad_signature' );
		}

		// Signed facts must describe this product, this offer and this file.
		if ( (string) ( $m['product'] ?? '' ) !== (string) $expect['product'] ) {
			return self::fail( 'wrong_product', $m );
		}
		$version = (string) ( $m['version'] ?? '' );
		if ( ! empty( $expect['version'] ) && $version !== (string) $expect['version'] ) {
			return self::fail( 'wrong_version', $m );
		}
		if ( ! empty( $expect['installed'] ) && version_compare( $version, (string) $expect['installed'], '<=' ) ) {
			return self::fail( 'not_newer', $m );
		}
		if ( ! is_readable( $file ) ) {
			return self::fail( 'file_missing', $m );
		}
		if ( (int) ( $m['size'] ?? -1 ) !== (int) filesize( $file ) ) {
			return self::fail( 'wrong_size', $m );
		}
		$sha = (string) ( $m['sha256'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $sha ) || ! hash_equals( $sha, (string) hash_file( 'sha256', $file ) ) ) {
			return self::fail( 'wrong_hash', $m );
		}

		return array( 'ok' => true, 'reason' => 'verified', 'manifest' => $m );
	}

	/**
	 * Ed25519 detached signature check; uses WordPress's bundled sodium_compat when ext-sodium is missing.
	 *
	 * @param string $signature Raw 64-byte signature.
	 * @param string $message   Signed bytes.
	 * @param string $public    Raw 32-byte public key.
	 * @return bool
	 */
	private static function signature_ok( $signature, $message, $public ) {
		try {
			if ( function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return sodium_crypto_sign_verify_detached( $signature, $message, $public );
			}
			if ( class_exists( 'ParagonIE_Sodium_Compat' ) ) {
				return ParagonIE_Sodium_Compat::crypto_sign_verify_detached( $signature, $message, $public );
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
	}

	/**
	 * Failure result.
	 *
	 * @param string $reason   Machine-readable reason.
	 * @param array  $manifest Parsed manifest, when there is one.
	 * @return array
	 */
	private static function fail( $reason, array $manifest = array() ) {
		return array( 'ok' => false, 'reason' => $reason, 'manifest' => $manifest );
	}
}
