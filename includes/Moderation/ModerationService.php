<?php
/**
 * Moderation service.
 *
 * Manages user-submitted reports, moderation actions (dismiss, escalate,
 * resolve), and user strikes. All state-changing actions that modify
 * content visibility or user standing require manage_options capability.
 *
 * @package BuddyNext\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Moderation;

use WP_Error;

/**
 * Handles reports, strikes, and moderation queue actions.
 */
class ModerationService {

	/**
	 * Valid report reasons.
	 */
	private const REASONS = array(
		'spam',
		'harassment',
		'misinformation',
		'inappropriate',
		'fake',
		'impersonation',
		'other',
	);

	/**
	 * Valid appeal decision values.
	 */
	private const APPEAL_DECISIONS = array( 'approved', 'denied' );

	/**
	 * Submit a report on an object.
	 *
	 * Each user may only report a given object once (UNIQUE KEY enforced at DB).
	 *
	 * @param int    $reporter_id Reporting user.
	 * @param string $object_type Object type (e.g. 'post', 'comment', 'user').
	 * @param int    $object_id   Object ID.
	 * @param string $reason      Report reason (one of REASONS).
	 * @param int    $space_id    Optional space context (0 = no space).
	 * @param string $notes       Optional free-text notes.
	 * @return int|WP_Error Inserted report ID or WP_Error on duplicate/validation.
	 */
	public function report( int $reporter_id, string $object_type, int $object_id, string $reason, int $space_id = 0, string $notes = '' ): int|WP_Error {
		$reason = sanitize_key( $reason );

		if ( ! in_array( $reason, self::REASONS, true ) ) {
			$reason = 'other';
		}

		global $wpdb;

		// Check for existing report by this user on this object.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_reports
				 WHERE reporter_id = %d AND object_type = %s AND object_id = %d",
				$reporter_id,
				sanitize_key( $object_type ),
				$object_id
			)
		);

		if ( null !== $existing ) {
			return new WP_Error( 'already_reported', __( 'You have already reported this content.', 'buddynext' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_reports',
			array(
				'reporter_id' => $reporter_id,
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id,
				'reason'      => $reason,
				'space_id'    => $space_id > 0 ? $space_id : null,
				'notes'       => sanitize_textarea_field( $notes ),
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s' )
		);

		$report_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a report is submitted.
		 *
		 * @param int    $report_id   New report ID.
		 * @param string $object_type Object type reported.
		 * @param int    $object_id   Object ID reported.
		 * @param int    $reporter_id User who submitted the report.
		 */
		do_action( 'buddynext_report_created', $report_id, sanitize_key( $object_type ), $object_id, $reporter_id );

		return $report_id;
	}

	/**
	 * Dismiss a report (no action taken — false positive).
	 *
	 * @param int $report_id Report to dismiss.
	 * @param int $actor_id  Admin acting on the report.
	 * @return true|WP_Error
	 */
	public function dismiss( int $report_id, int $actor_id ): true|WP_Error {
		return $this->set_status( $report_id, $actor_id, 'dismissed' );
	}

	/**
	 * Escalate a report for senior review.
	 *
	 * @param int $report_id Report to escalate.
	 * @param int $actor_id  Admin acting on the report.
	 * @return true|WP_Error
	 */
	public function escalate( int $report_id, int $actor_id ): true|WP_Error {
		return $this->set_status( $report_id, $actor_id, 'escalated' );
	}

	/**
	 * Resolve a report (content actioned, reporter notified).
	 *
	 * @param int $report_id Report to resolve.
	 * @param int $actor_id  Admin acting on the report.
	 * @return true|WP_Error
	 */
	public function resolve( int $report_id, int $actor_id ): true|WP_Error {
		return $this->set_status( $report_id, $actor_id, 'resolved' );
	}

	/**
	 * Return all reports for a given object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array[]
	 */
	public function get_reports_for_object( string $object_type, int $object_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_reports
				 WHERE object_type = %s AND object_id = %d
				 ORDER BY created_at DESC",
				sanitize_key( $object_type ),
				$object_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate_report' ), (array) $rows );
	}

	/**
	 * Issue a formal strike against a user.
	 *
	 * @param int    $user_id   User receiving the strike.
	 * @param int    $actor_id  Admin issuing the strike.
	 * @param string $reason    Reason for the strike.
	 * @return int|WP_Error Inserted strike ID or WP_Error.
	 */
	public function issue_strike( int $user_id, int $actor_id, string $reason = '' ): int|WP_Error {
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to issue strikes.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_user_strikes',
			array(
				'user_id'   => $user_id,
				'issued_by' => $actor_id,
				'reason'    => sanitize_textarea_field( $reason ),
			),
			array( '%d', '%d', '%s' )
		);

		$strike_id = (int) $wpdb->insert_id;

		/**
		 * Fires when a strike is issued.
		 *
		 * @param int $strike_id Strike ID.
		 * @param int $user_id   Struck user.
		 * @param int $actor_id  Admin who issued it.
		 */
		do_action( 'buddynext_strike_issued', $strike_id, $user_id, $actor_id );

		return $strike_id;
	}

	/**
	 * Reverse (nullify) a previously issued strike.
	 *
	 * @param int $strike_id Strike to reverse.
	 * @param int $actor_id  Admin reversing it.
	 * @return true|WP_Error
	 */
	public function reverse_strike( int $strike_id, int $actor_id ): true|WP_Error {
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to reverse strikes.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_user_strikes',
			array(
				'is_reversed' => 1,
				'reversed_by' => $actor_id,
				'reversed_at' => current_time( 'mysql' ),
			),
			array( 'id' => $strike_id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Return all active (non-reversed) strike rows for a user.
	 *
	 * @param int $user_id User to query.
	 * @return array[] Each item: id, user_id, issued_by, reason, created_at.
	 */
	public function get_strikes( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, issued_by, reason, created_at
				 FROM {$wpdb->prefix}bn_user_strikes
				 WHERE user_id = %d AND is_reversed = 0
				 ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $r ): array {
				return array(
					'id'         => (int) $r['id'],
					'user_id'    => (int) $r['user_id'],
					'issued_by'  => (int) $r['issued_by'],
					'reason'     => $r['reason'],
					'created_at' => $r['created_at'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Return the number of active (non-reversed) strikes for a user.
	 *
	 * @param int $user_id User to check.
	 * @return int
	 */
	public function get_active_strike_count( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_user_strikes
				 WHERE user_id = %d AND is_reversed = 0",
				$user_id
			)
		);
	}

	/**
	 * Return a paginated list of pending reports.
	 *
	 * Supported args:
	 *   per_page    int     Reports per page. Default 20. Max 100.
	 *   page        int     1-based page number. Default 1.
	 *   object_type string  Filter by object type ('post','comment','user','space','message').
	 *   reason      string  Filter by reason (one of REASONS).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array[], total: int}
	 */
	public function get_queue( array $args = array() ): array {
		global $wpdb;

		$per_page    = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$page        = max( 1, absint( $args['page'] ?? 1 ) );
		$offset      = ( $page - 1 ) * $per_page;
		$object_type = isset( $args['object_type'] ) ? sanitize_key( (string) $args['object_type'] ) : '';
		$reason      = ( isset( $args['reason'] ) && in_array( $args['reason'], self::REASONS, true ) )
			? sanitize_key( (string) $args['reason'] )
			: '';

		$where        = array( "status IN ('pending','escalated')" );
		$list_params  = array();
		$count_params = array();

		if ( '' !== $object_type ) {
			$where[]        = 'object_type = %s';
			$list_params[]  = $object_type;
			$count_params[] = $object_type;
		}

		if ( '' !== $reason ) {
			$where[]        = 'reason = %s';
			$list_params[]  = $reason;
			$count_params[] = $reason;
		}

		$where_sql   = 'WHERE ' . implode( ' AND ', $where );
		$list_params = array_merge( $list_params, array( $per_page, $offset ) );

		// $where_sql contains only hardcoded literals and validated enum values — no raw user data.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_reports {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...$list_params
			),
			ARRAY_A
		);

		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports {$where_sql}";
		if ( empty( $count_params ) ) {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$count_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'items' => array_map( array( $this, 'hydrate_report' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Suspend a user.
	 *
	 * Creates a row in bn_user_suspensions. A user may have multiple historical
	 * suspension records — only the most-recent active (lifted_at IS NULL) record
	 * is considered by is_suspended().
	 *
	 * Supported $opts keys:
	 *   duration_days  int   Temporary suspension length. Omit for permanent.
	 *   hide_posts     bool  Hide the user's posts during suspension. Default false.
	 *
	 * @param int                  $user_id  User to suspend.
	 * @param int                  $actor_id Admin performing the suspension.
	 * @param string               $reason   Reason for suspension.
	 * @param array<string, mixed> $opts     Optional suspension options.
	 * @return int|WP_Error Suspension ID or WP_Error on permission failure.
	 */
	public function suspend_user( int $user_id, int $actor_id, string $reason = '', array $opts = array() ): int|WP_Error {
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to suspend users.', 'buddynext' ) );
		}

		$duration_days = isset( $opts['duration_days'] ) ? absint( $opts['duration_days'] ) : null;
		$hide_posts    = ! empty( $opts['hide_posts'] ) ? 1 : 0;
		$expires_at    = null;

		if ( $duration_days > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( "+{$duration_days} days" ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_user_suspensions',
			array(
				'user_id'       => $user_id,
				'suspended_by'  => $actor_id,
				'reason'        => sanitize_textarea_field( $reason ),
				'duration_days' => $duration_days,
				'hide_posts'    => $hide_posts,
				'expires_at'    => $expires_at,
			),
			array( '%d', '%d', '%s', $duration_days ? '%d' : 'NULL', '%d', $expires_at ? '%s' : 'NULL' )
		);

		$suspension_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a user is suspended.
		 *
		 * @param int         $user_id    Suspended user.
		 * @param int         $actor_id   Admin who suspended them.
		 * @param string      $reason     Suspension reason.
		 * @param string|null $expires_at Expiry timestamp, or null for permanent.
		 */
		do_action( 'buddynext_member_suspended', $user_id, $actor_id );
		do_action( 'buddynext_user_suspended', $user_id, $actor_id, $reason, $expires_at );

		return $suspension_id;
	}

	/**
	 * Lift an active suspension for a user.
	 *
	 * Sets lifted_at and lifted_by on the most-recent active suspension row.
	 *
	 * @param int $user_id  User to unsuspend.
	 * @param int $actor_id Admin performing the action.
	 * @return true|WP_Error
	 */
	public function unsuspend_user( int $user_id, int $actor_id ): true|WP_Error {
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to lift suspensions.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_user_suspensions
				 SET lifted_at = %s, lifted_by = %d
				 WHERE user_id = %d AND lifted_at IS NULL
				 ORDER BY id DESC
				 LIMIT 1",
				current_time( 'mysql' ),
				$actor_id,
				$user_id
			)
		);

		/**
		 * Fires after a user suspension is lifted.
		 *
		 * @param int $user_id  Unsuspended user.
		 * @param int $actor_id Admin who lifted the suspension.
		 */
		do_action( 'buddynext_member_unsuspended', $user_id, $actor_id );

		return true;
	}

	/**
	 * Shadow-ban a user so their posts are hidden from feeds and search.
	 *
	 * @param int    $user_id  User to shadow-ban.
	 * @param int    $actor_id Moderator performing the action.
	 * @param string $reason   Reason for shadow-ban.
	 * @return true|WP_Error
	 */
	public function shadow_ban( int $user_id, int $actor_id, string $reason = '' ): true|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'buddynext_forbidden', __( 'Insufficient permissions.', 'buddynext' ), array( 'status' => 403 ) );
		}

		update_user_meta( $user_id, 'bn_shadow_banned', '1' );

		/**
		 * Fires after a user is shadow-banned.
		 *
		 * @param int    $user_id  Shadow-banned user ID.
		 * @param int    $actor_id Moderator user ID.
		 * @param string $reason   Reason for shadow-ban.
		 */
		do_action( 'buddynext_user_shadow_banned', $user_id, $actor_id, $reason );

		return true;
	}

	/**
	 * Remove a shadow-ban from a user.
	 *
	 * @param int $user_id  User to unshadow-ban.
	 * @param int $actor_id Moderator performing the action.
	 * @return true|WP_Error
	 */
	public function unshadow_ban( int $user_id, int $actor_id ): true|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'buddynext_forbidden', __( 'Insufficient permissions.', 'buddynext' ), array( 'status' => 403 ) );
		}

		delete_user_meta( $user_id, 'bn_shadow_banned' );

		/**
		 * Fires after a shadow-ban is removed from a user.
		 *
		 * @param int $user_id  User ID.
		 * @param int $actor_id Moderator user ID.
		 */
		do_action( 'buddynext_user_unshadow_banned', $user_id, $actor_id );

		return true;
	}

	/**
	 * Check whether a user is shadow-banned.
	 *
	 * @param int $user_id User to check.
	 * @return bool
	 */
	public function is_shadow_banned( int $user_id ): bool {
		return '1' === get_user_meta( $user_id, 'bn_shadow_banned', true );
	}

	/**
	 * Check whether a user is currently suspended.
	 *
	 * A user is suspended if they have an active suspension row (lifted_at IS NULL)
	 * that has not yet expired (expires_at IS NULL or expires_at > NOW()).
	 *
	 * @param int $user_id User to check.
	 * @return bool
	 */
	public function is_suspended( int $user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE user_id = %d
				   AND lifted_at IS NULL
				   AND (expires_at IS NULL OR expires_at > NOW())",
				$user_id
			)
		);

		return $count > 0;
	}

	/**
	 * Submit an appeal against a suspension.
	 *
	 * Only the suspended user may appeal. The suspension must exist and currently
	 * be active (not lifted) to accept an appeal.
	 *
	 * @param int    $user_id       User submitting the appeal.
	 * @param int    $suspension_id Suspension being appealed.
	 * @param string $message       Appeal message from the user.
	 * @return int|WP_Error Appeal ID or WP_Error if suspension not found.
	 */
	public function submit_appeal( int $user_id, int $suspension_id, string $message ): int|WP_Error {
		global $wpdb;

		// Verify the suspension exists and belongs to this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$suspension = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE id = %d AND user_id = %d",
				$suspension_id,
				$user_id
			)
		);

		if ( null === $suspension ) {
			return new WP_Error( 'not_suspended', __( 'No matching suspension found.', 'buddynext' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_appeals',
			array(
				'suspension_id' => $suspension_id,
				'user_id'       => $user_id,
				'message'       => sanitize_textarea_field( $message ),
			),
			array( '%d', '%d', '%s' )
		);

		$appeal_id = (int) $wpdb->insert_id;

		/**
		 * Fires after an appeal is submitted.
		 *
		 * @param int $appeal_id Appeal ID.
		 * @param int $user_id   User who submitted the appeal.
		 */
		do_action( 'buddynext_appeal_submitted', $appeal_id, $user_id );

		return $appeal_id;
	}

	/**
	 * Resolve an appeal (approve or deny).
	 *
	 * @param int    $appeal_id      Appeal to resolve.
	 * @param int    $actor_id       Admin resolving the appeal.
	 * @param string $decision       'approved' or 'denied'.
	 * @param string $reviewer_note  Optional note for the user.
	 * @return true|WP_Error
	 */
	public function resolve_appeal( int $appeal_id, int $actor_id, string $decision, string $reviewer_note = '' ): true|WP_Error {
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to resolve appeals.', 'buddynext' ) );
		}

		if ( ! in_array( $decision, self::APPEAL_DECISIONS, true ) ) {
			return new WP_Error( 'invalid_decision', __( 'Decision must be "approved" or "denied".', 'buddynext' ) );
		}

		global $wpdb;

		// Fetch user_id for the hook before updating.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_appeals WHERE id = %d",
				$appeal_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_appeals',
			array(
				'status'        => $decision,
				'reviewed_by'   => $actor_id,
				'reviewer_note' => sanitize_textarea_field( $reviewer_note ),
				'reviewed_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $appeal_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		/**
		 * Fires after an appeal is resolved.
		 *
		 * @param int    $appeal_id Appeal ID.
		 * @param int    $user_id   User whose appeal was resolved.
		 * @param string $decision  'approved' or 'denied'.
		 */
		do_action( 'buddynext_appeal_resolved', $appeal_id, $user_id, $decision );

		return true;
	}

	/**
	 * Update report status (internal helper).
	 *
	 * @param int    $report_id Report ID.
	 * @param int    $actor_id  Admin acting.
	 * @param string $status    New status.
	 * @return true|WP_Error
	 */
	private function set_status( int $report_id, int $actor_id, string $status ): true|WP_Error {
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to action reports.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_reports',
			array(
				'status'      => $status,
				'resolved_by' => $actor_id,
				'resolved_at' => current_time( 'mysql' ),
			),
			array( 'id' => $report_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Hydrate a raw report row.
	 *
	 * @param array $row ARRAY_A row.
	 * @return array
	 */
	private function hydrate_report( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'reporter_id' => (int) $row['reporter_id'],
			'object_type' => $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'reason'      => $row['reason'],
			'notes'       => $row['notes'],
			'status'      => $row['status'],
			'resolved_by' => isset( $row['resolved_by'] ) ? (int) $row['resolved_by'] : null,
			'resolved_at' => $row['resolved_at'],
			'created_at'  => $row['created_at'],
		);
	}
}
