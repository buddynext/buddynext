<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Wires WordPress action hooks into the notification routing layer.
 *
 * Each hook handler delegates to NotificationService::create() so that
 * cross-plugin events (follows, space joins, etc.) produce the correct
 * in-app notification rows without any coupling back to the caller.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

/**
 * Registers action hooks and routes them to NotificationService.
 */
class EventListener {

	/**
	 * Register all event hook listeners.
	 *
	 * Called once during Plugin::init(), before buddynext_loaded fires,
	 * so Pro and bridge hooks added at buddynext_loaded can also rely on
	 * the same notification routing.
	 */
	public function init(): void {
		add_action( 'buddynext_user_followed', array( $this, 'on_user_followed' ), 10, 2 );
		add_action( 'buddynext_space_member_joined', array( $this, 'on_space_member_joined' ), 10, 3 );

		// Social Graph.
		add_action( 'buddynext_connection_requested', array( $this, 'on_connection_requested' ), 10, 3 );
		add_action( 'buddynext_connection_accepted', array( $this, 'on_connection_accepted' ), 10, 3 );

		// Activity Feed.
		add_action( 'buddynext_reaction_added', array( $this, 'on_reaction_added' ), 10, 4 );
		add_action( 'buddynext_comment_created', array( $this, 'on_comment_created' ), 10, 4 );
		add_action( 'buddynext_post_shared', array( $this, 'on_post_shared' ), 10, 2 );
		add_action( 'buddynext_user_mentioned', array( $this, 'on_user_mentioned' ), 10, 3 );

		// Spaces.
		add_action( 'buddynext_space_join_requested', array( $this, 'on_space_join_requested' ), 10, 2 );
		add_action( 'buddynext_space_join_approved', array( $this, 'on_space_join_approved' ), 10, 3 );
		add_action( 'buddynext_space_member_invited', array( $this, 'on_space_member_invited' ), 10, 3 );

		// Moderation.
		add_action( 'buddynext_strike_issued', array( $this, 'on_strike_issued' ), 10, 3 );
		add_action( 'buddynext_member_suspended', array( $this, 'on_member_suspended' ), 10, 2 );
		add_action( 'buddynext_user_suspended', array( $this, 'on_user_suspended' ), 10, 4 );
		add_action( 'buddynext_appeal_resolved', array( $this, 'on_appeal_resolved' ), 10, 3 );
		add_action( 'buddynext_user_warned', array( $this, 'on_user_warned' ), 10, 3 );
		add_action( 'buddynext_user_unsuspended', array( $this, 'on_user_unsuspended' ), 10, 1 );
		add_action( 'buddynext_appeal_submitted', array( $this, 'on_appeal_submitted' ), 10, 2 );
		add_action( 'buddynext_user_shadow_banned', array( $this, 'on_user_shadow_banned' ), 10, 1 );
		add_action( 'buddynext_daily_queue_check', array( $this, 'on_daily_queue_check' ), 10, 0 );

		// Schedule daily moderation queue alert if not already registered.
		if ( ! wp_next_scheduled( 'buddynext_daily_queue_check' ) ) {
			wp_schedule_event( time(), 'daily', 'buddynext_daily_queue_check' );
		}

		// WBGamification bridge (fires only when that plugin is active).
		add_action( 'wb_gamification_badge_awarded', array( $this, 'on_badge_awarded' ), 10, 2 );
		add_action( 'wb_gamification_level_changed', array( $this, 'on_level_changed' ), 10, 3 );

		// Jetonomy bridge (fires only when Jetonomy is active).
		add_action( 'jetonomy_after_create_reply', array( $this, 'on_jetonomy_reply' ), 10, 3 );
	}

	/**
	 * Notify the followed user when someone follows them.
	 *
	 * @param int $follower_id  User who initiated the follow.
	 * @param int $following_id User who was followed (notification recipient).
	 */
	public function on_user_followed( int $follower_id, int $following_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		$svc = buddynext_service( 'notifications' );

		$svc->create(
			array(
				'recipient_id' => $following_id,
				'sender_id'    => $follower_id,
				'type'         => 'bn.new_follower',
				'object_type'  => 'user',
				'object_id'    => $follower_id,
				'group_key'    => 'follower_' . $following_id,
			)
		);
	}

