<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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
	 * Memoised, filtered report-reason list (per request).
	 *
	 * @var string[]|null
	 */
	private static ?array $reasons_cache = null;

	/**
	 * Allowed report reasons, filterable via buddynext_report_reasons.
	 *
	 * Filters should return a SUPERSET of the core reasons — the UI offers a
	 * fixed vocabulary and the DB column is VARCHAR(32); add custom reasons but
	 * do not remove core ones. Memoised so both validation sites see one list.
	 *
	 * @return string[]
	 */
	public static function reasons(): array {
		if ( null === self::$reasons_cache ) {
			self::$reasons_cache = array_values(
				array_unique(
					array_map( 'strval', (array) apply_filters( 'buddynext_report_reasons', self::REASONS ) )
				)
			);
		}

		return self::$reasons_cache;
	}

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

		if ( ! in_array( $reason, self::reasons(), true ) ) {
			$reason = 'other';
		}

		global $wpdb;

		// Check for existing report by this user on this object.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_reports
				 WHERE reporter_id = %d AND object_type = %s AND object_id = %d",
				$reporter_id,
				sanitize_key( $object_type ),
				$object_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null !== $existing ) {
			return new WP_Error( 'already_reported', __( 'You have already reported this content.', 'buddynext' ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_reports',
			array(
				'reporter_id' => $reporter_id,
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id,
				'reason'      => $reason,
				'space_id'    => $space_id > 0 ? $space_id : null,
				'notes'       => sanitize_textarea_field( $notes ),
				// Store UTC explicitly. The column default is MySQL
				// CURRENT_TIMESTAMP, which records the DB server's local time —
				// on a non-UTC server that mismatches resolved_at (written by
				// PHP in UTC) and makes the queue's relative time skew by the
				// GMT offset. Keep all report timestamps in UTC.
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$report_id = (int) $wpdb->insert_id;

		$report_row = array(
			'report_id'   => $report_id,
			'reporter_id' => $reporter_id,
			'object_type' => sanitize_key( $object_type ),
			'object_id'   => $object_id,
			'reason'      => $reason,
			'space_id'    => $space_id > 0 ? $space_id : null,
			'notes'       => sanitize_textarea_field( $notes ),
		);

		/**
		 * Fires after a report is submitted.
		 *
		 * @param int    $report_id   New report ID.
		 * @param string $object_type Object type reported.
		 * @param int    $object_id   Object ID reported.
		 * @param int    $reporter_id User who submitted the report.
		 */
		do_action( 'buddynext_report_created', $report_id, sanitize_key( $object_type ), $object_id, $reporter_id );

		/**
		 * Filter the list of automated actions to apply after a report is inserted.
		 *
		 * Free always returns an empty array (no auto-actions). Pro rules engines
		 * stack actions here. Each action is an associative array with at minimum
		 * an 'action' key. Supported action shapes:
		 *   ['action' => 'remove',  'reason' => string]
		 *   ['action' => 'warn',    'user_id' => int, 'reason' => string]
		 *   ['action' => 'suspend', 'user_id' => int, 'reason' => string, 'duration_days' => int]
		 *
		 * @since 1.0.0
		 *
		 * @param array $actions    Array of auto-action descriptors. Default empty array.
		 * @param array $report     Inserted report data including report_id.
		 */
		$auto_actions = (array) apply_filters( 'buddynext_moderation_auto_actions', array(), $report_row );

		foreach ( $auto_actions as $auto_action ) {
			if ( ! is_array( $auto_action ) || empty( $auto_action['action'] ) ) {
				continue;
			}

			$action_slug = sanitize_key( (string) $auto_action['action'] );

			switch ( $action_slug ) {
				case 'remove':
					/**
					 * Fires when an automated moderation action removes content.
					 *
					 * @param string $object_type Content type being removed.
					 * @param int    $object_id   Content ID.
					 * @param int    $actor_id    System actor (0 = automated).
					 */
					do_action( 'buddynext_content_removed', sanitize_key( $object_type ), $object_id, 0 );
					break;

				case 'warn':
					if ( ! empty( $auto_action['user_id'] ) ) {
						/**
						 * Fires when an automated moderation action warns a user.
						 * Canonical signature ( user_id, by_user_id, reason ).
						 *
						 * @param int    $user_id    User being warned.
						 * @param int    $by_user_id System actor (0 = automated).
						 * @param string $reason     Warning message/reason.
						 */
						do_action( 'buddynext_user_warned', (int) $auto_action['user_id'], 0, (string) ( $auto_action['reason'] ?? '' ) );
					}
					break;
			}
		}

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
	 * Resolve a report AND take the reported content down.
	 *
	 * Looks up the report's target, fires buddynext_content_removed (the
	 * ModerationListener handler soft-removes posts/comments from public view),
	 * then marks the report resolved. Unlike PostService::delete this does not
	 * require content ownership and does not hard-delete — the row is retained
	 * so the action is auditable and reversible.
	 *
	 * @param int $report_id Report whose content to remove.
	 * @param int $actor_id  Admin acting on the report.
	 * @return true|WP_Error
	 */
	public function remove_content( int $report_id, int $actor_id ): true|WP_Error {
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to remove content.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$report = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT object_type, object_id FROM {$wpdb->prefix}bn_reports WHERE id = %d",
				$report_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $report ) {
			return new WP_Error( 'not_found', __( 'Report not found.', 'buddynext' ) );
		}

		$object_type = sanitize_key( (string) ( $report['object_type'] ?? '' ) );
		$object_id   = (int) ( $report['object_id'] ?? 0 );

		if ( '' !== $object_type && $object_id > 0 ) {
			/**
			 * Fires when a moderator removes reported content from public view.
			 *
			 * @param string $object_type Content type ('post', 'comment', …).
			 * @param int    $object_id   Content ID.
			 * @param int    $actor_id    Moderator who removed it.
			 */
			do_action( 'buddynext_content_removed', $object_type, $object_id, $actor_id );
		}

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate_report' ), (array) $rows );
	}

	/**
	 * Read a post's content-warning state.
	 *
	 * @param int $post_id Post id.
	 * @return array{has_warning:bool,warning_type:string}|null Null when the post does not exist.
	 */
	public function get_post_content_warning( int $post_id ): ?array {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT content_warning, content_warning_type
				 FROM {$wpdb->prefix}bn_posts
				 WHERE id = %d
				 LIMIT 1",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $row ) {
			return null;
		}

		return array(
			'has_warning'  => (bool) $row['content_warning'],
			'warning_type' => (string) ( $row['content_warning_type'] ?? '' ),
		);
	}

	/**
	 * Set or clear a post's content warning.
	 *
	 * @param int    $post_id      Post id.
	 * @param bool   $has_warning  Whether the post carries a warning.
	 * @param string $warning_type Warning type slug (caller validates against the allowed set).
	 * @return bool|null True on success, false on DB error, null when the post does not exist.
	 */
	public function set_post_content_warning( int $post_id, bool $has_warning, string $warning_type ): ?bool {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1", $post_id )
		);

		if ( null === $exists ) {
			return null;
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array(
				'content_warning'      => $has_warning ? 1 : 0,
				'content_warning_type' => sanitize_key( $warning_type ),
			),
			array( 'id' => $post_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $updated;
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_user_strikes',
			array(
				'user_id'   => $user_id,
				'issued_by' => $actor_id,
				'reason'    => sanitize_textarea_field( $reason ),
			),
			array( '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
	 * Issue a formal warning to a user.
	 *
	 * Writes a 'warn' entry to the moderation log and fires
	 * buddynext_user_warned so listeners can dispatch
	 * the in-app notification and warning email.
	 *
	 * @param int    $user_id  User being warned.
	 * @param int    $actor_id Admin or moderator issuing the warning.
	 * @param string $reason   Human-readable warning reason.
	 * @return true|WP_Error
	 */
	public function warn( int $user_id, int $actor_id, string $reason = '' ): true|WP_Error {
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to issue warnings.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_mod_log',
			array(
				'actor_id'       => $actor_id,
				'action'         => 'warn',
				'object_type'    => 'user',
				'object_id'      => $user_id,
				'target_user_id' => $user_id,
				'note'           => sanitize_textarea_field( $reason ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		/**
		 * Fires after a formal warning is issued to a user.
		 *
		 * Canonical signature ( user_id, by_user_id, reason ) — matches
		 * buddynext_user_suspended and the Pro analytics listener.
		 *
		 * @param int    $user_id  Warned user.
		 * @param int    $actor_id Admin or moderator who issued the warning.
		 * @param string $reason   Warning reason shown to the user.
		 */
		do_action( 'buddynext_user_warned', $user_id, $actor_id, $reason );

		return true;
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_user_strikes
				 WHERE user_id = %d AND is_reversed = 0",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Return a paginated list of pending reports.
	 *
	 * Supported args:
	 *   per_page    int     Reports per page. Default 20. Max 100.
	 *   page        int     1-based page number. Default 1.
	 *   object_type string  Filter by object type ('post','comment','user','space','message').
	 *   reason      string  Filter by reason (one of REASONS).
	 *   space_ids   int[]   When non-empty, restrict results to reports where space_id is in
	 *                       this list. Used by space-scoped moderators who must only see reports
	 *                       originating from spaces they manage. An empty array means no filter
	 *                       (site admins see everything).
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
		$reason      = ( isset( $args['reason'] ) && in_array( $args['reason'], self::reasons(), true ) )
			? sanitize_key( (string) $args['reason'] )
			: '';

		// Normalise space_ids: array of positive ints, empty = no restriction.
		$space_ids = array();
		if ( ! empty( $args['space_ids'] ) && is_array( $args['space_ids'] ) ) {
			foreach ( $args['space_ids'] as $sid ) {
				$sid = absint( $sid );
				if ( $sid > 0 ) {
					$space_ids[] = $sid;
				}
			}
		}

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

		if ( ! empty( $space_ids ) ) {
			// Build a safe IN() clause from already-validated positive integers.
			$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
			$where[]      = "space_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $space_ids as $sid ) {
				$list_params[]  = $sid;
				$count_params[] = $sid;
			}
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
	 * Return the space IDs in which a user holds an owner or moderator role.
	 *
	 * Used by ModerationController to scope the report queue for non-admin moderators.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int[] Array of space IDs (may be empty).
	 */
	public function get_moderated_space_ids( int $user_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT space_id FROM {$wpdb->prefix}bn_space_members
				 WHERE user_id = %d AND role IN ('owner','moderator') AND status = 'active'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'absint', (array) $rows );
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$suspension_id = (int) $wpdb->insert_id;

		/**
		 * Legacy compat hook — fires after a user is suspended.
		 *
		 * @param int $user_id   Suspended user.
		 * @param int $actor_id  Admin who suspended them.
		 */
		do_action( 'buddynext_member_suspended', $user_id, $actor_id );

		/**
		 * Fires after a user is suspended.
		 *
		 * @param int         $user_id    Suspended user.
		 * @param int         $actor_id   Admin who suspended them.
		 * @param string      $reason     Suspension reason.
		 * @param string|null $expires_at Expiry timestamp, or null for permanent.
		 */
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		/**
		 * Fires after a user suspension is lifted.
		 *
		 * @param int $user_id  Unsuspended user.
		 * @param int $actor_id Admin who lifted the suspension.
		 */
		do_action( 'buddynext_member_unsuspended', $user_id, $actor_id );
		do_action( 'buddynext_user_unsuspended', $user_id );

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
		 * @param int $user_id    User ID.
		 * @param int $removed_by Moderator user ID.
		 */
		do_action( 'buddynext_user_shadow_ban_removed', $user_id, $actor_id );

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE user_id = %d
				   AND lifted_at IS NULL
				   AND (expires_at IS NULL OR expires_at > NOW())",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $count > 0;
	}

	/**
	 * Get the active suspension record for a user.
	 *
	 * Returns the full suspension row so callers can read the reason,
	 * expiry date, and hide_posts flag in one query. Returns null if
	 * the user has no active suspension.
	 *
	 * @param int $user_id User to check.
	 * @return array<string, mixed>|null Hydrated suspension row, or null.
	 */
	public function get_active_suspension( int $user_id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, suspended_by, reason, duration_days, hide_posts,
				        expires_at, created_at
				 FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE user_id = %d
				   AND lifted_at IS NULL
				   AND (expires_at IS NULL OR expires_at > NOW())
				 ORDER BY id DESC
				 LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $row ) {
			return null;
		}

		return array(
			'id'            => (int) $row['id'],
			'user_id'       => (int) $row['user_id'],
			'suspended_by'  => (int) $row['suspended_by'],
			'reason'        => (string) ( $row['reason'] ?? '' ),
			'duration_days' => null !== $row['duration_days'] ? (int) $row['duration_days'] : null,
			'hide_posts'    => (bool) $row['hide_posts'],
			'expires_at'    => $row['expires_at'],
			'created_at'    => $row['created_at'],
		);
	}

	/**
	 * List every currently-active suspension (not lifted, not expired).
	 *
	 * Powers the admin moderation queue's Suspensions panel. Newest first.
	 *
	 * @param int $limit Max rows. Default 100.
	 * @return array<int,array<string,mixed>> Active suspension rows.
	 */
	public function get_active_suspensions( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, suspended_by, reason, duration_days, hide_posts, expires_at, created_at
				 FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE lifted_at IS NULL
				   AND (expires_at IS NULL OR expires_at > NOW())
				 ORDER BY id DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'            => (int) $row['id'],
					'user_id'       => (int) $row['user_id'],
					'suspended_by'  => (int) $row['suspended_by'],
					'reason'        => (string) ( $row['reason'] ?? '' ),
					'duration_days' => null !== $row['duration_days'] ? (int) $row['duration_days'] : null,
					'hide_posts'    => (bool) $row['hide_posts'],
					'expires_at'    => $row['expires_at'],
					'created_at'    => $row['created_at'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * List pending appeals awaiting an admin decision. Powers the admin
	 * moderation queue's Appeals panel. Oldest first (FIFO review order).
	 *
	 * @param int $limit  Max rows. Default 100.
	 * @param int $offset Row offset for pagination. Default 0.
	 * @return array<int,array<string,mixed>> Pending appeal rows.
	 */
	public function get_pending_appeals( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, suspension_id, user_id, message, status, created_at
				 FROM {$wpdb->prefix}bn_appeals
				 WHERE status = 'pending'
				 ORDER BY id ASC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'            => (int) $row['id'],
					'suspension_id' => (int) $row['suspension_id'],
					'user_id'       => (int) $row['user_id'],
					'message'       => (string) ( $row['message'] ?? '' ),
					'status'        => (string) $row['status'],
					'created_at'    => $row['created_at'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Count pending appeals awaiting an admin decision.
	 *
	 * @return int
	 */
	public function count_pending_appeals(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_appeals WHERE status = %s", 'pending' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$suspension = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE id = %d AND user_id = %d",
				$suspension_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $suspension ) {
			return new WP_Error( 'not_suspended', __( 'No matching suspension found.', 'buddynext' ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_appeals',
			array(
				'suspension_id' => $suspension_id,
				'user_id'       => $user_id,
				'message'       => sanitize_textarea_field( $message ),
			),
			array( '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$appeal_id = (int) $wpdb->insert_id;

		/**
		 * Fires after an appeal is submitted.
		 *
		 * @param int    $appeal_id   Appeal ID.
		 * @param int    $user_id     User who submitted the appeal.
		 * @param string $target_type Type of the appealed object ('suspension').
		 * @param int    $target_id   ID of the appealed object.
		 */
		do_action( 'buddynext_appeal_submitted', $appeal_id, $user_id, 'suspension', $suspension_id );

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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_appeals WHERE id = %d",
				$appeal_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// $wpdb->update returns false on SQL error, int (rows affected) otherwise.
		// 0 affected means the report ID didn't exist — return WP_Error so callers
		// (especially Pro's BulkModService) don't claim phantom successes.
		if ( false === $updated ) {
			return new WP_Error( 'db_error', __( 'Database error updating report status.', 'buddynext' ) );
		}
		if ( 0 === $updated ) {
			return new WP_Error( 'report_not_found', __( 'Report not found.', 'buddynext' ) );
		}

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

	// ── Block 2 — service-layer thin API (callers own capability checks) ─────

	/**
	 * Shadow-ban a user by setting usermeta key `bn_shadow_banned`.
	 *
	 * @param int $user_id User to shadow-ban.
	 * @return bool True on success.
	 */
	public function set_shadow_ban( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$result = update_user_meta( $user_id, 'bn_shadow_banned', '1' );

		/**
		 * Fires after a user is shadow-banned.
		 *
		 * @param int $user_id Shadow-banned user ID.
		 */
		do_action( 'buddynext_user_shadow_banned', $user_id );

		return false !== $result;
	}

	/**
	 * Remove the shadow-ban from a user by deleting the `bn_shadow_banned` usermeta key.
	 *
	 * @param int $user_id User whose shadow-ban should be lifted.
	 * @return bool True.
	 */
	public function remove_shadow_ban( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		delete_user_meta( $user_id, 'bn_shadow_banned' );

		/**
		 * Fires after a shadow-ban is removed from a user.
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'buddynext_user_shadow_ban_removed', $user_id );

		return true;
	}

	/**
	 * Check whether a user has the shadow-ban usermeta flag set.
	 *
	 * @param int $user_id User to check.
	 * @return bool
	 */
	public function check_shadow_ban( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'bn_shadow_banned', true );
	}

	/**
	 * Insert an active suspension record into `bn_user_suspensions`.
	 *
	 * Duration_days = 0 means permanent (expires_at = NULL).
	 * Callers are responsible for capability checks before calling this method.
	 *
	 * @param int    $user_id       User to suspend.
	 * @param string $reason        Suspension reason.
	 * @param int    $duration_days Duration in days; 0 = permanent.
	 * @param bool   $hide_content  Whether to hide the user's content. Default false.
	 * @param int    $suspended_by  Actor user ID (0 if system-initiated).
	 * @return bool|WP_Error True on success or WP_Error on DB failure.
	 */
	public function suspend( int $user_id, string $reason, int $duration_days, bool $hide_content = false, int $suspended_by = 0 ): bool|WP_Error {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user ID.', 'buddynext' ) );
		}

		$expires_at = null;
		if ( $duration_days > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( "+{$duration_days} days" ) );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bn_user_suspensions',
			array(
				'user_id'      => $user_id,
				'suspended_by' => $suspended_by > 0 ? $suspended_by : null,
				'reason'       => sanitize_textarea_field( $reason ),
				'expires_at'   => $expires_at,
				'hide_posts'   => $hide_content ? 1 : 0,
			),
			array( '%d', $suspended_by > 0 ? '%d' : 'NULL', '%s', $expires_at ? '%s' : 'NULL', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $inserted || '' !== $wpdb->last_error ) {
			return new WP_Error( 'db_error', $wpdb->last_error );
		}

		$actor_id = $suspended_by > 0 ? $suspended_by : get_current_user_id();

		/**
		 * Fires after a suspension record is created.
		 *
		 * @param int         $user_id    Suspended user.
		 * @param int         $actor_id   Admin who performed the suspension.
		 * @param string      $reason     Suspension reason.
		 * @param string|null $expires_at Expiry timestamp (Y-m-d H:i:s), or null for permanent.
		 */
		do_action( 'buddynext_user_suspended', $user_id, $actor_id, $reason, $expires_at );

		return true;
	}

	/**
	 * Delete an active suspension record for a user.
	 *
	 * Removes rows from bn_user_suspensions where user_id matches and the
	 * suspension has not yet expired.
	 * Callers are responsible for capability checks before calling this method.
	 *
	 * @param int $user_id User to unsuspend.
	 * @return bool True if at least one row was deleted.
	 */
	public function unsuspend( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE user_id = %d AND (expires_at IS NULL OR expires_at > NOW())",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		/**
		 * Fires after a suspension is removed.
		 *
		 * @param int $user_id Unsuspended user.
		 */
		do_action( 'buddynext_user_unsuspended', $user_id );

		return (bool) $deleted;
	}

	/**
	 * Check whether a user has an active suspension row.
	 *
	 * A row is active when lifted_at IS NULL (or the column does not exist in the
	 * schema variant used here) and expires_at IS NULL or expires_at > NOW().
	 *
	 * @param int $user_id User to check.
	 * @return bool
	 */
	public function has_active_suspension( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE user_id = %d AND (expires_at IS NULL OR expires_at > NOW())",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $count > 0;
	}

	/**
	 * Return the most-recent active suspension row for a user as an associative
	 * array, or null if the user has no active suspension.
	 *
	 * @param int $user_id User to query.
	 * @return array<string, mixed>|null
	 */
	public function get_suspension( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE user_id = %d AND (expires_at IS NULL OR expires_at > NOW())
				 ORDER BY id DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $row ) {
			return null;
		}

		return $row;
	}

	/**
	 * Insert a warning entry into the moderation log.
	 *
	 * Writes object_type='user', action='warned' to bn_mod_log and fires
	 * buddynext_user_warned so event listeners can dispatch the warning email.
	 * Callers are responsible for capability checks before calling this method.
	 *
	 * @param int    $user_id   User receiving the warning.
	 * @param string $message   Warning message text.
	 * @param int    $warned_by Actor ID (0 = system).
	 * @return bool|WP_Error True on success or WP_Error on DB failure.
	 */
	public function log_warning( int $user_id, string $message, int $warned_by = 0 ): bool|WP_Error {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user ID.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bn_mod_log',
			array(
				'object_type'    => 'user',
				'object_id'      => $user_id,
				'action'         => 'warned',
				'note'           => sanitize_textarea_field( $message ),
				'actor_id'       => $warned_by > 0 ? $warned_by : null,
				'target_user_id' => $user_id,
			),
			array( '%s', '%d', '%s', '%s', $warned_by > 0 ? '%d' : 'NULL', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $inserted || '' !== $wpdb->last_error ) {
			return new WP_Error( 'db_error', $wpdb->last_error );
		}

		/**
		 * Fires after a warning is issued to a user.
		 *
		 * Canonical signature ( user_id, by_user_id, reason ) — matches the
		 * buddynext_user_suspended convention and the Pro analytics listener.
		 *
		 * @param int    $user_id    Warned user.
		 * @param int    $warned_by  Actor user ID (0 = system).
		 * @param string $message    Warning message / reason.
		 */
		do_action( 'buddynext_user_warned', $user_id, $warned_by, $message );

		return true;
	}

	/**
	 * Insert an appeal record into `bn_appeals`.
	 *
	 * This variant does not require a suspension_id — it records a general
	 * appeal submitted by the user. Callers own validation of the user's
	 * current moderation state before invoking this method.
	 *
	 * @param int    $user_id User submitting the appeal.
	 * @param string $message Appeal message from the user.
	 * @return int|WP_Error Inserted appeal ID or WP_Error on DB failure.
	 */
	public function create_appeal( int $user_id, string $message ): int|WP_Error {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user ID.', 'buddynext' ) );
		}

		// bn_appeals.suspension_id is NOT NULL — an appeal must reference the
		// user's active suspension. Resolve it before inserting so the write
		// does not fail with a constraint error.
		$suspension = $this->get_active_suspension( $user_id );
		if ( null === $suspension ) {
			return new WP_Error(
				'not_suspended',
				__( 'You do not have an active suspension to appeal.', 'buddynext' )
			);
		}

		$suspension_id = (int) $suspension['id'];

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bn_appeals',
			array(
				'suspension_id' => $suspension_id,
				'user_id'       => $user_id,
				'message'       => sanitize_textarea_field( $message ),
				'status'        => 'pending',
			),
			array( '%d', '%d', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $inserted || '' !== $wpdb->last_error ) {
			return new WP_Error( 'db_error', $wpdb->last_error );
		}

		$insert_id = (int) $wpdb->insert_id;

		/**
		 * Fires after an appeal is submitted.
		 *
		 * @param int $user_id   User who submitted the appeal.
		 * @param int $insert_id Inserted appeal ID.
		 */
		do_action( 'buddynext_appeal_submitted', $user_id, $insert_id );

		return $insert_id;
	}

	/**
	 * Update an appeal record with an admin decision.
	 *
	 * Decision must be 'approved' or 'denied'. Updates bn_appeals.status,
	 * admin_note, resolved_by, and resolved_at. Callers are responsible for
	 * capability checks before calling this method.
	 *
	 * @param int    $appeal_id    Appeal to resolve.
	 * @param string $decision     'approved' or 'denied'.
	 * @param string $admin_note   Optional admin note.
	 * @param int    $resolved_by  Admin user ID (0 = system).
	 * @return bool|WP_Error True on success or WP_Error on validation/DB failure.
	 */
	public function decide_appeal( int $appeal_id, string $decision, string $admin_note = '', int $resolved_by = 0 ): bool|WP_Error {
		if ( ! in_array( $decision, self::APPEAL_DECISIONS, true ) ) {
			return new WP_Error( 'invalid_decision', __( 'Decision must be "approved" or "denied".', 'buddynext' ) );
		}

		if ( $appeal_id <= 0 ) {
			return new WP_Error( 'invalid_appeal', __( 'Invalid appeal ID.', 'buddynext' ) );
		}

		global $wpdb;

		// Load the appeal's owner and the suspension it targets so an approval
		// can actually lift the suspension (set lifted_at), not just flip status.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$appeal = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, suspension_id FROM {$wpdb->prefix}bn_appeals WHERE id = %d",
				$appeal_id
			),
			ARRAY_A
		);

		$user_id       = isset( $appeal['user_id'] ) ? (int) $appeal['user_id'] : 0;
		$suspension_id = isset( $appeal['suspension_id'] ) ? (int) $appeal['suspension_id'] : 0;

		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_appeals',
			array(
				'status'      => $decision,
				'admin_note'  => sanitize_textarea_field( $admin_note ),
				'resolved_by' => $resolved_by > 0 ? $resolved_by : null,
				'resolved_at' => current_time( 'mysql' ),
			),
			array( 'id' => $appeal_id ),
			array( '%s', '%s', $resolved_by > 0 ? '%d' : 'NULL', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $updated || '' !== $wpdb->last_error ) {
			return new WP_Error( 'db_error', $wpdb->last_error );
		}

		// An approved appeal must lift the suspension that was appealed. Set
		// lifted_at directly on the appealed suspension row (not just "most
		// recent active") so the correct record is cleared even when several
		// historical suspensions exist.
		if ( 'approved' === $decision && $suspension_id > 0 ) {
			$this->lift_suspension_by_id( $suspension_id, $resolved_by, $user_id );
		}

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
	 * Lift a specific suspension row by its ID.
	 *
	 * Sets lifted_at/lifted_by on the suspension with the given ID when it is
	 * still active (lifted_at IS NULL) and fires the unsuspend hooks so search,
	 * gamification, and directory surfaces resync. Used by appeal approval to
	 * lift the exact suspension that was appealed.
	 *
	 * @param int $suspension_id Suspension row ID to lift.
	 * @param int $actor_id      Admin who lifted it (0 = system).
	 * @param int $user_id       Suspended user (for the unsuspend hooks).
	 * @return void
	 */
	private function lift_suspension_by_id( int $suspension_id, int $actor_id, int $user_id ): void {
		if ( $suspension_id <= 0 ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$lifted = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_user_suspensions
				 SET lifted_at = %s, lifted_by = %d
				 WHERE id = %d AND lifted_at IS NULL",
				current_time( 'mysql' ),
				$actor_id > 0 ? $actor_id : 0,
				$suspension_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $lifted && $user_id > 0 ) {
			/**
			 * Fires after a user suspension is lifted.
			 *
			 * @param int $user_id  Unsuspended user.
			 * @param int $actor_id Admin who lifted the suspension.
			 */
			do_action( 'buddynext_member_unsuspended', $user_id, $actor_id );
			do_action( 'buddynext_user_unsuspended', $user_id );
		}
	}

	// ── Spec-named aliases ────────────────────────────────────────────────────

	/**
	 * Spec alias: issue a strike against a user.
	 *
	 * Delegates to issue_strike() — included so callers can use the spec
	 * method name `strike()` as documented in the moderation spec.
	 *
	 * @param int    $actor_id Admin issuing the strike.
	 * @param int    $user_id  User receiving the strike.
	 * @param string $reason   Reason for the strike.
	 * @return int|WP_Error Strike ID or WP_Error.
	 */
	public function strike( int $actor_id, int $user_id, string $reason = '' ): int|WP_Error {
		return $this->issue_strike( $user_id, $actor_id, $reason );
	}

	/**
	 * Spec alias: remove the shadow-ban from a user.
	 *
	 * Delegates to remove_shadow_ban() — included so callers can use the spec
	 * method name `shadow_unban()` as documented in the moderation spec.
	 *
	 * @param int $actor_id Moderator performing the action.
	 * @param int $user_id  User to unshadow-ban.
	 * @return bool True on success.
	 */
	public function shadow_unban( int $actor_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return $this->remove_shadow_ban( $user_id );
	}
}
