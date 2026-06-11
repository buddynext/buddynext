<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Two-factor authentication for BuddyNext — fully in-house.
 *
 * No third-party service: TOTP (RFC 6238 / RFC 4226) is implemented here, so any
 * standard authenticator app (Google Authenticator, Authy, 1Password, …) works.
 * Recovery is covered by one-time backup codes, and an email one-time code is
 * offered at the login challenge as a fallback when the device is unavailable.
 *
 * Surfaces (three entry points, per the plugin rules):
 *   - Member  : enrolment + management in the profile edit "Account" area
 *               (TwoFactorController REST endpoints).
 *   - Login    : AuthController issues a challenge after the password step and
 *               completes sign-in only once a code verifies.
 *   - Admin    : Settings → Registration can require 2FA for chosen roles.
 *
 * Storage (usermeta):
 *   bn_2fa_enabled         '1' once confirmed.
 *   bn_2fa_secret          Base32 TOTP shared secret (active).
 *   bn_2fa_pending_secret  Base32 secret during enrolment, before first verify.
 *   bn_2fa_backup          Array of hashed, single-use backup codes.
 *
 * Transients:
 *   bn_2fa_login_{id}      One-time login-challenge ticket → [user, remember].
 *   bn_2fa_email_{user}    Hashed email one-time code.
 *
 * Filters:
 *   buddynext_2fa_issuer            (string) label shown in the authenticator app.
 *   buddynext_2fa_required_roles    (string[]) roles that must enable 2FA.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * In-house TOTP two-factor service.
 */
class TwoFactorService {

	private const META_ENABLED = 'bn_2fa_enabled';
	private const META_SECRET  = 'bn_2fa_secret';
	private const META_PENDING = 'bn_2fa_pending_secret';
	private const META_BACKUP  = 'bn_2fa_backup';

	private const PERIOD       = 30;   // TOTP step, seconds.
	private const DIGITS       = 6;
	private const WINDOW       = 1;    // ± steps tolerated for clock skew.
	private const LOGIN_TTL    = 300;  // Login-challenge ticket lifetime, seconds.
	private const EMAIL_TTL    = 600;  // Email one-time code lifetime, seconds.
	private const BACKUP_COUNT = 10;
	private const BASE32       = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/* ───────────────────────────── Status ──────────────────────────────── */

	/**
	 * Is confirmed 2FA active for this user?
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_enabled( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, self::META_ENABLED, true )
			&& '' !== (string) get_user_meta( $user_id, self::META_SECRET, true );
	}

	/**
	 * How many unused backup codes remain.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function backup_codes_remaining( int $user_id ): int {
		$codes = get_user_meta( $user_id, self::META_BACKUP, true );
		return is_array( $codes ) ? count( $codes ) : 0;
	}

	/**
	 * Whether this user's role is expected to use 2FA.
	 *
	 * 2FA is opt-in for everyone by default — this returns false unless a site
	 * opts a role in via the `buddynext_2fa_required_roles` filter. It is purely
	 * advisory (surfaced as a hint in the UI); BuddyNext never blocks sign-in for
	 * not having 2FA on.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function is_required_for( WP_User $user ): bool {
		$roles = (array) apply_filters( 'buddynext_2fa_required_roles', array() );
		if ( empty( $roles ) ) {
			return false;
		}
		return (bool) array_intersect( (array) $user->roles, array_map( 'strval', $roles ) );
	}

	/* ─────────────────────────── Enrolment ─────────────────────────────── */

	/**
	 * Begin enrolment: mint a fresh secret, stash it as pending, and return the
	 * secret plus an otpauth:// URI for the authenticator-app QR code. Nothing is
	 * enforced until confirm_enrollment() verifies the first code.
	 *
	 * @param int $user_id User ID.
	 * @return array{secret:string, uri:string}
	 */
	public static function begin_enrollment( int $user_id ): array {
		$secret = self::generate_secret();
		update_user_meta( $user_id, self::META_PENDING, $secret );

		$user    = get_userdata( $user_id );
		$account = $user ? (string) $user->user_login : (string) $user_id;

		return array(
			'secret' => $secret,
			'uri'    => self::provisioning_uri( $secret, $account ),
		);
	}

