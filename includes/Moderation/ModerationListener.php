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
		// Suspension notifications/emails are handled solely by on_user_suspended
		// (the canonical buddynext_user_suspended hook, which carries the reason +
		// expiry). The legacy buddynext_member_suspended hook still fires for
		// external listeners, but we no longer subscribe a second notification
		// handler to it — that was the source of duplicate suspension emails.
		add_action( 'buddynext_user_suspended', array( $this, 'on_user_suspended' ), 10, 4 );
		add_action( 'buddynext_appeal_resolved', array( $this, 'on_appeal_resolved' ), 10, 3 );
		add_action( 'buddynext_user_warned', array( $this, 'on_user_warned' ), 10, 3 );
		// The takedown runs on the buddynext_content_removal_handled FILTER (not
		// the buddynext_content_removed action) so ModerationService can tell
		// whether anything actually removed the content before it marks the
		// report resolved. buddynext_content_removed still fires right after, as
		// the public side-effect hook for notification/analytics listeners.
		add_filter( 'buddynext_content_removal_handled', array( $this, 'on_content_removed' ), 10, 4 );
		// The takedown notice. buddynext_content_removed was documented as "the
		// public side-effect hook for notification listeners" but had none — so a
		// member's content vanished from the report queue with no explanation and
		// no prompt to appeal, while every other moderation action (warn, strike,
		// suspend, pre-moderation reject) told them.
		add_action( 'buddynext_content_removed', array( $this, 'on_content_removed_notify' ), 10, 3 );
		add_action( 'buddynext_user_unsuspended', array( $this, 'on_user_unsuspended' ), 10, 1 );
		add_action( 'buddynext_appeal_submitted', array( $this, 'on_appeal_submitted' ), 10, 2 );
		add_action( 'buddynext_report_created', array( $this, 'on_report_created' ), 10, 4 );
		add_action( 'buddynext_user_shadow_banned', array( $this, 'on_user_shadow_banned' ), 10, 1 );
		add_action( 'buddynext_user_shadow_ban_removed', array( $this, 'on_user_shadow_ban_removed' ), 10, 1 );
		add_action( 'buddynext_daily_queue_check', array( $this, 'on_daily_queue_check' ), 10, 0 );

		// Pre-moderation decisions — tell the author when their held post is
		// approved (now live) or rejected, so they are never left guessing.
		add_action( 'buddynext_post_approved', array( $this, 'on_post_approved' ), 10, 2 );
		add_action( 'buddynext_post_rejected', array( $this, 'on_post_rejected' ), 10, 3 );

		// Schedule the daily moderation-queue alert. Deferred to wp_loaded because
		// Action Scheduler is not initialised until 'init'; scheduling here at
		// plugins_loaded would no-op AND leave the job unscheduled.
		add_action( 'wp_loaded', array( $this, 'schedule_queue_check' ) );
	}

	/**
	 * Ensure the daily moderation-queue alert is scheduled.
	 *
	 * Runs on Action Scheduler (group 'buddynext') when available — one
	 * observable, retrying queue runnable off real system cron — and falls back
	 * to native WP-Cron when AS is absent. Idempotent; clears any legacy native
	 * WP-Cron event before registering the AS action so it never double-runs.
	 *
	 * @return void
	 */
	public function schedule_queue_check(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			if ( false === as_next_scheduled_action( 'buddynext_daily_queue_check', array(), 'buddynext' ) ) {
				if ( wp_next_scheduled( 'buddynext_daily_queue_check' ) ) {
					wp_clear_scheduled_hook( 'buddynext_daily_queue_check' );
				}
				as_schedule_recurring_action( time(), DAY_IN_SECONDS, 'buddynext_daily_queue_check', array(), 'buddynext' );
			}
			return;
		}

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

		// Escalation thresholds, strongest first: permanent ban → suspension →
		// warning. The permanent-ban tier is opt-in (0 = disabled) and is a
		// permanent suspension with the member's content hidden, which is
		// meaningfully stronger than the plain suspend tier (indefinite but
		// content-visible) — so the "Strikes before permanent ban" setting does
		// something distinct.
		$warn_threshold      = (int) get_option( 'buddynext_strike_warn_threshold', 2 );
		$suspend_threshold   = (int) get_option( 'buddynext_strike_suspend_threshold', 5 );
		$perma_ban_threshold = (int) get_option( 'buddynext_strike_perma_ban_threshold', 0 );
		$active_strikes      = buddynext_service( 'moderation' )->get_active_strike_count( $user_id );

		// A single strike notice (+ one email). When the member has reached the
		// "Strikes before warning" threshold but is not yet at suspension, this
		// notice ESCALATES its own copy to flag that suspension is approaching —
		// instead of firing a second bn.strike_warning notification/email, which
		// double-notified and double-emailed the member for one strike (and whose
		// "you are close to a strike" copy contradicts a strike having just been
		// issued). warn_threshold stays meaningful: it decides the escalated copy.
		$near_suspension = ( $active_strikes >= $warn_threshold && $active_strikes < $suspend_threshold );
		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $actor_id,
				'type'         => 'bn.strike_issued',
				'object_type'  => 'strike',
				'object_id'    => $strike_id,
				'group_key'    => null,
				// Nested under 'data' so it persists to the notification's data
				// column (create() only JSON-encodes $data['data']); compose_single()
				// reads these back to escalate the copy near suspension.
				'data'         => array(
					'count'             => $active_strikes,
					'suspend_threshold' => $suspend_threshold,
					'near_suspension'   => $near_suspension,
				),
			)
		);

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
		}
	}

	/**
	 * Notify and email a user when their account is suspended.
	 *
	 * Fires from the buddynext_user_suspended action, which carries the full
	 * suspension context (reason, optional expiry). Creates the in-app
	 * notification; EmailDispatchListener turns that into the bn.member_suspended
	 * email, so this is the single send path. The direct email is only a fallback
	 * for when the in-app notification was suppressed (recipient disabled the
	 * in-app channel) — a suspension is transactional and must still reach them.
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

		$expires_label = $expires_at ?? __( 'permanent', 'buddynext' );

		// reason + expires_at are passed as top-level scalars so EmailSender::
		// render() exposes them as {{reason}} / {{expires_at}} tokens for any
		// customised template (the default template uses only auto tokens).
		$created = buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $mod_id,
				'type'         => 'bn.member_suspended',
				'object_type'  => 'user',
				'object_id'    => $user_id,
				'group_key'    => null,
				'reason'       => $reason,
				'expires_at'   => $expires_label,
			)
		);

		if ( 0 === $created ) {
			buddynext_service( 'email_sender' )->send(
				$user_id,
				'bn.member_suspended',
				array(
					'reason'     => $reason,
					'expires_at' => $expires_label,
				)
			);
		}
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

		// 'decision' is top-level (not only nested in 'data') so the email's
		// {{decision}} token resolves — render() exposes scalar top-level keys
		// only. The notification create is the single email trigger; direct send
		// is a fallback for a suppressed in-app notification.
		$created = buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => 0,
				'type'         => 'bn.appeal_resolved',
				'object_type'  => 'appeal',
				'object_id'    => $appeal_id,
				'group_key'    => null,
				'data'         => array( 'decision' => $decision ),
				'decision'     => $decision,
			)
		);

		if ( 0 === $created ) {
			buddynext_service( 'email_sender' )->send(
				$user_id,
				'bn.appeal_resolved',
				array( 'decision' => $decision )
			);
		}
	}

	/**
	 * Notify the author when their held (pre-moderated) post is approved and is
	 * now live. The notification deep-links to the published post.
	 *
	 * @param int $post_id   Approved post ID.
	 * @param int $author_id Post author user ID.
	 * @return void
	 */
	public function on_post_approved( int $post_id, int $author_id ): void {
		if ( ! function_exists( 'buddynext_service' ) || $author_id <= 0 ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $author_id,
				'sender_id'    => 0,
				'type'         => 'bn.post_approved',
				'object_type'  => 'post',
				'object_id'    => $post_id,
			)
		);
	}

	/**
	 * Notify the author when their held (pre-moderated) post is rejected, with
	 * the moderator's reason when one was given.
	 *
	 * @param int    $post_id   Rejected post ID.
	 * @param int    $author_id Post author user ID.
	 * @param string $reason    Optional reason for the rejection.
	 * @return void
	 */
	public function on_post_rejected( int $post_id, int $author_id, string $reason = '' ): void {
		if ( ! function_exists( 'buddynext_service' ) || $author_id <= 0 ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $author_id,
				'sender_id'    => 0,
				'type'         => 'bn.post_rejected',
				'object_type'  => 'post',
				'object_id'    => $post_id,
				'data'         => array( 'reason' => $reason ),
			)
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

		// Not duplicated: the bn.user_warned notification has no email template, so
		// EmailDispatchListener sends nothing for it. The warning email therefore
		// comes ONLY from this direct send of the bn.strike_warning template — it
		// must stay unconditional (do not move it behind a create()===0 guard, or
		// warned members would stop receiving the email entirely).
		buddynext_service( 'email_sender' )->send(
			$user_id,
			'bn.strike_warning',
			array( 'message' => $message )
		);
	}

	/**
	 * Take reported content down when a moderator removes it.
	 *
	 * Answers the buddynext_content_removal_handled filter: returns true only
	 * when the content was really taken down, so ModerationService::remove_content()
	 * can refuse to mark a report 'resolved' for content that is still live.
	 *
	 * Soft-removes the target by flipping its status away from 'published'
	 * (all feed/profile/space read queries filter status = 'published', so the
	 * row vanishes from public view while staying in the table for audit and
	 * potential restore). Posts → status 'deleted' (the bn_posts status enum's
	 * soft-removed value); comments → is_deleted flag so threads keep shape;
	 * direct messages → tombstoned through the WPMediaVerse messaging engine
	 * (see remove_message()).
	 *
	 * @param bool   $handled     Whether an earlier handler already removed the content.
	 * @param string $object_type Content type being removed.
	 * @param int    $object_id   Content ID.
	 * @param int    $actor_id    Moderator who removed it (0 = automated).
	 * @return bool True when the content was taken down.
	 */
	public function on_content_removed( bool $handled, string $object_type, int $object_id, int $actor_id ): bool {
		if ( $handled || $object_id <= 0 ) {
			return $handled;
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

			return true;
		}

		if ( 'comment' === $object_type ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_comments',
				array( 'is_deleted' => 1 ),
				array( 'id' => $object_id ),
				array( '%d' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			return true;
		}

		if ( 'message' === $object_type ) {
			return $this->remove_message( $object_id );
		}

		return false;
	}

	/**
	 * Tell the author when a moderator takes their content down.
	 *
	 * Runs on the buddynext_content_removed action, which fires only after a
	 * handler has confirmed the removal really happened — so this never announces
	 * a takedown of an object type nothing could remove.
	 *
	 * The author is resolved from the surviving row: posts and comments are soft
	 * -deleted (status='deleted' / is_deleted=1) and messages are tombstoned, so
	 * the authorship is still readable at this point. A moderator removing their
	 * own content is not notified about it.
	 *
	 * @param string $object_type Content type ('post', 'comment', 'message', …).
	 * @param int    $object_id   Content ID.
	 * @param int    $actor_id    Moderator who removed it (0 = automated).
	 */
	public function on_content_removed_notify( string $object_type, int $object_id, int $actor_id ): void {
		if ( ! function_exists( 'buddynext_service' ) || $object_id <= 0 ) {
			return;
		}

		$author_id = $this->resolve_content_author( $object_type, $object_id );

		if ( $author_id <= 0 || $author_id === $actor_id ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $author_id,
				'sender_id'    => 0,
				'type'         => 'bn.content_removed',
				'object_type'  => $object_type,
				'object_id'    => $object_id,
				'group_key'    => null,
				'data'         => array( 'content_type' => $object_type ),
			)
		);
	}

	/**
	 * Resolve who wrote a piece of moderated content.
	 *
	 * @param string $object_type Content type ('post', 'comment', 'message', …).
	 * @param int    $object_id   Content ID.
	 * @return int Author user ID, or 0 when it cannot be resolved.
	 */
	private function resolve_content_author( string $object_type, int $object_id ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 'post' === $object_type ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d", $object_id )
			);
		}

		if ( 'comment' === $object_type ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bn_comments WHERE id = %d", $object_id )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 'message' === $object_type ) {
			$senders = ( new ModerationService() )->get_message_sender_ids( array( $object_id ) );

			return (int) ( $senders[ $object_id ] ?? 0 );
		}

		/**
		 * Filters the resolved author of removed content.
		 *
		 * Lets an extension that claimed its own object type on
		 * buddynext_content_removal_handled also name the author, so the takedown
		 * notice reaches them too.
		 *
		 * @since 1.1.1
		 *
		 * @param int    $author_id   Resolved author user ID. 0 when core cannot resolve it.
		 * @param string $object_type Content type.
		 * @param int    $object_id   Content ID.
		 */
		return (int) apply_filters( 'buddynext_removed_content_author', 0, $object_type, $object_id );
	}

	/**
	 * Tombstone a reported direct message through the WPMediaVerse engine.
	 *
	 * DMs live in the WPMediaVerse messaging store, not in a BuddyNext table, so
	 * the takedown is delegated to MessagingService::delete_message() — the exact
	 * "Delete for everyone" path the message's own author uses. That leaves the
	 * standard "This message was deleted" tombstone for every participant and
	 * runs the engine's bookkeeping (conversation last-message preview refresh,
	 * mvs_message_deleted event). Never write to the messaging tables directly:
	 * a raw UPDATE would skip all of it.
	 *
	 * delete_message() is sender-scoped, so the moderator's takedown is addressed
	 * as the sender's own delete-for-everyone. The moderator remains the actor of
	 * record in bn_mod_log (written by the REST controller / wp-admin queue).
	 *
	 * @param int $message_id Reported message ID.
	 * @return bool True when the message was tombstoned.
	 */
	private function remove_message( int $message_id ): bool {
		$messaging = \BuddyNext\Media\MediaClient::messaging();

		if ( ! is_object( $messaging ) || ! method_exists( $messaging, 'delete_message' ) ) {
			// The DM engine is gone but its reports remain in the queue. Say so
			// rather than reporting a removal that never happened.
			error_log( sprintf( 'BuddyNext moderation: cannot remove reported message %d — the WPMediaVerse messaging engine is unavailable.', $message_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return false;
		}

		$senders   = ( new ModerationService() )->get_message_sender_ids( array( $message_id ) );
		$sender_id = (int) ( $senders[ $message_id ] ?? 0 );

		if ( $sender_id <= 0 ) {
			error_log( sprintf( 'BuddyNext moderation: cannot remove reported message %d — its sender could not be resolved (message deleted already?).', $message_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return false;
		}

		$result = $messaging->delete_message( $message_id, $sender_id );

		if ( empty( $result['success'] ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'BuddyNext moderation: the messaging engine refused to remove reported message %d (%s).',
					$message_id,
					isset( $result['error'] ) ? (string) $result['error'] : 'unknown_error'
				)
			);

			return false;
		}

		return true;
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
			// 'action_url' is top-level so the email's {{action_url}} token points
			// at the moderation queue. The notification create is the single email
			// trigger; direct send only when the in-app notification was
			// suppressed for that recipient.
			$created = $notifications->create(
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
					'action_url'   => $queue_url,
				)
			);

			if ( 0 === $created ) {
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

		// A threshold of 0 means the alert is OFF, not "alert at any size".
		//
		// The field is an `optional_limit`, where unticking the box stores 0 and 0
		// conventionally means "no limit". That reading is right for a cap and
		// exactly backwards for a threshold to EXCEED: with $threshold = 0 the guard
		// below is `$count < 0`, which a count never is, so unticking "Email admins
		// when the queue builds up" made the daily alert fire every single day
		// regardless of queue size. The off switch turned it on.
		if ( $threshold < 1 ) {
			return;
		}

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
