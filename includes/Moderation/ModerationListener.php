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
		add_action( 'buddynext_user_unsuspended', array( $this, 'on_user_unsuspended' ), 10, 1 );
		add_action( 'buddynext_appeal_submitted', array( $this, 'on_appeal_submitted' ), 10, 2 );
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

		// Enforce configurable strike thresholds.
		$warn_threshold    = (int) get_option( 'buddynext_strike_warn_threshold', 2 );
		$suspend_threshold = (int) get_option( 'buddynext_strike_suspend_threshold', 5 );
		$active_strikes    = buddynext_service( 'moderation' )->get_active_strike_count( $user_id );

		if ( $active_strikes >= $suspend_threshold ) {
			buddynext_service( 'admin_members' )->suspend_member( $user_id );
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
	 * @param int    $user_id   User receiving the warning.
	 * @param string $message   Warning message from the moderator.
	 * @param int    $warned_by Moderator user ID who issued the warning.
	 */
	public function on_user_warned( int $user_id, string $message, int $warned_by ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $warned_by,
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
				"SELECT id FROM {$wpdb->prefix}bn_email_templates WHERE slug = %s AND enabled = 1 LIMIT 1",
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

		$threshold   = (int) get_option( 'bn_moderation_queue_alert_threshold', 20 );
		$alert_email = (string) get_option( 'bn_moderation_alert_email', get_option( 'admin_email' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE status IN ('pending','escalated')"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( $count < $threshold ) {
			return;
		}

		wp_mail(
			$alert_email,
			__( 'BuddyNext: Moderation queue threshold reached', 'buddynext' ),
			sprintf(
				/* translators: %1$d: current queue count, %2$d: configured threshold */
				__(
					"Your BuddyNext moderation queue currently has %1\$d pending or escalated report(s), which meets or exceeds the configured alert threshold of %2\$d.\n\nPlease review the queue at your earliest convenience.",
					'buddynext'
				),
				$count,
				$threshold
			)
		);
	}
}