	/**
	 * Notify the space owner when a new member joins their space.
	 *
	 * No notification is sent if the joining user is the space owner
	 * (e.g. the owner re-joining after removal).
	 *
	 * @param int    $space_id Space that was joined.
	 * @param int    $user_id  User who joined the space.
	 * @param string $_role    Member role assigned (unused — required by hook contract).
	 */
	public function on_space_member_joined( int $space_id, int $user_id, string $_role ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_role required by hook contract.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT owner_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1",
				$space_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		$svc = buddynext_service( 'notifications' );

		$svc->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.space_join',
				'object_type'  => 'space',
				'object_id'    => $space_id,
				'group_key'    => 'space_join_' . $space_id,
			)
		);
	}

	/**
	 * Notify the addressee when someone requests a connection.
	 *
	 * @param int $connection_id Connection row ID (unused — provided for hook contract completeness).
	 * @param int $requester_id  User who sent the connection request.
	 * @param int $addressee_id  User who received the request (notification recipient).
	 */
	public function on_connection_requested( int $connection_id, int $requester_id, int $addressee_id ): void {
		if ( $requester_id === $addressee_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $addressee_id, $requester_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $addressee_id,
				'sender_id'    => $requester_id,
				'type'         => 'bn.connection_requested',
				'object_type'  => 'connection',
				'object_id'    => $requester_id,
				'group_key'    => 'conn_req_' . $addressee_id . '_' . $requester_id,
			)
		);
	}

	/**
	 * Notify the original requester when their connection request is accepted.
	 *
	 * @param int $connection_id Connection row ID (unused — provided for hook contract completeness).
	 * @param int $requester_id  User who originally sent the request (notification recipient).
	 * @param int $addressee_id  User who accepted the request.
	 */
	public function on_connection_accepted( int $connection_id, int $requester_id, int $addressee_id ): void {
		if ( $requester_id === $addressee_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $requester_id, $addressee_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $requester_id,
				'sender_id'    => $addressee_id,
				'type'         => 'bn.connection_accepted',
				'object_type'  => 'connection',
				'object_id'    => $addressee_id,
				'group_key'    => 'conn_accepted_' . $requester_id . '_' . $addressee_id,
			)
		);
	}

	/**
	 * Notify the post owner when someone reacts to their content.
	 *
	 * Only fires a notification for 'post' object type.
	 *
	 * @param string $object_type Object type (e.g. 'post', 'comment').
	 * @param int    $object_id   Object ID.
	 * @param int    $user_id     User who reacted.
	 * @param string $emoji       Emoji slug used for the reaction.
	 */
	public function on_reaction_added( string $object_type, int $object_id, int $user_id, string $emoji ): void {
		if ( 'post' !== $object_type ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$object_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $owner_id, $user_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.post_reacted',
				'object_type'  => 'post',
				'object_id'    => $object_id,
				'group_key'    => 'post_reactions_' . $object_id,
				'data'         => array( 'emoji' => $emoji ),
			)
		);
	}

	/**
	 * Notify the post owner when someone comments on their content.
	 *
	 * Only fires a notification for 'post' object type.
	 *
	 * @param int    $comment_id  ID of the new comment.
	 * @param string $object_type Object type (e.g. 'post').
	 * @param int    $object_id   Object ID.
	 * @param int    $user_id     User who commented.
	 */
	public function on_comment_created( int $comment_id, string $object_type, int $object_id, int $user_id ): void {
		if ( 'post' !== $object_type ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$object_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $owner_id, $user_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.post_commented',
				'object_type'  => 'post',
				'object_id'    => $object_id,
				'group_key'    => 'post_comments_' . $object_id,
				'data'         => array( 'comment_id' => $comment_id ),
			)
		);
	}

	/**
	 * Notify the post owner when someone shares their post.
	 *
	 * @param int $post_id Post that was shared.
	 * @param int $user_id User who shared the post.
	 */
	public function on_post_shared( int $post_id, int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$post_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $owner_id, $user_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.post_shared',
				'object_type'  => 'post',
				'object_id'    => $post_id,
				'group_key'    => 'post_shares_' . $post_id,
			)
		);
	}

	/**
	 * Notify a user when they are mentioned in a post or comment.
	 *
	 * @param int $mentioned_user_id User who was mentioned (notification recipient).
	 * @param int $mentioner_id      User who wrote the mention.
	 * @param int $context_id        ID of the post or comment containing the mention.
	 */
	public function on_user_mentioned( int $mentioned_user_id, int $mentioner_id, int $context_id ): void {
		if ( $mentioned_user_id === $mentioner_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $mentioned_user_id, $mentioner_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $mentioned_user_id,
				'sender_id'    => $mentioner_id,
				'type'         => 'bn.mention',
				'object_type'  => 'post',
				'object_id'    => $context_id,
				'group_key'    => 'mention_' . $mentioned_user_id . '_' . $context_id,
			)
		);
	}

	/**
	 * Notify the space owner when a user requests to join a private space.
	 *
	 * @param int $space_id Space that received the join request.
	 * @param int $user_id  User requesting to join.
	 */
	public function on_space_join_requested( int $space_id, int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT owner_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1",
				$space_id
			)
		);

		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $owner_id, $user_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.space_join_requested',
				'object_type'  => 'space',
				'object_id'    => $space_id,
				'group_key'    => 'space_join_req_' . $space_id,
			)
		);
	}

	/**
	 * Notify the user when their space join request is approved.
	 *
	 * @param int $space_id   Space they have been approved to join.
	 * @param int $user_id    User whose request was approved (notification recipient).
	 * @param int $_by_user_id User who approved the request (unused — required by hook contract).
	 */
	public function on_space_join_approved( int $space_id, int $user_id, int $_by_user_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_by_user_id required by hook contract.
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => null,
				'type'         => 'bn.space_request_approved',
				'object_type'  => 'space',
				'object_id'    => $space_id,
				'group_key'    => 'space_approved_' . $space_id . '_' . $user_id,
			)
		);
	}

	/**
	 * Notify a user when they are invited to join a space.
	 *
	 * @param int $invited_user_id User who was invited (notification recipient).
	 * @param int $space_id        Space they were invited to.
	 * @param int $inviter_id      User who sent the invitation.
	 */
	public function on_space_member_invited( int $invited_user_id, int $space_id, int $inviter_id ): void {
		if ( $invited_user_id === $inviter_id ) {
			return;
		}

		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( $this->is_blocked( $invited_user_id, $inviter_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $invited_user_id,
				'sender_id'    => $inviter_id,
				'type'         => 'bn.space_invite',
				'object_type'  => 'space',
				'object_id'    => $space_id,
				'group_key'    => 'space_invite_' . $space_id . '_' . $invited_user_id,
			)
		);
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
	 * Notify the user when a gamification badge is awarded to them.
	 *
	 * Only fires when WBGamification plugin is active and awards a badge.
	 *
	 * @param int $user_id  User who earned the badge.
	 * @param int $badge_id Badge that was awarded.
	 */
	public function on_badge_awarded( int $user_id, int $badge_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => null,
				'type'         => 'bn.badge_awarded',
				'object_type'  => 'badge',
				'object_id'    => $badge_id,
				'group_key'    => null,
			)
		);
	}

	/**
	 * Notify the user when their gamification level changes.
	 *
	 * Only fires when WBGamification plugin is active and changes user level.
	 *
	 * @param int $user_id   User whose level changed.
	 * @param int $old_level Level before the change.
	 * @param int $new_level Level after the change.
	 */
	public function on_level_changed( int $user_id, int $old_level, int $new_level ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => null,
				'type'         => 'bn.level_up',
				'object_type'  => 'level',
				'object_id'    => $new_level,
				'group_key'    => null,
				'data'         => array(
					'old_level' => $old_level,
					'new_level' => $new_level,
				),
			)
		);
	}

	/**
	 * Notify the Jetonomy post author when someone replies to their post.
	 *
	 * Only fires when Jetonomy plugin is active and a new reply is created.
	 *
	 * @param int $reply_id ID of the new reply.
	 * @param int $post_id  ID of the Jetonomy post that received the reply.
	 * @param int $user_id  User who wrote the reply.
	 */
	public function on_jetonomy_reply( int $reply_id, int $post_id, int $user_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		global $wpdb;

		// Jetonomy posts use the standard wp_posts table.
		$author_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $post_id )
		);

		if ( 0 === $author_id || $author_id === $user_id ) {
			return;
		}

		if ( $this->is_blocked( $author_id, $user_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $author_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.jetonomy_reply',
				'object_type'  => 'jetonomy_post',
				'object_id'    => $post_id,
				'group_key'    => 'jt_reply_' . $post_id,
				'data'         => array( 'reply_id' => $reply_id ),
			)
		);
	}

	/**
	 * Notify a user when a moderator issues them a formal warning.
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
				'type'         => 'user_warned',
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
				'type'         => 'user_unsuspended',
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
					'type'         => 'appeal_submitted',
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

	/**
	 * Check whether either user has blocked the other.
	 *
	 * Returns true when a block record exists in either direction, meaning
	 * the notification should be suppressed.
	 *
	 * @param int $recipient_id Notification recipient.
	 * @param int $sender_id    User triggering the event.
	 * @return bool
	 */
	private function is_blocked( int $recipient_id, int $sender_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$blocked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blocker_id FROM {$wpdb->prefix}bn_blocks
				 WHERE ( blocker_id = %d AND blocked_id = %d )
				    OR ( blocker_id = %d AND blocked_id = %d )
				 LIMIT 1",
				$recipient_id,
				$sender_id,
				$sender_id,
				$recipient_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null !== $blocked;
	}
}