	/**
	 * Confirm enrolment by verifying a code against the pending secret. On
	 * success the secret becomes active, 2FA turns on, and a fresh set of backup
	 * codes is generated and returned (plaintext, shown once).
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    6-digit code from the authenticator app.
	 * @return array{backup_codes:string[]}|WP_Error
	 */
	public static function confirm_enrollment( int $user_id, string $code ): array|WP_Error {
		$pending = (string) get_user_meta( $user_id, self::META_PENDING, true );
		if ( '' === $pending ) {
			return new WP_Error( 'bn_2fa_no_pending', __( 'Start the setup again — the previous attempt expired.', 'buddynext' ) );
		}
		if ( ! self::verify_totp( $pending, $code ) ) {
			return new WP_Error( 'bn_2fa_bad_code', __( 'That code did not match. Check your authenticator app and try again.', 'buddynext' ) );
		}

		update_user_meta( $user_id, self::META_SECRET, $pending );
		update_user_meta( $user_id, self::META_ENABLED, '1' );
		delete_user_meta( $user_id, self::META_PENDING );

		$codes = self::generate_backup_codes( $user_id );

		/**
		 * Fires when a user turns on two-factor authentication.
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'buddynext_2fa_enabled', $user_id );

		return array( 'backup_codes' => $codes );
	}

	/**
	 * Turn 2FA off and wipe all related secrets/codes for the user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function disable( int $user_id ): void {
		delete_user_meta( $user_id, self::META_ENABLED );
		delete_user_meta( $user_id, self::META_SECRET );
		delete_user_meta( $user_id, self::META_PENDING );
		delete_user_meta( $user_id, self::META_BACKUP );

		/**
		 * Fires when a user turns off two-factor authentication.
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'buddynext_2fa_disabled', $user_id );
	}

	/* ─────────────────────────── Backup codes ──────────────────────────── */

	/**
	 * Generate, store (hashed), and return a fresh set of one-time backup codes.
	 * Replaces any existing set.
	 *
	 * @param int $user_id User ID.
	 * @return string[] Plaintext codes (format "abcd-efgh"), shown to the user once.
	 */
	public static function generate_backup_codes( int $user_id ): array {
		$plain  = array();
		$hashed = array();
		for ( $i = 0; $i < self::BACKUP_COUNT; $i++ ) {
			$raw      = strtolower( wp_generate_password( 8, false, false ) );
			$code     = substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 4 );
			$plain[]  = $code;
			$hashed[] = self::hash_code( $code );
		}
		update_user_meta( $user_id, self::META_BACKUP, $hashed );
		return $plain;
	}

	/**
	 * Verify and consume a single-use backup code (constant work per stored code).
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Code as entered (case/space insensitive).
	 * @return bool True when a code matched and was consumed.
	 */
	public static function verify_backup_code( int $user_id, string $code ): bool {
		$code  = strtolower( str_replace( array( ' ', '_' ), array( '', '-' ), trim( $code ) ) );
		$codes = get_user_meta( $user_id, self::META_BACKUP, true );
		if ( ! is_array( $codes ) || empty( $code ) ) {
			return false;
		}
		$target = self::hash_code( $code );
		$found  = false;
		$remain = array();
		foreach ( $codes as $stored ) {
			if ( ! $found && hash_equals( (string) $stored, $target ) ) {
				$found = true; // Consume exactly one match.
				continue;
			}
			$remain[] = $stored;
		}
		if ( $found ) {
			update_user_meta( $user_id, self::META_BACKUP, $remain );
		}
		return $found;
	}

	/* ──────────────────────── Email fallback code ──────────────────────── */

