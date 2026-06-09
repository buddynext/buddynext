<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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

		$invite_id = (int) $wpdb->insert_id;
		if ( $invite_id > 0 ) {
			$this->send_invite_email(
				array(
					'id'         => $invite_id,
					'email'      => sanitize_email( $email ),
					'first_name' => sanitize_text_field( $first_name ),
					'token'      => $token,
				)
			);
		}

		return $invite_id;
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
	 * Revoke (delete) an invite so its token can no longer be redeemed.
	 *
	 * @param int $invite_id Invite record ID.
	 * @return bool True when a row was removed.
	 */
	public function revoke( int $invite_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $wpdb->prefix . 'bn_invites', array( 'id' => (int) $invite_id ), array( '%d' ) );

		return (bool) $deleted;
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

		return ! empty( $rows ) ? $rows : array();
	}

	/**
	 * Regenerate the token and resend the invite email.
	 *
	 * Resets an existing invite (any status) to 'pending' with a fresh token
	 * and a new expiry window, then dispatches the invite email again.
	 *
	 * @param int $invite_id Invite record ID.
	 * @return bool True if the update succeeded and email was dispatched.
	 */
	public function resend( int $invite_id ): bool {
		global $wpdb;

		$token      = $this->generate_token();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( self::DEFAULT_TTL_DAYS * DAY_IN_SECONDS ) );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_invites',
			array(
				'token'      => $token,
				'expires_at' => $expires_at,
				'status'     => 'pending',
			),
			array( 'id' => $invite_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated || 0 === $updated ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$invite = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bn_invites WHERE id = %d", $invite_id ),
			ARRAY_A
		);

		if ( $invite ) {
			$this->send_invite_email( $invite );
		}

		return true;
	}

	/**
	 * Import invites from a CSV file.
	 *
	 * Reads the file at $csv_path, skips the header row, and attempts to
	 * create one invite per row. Each row must have the email address in the
	 * first column; an optional second column is treated as the recipient's
	 * first name.
	 *
	 * Rows with an invalid email format are counted as skipped. Rows that
	 * fail to insert (e.g. duplicate email in a pending state) are counted
	 * as errors with a descriptive message. The file handle is always closed.
	 *
	 * @param int    $inviter_id User ID of the person sending invites (reserved for capability checks by callers).
	 * @param string $csv_path   Absolute path to the uploaded CSV file.
	 * @return array{imported: int, skipped: int, errors: string[]} Import summary.
	 */
	public function import_from_csv( int $inviter_id, string $csv_path ): array {
		$summary = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		if ( ! is_readable( $csv_path ) ) {
			$summary['errors'][] = __( 'CSV file is not readable.', 'buddynext' );
			return $summary;
		}

		$handle = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$summary['errors'][] = __( 'Could not open CSV file.', 'buddynext' );
			return $summary;
		}

		try {
			// Skip header row.
			fgetcsv( $handle );

			while ( true ) {
				$row = fgetcsv( $handle );
				if ( false === $row ) {
					break;
				}

				$raw_email = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
				if ( '' === $raw_email ) {
					++$summary['skipped'];
					continue;
				}

				$email = is_email( $raw_email );
				if ( false === $email ) {
					++$summary['skipped'];
					continue;
				}

				$first_name = isset( $row[1] ) ? sanitize_text_field( trim( (string) $row[1] ) ) : '';
				$invite_id  = $this->create( $email, $first_name );

				if ( $invite_id > 0 ) {
					++$summary['imported'];
				} else {
					/* translators: %s: email address */
					$summary['errors'][] = sprintf( __( 'Failed to create invite for %s.', 'buddynext' ), $email );
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $summary;
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

	/**
	 * Send the invite email for a given invite row.
	 *
	 * Fetches the bn.bulk_invite template from the DB (enabled rows only),
	 * replaces {{first_name}}, {{site_name}}, and {{invite_url}} placeholders,
	 * and dispatches via wp_mail.
	 *
	 * @param array<string, mixed> $invite Invite row (must contain 'email', 'first_name', 'token').
	 * @return void
	 */
	private function send_invite_email( array $invite ): void {
		$to = sanitize_email( (string) ( $invite['email'] ?? '' ) );
		if ( '' === $to ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tpl = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT subject, body_html FROM {$wpdb->prefix}bn_email_templates WHERE type = %s AND enabled = 1",
				'bn.bulk_invite'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $tpl ) {
			return;
		}

		$first_name = '' !== ( (string) ( $invite['first_name'] ?? '' ) ) ? (string) $invite['first_name'] : __( 'there', 'buddynext' );
		$invite_url = add_query_arg( 'bn_invite', rawurlencode( (string) ( $invite['token'] ?? '' ) ), wp_registration_url() );
		$site_name  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$tokens = array(
			'{{first_name}}' => esc_html( $first_name ),
			'{{site_name}}'  => esc_html( $site_name ),
			'{{invite_url}}' => esc_url( $invite_url ),
		);

		$subject = str_replace( array_keys( $tokens ), array_values( $tokens ), (string) $tpl->subject );
		$body    = str_replace( array_keys( $tokens ), array_values( $tokens ), (string) $tpl->body_html );

		wp_mail(
			$to,
			$subject,
			'<html><body>' . $body . '</body></html>',
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}
}
