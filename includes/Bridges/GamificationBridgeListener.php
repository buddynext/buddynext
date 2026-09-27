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
	 * The bell's object_type for a kudos. WB Gamification rows are namespaced
	 * wbgam_* so their ids are never read as BuddyNext objects.
	 */
	private const OBJECT_KUDOS = 'wbgam_kudos';

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

		// Six more WB Gamification moments reach the member's inbox, never email
		// (owner decision 2026-09-27, card 10344475847). WB Gamification skips these
		// hooks while replaying an import, so a migration sends nothing.
		add_action( 'wb_gam_kudos_given', array( $this, 'on_kudos_given' ), 10, 4 );
		add_action( 'wb_gam_kudos_revoked', array( $this, 'on_kudos_revoked' ), 10, 1 );
		add_action( 'wb_gam_challenge_completed', array( $this, 'on_challenge_completed' ), 10, 2 );
		add_action( 'wb_gam_redemption_fulfilled', array( $this, 'on_redemption_fulfilled' ), 10, 2 );
		add_action( 'wb_gam_credential_expired', array( $this, 'on_credential_expired' ), 10, 2 );
		add_action( 'wb_gam_personal_record', array( $this, 'on_personal_record' ), 10, 5 );
		add_action( 'wb_gam_streak_milestone', array( $this, 'on_streak_milestone' ), 10, 2 );
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
				'object_type'  => 'wbgam_badge',
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

		// wb_gam_level_changed also fires on a drop (a deduction, decay, a reversal);
		// only a climb is news (card 10344451935).
		if ( function_exists( 'wb_gam_is_level_climb' ) && ! wb_gam_is_level_climb( $new_level, $old_level ) ) {
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
				'object_type'  => 'wbgam_level',
				'object_id'    => $new_level_id,
				'group_key'    => null,
				// NotificationMessageService renders "You reached <level_name>."; the
				// ids and threshold stay for app/REST consumers.
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
	}
	/**
	 * Kudos received: "Priya gave you kudos", from the giver to the receiver.
	 *
	 * Hooked on: wb_gam_kudos_given( int $giver_id, int $receiver_id, string $message, int $kudos_id ).
	 * A block in either direction drops it, like every social notification.
	 *
	 * @param int    $giver_id    Giver.
	 * @param int    $receiver_id Receiver.
	 * @param string $message     Kudos message (may be empty).
	 * @param int    $kudos_id    Kudos row id.
	 * @return void
	 */
	public function on_kudos_given( int $giver_id, int $receiver_id, string $message = '', int $kudos_id = 0 ): void {
		if ( $giver_id <= 0 || $receiver_id <= 0 || $giver_id === $receiver_id || $this->blocked( $receiver_id, $giver_id ) ) {
			return;
		}
		$this->notify(
			$receiver_id,
			'bn.kudos_received',
			self::OBJECT_KUDOS,
			$kudos_id,
			array( 'message' => $message ),
			$giver_id
		);
	}

	/**
	 * A revoked kudos takes its notification with it.
	 *
	 * Hooked on: wb_gam_kudos_revoked( int $kudos_id, int $giver_id, int $receiver_id, string $reason, int $admin_id ).
	 *
	 * @param int $kudos_id Kudos row id.
	 * @return void
	 */
	public function on_kudos_revoked( int $kudos_id ): void {
		if ( $kudos_id > 0 && function_exists( 'buddynext_service' ) ) {
			buddynext_service( 'notifications' )->delete_for_object( self::OBJECT_KUDOS, $kudos_id );
		}
	}

	/**
	 * Challenge completed: "You completed {title}".
	 *
	 * Hooked on: wb_gam_challenge_completed( int $user_id, array $challenge ).
	 *
	 * @param int                 $user_id   Member.
	 * @param array<string,mixed> $challenge Challenge row (id, title, ...).
	 * @return void
	 */
	public function on_challenge_completed( int $user_id, array $challenge = array() ): void {
		$this->notify(
			$user_id,
			'bn.challenge_completed',
			'wbgam_challenge',
			(int) ( $challenge['id'] ?? 0 ),
			array( 'title' => (string) ( $challenge['title'] ?? '' ) )
		);
	}

	/**
	 * Reward fulfilled: "Your reward is ready".
	 *
	 * Hooked on: wb_gam_redemption_fulfilled( int $redemption_id, int $user_id ).
	 *
	 * @param int $redemption_id Redemption row id.
	 * @param int $user_id       Member.
	 * @return void
	 */
	public function on_redemption_fulfilled( int $redemption_id, int $user_id ): void {
		$this->notify( $user_id, 'bn.reward_fulfilled', 'wbgam_redemption', $redemption_id, array() );
	}

	/**
	 * Badge credential expired: "Your {badge} credential has expired".
	 *
	 * Hooked on: wb_gam_credential_expired( int $user_id, string $badge_id, string $expires_at ).
	 *
	 * @param int    $user_id  Member.
	 * @param string $badge_id Badge id.
	 * @return void
	 */
	public function on_credential_expired( int $user_id, string $badge_id ): void {
		$name = '';
		if ( function_exists( 'wb_gam_get_all_badges_for_user' ) ) {
			foreach ( (array) wb_gam_get_all_badges_for_user( $user_id ) as $badge ) {
				if ( (string) ( $badge['id'] ?? '' ) === $badge_id ) {
					$name = (string) ( $badge['name'] ?? '' );
					break;
				}
			}
		}
		$this->notify(
			$user_id,
			'bn.credential_expired',
			'wbgam_badge',
			0,
			array(
				'badge_id' => $badge_id,
				'badge'    => $name,
			)
		);
	}

	/**
	 * Personal record: WB Gamification's own sentence, as sent.
	 *
	 * Hooked on: wb_gam_personal_record( int $user_id, string $period, int $current, int $previous, string $message ).
	 *
	 * @param int    $user_id  Member.
	 * @param string $period   Period (week, month, ...).
	 * @param int    $current  New best.
	 * @param int    $previous Previous best.
	 * @param string $message  Ready-to-show sentence.
	 * @return void
	 */
	public function on_personal_record( int $user_id, string $period = '', int $current = 0, int $previous = 0, string $message = '' ): void {
		$this->notify(
			$user_id,
			'bn.personal_record',
			'',
			0,
			array(
				'message' => $message,
				'period'  => $period,
				'current' => $current,
			)
		);
	}

	/**
	 * Streak milestone: "{n}-day streak" (7, 14, 30, 60, 100, 180, 365).
	 *
	 * Hooked on: wb_gam_streak_milestone( int $user_id, int $streak_days ).
	 *
	 * @param int $user_id     Member.
	 * @param int $streak_days Streak length.
	 * @return void
	 */
	public function on_streak_milestone( int $user_id, int $streak_days ): void {
		$this->notify( $user_id, 'bn.streak_milestone', '', 0, array( 'days' => $streak_days ) );
	}

	/**
	 * Create one inbox row for a WB Gamification moment.
	 *
	 * @param int                 $user_id     Recipient.
	 * @param string              $type        BuddyNext notification type.
	 * @param string              $object_type Namespaced object type, or '' for none.
	 * @param int                 $object_id   Object id, or 0.
	 * @param array<string,mixed> $data        Data the message and link read.
	 * @param int                 $sender_id   Acting member, or 0.
	 * @return void
	 */
	private function notify( int $user_id, string $type, string $object_type, int $object_id, array $data, int $sender_id = 0 ): void {
		if ( $user_id <= 0 || ! function_exists( 'buddynext_service' ) ) {
			return;
		}
		buddynext_service( 'notifications' )->create(
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $sender_id > 0 ? $sender_id : null,
				'type'         => $type,
				'object_type'  => '' !== $object_type ? $object_type : null,
				'object_id'    => $object_id > 0 ? $object_id : null,
				'data'         => $data,
			)
		);
	}

	/**
	 * Whether either member has blocked the other.
	 *
	 * @param int $recipient_id Recipient.
	 * @param int $sender_id    Actor.
	 * @return bool
	 */
	private function blocked( int $recipient_id, int $sender_id ): bool {
		$blocks = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : null;
		if ( ! is_object( $blocks ) || ! method_exists( $blocks, 'has_blocked' ) ) {
			return false;
		}
		return $blocks->has_blocked( $recipient_id, $sender_id ) || $blocks->has_blocked( $sender_id, $recipient_id );
	}
}
