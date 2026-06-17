<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * Moderation listener.
 *
 * Responds to moderation events — strikes, suspensions, appeals, shadow bans.
 * Sends in-app notifications and transactional emails for each moderation action
 * and maintains the search index when shadow-ban state changes.
 *
 * @package BuddyNext\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Moderation;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Registers moderation action hooks and routes them to NotificationService / EmailSender.
 */
class ModerationListener implements ListenerInterface {

	/**
	 * Register all moderation event hook listeners.
	 */
	public function register(): void {
		add_action( 'buddynext_strike_issued', array( $this, 'on_strike_issued' ), 10, 3 );
		add_action( 'buddynext_member_suspended', array( $this, 'on_member_suspended' ), 10, 2 );
		add_action( 'buddynext_user_suspended', array( $this, 'on_user_suspended' ), 10, 4 );
		add_action( 'buddynext_appeal_resolved', array( $this, 'on_appeal_resolved' ), 10, 3 );
		add_action( 'buddynext_user_warned', array( $this, 'on_user_warned' ), 10, 3 );
		add_action( 'buddynext_content_removed', array( $this, 'on_content_removed' ), 10, 3 );
		add_action( 'buddynext_user_unsuspended', array( $this, 'on_user_unsuspended' ), 10, 1 );
		add_action( 'buddynext_appeal_submitted', array( $this, 'on_appeal_submitted' ), 10, 2 );
		add_action( 'buddynext_report_created', array( $this, 'on_report_created' ), 10, 4 );
		add_action( 'buddynext_user_shadow_banned', array( $this, 'on_user_shadow_banned' ), 10, 1 );
		add_action( 'buddynext_user_shadow_ban_removed', array( $this, 'on_user_shadow_ban_removed' ), 10, 1 );
		add_action( 'buddynext_daily_queue_check', array( $this, 'on_daily_queue_check' ), 10, 0 );

		// Schedule daily moderation queue alert if not already registered.
		if ( ! wp_next_scheduled( 'buddynext_daily_queue_check' ) ) {
			wp_schedule_event( time(), 'daily', 'buddynext_daily_queue_check' );
		}
	}

