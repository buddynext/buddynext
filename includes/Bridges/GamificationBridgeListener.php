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
	 * User-meta key holding queued on-earn toasts awaiting the next shell load.
	 */
	private const TOAST_META = 'bn_gam_pending_toasts';

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
		//
		// Hook names use the plugin's short 'wb_gam_' prefix — verified against
		// the installed plugin: BadgeEngine.php:296 fires
		// do_action( 'wb_gam_badge_awarded', int $user_id, array $def, string $badge_id );
		// LevelEngine.php:146 fires
		// do_action( 'wb_gam_level_changed', int $user_id, array $new_level, array|null $old_level ).
		add_action( 'wb_gam_badge_awarded', array( $this, 'on_badge_awarded' ), 10, 3 );
		add_action( 'wb_gam_level_changed', array( $this, 'on_level_changed' ), 10, 3 );

		// Celebratory on-earn toast: the earn fires server-side, so queue a toast for
		// the earner and flush it on their next shell load (Free has no realtime push).
		add_action( 'wp_enqueue_scripts', array( $this, 'flush_toasts' ), 20 );
	}

	/**
	 * Notify the user when a gamification badge is awarded to them.
	 *
	 * Matches the wb-gamification BadgeEngine fire signature (verified against
	 * the installed plugin, BadgeEngine.php:296):
	 * do_action( 'wb_gam_badge_awarded', int $user_id, array $def, string $badge_id ).
	 *
	 * @param int    $user_id  User who earned the badge.
	 * @param array  $def      Badge definition (id, name, image_url, ...).
	 * @param string $badge_id Badge identifier (string slug).
	 */
	public function on_badge_awarded( int $user_id, array $def, string $badge_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		$badge_name = isset( $def['name'] ) ? (string) $def['name'] : '';

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => null,
				'type'         => 'bn.badge_awarded',
				'object_type'  => 'badge',
				'object_id'    => 0,
				'group_key'    => null,
				// 'badge' is the key NotificationMessageService::resolve_message()
				// reads to render "You earned a new badge: <name>." Keep
				// badge_id/badge_name alongside for app/REST consumers.
				'data'         => array(
					'badge'      => $badge_name,
					'badge_id'   => $badge_id,
					'badge_name' => $badge_name,
				),
			)
		);

		$this->queue_toast(
			$user_id,
			'' !== $badge_name
				/* translators: %s: badge name. */
				? sprintf( __( 'You earned the “%s” badge!', 'buddynext' ), $badge_name )
				: __( 'You earned a new badge!', 'buddynext' )
		);
	}

	/**
	 * Notify the user when their gamification level changes.
	 *
	 * Matches the wb-gamification LevelEngine fire signature (verified against
	 * the installed plugin, LevelEngine.php:146):
	 * do_action( 'wb_gam_level_changed', int $user_id, array $new_level, array|null $old_level ).
	 *
	 * Both level arguments are level-definition rows shaped
	 * { id:int, name:string, min_points:int, icon_url:string|null }. $old_level
	 * is null only on a first-ever assignment, which the plugin routes through a
	 * separate hook — but the listener still guards for null so a future change
	 * can never fatal here.
	 *
	 * @param int        $user_id   User whose level changed.
	 * @param array      $new_level New level data (id, name, min_points).
	 * @param array|null $old_level Previous level data, or null when none.
	 */
	public function on_level_changed( int $user_id, array $new_level, ?array $old_level = null ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		$new_level_id   = isset( $new_level['id'] ) ? (int) $new_level['id'] : 0;
		$new_level_name = isset( $new_level['name'] ) ? (string) $new_level['name'] : '';
		$new_min_points = isset( $new_level['min_points'] ) ? (int) $new_level['min_points'] : 0;

		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => null,
				'type'         => 'bn.level_up',
				'object_type'  => 'level',
				'object_id'    => $new_level_id,
				'group_key'    => null,
				// 'level' is the key NotificationMessageService::resolve_message()
				// reads to render "You reached level <n>." Keep the named/threshold
				// fields alongside for app/REST consumers and email rendering.
				'data'         => array(
					'level'          => $new_level_id,
					'level_id'       => $new_level_id,
					'level_name'     => $new_level_name,
					'min_points'     => $new_min_points,
					'old_level_id'   => isset( $old_level['id'] ) ? (int) $old_level['id'] : 0,
					'old_level_name' => isset( $old_level['name'] ) ? (string) $old_level['name'] : '',
				),
			)
		);

		$this->queue_toast(
			$user_id,
			'' !== $new_level_name
				/* translators: %s: level name. */
				? sprintf( __( 'You reached %s!', 'buddynext' ), $new_level_name )
				: __( 'You levelled up!', 'buddynext' )
		);
	}

	/**
	 * Queue a celebratory toast for a member, shown on their next shell page load.
	 *
	 * The earn happens server-side (often during a background/AJAX request), so the
	 * toast is parked in user meta and flushed by flush_toasts() on the next front-end
	 * render — a realtime-free path that still gives the Duolingo/Discord "you earned
	 * it!" moment. Capped so a burst of earns can never bloat the meta row.
	 *
	 * @param int    $user_id Earner.
	 * @param string $message Ready-to-display celebratory line.
	 * @return void
	 */
	private function queue_toast( int $user_id, string $message ): void {
		if ( $user_id <= 0 || '' === $message ) {
			return;
		}

		// get_user_meta returns '' (not array()) when unset — guard so the cast never
		// seeds a stray empty entry.
		$pending   = get_user_meta( $user_id, self::TOAST_META, true );
		$pending   = is_array( $pending ) ? $pending : array();
		$pending[] = array( 'message' => $message );
		$pending   = array_slice( $pending, -5 );

		update_user_meta( $user_id, self::TOAST_META, $pending );
	}

	/**
	 * Flush any queued toasts for the current user onto the shell.
	 *
	 * Runs on wp_enqueue_scripts (after the shell bundle registers). Localises the
	 * pending toasts onto bn-shell-extras and appends a tiny trigger that fires
	 * window.bnToast() once the shell dialog module has defined it, then clears the
	 * queue so each toast shows exactly once.
	 *
	 * @return void
	 */
	public function flush_toasts(): void {
		if ( ! is_user_logged_in() || ! wp_script_is( 'bn-shell-extras', 'enqueued' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$pending = get_user_meta( $user_id, self::TOAST_META, true );
		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}

		// Expose the queued toasts as a global; the notifications store module shows them
		// on nav-ready (post-hydration) via window.bnToast — the reliable path, since an
		// inline trigger races the shell's Interactivity hydration and gets wiped.
		wp_localize_script( 'bn-shell-extras', 'bnGamToasts', array_values( $pending ) );

		delete_user_meta( $user_id, self::TOAST_META );
	}
}
