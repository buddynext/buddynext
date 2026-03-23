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
		// Outbound webhook dispatch.
		add_action( 'user_register', array( $this, 'on_webhook_member_registered' ), 10, 1 );
		add_action( 'buddynext_post_created', array( $this, 'on_webhook_post_created' ), 10, 3 );
		add_action( 'buddynext_post_deleted', array( $this, 'on_webhook_post_deleted' ), 10, 2 );
		add_action( 'buddynext_space_member_joined', array( $this, 'on_webhook_space_joined' ), 10, 3 );
		add_action( 'buddynext_space_member_left', array( $this, 'on_webhook_space_left' ), 10, 2 );
		add_action( 'buddynext_connection_accepted', array( $this, 'on_webhook_connection_accepted' ), 10, 3 );
		add_action( 'buddynext_user_followed', array( $this, 'on_webhook_user_followed' ), 10, 2 );
		add_action( 'buddynext_reaction_added', array( $this, 'on_webhook_reaction_added' ), 10, 4 );
		add_action( 'buddynext_comment_created', array( $this, 'on_webhook_comment_created' ), 10, 4 );
		add_action( 'buddynext_user_suspended', array( $this, 'on_webhook_user_suspended' ), 10, 4 );
		add_action( 'buddynext_user_unsuspended', array( $this, 'on_webhook_user_unsuspended' ), 10, 1 );
		add_action( 'buddynext_ability_granted', array( $this, 'on_webhook_ability_granted' ), 10, 2 );
		add_action( 'buddynext_ability_revoked', array( $this, 'on_webhook_ability_revoked' ), 10, 2 );
		add_action( 'buddynext_user_verified', array( $this, 'on_webhook_member_verified' ), 10, 1 );

		// WBGamification bridge (fires only when that plugin is active).
		add_action( 'wb_gamification_badge_awarded', array( $this, 'on_badge_awarded' ), 10, 2 );
		add_action( 'wb_gamification_level_changed', array( $this, 'on_level_changed' ), 10, 3 );

		// Jetonomy bridge (fires only when Jetonomy is active).
		add_action( 'jetonomy_after_create_reply', array( $this, 'on_jetonomy_reply' ), 10, 3 );

		// Onboarding nudge emails.
		add_action( 'user_register', array( $this, 'on_user_register_schedule_nudges' ), 15, 1 );
		add_action( 'buddynext_onboarding_completed', array( $this, 'on_onboarding_completed_cancel_nudges' ), 10, 1 );
		add_action( 'bn_onboarding_nudge_24h', array( $this, 'handle_onboarding_nudge' ), 10, 1 );
		add_action( 'bn_onboarding_nudge_72h', array( $this, 'handle_onboarding_nudge' ), 10, 1 );
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
				'type'         => 'jt.discussion_reply',
				'object_type'  => 'jetonomy_post',
				'object_id'    => $post_id,
				'group_key'    => 'jt_reply_' . $post_id,
				'data'         => array( 'reply_id' => $reply_id ),
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

	// ── Outbound webhook dispatch handlers ────────────────────────────────────

	/**
	 * Dispatch member.registered when a new WordPress user account is created.
	 *
	 * @param int $user_id Newly created user ID.
	 */
	public function on_webhook_member_registered( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		buddynext_service( 'webhooks' )->dispatch(
			'member.registered',
			array(
				'user_id'      => $user_id,
				'display_name' => $user->display_name,
				'user_email'   => $user->user_email,
				'registered'   => $user->user_registered,
			)
		);
	}

	/**
	 * Dispatch post.created when a BuddyNext post is published.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id Author user ID.
	 * @param string $type    Post type slug.
	 */
	public function on_webhook_post_created( int $post_id, int $user_id, string $type ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'post.created',
			array(
				'post_id' => $post_id,
				'user_id' => $user_id,
				'type'    => $type,
			)
		);
	}

	/**
	 * Dispatch post.deleted when a BuddyNext post is removed.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id Author user ID.
	 */
	public function on_webhook_post_deleted( int $post_id, int $user_id ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'post.deleted',
			array(
				'post_id' => $post_id,
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * Dispatch space.joined when a user becomes a member of a space.
	 *
	 * @param int    $user_id  User who joined.
	 * @param int    $space_id Space that was joined.
	 * @param string $role     Role assigned to the new member.
	 */
	public function on_webhook_space_joined( int $user_id, int $space_id, string $role ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'space.joined',
			array(
				'user_id'  => $user_id,
				'space_id' => $space_id,
				'role'     => $role,
			)
		);
	}

	/**
	 * Dispatch space.left when a user leaves a space voluntarily.
	 *
	 * @param int $user_id  User who left.
	 * @param int $space_id Space that was left.
	 */
	public function on_webhook_space_left( int $user_id, int $space_id ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'space.left',
			array(
				'user_id'  => $user_id,
				'space_id' => $space_id,
			)
		);
	}

	/**
	 * Dispatch connection.accepted when two users form a connection.
	 *
	 * @param int $connection_id Connection row ID.
	 * @param int $requester_id  User who originally sent the request.
	 * @param int $addressee_id  User who accepted the request.
	 */
	public function on_webhook_connection_accepted( int $connection_id, int $requester_id, int $addressee_id ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'connection.accepted',
			array(
				'connection_id' => $connection_id,
				'requester_id'  => $requester_id,
				'addressee_id'  => $addressee_id,
			)
		);
	}

	/**
	 * Dispatch user.followed when a follow relationship is created.
	 *
	 * @param int $follower_id  User who initiated the follow.
	 * @param int $following_id User who was followed.
	 */
	public function on_webhook_user_followed( int $follower_id, int $following_id ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'user.followed',
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
			)
		);
	}

	/**
	 * Dispatch reaction.added when a user reacts to content.
	 *
	 * Matches buddynext_reaction_added hook: object_type, object_id, user_id, emoji.
	 *
	 * @param string $object_type Object type (e.g. 'post', 'comment').
	 * @param int    $object_id   Object that received the reaction.
	 * @param int    $user_id     User who reacted.
	 * @param string $emoji       Emoji slug used for the reaction.
	 */
	public function on_webhook_reaction_added( string $object_type, int $object_id, int $user_id, string $emoji ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'reaction.added',
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'user_id'     => $user_id,
				'emoji'       => $emoji,
			)
		);
	}

	/**
	 * Dispatch comment.created when a new comment is posted.
	 *
	 * Matches the 4-arg buddynext_comment_created hook:
	 * comment_id, object_type, object_id, user_id.
	 *
	 * @param int    $comment_id  Comment row ID.
	 * @param string $object_type Object type the comment belongs to (e.g. 'post').
	 * @param int    $object_id   ID of the commented object.
	 * @param int    $user_id     User who wrote the comment.
	 */
	public function on_webhook_comment_created( int $comment_id, string $object_type, int $object_id, int $user_id ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'comment.created',
			array(
				'comment_id'  => $comment_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'user_id'     => $user_id,
			)
		);
	}

	/**
	 * Dispatch user.suspended when an account is suspended.
	 *
	 * Matches the 4-argument form fired by ModerationService::suspend():
	 * user_id, actor_id, reason, expires_at.
	 *
	 * @param int         $user_id    Suspended user ID.
	 * @param int         $actor_id   Moderator who issued the suspension.
	 * @param string      $reason     Human-readable suspension reason.
	 * @param string|null $expires_at ISO 8601 expiry or null for permanent.
	 */
	public function on_webhook_user_suspended( int $user_id, int $actor_id, string $reason, ?string $expires_at ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'user.suspended',
			array(
				'user_id'    => $user_id,
				'actor_id'   => $actor_id,
				'reason'     => $reason,
				'expires_at' => $expires_at,
			)
		);
	}

	/**
	 * Dispatch user.unsuspended when a suspension is lifted.
	 *
	 * @param int $user_id User whose suspension was removed.
	 */
	public function on_webhook_user_unsuspended( int $user_id ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'user.unsuspended',
			array(
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * Dispatch member.ability_granted when a custom ability is granted to a user.
	 *
	 * @param int    $user_id User who received the ability.
	 * @param string $ability Ability slug (e.g. 'bn-post-in-feed').
	 */
	public function on_webhook_ability_granted( int $user_id, string $ability ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'member.ability_granted',
			array(
				'user_id' => $user_id,
				'ability' => $ability,
			)
		);
	}

	/**
	 * Dispatch member.ability_revoked when a custom ability is removed from a user.
	 *
	 * @param int    $user_id User whose ability was revoked.
	 * @param string $ability Ability slug.
	 */
	public function on_webhook_ability_revoked( int $user_id, string $ability ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'member.ability_revoked',
			array(
				'user_id' => $user_id,
				'ability' => $ability,
			)
		);
	}

	// ── Onboarding nudge handlers ─────────────────────────────────────────────

	/**
	 * Schedule 24h and 72h nudge emails when a new user registers.
	 *
	 * @param int $user_id Newly registered user ID.
	 */
	public function on_user_register_schedule_nudges( int $user_id ): void {
		wp_schedule_single_event( time() + DAY_IN_SECONDS, 'bn_onboarding_nudge_24h', array( $user_id ) );
		wp_schedule_single_event( time() + ( 3 * DAY_IN_SECONDS ), 'bn_onboarding_nudge_72h', array( $user_id ) );
	}

	/**
	 * Cancel pending nudge emails when a user completes onboarding.
	 *
	 * @param int $user_id User who completed onboarding.
	 */
	public function on_onboarding_completed_cancel_nudges( int $user_id ): void {
		wp_clear_scheduled_hook( 'bn_onboarding_nudge_24h', array( $user_id ) );
		wp_clear_scheduled_hook( 'bn_onboarding_nudge_72h', array( $user_id ) );
	}

	/**
	 * Send an onboarding nudge email if the user has not yet completed onboarding.
	 *
	 * Shared handler for both the 24h and 72h nudge cron hooks.
	 *
	 * @param int $user_id User ID to nudge.
	 */
	public function handle_onboarding_nudge( int $user_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( buddynext_service( 'onboarding' )->is_complete( $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		buddynext_service( 'email_sender' )->send(
			$user_id,
			'bn.onboarding_nudge',
			array(
				'recipient_name' => $user->display_name,
				'onboarding_url' => home_url( '/?bn_hub=profile&bn_endpoint=onboarding' ),
			)
		);
	}

	/**
	 * Dispatch member.verified when a user completes email verification.
	 *
	 * @param int $user_id Verified user ID.
	 */
	public function on_webhook_member_verified( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		buddynext_service( 'webhooks' )->dispatch(
			'member.verified',
			array(
				'user_id'      => $user_id,
				'display_name' => $user->display_name,
				'user_email'   => $user->user_email,
			)
		);
	}
}
