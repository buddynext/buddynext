<?php
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
class WPMediaVerse {

	/**
	 * Attach all hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		// Tell WPMediaVerse that BuddyNext is active so it skips its own notification.
		add_filter( 'mvs_buddynext_active', '__return_true' );

		// Gate DMs on bn_blocks.
		add_filter( 'mvs_can_send_message', array( $this, 'check_block' ), 10, 3 );

		// Route new-message events into bn_notifications.
		add_action( 'mvs_message_sent', array( $this, 'on_message_sent' ), 10, 4 );
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
