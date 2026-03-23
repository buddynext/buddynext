<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * Gamification bridge listener.
 *
 * Creates BuddyNext notifications when WBGamification events occur.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Listens for WBGamification events and routes them into BuddyNext notifications.
 */
class GamificationBridgeListener implements ListenerInterface {

	/**
	 * Register WBGamification notification hooks.
	 *
	 * Bails immediately when WBGamification is not active so no hooks are
	 * registered on sites that do not use the gamification plugin.
	 */
	public function register(): void {
		if ( ! class_exists( 'WBGamification\Plugin' ) ) {
			return;
		}

		add_action( 'wb_gamification_badge_awarded', array( $this, 'on_badge_awarded' ), 10, 2 );
		add_action( 'wb_gamification_level_changed', array( $this, 'on_level_changed' ), 10, 3 );
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
}
