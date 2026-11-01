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
	public const DEFAULT_TTL_DAYS = 7;

	/**
	 * Create a new invite record.
	 *
	 * @param string $email      Email address to invite.
	 * @param string $first_name Recipient first name (optional, for personalisation).
	 * @param int    $ttl_days   Token lifetime in days. Negative values create an already-expired invite (useful in tests).
	 * @param int    $space_id   Optional space to drop the new member into on registration (0 = none).
	 * @param bool   $may_attach Whether the actor may point an existing pending invite with no space at $space_id.
	 *                           Site managers only: that invite is someone else's, and bn_invites records no issuer.
	 * @return array{status:string,invite_id:int} What happened, and the invite it happened to (0 when none):
	 *   - sent:              new invite, wp_mail accepted the email.
	 *   - queued:            new invite, the email went to Action Scheduler; the outcome is not known yet
	 *                        (a failed send marks the row 'bounced').
	 *   - mail_failed:       new invite, wp_mail refused the email; the row is 'bounced' and can be resent.
	 *   - attached:          the address had a pending invite with no space; it now leads to $space_id. No new email.
	 *   - already_invited:   the address has a pending invite this call left unchanged. No new email.
	 *   - invited_elsewhere: the address has a pending invite to a different space, which one invite cannot also hold.
	 *   - existing_member:   the address already has an account.
	 *   - invalid:           empty or malformed email.
	 *   - failed:            the row could not be written.
	 */
	public function create( string $email, string $first_name = '', int $ttl_days = self::DEFAULT_TTL_DAYS, int $space_id = 0, bool $may_attach = false ): array {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array(
				'status'    => 'invalid',
				'invite_id' => 0,
			);
		}

		// Dedup: never re-invite an address that already has a real account or a
		// live (non-expired) pending invite.
		if ( get_user_by( 'email', $email ) ) {
			return array(
				'status'    => 'existing_member',
				'invite_id' => 0,
			);
		}
		$pending = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, space_id FROM {$wpdb->prefix}bn_invites WHERE email = %s AND status = 'pending' AND expires_at > %s ORDER BY id DESC LIMIT 1",
				$email,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);
		if ( is_array( $pending ) ) {
			return array(
				'status'    => $this->link_pending_invite( (int) $pending['id'], (int) $pending['space_id'], $space_id, $may_attach ),
				'invite_id' => (int) $pending['id'],
			);
		}

		$token      = $this->generate_token();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $ttl_days * DAY_IN_SECONDS ) );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'bn_invites',
			array(
				'email'      => $email,
				'first_name' => sanitize_text_field( $first_name ),
				'space_id'   => $space_id > 0 ? $space_id : null,
				'token'      => $token,
				'status'     => 'pending',
				'expires_at' => $expires_at,
				'created_at' => current_time( 'mysql', true ),
			),
			$space_id > 0
				? array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
				: array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$invite_id = (int) $wpdb->insert_id;
		if ( $invite_id <= 0 ) {
			return array(
				'status'    => 'failed',
				'invite_id' => 0,
			);
		}

		$payload = array(
			'id'         => $invite_id,
			'email'      => $email,
			'first_name' => sanitize_text_field( $first_name ),
			'token'      => $token,
		);
		// Defer the send to Action Scheduler so a bulk/CSV import (which loops
		// create()) enqueues N fast async sends instead of N blocking wp_mail()
		// calls that time the request out. Falls back to inline when AS is absent.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'buddynext_async_send_invite_email', array( $payload ), 'buddynext' );
			$status = 'queued';
		} else {
			$status = $this->deliver_invite_email( $payload ) ? 'sent' : 'mail_failed';
		}

		return array(
			'status'    => $status,
			'invite_id' => $invite_id,
		);
	}

	/**
	 * Decide what a second invite to an address with a live pending invite means.
	 *
	 * One invite row holds one space, so a pending invite with no space can take on the
	 * space being asked for, and one already bound to another space cannot.
	 *
	 * @param int  $invite_id  Pending invite ID.
	 * @param int  $current    Space the pending invite leads to (0 = none).
	 * @param int  $requested  Space this invite is for (0 = none).
	 * @param bool $may_attach Whether the actor may repoint the invite. See create().
	 * @return string A create() status: attached, already_invited or invited_elsewhere.
	 */
	private function link_pending_invite( int $invite_id, int $current, int $requested, bool $may_attach ): string {
		if ( $requested <= 0 || $current === $requested ) {
			return 'already_invited';
		}
		if ( $current > 0 ) {
			return 'invited_elsewhere';
		}
		if ( ! $may_attach ) {
			return 'already_invited';
		}

		global $wpdb;
		// The space_id condition keeps two simultaneous invites from overwriting each other.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attached = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_invites SET space_id = %d WHERE id = %d AND space_id IS NULL",
				$requested,
				$invite_id
			)
		);

		return $attached > 0 ? 'attached' : 'invited_elsewhere';
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
	 * Is this invite addressed to that email?
	 *
	 * AN INVITE IS BOUND TO THE ADDRESS IT WAS SENT TO. It is not a bearer token.
	 * (Owner decision, 2026-07-13.)
	 *
	 * Until this existed, `bn_invites.email` was written at issue time and read in
	 * exactly one place — to address the outgoing mail. Nothing compared it to the
	 * person actually redeeming. So anyone holding the token (a forwarded mail, a
	 * referrer leak, a shared screenshot) could register ANY address on an
	 * invite-only site — and doing so BURNED the invite, locking the real invitee
	 * out permanently with no error and no way to reissue from their side.
	 *
	 * Case- and whitespace-insensitive: mail addresses are compared the way people
	 * type them, not the way they were stored. An invite with no email is never a
	 * match — an unaddressed invite would otherwise become a skeleton key.
	 *
	 * @param array<string,mixed> $invite Invite row from get_by_token().
	 * @param string              $email  The address actually being registered.
	 * @return bool
	 */
	public function is_for_email( array $invite, string $email ): bool {
		$invited = strtolower( trim( (string) ( $invite['email'] ?? '' ) ) );
		$actual  = strtolower( trim( $email ) );

		if ( '' === $invited || '' === $actual ) {
			return false;
		}

		return $invited === $actual;
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
	 * Reconcile every pending invite for an email address to 'registered'.
	 *
	 * The token-redemption path (AuthController) flips the one invite a member signed
	 * up through, but a member can also register with the invited address WITHOUT the
	 * link (direct sign-up, admin-created account, social login). bn_invites keys on
	 * email and carries no user_id, so absent this those invites would sit 'pending'
	 * forever in the admin list. Hooked on user_register — one indexed write (the
	 * `email` key) on a rare event. Flips ALL of an address's pending invites, since
	 * registering fulfils every outstanding invitation to that person at once.
	 *
	 * @param string $email Newly-registered account email.
	 * @return int Number of pending invites flipped to 'registered'.
	 */
	public function mark_registered_by_email( string $email ): int {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->update(
			$wpdb->prefix . 'bn_invites',
			array( 'status' => 'registered' ),
			array(
				'email'  => $email,
				'status' => 'pending',
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
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
	 * Retrieve all pending invites (back-compat wrapper, unbounded).
	 *
	 * Prefer get_invites() for paginated, status-filtered access.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_pending(): array {
		return $this->get_invites( 'pending', 1, 1000 );
	}

	/**
	 * Retrieve a paginated, status-filtered page of invites.
	 *
	 * @param string $status   'pending' | 'registered' | 'bounced' | 'expired' | 'all'.
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Rows per page.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_invites( string $status = 'pending', int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$offset   = ( $page - 1 ) * $per_page;
		$where    = $this->status_where( $status );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// $where is built from a fixed allow-list in status_where(); only LIMIT/OFFSET are bound.
				"SELECT * FROM {$wpdb->prefix}bn_invites {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return ! empty( $rows ) ? $rows : array();
	}

	/**
	 * Count invites matching a status filter (for pagination + badges).
	 *
	 * @param string $status See get_invites().
	 * @return int
	 */
	public function count_invites( string $status = 'pending' ): int {
		global $wpdb;
		$where = $this->status_where( $status );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_invites {$where}" );
	}

	/**
	 * Build a safe WHERE clause for a status filter. "expired" is a derived
	 * state (pending + past expiry); "pending" means live + unexpired.
	 *
	 * @param string $status Status filter.
	 * @return string SQL WHERE clause (may be empty for 'all').
	 */
	private function status_where( string $status ): string {
		global $wpdb;
		$now = esc_sql( current_time( 'mysql', true ) );

		switch ( $status ) {
			case 'pending':
				return "WHERE status = 'pending' AND expires_at > '{$now}'";
			case 'expired':
				return "WHERE status = 'pending' AND expires_at <= '{$now}'";
			case 'registered':
				return "WHERE status = 'registered'";
			case 'bounced':
				return "WHERE status = 'bounced'";
			case 'all':
			default:
				return '';
		}
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

		if ( ! $invite ) {
			return false;
		}

		// Report the real delivery result so the admin notice is honest rather
		// than always claiming success.
		return $this->deliver_invite_email( $invite );
	}

	/**
	 * Import invites from a CSV file.
	 *
	 * Reads the file at $csv_path, skips the header row, and attempts to
	 * create one invite per row. Each row must have the email address in the
	 * first column; an optional second column is treated as the recipient's
	 * first name.
	 *
	 * Blank or malformed rows, addresses that already have an account and addresses
	 * with a pending invite are counted as skipped, by reason. Rows that could not
	 * be written are errors. The file handle is always closed.
	 *
	 * @param int    $inviter_id User ID of the person sending invites (reserved for capability checks by callers).
	 * @param string $csv_path   Absolute path to the uploaded CSV file.
	 * @return array{imported: int, queued: int, mail_failed: int, skipped: int, reasons: array{already_invited: int, existing_member: int, invalid: int}, errors: string[], not_read: int, max_rows: int} Import summary.
	 *         imported counts every invite accepted for delivery; queued is the part of it not
	 *         actually mailed yet. not_read is how many rows the max_rows cap left unread.
	 */
	public function import_from_csv( int $inviter_id, string $csv_path ): array {
		$summary = array(
			'imported'    => 0,
			'queued'      => 0,
			'mail_failed' => 0,
			'skipped'     => 0,
			'reasons'     => array(
				'already_invited' => 0,
				'existing_member' => 0,
				'invalid'         => 0,
			),
			'errors'      => array(),
			'not_read'    => 0,
			'max_rows'    => 0,
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

		// Cap rows processed per upload so a huge file can't run an unbounded
		// insert + wp_mail loop. Filterable for larger trusted imports.
		$max_rows            = (int) apply_filters( 'buddynext_invite_csv_max_rows', 500 );
		$summary['max_rows'] = $max_rows;
		$processed           = 0;

		try {
			// Skip header row.
			fgetcsv( $handle, null, ',', '"', '' );

			while ( true ) {
				$row = fgetcsv( $handle, null, ',', '"', '' );
				if ( false === $row ) {
					break;
				}
				if ( $processed >= $max_rows ) {
					++$summary['not_read'];
					continue;
				}
				++$processed;

				$raw_email = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
				$email     = '' === $raw_email ? false : is_email( $raw_email );
				if ( false === $email ) {
					++$summary['skipped'];
					++$summary['reasons']['invalid'];
					continue;
				}

				$first_name = isset( $row[1] ) ? sanitize_text_field( trim( (string) $row[1] ) ) : '';
				$status     = $this->create( $email, $first_name )['status'];

				switch ( $status ) {
					case 'queued':
						++$summary['queued'];
						++$summary['imported'];
						break;
					case 'sent':
						++$summary['imported'];
						break;
					case 'mail_failed':
						++$summary['mail_failed'];
						break;
					case 'already_invited':
					case 'existing_member':
					case 'invalid':
						++$summary['skipped'];
						++$summary['reasons'][ $status ];
						break;
					default:
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
	 * Public entry point for the deferred invite-email send.
	 *
	 * The `buddynext_async_send_invite_email` Action Scheduler callback
	 * (OnboardingListener::handle_async_invite_email) calls this so create() can
	 * enqueue the send instead of dispatching inline.
	 *
	 * A refused send marks the invite 'bounced'.
	 *
	 * @param array<string, mixed> $invite Invite payload { id, email, first_name, token }.
	 * @return bool True when the email was handed to wp_mail successfully.
	 */
	public function deliver_invite_email( array $invite ): bool {
		$sent = $this->send_invite_email( $invite );

		// Nobody is waiting on a queued send, so the row is the only place a failure can
		// show: the invite moves to the Bounced tab, where it can be resent.
		if ( ! $sent && ! empty( $invite['id'] ) ) {
			$this->set_status( (int) $invite['id'], 'bounced' );
		}

		return $sent;
	}

	/**
	 * Send the invite email for a given invite row.
	 *
	 * Fetches the bn.bulk_invite template from the DB (enabled rows only),
	 * replaces {{first_name}}, {{site_name}}, and {{invite_url}} placeholders,
	 * and dispatches via wp_mail.
	 *
	 * @param array<string, mixed> $invite Invite row (must contain 'email', 'first_name', 'token').
	 * @return bool True when the email was handed to wp_mail successfully.
	 */
	private function send_invite_email( array $invite ): bool {
		$to = sanitize_email( (string) ( $invite['email'] ?? '' ) );
		if ( '' === $to ) {
			return false;
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
			return false;
		}

		$first_name = '' !== ( (string) ( $invite['first_name'] ?? '' ) ) ? (string) $invite['first_name'] : __( 'there', 'buddynext' );
		// Point the link at the branded registration screen with the token under
		// the `invite` param the signup form + AuthController actually read (not
		// wp-login.php / bn_invite, which nothing consumes).
		$invite_url = add_query_arg( 'invite', rawurlencode( (string) ( $invite['token'] ?? '' ) ), \BuddyNext\Core\PageRouter::signup_url() );
		$site_name  = wp_specialchars_decode( buddynext_site_name(), ENT_QUOTES );

		$tokens = array(
			'{{first_name}}' => esc_html( $first_name ),
			'{{site_name}}'  => esc_html( $site_name ),
			'{{invite_url}}' => esc_url( $invite_url ),
		);

		$subject = str_replace( array_keys( $tokens ), array_values( $tokens ), (string) $tpl->subject );
		$body    = str_replace( array_keys( $tokens ), array_values( $tokens ), (string) $tpl->body_html );

		// Route through the shared identity helper + branded shell so invites
		// carry the same From/Reply-To and chrome as every other email.
		return \BuddyNext\Notifications\EmailSender::send_with_identity(
			$to,
			$subject,
			\BuddyNext\Notifications\EmailSender::brand_wrap( $body, $subject ),
			\BuddyNext\Notifications\EmailSender::build_identity_headers()
		);
	}
}
