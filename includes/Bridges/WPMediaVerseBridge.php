<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WPMediaVerse bridge.
 *
 * Connects BuddyNext to the WPMediaVerse DM engine. Responsible for:
 *
 * 1. Declaring BuddyNext as active so WPMediaVerse's NotificationListener
 *    skips its own notification (avoids duplicates).
 * 2. Blocking DMs from users who are blocked via bn_blocks.
 * 3. Routing new-message events into bn_notifications (type bn.new_message)
 *    so the BuddyNext notification system handles delivery + email prefs.
 *
 * Only boots if WPMediaVerse free is active — checked at hook time via
 * class_exists, not on load, so activation order doesn't matter.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Notifications\NotificationService;

/**
 * WPMediaVerse ↔ BuddyNext integration layer.
 */
class WPMediaVerseBridge {

	/**
	 * Attach all hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		if ( ! class_exists( 'WPMediaVerse\Core\Plugin' ) ) {
			return;
		}

		// Tell WPMediaVerse that BuddyNext is active so it skips its own notification.
		add_filter( 'mvs_buddynext_active', '__return_true' );

		// Gate DMs on bn_blocks.
		add_filter( 'mvs_can_send_message', array( $this, 'check_block' ), 10, 3 );

		// Route new-message events into bn_notifications.
		add_action( 'mvs_message_sent', array( $this, 'on_message_sent' ), 10, 4 );

		// Notify media owner when someone favourites their content.
		add_action( 'mvs_favorite_toggled', array( $this, 'on_favorite_toggled' ), 10, 3 );
	}

	/**
	 * Return false if the sender is blocked by the recipient.
	 *
	 * Hooked on: mvs_can_send_message (int $sender_id, int $recipient_id)
	 *
	 * @param bool $allowed      Current allowed state from earlier filters.
	 * @param int  $sender_id    User attempting to send.
	 * @param int  $recipient_id Intended message recipient.
	 * @return bool
	 */
	public function check_block( bool $allowed, int $sender_id, int $recipient_id ): bool {
		if ( ! $allowed ) {
			return false;
		}

		global $wpdb;

		// Check whether recipient has blocked sender.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$block = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blocker_id FROM {$wpdb->prefix}bn_blocks
				 WHERE blocker_id = %d AND blocked_id = %d AND type = 'block'
				 LIMIT 1",
				$recipient_id,
				$sender_id
			)
		);

		return null === $block;
	}

	/**
	 * Notify the media owner when their content is favourited.
	 *
	 * Only fires a notification on 'added' — not on 'removed' — to avoid
	 * spamming the owner when a user toggles the favourite off.
	 *
	 * Hooked on: mvs_favorite_toggled ($media_id, $user_id, $action)
	 *
	 * @param int    $media_id Media item ID.
	 * @param int    $user_id  User who toggled the favourite.
	 * @param string $action   'added' or 'removed'.
	 */
	public function on_favorite_toggled( int $media_id, int $user_id, string $action ): void {
		if ( 'added' !== $action ) {
			return;
		}

		$owner_id = (int) get_post_field( 'post_author', $media_id );
		if ( 0 === $owner_id || $owner_id === $user_id ) {
			return;
		}

		( new NotificationService() )->create(
			array(
				'recipient_id' => $owner_id,
				'sender_id'    => $user_id,
				'type'         => 'bn.media_favorited',
				'object_type'  => 'media',
				'object_id'    => $media_id,
				'group_key'    => "mvs_fav_{$media_id}",
				'data'         => array( 'media_id' => $media_id ),
			)
		);
	}

	/**
	 * Create a bn.new_message notification for each recipient.
	 *
	 * Skips the sender themselves (no self-notification).
	 *
	 * Hooked on: mvs_message_sent ($message_id, $conversation_id, $sender_id, $recipient_ids)
	 *
	 * @param int   $message_id      Message that was sent.
	 * @param int   $conversation_id Conversation the message belongs to.
	 * @param int   $sender_id       User who sent the message.
	 * @param int[] $recipient_ids   Users who should receive the notification.
	 */
	public function on_message_sent( int $message_id, int $conversation_id, int $sender_id, array $recipient_ids ): void {
		$service = new NotificationService();

		foreach ( $recipient_ids as $recipient_id ) {
			if ( (int) $recipient_id === $sender_id ) {
				continue;
			}

			$service->create(
				array(
					'recipient_id' => (int) $recipient_id,
					'sender_id'    => $sender_id,
					'type'         => 'bn.new_message',
					'object_type'  => 'conversation',
					'object_id'    => $conversation_id,
					'group_key'    => "dm_{$conversation_id}_{$recipient_id}",
					'data'         => array( 'message_id' => $message_id ),
				)
			);
		}
	}
}
