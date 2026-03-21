<?php
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
		add_action( 'buddynext_member_joined_space', array( $this, 'on_member_joined_space' ), 10, 2 );

		// Social Graph.
		add_action( 'buddynext_connection_requested', array( $this, 'on_connection_requested' ), 10, 2 );
		add_action( 'buddynext_connection_accepted', array( $this, 'on_connection_accepted' ), 10, 2 );

		// Activity Feed.
		add_action( 'buddynext_post_reacted', array( $this, 'on_post_reacted' ), 10, 3 );
		add_action( 'buddynext_post_commented', array( $this, 'on_post_commented' ), 10, 3 );
		add_action( 'buddynext_post_shared', array( $this, 'on_post_shared' ), 10, 2 );
		add_action( 'buddynext_user_mentioned', array( $this, 'on_user_mentioned' ), 10, 3 );

		// Spaces.
		add_action( 'buddynext_space_join_requested', array( $this, 'on_space_join_requested' ), 10, 2 );
		add_action( 'buddynext_space_request_approved', array( $this, 'on_space_request_approved' ), 10, 2 );
		add_action( 'buddynext_space_member_invited', array( $this, 'on_space_member_invited' ), 10, 3 );
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
	 * @param int $user_id  User who joined the space.
	 * @param int $space_id Space that was joined.
	 */
	public function on_member_joined_space( int $user_id, int $space_id ): void {
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
	 * @param int $requester_id User who sent the connection request.
	 * @param int $addressee_id User who received the request (notification recipient).
	 */
	public function on_connection_requested( int $requester_id, int $addressee_id ): void {
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
	 * @param int $requester_id User who originally sent the request (notification recipient).
	 * @param int $addressee_id User who accepted the request.
	 */
	public function on_connection_accepted( int $requester_id, int $addressee_id ): void {
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
	 * Notify the post owner when someone reacts to their post.
	 *
	 * @param int    $post_id Post that received the reaction.
	 * @param int    $user_id User who reacted.
	 * @param string $emoji   Emoji slug used for the reaction.
	 */
	public function on_post_reacted( int $post_id, int $user_id, string $emoji ): void {
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
				'type'         => 'bn.post_reacted',
				'object_type'  => 'post',
				'object_id'    => $post_id,
				'group_key'    => 'post_reactions_' . $post_id,
				'data'         => array( 'emoji' => $emoji ),
			)
		);
	}

	/**
	 * Notify the post owner when someone comments on their post.
	 *
	 * @param int $comment_id ID of the new comment.
	 * @param int $post_id    Post that received the comment.
	 * @param int $user_id    User who commented.
	 */
	public function on_post_commented( int $comment_id, int $post_id, int $user_id ): void {
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
				'type'         => 'bn.post_commented',
				'object_type'  => 'post',
				'object_id'    => $post_id,
				'group_key'    => 'post_comments_' . $post_id,
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
	 * @param int $user_id  User requesting to join.
	 * @param int $space_id Space that received the join request.
	 */
	public function on_space_join_requested( int $user_id, int $space_id ): void {
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
	 * @param int $user_id  User whose request was approved (notification recipient).
	 * @param int $space_id Space they have been approved to join.
	 */
	public function on_space_request_approved( int $user_id, int $space_id ): void {
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$blocked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_blocks
				 WHERE ( blocker_id = %d AND blocked_id = %d )
				    OR ( blocker_id = %d AND blocked_id = %d )
				 LIMIT 1",
				$recipient_id,
				$sender_id,
				$sender_id,
				$recipient_id
			)
		);

		return null !== $blocked;
	}
}
