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
			$already_message = 'user' === sanitize_key( $object_type )
				? __( 'You have already reported this member.', 'buddynext' )
				: __( 'You have already reported this content.', 'buddynext' );
			return new WP_Error( 'already_reported', $already_message );
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

		// Auto-hide: once a post accrues enough distinct reports, pull it out of
		// public view into the moderation queue. Enforces the Settings →
		// Moderation → "Auto-Hide Threshold" setting (0 = disabled). Reuses the
		// existing 'pending' status (the moderation-hold state) — no new flag.
		if ( 'post' === sanitize_key( $object_type ) ) {
			$auto_hide_threshold = (int) get_option( 'buddynext_auto_hide_threshold', 5 );
			if ( $auto_hide_threshold > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$report_total = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE object_type = 'post' AND object_id = %d",
						$object_id
					)
				);
				if ( $report_total >= $auto_hide_threshold ) {
					$this->auto_hide_post( $object_id );
				}
			}
		}

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
	 * Whether a user has already reported a given object.
	 *
	 * Backs the post-card UI so the action menu can surface a "Reported" state
	 * instead of re-offering the Report action (the DB UNIQUE KEY on
	 * reporter_id/object_type/object_id already blocks duplicates server-side;
	 * this lets the UI reflect that). The lookup is a single index seek on that
	 * UNIQUE KEY, so it is cheap enough to call per reportable post — mirroring
	 * how the card already resolves per-viewer bookmark state.
	 *
	 * @param int    $reporter_id Reporting user (0 returns false).
	 * @param string $object_type Object type (e.g. 'post', 'comment', 'user').
	 * @param int    $object_id   Object ID.
	 * @return bool True when this user has an existing report on the object.
	 */
	public function user_has_reported( int $reporter_id, string $object_type, int $object_id ): bool {
		if ( $reporter_id <= 0 || $object_id <= 0 ) {
			return false;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_reports
				 WHERE reporter_id = %d AND object_type = %s AND object_id = %d
				 LIMIT 1",
				$reporter_id,
				sanitize_key( $object_type ),
				$object_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null !== $existing;
	}

	/**
	 * Auto-hide a reported post by moving it to the 'pending' moderation state.
	 *
	 * Only flips a currently 'published' post (never touches drafts, scheduled,
	 * or already-removed posts), so the public feed stops showing it while the
	 * moderation queue retains the open reports for a human decision.
	 *
	 * @param int $post_id Post to hide.
	 * @return void
	 */
	private function auto_hide_post( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_posts SET status = 'pending' WHERE id = %d AND status = 'published'",
				$post_id
			)
		);

		if ( $updated > 0 ) {
			/**
			 * Fires when a post is auto-hidden after reaching the report threshold.
			 *
			 * @param int $post_id The post that was hidden.
			 */
			do_action( 'buddynext_post_auto_hidden', $post_id );
		}
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
	 * @internal Performs NO capability check — reporter identities are sensitive.
	 *           Callers MUST gate access first; the only REST caller is behind
	 *           ModerationController::require_admin.
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
	 * Requires manage_options capability on the acting user. Returns false immediately
	 * when the actor lacks the capability so no DB write is attempted. The REST caller
	 * (ModerationController::set_content_warning) is already behind require_admin, but
	 * this in-method guard ensures programmatic callers are also gated correctly.
	 *
	 * @param int    $post_id      Post id.
	 * @param bool   $has_warning  Whether the post carries a warning.
	 * @param string $warning_type Warning type slug (caller validates against the allowed set).
	 * @param int    $actor_id     ID of the user performing the action. Defaults to the current user.
	 * @return bool|null True on success, false on DB error or insufficient permissions,
	 *                   null when the post does not exist.
	 */
	public function set_post_content_warning( int $post_id, bool $has_warning, string $warning_type, int $actor_id = 0 ): ?bool {
		$actor_id = $actor_id > 0 ? $actor_id : get_current_user_id();

		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return false;
		}

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
		// A missing or already-reversed strike must not report success. Confirm an
		// active row exists first, otherwise the caller got 200 {"reversed":true}
		// while nothing changed.
		$active = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_user_strikes WHERE id = %d AND is_reversed = 0",
				$strike_id
			)
		);
		if ( 0 === $active ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return new WP_Error(
				'strike_not_found',
				__( 'That strike does not exist or has already been reversed.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$updated = $wpdb->update(
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

		if ( false === $updated ) {
			return new WP_Error(
				'strike_reverse_failed',
				__( 'Could not reverse the strike. Please try again.', 'buddynext' ),
				array( 'status' => 500 )
			);
		}

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
	 * Fetch a single report row by id, or null when it does not exist.
	 *
	 * @param int $report_id Report id.
	 * @return array<string,mixed>|null
	 */
	public function get_report( int $report_id ): ?array {
		if ( $report_id <= 0 ) {
			return null;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, reporter_id, object_type, object_id, reason, status, space_id, created_at FROM {$wpdb->prefix}bn_reports WHERE id = %d LIMIT 1",
				$report_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $row ) ? $row : null;
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
	 * @internal Performs NO capability check. Callers MUST gate first; the REST
	 *           caller is behind ModerationController::require_queue_access, which
	 *           also supplies space_ids for space-scoped moderators.
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
		// Consolidate every report for the same content into one queue row:
		// GROUP BY object_type,object_id and aggregate the count / distinct
		// reporters / reasons so admins act once per piece of content instead of
		// once per report. One user can only report a given object once, so
		// report_count equals reporter_count, but both are exposed for clarity.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT MAX(id) AS id,
				        MAX(reporter_id) AS reporter_id,
				        object_type, object_id,
				        MAX(space_id) AS space_id,
				        MAX(reason) AS reason,
				        MAX(notes) AS notes,
				        MAX(status) AS status,
				        COUNT(*) AS report_count,
				        COUNT(DISTINCT reporter_id) AS reporter_count,
				        GROUP_CONCAT(DISTINCT reason ORDER BY reason SEPARATOR ', ') AS reasons,
				        MAX(resolved_by) AS resolved_by,
				        MAX(resolved_at) AS resolved_at,
				        MAX(created_at) AS created_at
				 FROM {$wpdb->prefix}bn_reports {$where_sql}
				 GROUP BY object_type, object_id
				 ORDER BY MAX(created_at) DESC
				 LIMIT %d OFFSET %d",
				...$list_params
			),
			ARRAY_A
		);

		// Count distinct content groups, not raw report rows, so pagination
		// matches the consolidated list.
		$count_sql = "SELECT COUNT(*) FROM ( SELECT 1 FROM {$wpdb->prefix}bn_reports {$where_sql} GROUP BY object_type, object_id ) AS bn_grouped_reports";
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

		// Don't stack a second active suspension on an already-suspended user —
		// is_suspended() and the moderation queue would double-count it. A
		// re-suspend is idempotent: return the existing active suspension's id.
		$already = $this->get_active_suspension( $user_id );
		if ( null !== $already ) {
			return (int) $already['id'];
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
			array( '%d', '%d', '%s', '%d', '%d', '%s' )
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
		$lifted = $wpdb->query(
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

		// No active suspension to lift — report it instead of a silent success, so
		// a moderator who unsuspends the wrong/already-active user sees a real
		// notice rather than a false "Done.".
		if ( ! $lifted ) {
			return new WP_Error( 'bn_not_suspended', __( 'That user is not currently suspended.', 'buddynext' ) );
		}

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
		// Authorise the declared actor, not whoever happens to be the current
		// user — the method takes an explicit $actor_id and must check that.
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
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
		// Authorise the declared actor, not the current user (see shadow_ban).
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
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
	 * Build a SQL fragment that excludes suspended and shadow-banned users.
	 *
	 * The single canonical moderation-exclusion builder, keyed on a caller-named
	 * ID column so any surface (feed, follow suggestions, …) excludes the same
	 * set the same way. Prefixed with AND so it appends directly to a WHERE
	 * clause; uses two NOT IN subqueries (active suspensions + bn_shadow_banned
	 * usermeta). The column name is caller-supplied code (never user input) and
	 * is hard-sanitised to word characters to keep the fragment injection-safe.
	 *
	 * Suspensions are gated on hide_posts = 1: a suspension hides the user's
	 * content only when the moderator opted into it. A hide_posts = 0 suspension
	 * is an action restriction (the user can't post/comment) that leaves their
	 * existing content and discoverability intact. Shadow-ban always hides
	 * content — that is its whole purpose.
	 *
	 * @param string $column ID column to filter (e.g. 'user_id', 'following_id').
	 * @return string Raw SQL fragment — safe to embed.
	 */
	public function moderation_exclude_sql( string $column = 'user_id' ): string {
		global $wpdb;

		$column = preg_replace( '/[^a-zA-Z0-9_]/', '', $column );
		if ( '' === $column ) {
			$column = 'user_id';
		}

		return "AND {$column} NOT IN (
			    SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions
			    WHERE lifted_at IS NULL AND hide_posts = 1 AND (expires_at IS NULL OR expires_at > NOW())
			  )
			  AND {$column} NOT IN (
			    SELECT user_id FROM {$wpdb->usermeta}
			    WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'
			  )";
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
	 * List a user's own appeals (any status), newest first.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Max rows (capped at 50).
	 * @return array<int,array<string,mixed>> Appeal rows.
	 */
	public function get_user_appeals( int $user_id, int $limit = 50 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 50, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, suspension_id, user_id, message, status, created_at
				 FROM {$wpdb->prefix}bn_appeals
				 WHERE user_id = %d
				 ORDER BY created_at DESC
				 LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List warning-log entries for a user, newest first.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Max rows (capped at 50).
	 * @return array<int,array<string,mixed>> Warning rows from bn_mod_log.
	 */
	public function get_warnings( int $user_id, int $limit = 50 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 50, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, actor_id, action, note, created_at
				 FROM {$wpdb->prefix}bn_mod_log
				 WHERE target_user_id = %d AND action IN ( 'warn', 'warned' )
				 ORDER BY created_at DESC
				 LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List a single user's active (unlifted) suspensions, newest first.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>> Suspension rows.
	 */
	public function get_user_suspensions( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, suspended_by, reason, duration_days, hide_posts, expires_at, created_at
				 FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE user_id = %d AND lifted_at IS NULL
				 ORDER BY created_at DESC
				 LIMIT 50",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
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
		 * @param int    $user_id     User who submitted the appeal.
		 * @param int    $appeal_id   Appeal ID.
		 * @param string $target_type Type of the appealed object ('suspension').
		 * @param int    $target_id   ID of the appealed object.
		 */
		do_action( 'buddynext_appeal_submitted', $user_id, $appeal_id, 'suspension', $suspension_id );

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

		// Appeal row does not exist (appeals are always filed by a real user, so
		// user_id is never 0 for a genuine row). Report it instead of updating
		// zero rows and returning a false success.
		if ( $user_id <= 0 ) {
			return new WP_Error( 'bn_appeal_not_found', __( 'That appeal no longer exists.', 'buddynext' ) );
		}

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

		// The queue consolidates every report for one piece of content into a
		// single row (GROUP BY object_type,object_id in get_queue), so a single
		// action must clear ALL of that content's open reports — otherwise the
		// siblings stay pending and the item reappears. Resolve the report's
		// target, then cascade the status to all its pending/escalated reports.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$target = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT object_type, object_id FROM {$wpdb->prefix}bn_reports WHERE id = %d",
				$report_id
			),
			ARRAY_A
		);

		if ( ! $target ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return new WP_Error( 'report_not_found', __( 'Report not found.', 'buddynext' ) );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_reports
				 SET status = %s, resolved_by = %d, resolved_at = %s
				 WHERE object_type = %s AND object_id = %d AND status IN ('pending','escalated')",
				$status,
				$actor_id,
				current_time( 'mysql' ),
				(string) $target['object_type'],
				(int) $target['object_id']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// $wpdb->query returns false on SQL error, int (rows affected) otherwise.
		if ( false === $updated ) {
			return new WP_Error( 'db_error', __( 'Database error updating report status.', 'buddynext' ) );
		}

		// Lift an auto-hide once the reports are cleared. auto_hide_post() flips a
		// 'published' post to 'pending' when the report threshold is hit; resolving
		// or dismissing all of its open reports means a human has cleared it, so it
		// should reappear in the feed. The status = 'pending' guard restores ONLY
		// the auto-hide state — content taken down via remove_content() is
		// 'deleted' (ModerationListener::on_content_removed) and is left untouched.
		if ( 'post' === (string) $target['object_type'] && in_array( $status, array( 'resolved', 'dismissed' ), true ) ) {
			$restore_post_id = (int) $target['object_id'];

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$restored = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_posts SET status = 'published' WHERE id = %d AND status = 'pending'",
					$restore_post_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $restored > 0 ) {
				wp_cache_delete( "post_{$restore_post_id}", 'buddynext_posts' );
				/**
				 * Fires when an auto-hidden post is restored after its reports are cleared.
				 *
				 * @param int $post_id  The restored post.
				 * @param int $actor_id Moderator who cleared the reports.
				 */
				do_action( 'buddynext_post_restored', $restore_post_id, $actor_id );
			}
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
		// report_count / reporter_count / reasons are present on consolidated
		// queue rows (get_queue) and absent on single-row reads — default them
		// so both callers get a stable shape.
		$report_count = isset( $row['report_count'] ) ? (int) $row['report_count'] : 1;
		$reasons      = isset( $row['reasons'] ) && '' !== (string) $row['reasons']
			? array_values( array_unique( array_map( 'trim', explode( ',', (string) $row['reasons'] ) ) ) )
			: array_filter( array( (string) ( $row['reason'] ?? '' ) ) );

		return array(
			'id'             => (int) $row['id'],
			'reporter_id'    => (int) $row['reporter_id'],
			'object_type'    => $row['object_type'],
			'object_id'      => (int) $row['object_id'],
			'reason'         => $row['reason'],
			'reasons'        => array_values( $reasons ),
			'report_count'   => $report_count,
			'reporter_count' => isset( $row['reporter_count'] ) ? (int) $row['reporter_count'] : $report_count,
			'notes'          => $row['notes'],
			'status'         => $row['status'],
			'resolved_by'    => isset( $row['resolved_by'] ) ? (int) $row['resolved_by'] : null,
			'resolved_at'    => $row['resolved_at'],
			'created_at'     => $row['created_at'],
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
		 * Same (user_id, actor_id, reason) signature as shadow_ban(); this path
		 * has no explicit actor, so actor_id is 0 (system) and reason is empty.
		 *
		 * @param int    $user_id  Shadow-banned user ID.
		 * @param int    $actor_id Moderator user ID (0 = system).
		 * @param string $reason   Reason for shadow-ban.
		 */
		do_action( 'buddynext_user_shadow_banned', $user_id, 0, '' );

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
		 * Same (user_id, actor_id) signature as unshadow_ban(); this path has no
		 * explicit actor, so actor_id is 0 (system).
		 *
		 * @param int $user_id  User ID.
		 * @param int $actor_id Moderator user ID (0 = system).
		 */
		do_action( 'buddynext_user_shadow_ban_removed', $user_id, 0 );

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

		// Already actively suspended — don't insert a duplicate active row.
		if ( null !== $this->get_active_suspension( $user_id ) ) {
			return true;
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
			array( '%d', '%d', '%s', '%s', '%d' )
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

		// Soft-lift (set lifted_at) rather than DELETE — the suspension row is the
		// moderation audit trail (who/why/when), and hard-deleting it destroyed
		// that history. Mirrors unsuspend_user(); lifted_by = 0 marks a system or
		// capability-gated lift where no specific actor was threaded in.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$lifted = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_user_suspensions
				 SET lifted_at = %s, lifted_by = %d
				 WHERE user_id = %d AND lifted_at IS NULL",
				current_time( 'mysql' ),
				0,
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

		return ! empty( $lifted );
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
				 WHERE user_id = %d AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())",
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
				 WHERE user_id = %d AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
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
		 * Same (user_id, appeal_id, type, related_id) signature as the other
		 * appeal-submit path; create_appeal only handles suspension appeals.
		 *
		 * @param int    $user_id   User who submitted the appeal.
		 * @param int    $appeal_id Inserted appeal ID.
		 * @param string $type      Appeal subject type.
		 * @param int    $related_id Related record ID (the suspension).
		 */
		do_action( 'buddynext_appeal_submitted', $user_id, $insert_id, 'suspension', $suspension_id );

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

		// The appeal must exist. Without this guard, $wpdb->update() below returns
		// 0 (no rows matched) which the false-only error check treats as success,
		// so the endpoint returned 200 and fired buddynext_appeal_resolved with
		// user_id=0 for a non-existent appeal. Bail with 404 instead.
		if ( null === $appeal ) {
			return new WP_Error(
				'appeal_not_found',
				__( 'Appeal not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

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
			array( '%s', '%s', '%d', '%s' ),
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
	 * method name `strike()`. Parameter order matches issue_strike()
	 * ($user_id, $actor_id) so the alias can't silently swap the two when called
	 * positionally.
	 *
	 * @param int    $user_id  User receiving the strike.
	 * @param int    $actor_id Admin issuing the strike.
	 * @param string $reason   Reason for the strike.
	 * @return int|WP_Error Strike ID or WP_Error.
	 */
	public function strike( int $user_id, int $actor_id, string $reason = '' ): int|WP_Error {
		return $this->issue_strike( $user_id, $actor_id, $reason );
	}

	/**
	 * Spec alias: remove the shadow-ban from a user.
	 *
	 * Delegates to unshadow_ban() (not the actor-less remove_shadow_ban) so the
	 * acting moderator is carried into the buddynext_user_shadow_ban_removed hook
	 * — otherwise $actor_id was accepted and silently dropped, leaving no audit
	 * trail of who lifted the shadow-ban.
	 *
	 * @param int $actor_id Moderator performing the action.
	 * @param int $user_id  User to unshadow-ban.
	 * @return bool True on success.
	 */
	public function shadow_unban( int $actor_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return ! is_wp_error( $this->unshadow_ban( $user_id, $actor_id ) );
	}
}
