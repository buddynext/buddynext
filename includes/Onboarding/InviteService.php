<?php
/**
 * Admin bulk invite service.
 *
 * Creates invite records in bn_invites, generates a unique secure token per
 * invite, and tracks invite lifecycle (pending → registered | bounced).
 *
 * The invite token is included in the registration URL so that:
 *   - Invite-only registration accepts the token without a public invite code.
 *   - The registration form pre-fills the email field.
 *   - On registration, mark_registered() is called.
 *
 * Token expiry is checked on get_by_token() — expired invites return null.
 *
 * @package BuddyNext\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Onboarding;

/**
 * Manages the bn_invites table for admin bulk invitations.
 */
class InviteService {

	/**
	 * Default invite TTL in days.
	 */
	private const DEFAULT_TTL_DAYS = 7;

	/**
	 * Create a new invite record.
	 *
	 * @param string $email      Email address to invite.
	 * @param string $first_name Recipient first name (optional, for personalisation).
	 * @param int    $ttl_days   Token lifetime in days. Negative values create an already-expired invite (useful in tests).
	 * @return int  New invite ID.
	 */
	public function create( string $email, string $first_name = '', int $ttl_days = self::DEFAULT_TTL_DAYS ): int {
		global $wpdb;

		$token      = $this->generate_token();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $ttl_days * DAY_IN_SECONDS ) );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'bn_invites',
			array(
				'email'      => sanitize_email( $email ),
				'first_name' => sanitize_text_field( $first_name ),
				'token'      => $token,
				'status'     => 'pending',
				'expires_at' => $expires_at,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a valid (non-expired, non-registered) invite by token.
	 *
	 * @param string $token Invite token.
	 * @return array<string, mixed>|null Invite row as associative array, or null if not found / expired.
	 */
	public function get_by_token( string $token ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_invites
				  WHERE token = %s
				    AND status = 'pending'
				    AND expires_at > %s",
				$token,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		return $row ?? null;
	}

	/**
	 * Mark an invite as registered (invited user signed up).
	 *
	 * @param int $invite_id Invite record ID.
	 * @return void
	 */
	public function mark_registered( int $invite_id ): void {
		$this->set_status( $invite_id, 'registered' );
	}

	/**
	 * Mark an invite as bounced (email could not be delivered).
	 *
	 * @param int $invite_id Invite record ID.
	 * @return void
	 */
	public function mark_bounced( int $invite_id ): void {
		$this->set_status( $invite_id, 'bounced' );
	}

	/**
	 * Retrieve all pending invites.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_pending(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$wpdb->prefix}bn_invites WHERE status = 'pending' ORDER BY created_at DESC",
			ARRAY_A
		);

		return $rows ?: array();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Generate a cryptographically secure token.
	 *
	 * @return string 64-character hex string.
	 */
	private function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Update the status column for an invite.
	 *
	 * @param int    $invite_id Invite record ID.
	 * @param string $status    New status value.
	 * @return void
	 */
	private function set_status( int $invite_id, string $status ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_invites',
			array( 'status' => $status ),
			array( 'id' => $invite_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