	/**
	 * Notify the user when a moderation strike is issued against them.
	 *
	 * @param int $strike_id Strike record ID.
	 * @param int $user_id   User who received the strike.
	 * @param int $actor_id  Admin who issued the strike.
	 */
	public function on_strike_issued( int $strike_id, int $user_id, int $actor_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $actor_id,
				'type'         => 'bn.strike_issued',
				'object_type'  => 'strike',
				'object_id'    => $strike_id,
				'group_key'    => null,
			)
		);

		// Enforce configurable strike thresholds. Escalation, strongest first:
		// permanent ban → suspension → warning. The permanent-ban tier is opt-in
		// (0 = disabled) and is a permanent suspension with the member's content
		// hidden, which is meaningfully stronger than the plain suspend tier
		// (indefinite but content-visible) — so the "Strikes before permanent
		// ban" setting actually does something distinct.
		$warn_threshold      = (int) get_option( 'buddynext_strike_warn_threshold', 2 );
		$suspend_threshold   = (int) get_option( 'buddynext_strike_suspend_threshold', 5 );
		$perma_ban_threshold = (int) get_option( 'buddynext_strike_perma_ban_threshold', 0 );
		$active_strikes      = buddynext_service( 'moderation' )->get_active_strike_count( $user_id );

		if ( $perma_ban_threshold > 0 && $active_strikes >= $perma_ban_threshold ) {
			buddynext_service( 'moderation' )->suspend(
				$user_id,
				__( 'Automatic permanent ban: strike threshold reached.', 'buddynext' ),
				0,    // duration_days = 0 → permanent (expires_at NULL).
				true, // hide the banned member's content.
				$actor_id
			);
		} elseif ( $active_strikes >= $suspend_threshold ) {
			// Route through the canonical suspension method so the strike-issuing
			// admin is recorded as the actor (admin_members->suspend_member() used
			// get_current_user_id(), which is wrong/0 in a cron or async strike
			// context) and the suspension reason + bn.member_suspended email carry
			// real context. Indefinite, content stays visible — distinct from the
			// perma-ban tier above which hides content.
			buddynext_service( 'moderation' )->suspend(
				$user_id,
				__( 'Automatic suspension: strike threshold reached.', 'buddynext' ),
				0,
				false,
				$actor_id
			);
		} elseif ( $active_strikes >= $warn_threshold ) {
			buddynext_service( 'notifications' )->create(
				array(
					'recipient_id' => $user_id,
					'sender_id'    => $actor_id,
					'type'         => 'bn.strike_warning',
					'object_type'  => 'strike',
					'object_id'    => $strike_id,
					'group_key'    => null,
				)
			);

			buddynext_service( 'email_sender' )->send(
				$user_id,
				'bn.strike_warning',
				array( 'count' => $active_strikes )
			);
		}
	}

	/**
	 * Notify the suspended user by email when their account is suspended.
	 *
	 * Creates a bn.member_suspended notification so the EmailDispatchListener
	 * picks it up and sends the corresponding email template.
	 *
	 * @param int $user_id  The suspended user.
	 * @param int $actor_id Admin who issued the suspension.
	 */
	public function on_member_suspended( int $user_id, int $actor_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $actor_id,
				'type'         => 'bn.member_suspended',
				'object_type'  => 'user',
				'object_id'    => $user_id,
				'group_key'    => null,
			)
		);
	}

	/**
	 * Notify and email a user when their account is suspended via the extended hook.
	 *
	 * Fires from the buddynext_user_suspended action, which carries the full
	 * suspension context (reason, optional expiry). Creates an in-app notification
	 * and dispatches a transactional suspension email.
	 *
	 * @param int         $user_id    The suspended user.
	 * @param int         $mod_id     Moderator or admin who issued the suspension.
	 * @param string      $reason     Human-readable reason for the suspension.
	 * @param string|null $expires_at ISO 8601 expiry timestamp, or null for permanent.
	 */
	public function on_user_suspended( int $user_id, int $mod_id, string $reason, ?string $expires_at ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $mod_id,
				'type'         => 'bn.member_suspended',
				'object_type'  => 'user',
				'object_id'    => $user_id,
				'group_key'    => null,
			)
		);

		buddynext_service( 'email_sender' )->send(
			$user_id,
			'bn.member_suspended',
			array(
				'reason'     => $reason,
				'expires_at' => $expires_at ?? __( 'permanent', 'buddynext' ),
			)
		);
	}

	/**
	 * Notify the appellant by email when their appeal is resolved.
	 *
	 * Creates a bn.appeal_resolved notification so the EmailDispatchListener
	 * can deliver the outcome email with the decision included.
	 *
	 * @param int    $appeal_id Appeal row ID.
	 * @param int    $user_id   User who submitted the appeal.
	 * @param string $decision  Resolution decision: 'approved' or 'denied'.
	 */
	public function on_appeal_resolved( int $appeal_id, int $user_id, string $decision ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => 0,
				'type'         => 'bn.appeal_resolved',
				'object_type'  => 'appeal',
				'object_id'    => $appeal_id,
				'group_key'    => null,
				'data'         => array( 'decision' => $decision ),
			)
		);

		buddynext_service( 'email_sender' )->send(
			$user_id,
			'bn.appeal_resolved',
			array( 'status' => $decision )
		);
	}

	/**
	 * Notify the user when a moderator issues them a formal warning.
	 *
	 * Creates a bn.user_warned in-app notification and dispatches the
	 * bn.strike_warning transactional email to the warned user's address.
	 *
	 * @param int    $user_id    User receiving the warning.
	 * @param int    $by_user_id Moderator user ID who issued the warning.
	 * @param string $message    Warning message / reason from the moderator.
	 */
	public function on_user_warned( int $user_id, int $by_user_id, string $message ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $by_user_id,
				'type'         => 'bn.user_warned',
				'object_type'  => 'user',
				'object_id'    => $user_id,
				'group_key'    => null,
				'message'      => __( 'You have received a formal warning from a moderator.', 'buddynext' ),
			)
		);

		buddynext_service( 'email_sender' )->send(
			$user_id,
			'bn.strike_warning',
			array( 'message' => $message )
		);
	}

	/**
	 * Take reported content down when a moderator removes it.
	 *
	 * Soft-removes the target by flipping its status away from 'published'
	 * (all feed/profile/space read queries filter status = 'published', so the
	 * row vanishes from public view while staying in the table for audit and
	 * potential restore). Posts → status 'deleted' (the bn_posts status enum's
	 * soft-removed value); comments → is_deleted flag so threads keep shape.
	 *
	 * @param string $object_type Content type being removed.
	 * @param int    $object_id   Content ID.
	 * @param int    $actor_id    Moderator who removed it (0 = automated).
	 */
	public function on_content_removed( string $object_type, int $object_id, int $actor_id ): void {
		if ( $object_id <= 0 ) {
			return;
		}

		global $wpdb;

		if ( 'post' === $object_type ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_posts',
				array( 'status' => 'deleted' ),
				array( 'id' => $object_id ),
				array( '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_cache_delete( "post_{$object_id}", 'buddynext_posts' );
		} elseif ( 'comment' === $object_type ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_comments',
				array( 'is_deleted' => 1 ),
				array( 'id' => $object_id ),
				array( '%d' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Notify a user when their account suspension is lifted.
	 *
	 * Creates a bn.user_unsuspended in-app notification. Dispatches a
	 * bn.unsuspension_confirmation email when that template exists; logs a
	 * notice and skips the email send when the template is absent.
	 *
	 * @param int $user_id User whose suspension has been removed.
	 */
	public function on_user_unsuspended( int $user_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => null,
				'type'         => 'bn.user_unsuspended',
				'object_type'  => 'user',
				'object_id'    => $user_id,
				'group_key'    => null,
				'message'      => __( 'Your account suspension has been lifted.', 'buddynext' ),
			)
		);

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$template_exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_email_templates WHERE type = %s AND enabled = 1 LIMIT 1",
				'bn.unsuspension_confirmation'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $template_exists ) {
			buddynext_service( 'email_sender' )->send( $user_id, 'bn.unsuspension_confirmation', array() );
		} else {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'BuddyNext: email template bn.unsuspension_confirmation not found — skipping email for user %d.',
					$user_id
				)
			);
		}
	}

	/**
	 * Notify all site administrators when a user submits a moderation appeal.
	 *
	 * No email is sent — admins are expected to monitor the appeal queue via
	 * the BuddyNext moderation dashboard.
	 *
	 * @param int $user_id   User who submitted the appeal.
	 * @param int $appeal_id Appeal row ID.
	 */
	public function on_appeal_submitted( int $user_id, int $appeal_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		$admin_ids = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);

		if ( empty( $admin_ids ) ) {
			return;
		}

		$svc = buddynext_service( 'notifications' );

		foreach ( $admin_ids as $admin_id ) {
			$svc->create(
				array(
					'recipient_id' => (int) $admin_id,
					'sender_id'    => $user_id,
					'type'         => 'bn.appeal_submitted',
					'object_type'  => 'appeal',
					'object_id'    => $appeal_id,
					'group_key'    => 'appeal_submitted_' . $appeal_id,
					'data'         => array(
						'user_id'   => $user_id,
						'appeal_id' => $appeal_id,
					),
				)
			);
		}
	}

	/**
	 * Notify moderators the moment a new report is filed.
	 *
	 * Fires from buddynext_report_created — previously unsubscribed, so reports
	 * sat unseen until an admin happened to open the queue. Sends an in-app
	 * notification (and a bn.new_report email, subject to each recipient's email
	 * frequency preference) to every site administrator, plus the owners and
	 * moderators of the space when the report is space-scoped. The reporter is
	 * never notified about their own report.
	 *
	 * @param int    $report_id   New report ID.
	 * @param string $object_type Reported object type (post|comment|user).
	 * @param int    $object_id   Reported object ID.
	 * @param int    $reporter_id User who filed the report.
	 */
	public function on_report_created( int $report_id, string $object_type, int $object_id, int $reporter_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		global $wpdb;

		// Resolve the report's space (if any) so space-scoped reports also reach
		// that space's owners/moderators, not only site admins.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}bn_reports WHERE id = %d", $report_id )
		);

		// Deduplicated recipient set keyed by user ID.
		$recipients = array();
		foreach ( (array) get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		) as $admin_id ) {
			$recipients[ (int) $admin_id ] = true;
		}
		if ( $space_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$space_mods = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->prefix}bn_space_members
					 WHERE space_id = %d AND status = 'active' AND role IN ( 'owner', 'moderator' )",
					$space_id
				)
			);
			foreach ( (array) $space_mods as $mod_id ) {
				$recipients[ (int) $mod_id ] = true;
			}
		}

		unset( $recipients[ $reporter_id ] );
		if ( empty( $recipients ) ) {
			return;
		}

		$queue_url     = admin_url( 'admin.php?page=buddynext-moderation' );
		$notifications = buddynext_service( 'notifications' );
		$email_sender  = buddynext_service( 'email_sender' );

		$message = sprintf(
			/* translators: 1: object type (post/comment/user), 2: object id */
			__( 'New report filed on %1$s #%2$d — review the moderation queue.', 'buddynext' ),
			$object_type,
			$object_id
		);

		foreach ( array_keys( $recipients ) as $recipient_id ) {
			$notifications->create(
				array(
					'recipient_id' => $recipient_id,
					'sender_id'    => $reporter_id,
					'type'         => 'bn.new_report',
					'object_type'  => $object_type,
					'object_id'    => $object_id,
					'group_key'    => 'report_created_' . $report_id,
					'data'         => array(
						'message' => $message,
						'url'     => $queue_url,
					),
				)
			);

			$email_sender->send(
				$recipient_id,
				'bn.new_report',
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'action_url'  => $queue_url,
				)
			);
		}
	}

	/**
	 * Remove a shadow-banned user from the BuddyNext search index.
	 *
	 * Shadow-banned users must not appear in member directory or unified
	 * search results. Their row is deleted from bn_search_index so the next
	 * search rebuild does not re-add them until the ban is lifted.
	 *
	 * @param int $user_id User who has been shadow-banned.
	 */
	public function on_user_shadow_banned( int $user_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			"{$wpdb->prefix}bn_search_index",
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
			),
			array( '%s', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Re-index the user in the search index when a shadow ban is lifted.
	 *
	 * Fires the buddynext_index_user action so any registered indexer
	 * (e.g. SearchService) can rebuild the user's search record.
	 *
	 * @param int $user_id User whose shadow ban was removed.
	 */
	public function on_user_shadow_ban_removed( int $user_id ): void {
		do_action( 'buddynext_index_user', $user_id );
	}

	/**
	 * Check the moderation queue size and alert admins when a threshold is reached.
	 *
	 * Runs once daily via WP-Cron. Counts all pending and escalated reports
	 * and sends a plain-text email to the configured alert address when the
	 * count meets or exceeds the configured threshold.
	 */
	public function on_daily_queue_check(): void {
		global $wpdb;

		// Read the keys the Settings → Moderation screen actually registers and
		// saves (Settings.php). The previous bn_* keys were never written, so the
		// daily check always used the hardcoded threshold of 20 and ignored the
		// admin's configured alert email.
		$threshold   = (int) get_option( 'buddynext_mod_queue_alert_threshold', 20 );
		$alert_email = (string) get_option( 'buddynext_admin_alert_email', get_option( 'admin_email' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE status IN ('pending','escalated')"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( $count < $threshold ) {
			return;
		}

		$alert_subject = __( 'BuddyNext: Moderation queue threshold reached', 'buddynext' );
		$alert_body    = sprintf(
			/* translators: %1$d: current queue count, %2$d: configured threshold */
			__(
				"Your BuddyNext moderation queue currently has %1\$d pending or escalated report(s), which meets or exceeds the configured alert threshold of %2\$d.\n\nPlease review the queue at your earliest convenience.",
				'buddynext'
			),
			$count,
			$threshold
		);

		\BuddyNext\Notifications\EmailSender::send_with_identity(
			$alert_email,
			$alert_subject,
			\BuddyNext\Notifications\EmailSender::brand_wrap( wpautop( esc_html( $alert_body ) ), $alert_subject ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}
}
