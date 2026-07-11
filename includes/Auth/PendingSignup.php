<?php
/**
 * A signup parked mid-flight, before any WordPress user exists.
 *
 * OAuth gives us an email and a name. It cannot give us terms consent, or the
 * profile fields the owner marked required at registration. The old code created
 * the account anyway and patched the gaps afterwards — and that ordering is
 * precisely what produced the admin-approval bypass: the provider link was
 * written before the "awaiting approval" branch returned, so a second click
 * matched an existing linked owner and sailed straight past the gate.
 *
 * So we do not create anything until the signup is complete. The provider
 * profile is parked here and finished on a real form. No user row, no orphan,
 * nothing to patch.
 *
 * Transient-backed and deliberately short-lived.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Holds a social signup that cannot complete yet.
 */
class PendingSignup {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'bn_pending_signup_';

	/**
	 * How long a parked signup stays resumable.
	 */
	private const TTL = 900; // 15 minutes.

	/**
	 * Park a provider profile and return its one-time token.
	 *
	 * @param array<string,mixed> $profile Provider profile: provider, uid, email,
	 *                                     email_verified, name, picture.
	 * @return string Opaque token.
	 */
	public static function park( array $profile ): string {
		$token = bin2hex( random_bytes( 24 ) );
		set_transient( self::PREFIX . $token, $profile, self::TTL );

		return $token;
	}

	/**
	 * Read a parked signup without consuming it.
	 *
	 * @param string $token Token from park().
	 * @return array<string,mixed>|null Null when unknown or expired.
	 */
	public static function get( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}

		$data = get_transient( self::PREFIX . sanitize_key( $token ) );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Read and destroy a parked signup.
	 *
	 * Single-use: the token is spent the moment the account is created, so a
	 * replayed "finish signup" cannot mint a second member.
	 *
	 * @param string $token Token from park().
	 * @return array<string,mixed>|null Null when unknown or expired.
	 */
	public static function consume( string $token ): ?array {
		$data = self::get( $token );

		if ( null !== $data ) {
			delete_transient( self::PREFIX . sanitize_key( $token ) );
		}

		return $data;
	}
}
