<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * Outbound webhook listener.
 *
 * Dispatches outbound webhooks in response to BuddyNext domain events.
 * Each handler calls buddynext_service('webhooks')->dispatch() so that
 * the OutboundWebhookService can fan out the signed HTTP POST to every
 * registered endpoint that subscribes to the relevant event slug.
 *
 * @package BuddyNext\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Outbound;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Registers WordPress action hooks and routes them to OutboundWebhookService.
 */
class OutboundWebhookListener implements ListenerInterface {

	/**
	 * Register all outbound webhook event hook listeners.
	 *
	 * Called once during Plugin::init(), after the service container is
	 * bootstrapped, so buddynext_service() is available to every handler.
	 */
	public function register(): void {
		add_action( 'user_register', array( $this, 'on_webhook_member_registered' ), 10, 1 );
		add_action( 'buddynext_post_created', array( $this, 'on_webhook_post_created' ), 10, 3 );
		add_action( 'buddynext_post_deleted', array( $this, 'on_webhook_post_deleted' ), 10, 2 );
		add_action( 'buddynext_space_member_joined', array( $this, 'on_webhook_space_joined' ), 10, 3 );
		add_action( 'buddynext_space_member_left', array( $this, 'on_webhook_space_left' ), 10, 2 );
		add_action( 'buddynext_connection_accepted', array( $this, 'on_webhook_connection_accepted' ), 10, 3 );
		add_action( 'buddynext_user_followed', array( $this, 'on_webhook_user_followed' ), 10, 2 );
		add_action( 'buddynext_reaction_added', array( $this, 'on_webhook_reaction_added' ), 10, 5 );
		add_action( 'buddynext_comment_created', array( $this, 'on_webhook_comment_created' ), 10, 3 );
		add_action( 'buddynext_user_suspended', array( $this, 'on_webhook_user_suspended' ), 10, 4 );
		add_action( 'buddynext_user_unsuspended', array( $this, 'on_webhook_user_unsuspended' ), 10, 1 );
		add_action( 'buddynext_ability_granted', array( $this, 'on_webhook_ability_granted' ), 10, 2 );
		add_action( 'buddynext_ability_revoked', array( $this, 'on_webhook_ability_revoked' ), 10, 2 );
		add_action( 'buddynext_user_verified', array( $this, 'on_webhook_member_verified' ), 10, 1 );
	}

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
	public function on_webhook_reaction_added( int $reaction_id, string $object_type, int $object_id, int $user_id, string $emoji ): void {
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
	 * Matches the 3-arg buddynext_comment_created hook:
	 * comment_id, post_id, user_id.
	 *
	 * @param int $comment_id Comment row ID.
	 * @param int $post_id    Post the comment belongs to.
	 * @param int $user_id    User who wrote the comment.
	 */
	public function on_webhook_comment_created( int $comment_id, int $post_id, int $user_id ): void {
		buddynext_service( 'webhooks' )->dispatch(
			'comment.created',
			array(
				'comment_id'  => $comment_id,
				'object_type' => 'post',
				'object_id'   => $post_id,
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
