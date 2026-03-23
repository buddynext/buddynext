<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Email verification token management.
 *
 * Handles token creation, verification, and status checks for the
 * buddynext_email_verify feature gate.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use WP_Error;

/**
 * Manages email verification tokens stored in bn_verify_tokens.
 */
class VerificationService {

	/**
	 * Create a verification token for a user and fire the send action.
	 *
	 * Generates a 32-byte hex token, persists it to bn_verify_tokens with a
	 * 24-hour expiry, then fires the buddynext_send_verification_email action
	 * so that the listener can dispatch the email.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string The generated token (hex, 64 chars).
	 */
	public function create_token( int $user_id ): string {
		global $wpdb;

		$token = bin2hex( random_bytes( 32 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'bn_verify_tokens',
			array(
				'user_id'    => $user_id,
				'token'      => $token,
				'type'       => 'email_verify',
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		$token_url = add_query_arg( array( 'bn_verify' => $token ), home_url( '/' ) );

		/**
		 * Fires after a verification token is created.
		 *
		 * Listeners should send the verification email to the user.
		 *
		 * @param int    $user_id   WordPress user ID.
		 * @param string $token_url Full verification URL with token parameter.
		 */
		do_action( 'buddynext_send_verification_email', $user_id, $token_url );

		return $token;
	}

	/**
	 * Verify a token submitted by the user.
	 *
	 * Looks up the token in bn_verify_tokens, marks the user as verified via
	 * usermeta, deletes the token row, and returns the verified user ID.
	 * Returns a WP_Error when the token is not found or has expired.
	 *
	 * @param string $token The raw token string from the query parameter.
	 * @return int|WP_Error Verified user ID on success, WP_Error on failure.
	 */
	public function verify( string $token ): int|WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, expires_at FROM {$wpdb->prefix}bn_verify_tokens
				 WHERE token = %s AND type = 'email_verify'
				 LIMIT 1",
				$token
			)
		);

		if ( null === $row ) {
			return new WP_Error(
				'invalid_token',
				__( 'The verification token is invalid.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		if ( strtotime( (string) $row->expires_at ) <= time() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->prefix . 'bn_verify_tokens',
				array( 'id' => (int) $row->id ),
				array( '%d' )
			);

			return new WP_Error(
				'expired_token',
				__( 'The verification token has expired. Please request a new one.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$user_id = (int) $row->user_id;

		update_user_meta( $user_id, 'buddynext_email_verified', 1 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_verify_tokens',
			array( 'id' => (int) $row->id ),
			array( '%d' )
		);

		/**
		 * Fires after a user successfully verifies their email address.
		 *
		 * @param int $user_id Verified user ID.
		 */
		do_action( 'buddynext_user_verified', $user_id );

		return $user_id;
	}

	/**
	 * Check whether a user's email address is verified.
	 *
	 * Returns true immediately when the buddynext_email_verify setting is
	 * disabled, treating all users as verified in that mode.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public function is_verified( int $user_id ): bool {
		if ( ! (bool) get_option( 'buddynext_email_verify', false ) ) {
			return true;
		}

		return (bool) get_user_meta( $user_id, 'buddynext_email_verified', true );
	}

	/**
	 * Resend the verification email for a user.
	 *
	 * Deletes any existing pending token for the user, then calls create_token()
	 * to generate a fresh one and trigger the send action.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|WP_Error The new token on success, WP_Error when already verified.
	 */
	public function resend( int $user_id ): string|WP_Error {
		if ( $this->is_verified( $user_id ) ) {
			return new WP_Error(
				'already_verified',
				__( 'Your email address is already verified.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;

		// Delete any existing pending tokens for this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_verify_tokens',
			array(
				'user_id' => $user_id,
				'type'    => 'email_verify',
			),
			array( '%d', '%s' )
		);

		return $this->create_token( $user_id );
	}
}
