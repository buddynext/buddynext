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
		// WBGamification bridge (fires only when that plugin is active).
		add_action( 'wb_gamification_badge_awarded', array( $this, 'on_badge_awarded' ), 10, 2 );
		add_action( 'wb_gamification_level_changed', array( $this, 'on_level_changed' ), 10, 3 );

		// Jetonomy bridge (fires only when Jetonomy is active).
		add_action( 'jetonomy_after_create_reply', array( $this, 'on_jetonomy_reply' ), 10, 3 );
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
}