	/**
	 * Generate a one-time numeric code, store it hashed, and email it to the user.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when the mail was handed off to wp_mail().
	 */
	public static function send_email_code( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || '' === (string) $user->user_email ) {
			return false;
		}
		$code = (string) wp_rand( 100000, 999999 );
		set_transient( 'bn_2fa_email_' . $user_id, self::hash_code( $code ), self::EMAIL_TTL );

		$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$minutes = (int) ( self::EMAIL_TTL / 60 );
		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Your %s sign-in code', 'buddynext' ),
			$site
		);
		$message = sprintf(
			/* translators: 1: 6-digit code, 2: minutes until expiry. */
			__( "Your verification code is: %1\$s\n\nIt expires in %2\$d minutes. If you did not try to sign in, you can ignore this email.", 'buddynext' ),
			$code,
			$minutes
		);

		return (bool) wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Verify and consume an emailed one-time code.
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Code as entered.
	 * @return bool
	 */
	public static function verify_email_code( int $user_id, string $code ): bool {
		$stored = (string) get_transient( 'bn_2fa_email_' . $user_id );
		$code   = preg_replace( '/\D/', '', $code );
		if ( '' === $stored || '' === (string) $code ) {
			return false;
		}
		if ( hash_equals( $stored, self::hash_code( (string) $code ) ) ) {
			delete_transient( 'bn_2fa_email_' . $user_id );
			return true;
		}
		return false;
	}

	/**
	 * Verify a code at login against any active factor: TOTP, then emailed code,
	 * then a single-use backup code.
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Code as entered.
	 * @return bool
	 */
	public static function verify_login_code( int $user_id, string $code ): bool {
		$secret = (string) get_user_meta( $user_id, self::META_SECRET, true );
		if ( '' !== $secret && self::verify_totp( $secret, $code ) ) {
			return true;
		}
		if ( self::verify_email_code( $user_id, $code ) ) {
			return true;
		}
		return self::verify_backup_code( $user_id, $code );
	}

	/* ─────────────────────── Login-challenge ticket ────────────────────── */

	/**
	 * Issue a one-time, server-side login-challenge ticket (no auth cookie set).
	 * The opaque token is handed to the client and exchanged for completion once
	 * a code verifies.
	 *
	 * @param int  $user_id  Authenticated (password-verified) user ID.
	 * @param bool $remember Whether the eventual cookie should be persistent.
	 * @return string Opaque ticket token.
	 */
	public static function issue_login_challenge( int $user_id, bool $remember ): string {
		$token = wp_generate_password( 32, false, false );
		set_transient(
			'bn_2fa_login_' . $token,
			array(
				'user'     => $user_id,
				'remember' => $remember,
			),
			self::LOGIN_TTL
		);
		return $token;
	}

	/**
	 * Read a login-challenge ticket without consuming it (e.g. to email a code).
	 *
	 * @param string $token Ticket token.
	 * @return array{user:int, remember:bool}|null
	 */
	public static function peek_login_challenge( string $token ): ?array {
		$data = get_transient( 'bn_2fa_login_' . $token );
		if ( ! is_array( $data ) || empty( $data['user'] ) ) {
			return null;
		}
		return array(
			'user'     => (int) $data['user'],
			'remember' => ! empty( $data['remember'] ),
		);
	}

	/**
	 * Consume (read once, then delete) a login-challenge ticket.
	 *
	 * @param string $token Ticket token.
	 * @return array{user:int, remember:bool}|null
	 */
	public static function consume_login_challenge( string $token ): ?array {
		$data = self::peek_login_challenge( $token );
		if ( null !== $data ) {
			delete_transient( 'bn_2fa_login_' . $token );
		}
		return $data;
	}

	/* ─────────────────────────── TOTP / RFC 6238 ───────────────────────── */

	/**
	 * Generate a fresh Base32 TOTP secret (160-bit).
	 *
	 * @return string 32-character Base32 string.
	 */
	public static function generate_secret(): string {
		$bytes = function_exists( 'random_bytes' ) ? random_bytes( 20 ) : '';
		if ( '' === $bytes ) {
			// Fallback: 20 bytes derived from wp_generate_password entropy.
			for ( $i = 0; $i < 20; $i++ ) {
				$bytes .= chr( wp_rand( 0, 255 ) );
			}
		}
		return self::base32_encode( $bytes );
	}

	/**
	 * Build the otpauth:// provisioning URI an authenticator app scans.
	 *
	 * @param string $secret  Base32 secret.
	 * @param string $account Account label (username).
	 * @return string
	 */
	public static function provisioning_uri( string $secret, string $account ): string {
		$issuer = (string) apply_filters(
			'buddynext_2fa_issuer',
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES )
		);
		$label  = rawurlencode( $issuer . ':' . $account );
		return sprintf(
			'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
			$label,
			rawurlencode( $secret ),
			rawurlencode( $issuer ),
			self::DIGITS,
			self::PERIOD
		);
	}

	/**
	 * Verify a TOTP code against a secret, tolerating ± WINDOW steps of skew.
	 *
	 * @param string $secret Base32 secret.
	 * @param string $code   Submitted code.
	 * @return bool
	 */
	public static function verify_totp( string $secret, string $code ): bool {
		$code = preg_replace( '/\D/', '', $code );
		if ( strlen( (string) $code ) !== self::DIGITS ) {
			return false;
		}
		$key = self::base32_decode( $secret );
		if ( '' === $key ) {
			return false;
		}
		$counter = (int) floor( time() / self::PERIOD );
		for ( $offset = -self::WINDOW; $offset <= self::WINDOW; $offset++ ) {
			if ( hash_equals( self::hotp( $key, $counter + $offset ), (string) $code ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * HOTP (RFC 4226) for one counter value — the per-step TOTP building block.
	 *
	 * @param string $key     Raw (decoded) secret bytes.
	 * @param int    $counter Moving factor.
	 * @return string Zero-padded DIGITS-length code.
	 */
	private static function hotp( string $key, int $counter ): string {
		// 8-byte big-endian counter (top 4 bytes are zero for any realistic time).
		$binary = pack( 'N', 0 ) . pack( 'N', $counter );
		$hash   = hash_hmac( 'sha1', $binary, $key, true );
		$offset = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0f;
		$value  = ( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 )
			| ( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 )
			| ( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 )
			| ( ord( $hash[ $offset + 3 ] ) & 0xff );
		$value %= 10 ** self::DIGITS;
		return str_pad( (string) $value, self::DIGITS, '0', STR_PAD_LEFT );
	}

	/* ───────────────────────────── Helpers ─────────────────────────────── */

	/**
	 * Salted hash for backup/email codes (high-entropy inputs → SHA-256 is ample).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function hash_code( string $code ): string {
		return hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
	}

	/**
	 * RFC 4648 Base32 encode (no padding).
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function base32_encode( string $data ): string {
		if ( '' === $data ) {
			return '';
		}
		$bits = '';
		$len  = strlen( $data );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( ord( $data[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$out    = '';
		$chunks = str_split( $bits, 5 );
		foreach ( $chunks as $chunk ) {
			$out .= self::BASE32[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	/**
	 * RFC 4648 Base32 decode (case-insensitive, ignores padding/spaces).
	 *
	 * @param string $data Base32 string.
	 * @return string Raw bytes, or '' on invalid input.
	 */
	private static function base32_decode( string $data ): string {
		$data = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', $data ) );
		if ( '' === $data ) {
			return '';
		}
		$bits = '';
		$len  = strlen( $data );
		for ( $i = 0; $i < $len; $i++ ) {
			$pos = strpos( self::BASE32, $data[ $i ] );
			if ( false === $pos ) {
				return '';
			}
			$bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}
		$out    = '';
		$chunks = str_split( $bits, 8 );
		foreach ( $chunks as $chunk ) {
			if ( 8 === strlen( $chunk ) ) {
				$out .= chr( bindec( $chunk ) );
			}
		}
		return $out;
	}
}
