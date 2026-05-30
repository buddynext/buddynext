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
		if ( ! function_exists( 'wb_gam_submit_event' ) ) {
			return;
		}

		// Inbound only: these are wb-gamification OUTBOUND signals (engine ->
		// site). The listener routes them into BuddyNext notifications and does
		// NOT submit any award event, so it can never double-award alongside
		// GamificationBridge (which owns all emit/submit responsibility).
		add_action( 'wb_gamification_badge_awarded', array( $this, 'on_badge_awarded' ), 10, 3 );
		add_action( 'wb_gamification_level_changed', array( $this, 'on_level_changed' ), 10, 3 );
	}

	/**
	 * Notify the user when a gamification badge is awarded to them.
	 *
	 * Matches the wb-gamification BadgeEngine fire signature:
	 * do_action( 'wb_gamification_badge_awarded', int $user_id, array $def, string $badge_id ).
	 *
	 * @param int    $user_id  User who earned the badge.
	 * @param array  $def      Badge definition (id, name, image_url, ...).
	 * @param string $badge_id Badge identifier (string slug).
	 */
	public function on_badge_awarded( int $user_id, array $def, string $badge_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => null,
				'type'         => 'bn.badge_awarded',
				'object_type'  => 'badge',
				'object_id'    => 0,
				'group_key'    => null,
				'data'         => array(
					'badge_id'   => $badge_id,
					'badge_name' => isset( $def['name'] ) ? (string) $def['name'] : '',
				),
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
