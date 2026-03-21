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
}
