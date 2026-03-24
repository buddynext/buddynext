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

		// jetonomy_after_create_reply fires ($reply_id, $post_id) — 2 args only.
		// The replier's user ID is fetched from jt_replies inside the callback.
		add_action( 'jetonomy_after_create_reply', array( $this, 'on_jetonomy_reply' ), 10, 2 );
	}

	/**
	 * Notify the Jetonomy post author when someone replies to their post.
	 *
	 * Hooked on: jetonomy_after_create_reply( int $reply_id, int $post_id )
	 *
	 * Note: Jetonomy fires only 2 args — reply_id and post_id. The replier's
	 * user ID and the post author are both fetched from the DB to avoid relying
	 * on a wider hook signature.
	 *
	 * @param int $reply_id ID of the new reply (jt_replies.id).
	 * @param int $post_id  ID of the Jetonomy post that received the reply.
	 */
	public function on_jetonomy_reply( int $reply_id, int $post_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reply_author_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT author_id FROM {$wpdb->prefix}jt_replies WHERE id = %d LIMIT 1",
				$reply_id
			)
		);

		$post_author_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT author_id FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Skip when IDs couldn't be resolved or the replier is the post author.
		if ( 0 === $reply_author_id || 0 === $post_author_id || $reply_author_id === $post_author_id ) {
			return;
		}

		if ( $this->is_blocked( $post_author_id, $reply_author_id ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $post_author_id,
				'sender_id'    => $reply_author_id,
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
