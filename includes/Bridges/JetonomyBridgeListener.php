<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * Jetonomy bridge listener.
 *
 * Creates BuddyNext notifications when Jetonomy forum events occur.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Listens for Jetonomy forum events and routes them into BuddyNext notifications.
 */
class JetonomyBridgeListener implements ListenerInterface {

	/**
	 * Register Jetonomy event hooks.
	 *
	 * Bails immediately when Jetonomy is not active so no hooks are registered
	 * on sites that do not use the forum plugin.
	 */
	public function register(): void {
		if ( ! class_exists( 'Jetonomy\Core\Plugin' ) ) {
			return;
		}

		add_action( 'jetonomy_after_create_reply', array( $this, 'on_jetonomy_reply' ), 10, 3 );
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
