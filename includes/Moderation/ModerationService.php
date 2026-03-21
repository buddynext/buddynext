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
	 * Submit a report on an object.
	 *
	 * Each user may only report a given object once (UNIQUE KEY enforced at DB).
	 *
	 * @param int    $reporter_id Reporting user.
	 * @param string $object_type Object type (e.g. 'post', 'comment', 'user').
	 * @param int    $object_id   Object ID.
	 * @param string $reason      Report reason (one of REASONS).
	 * @param string $notes       Optional free-text notes.
	 * @return int|WP_Error Inserted report ID or WP_Error on duplicate/validation.
	 */
	public function report( int $reporter_id, string $object_type, int $object_id, string $reason, string $notes = '' ): int|WP_Error {
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
				'notes'       => sanitize_textarea_field( $notes ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
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
