<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WBGamification bridge — consumer side.
 *
 * Broadcasts credential-badge activity to the BuddyNext feed so members can
 * see and celebrate each other's earned credentials (social proof surface).
 *
 * The producer wiring (ACTION_CATALOGUE, NOOP_HOOK, register_actions(), and
 * the on_* handlers that called wb_gam_submit_event) has been retired. Hook
 * auto-binding and point awards are now owned entirely by the wb-gamification
 * manifest at integrations/buddynext.php inside the wb-gamification plugin.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Feed\IntegrationActivity;

/**
 * WB Gamification ↔ BuddyNext consumer bridge.
 */
class GamificationBridge {

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		// Gamification standing is surfaced through the dedicated Achievements
		// profile tab (badge grid + standing strip), registered by
		// \BuddyNext\Profile\GamificationAchievements via the Nav API — NOT as
		// header stat pills (those were progression churn; the credential tab is
		// the LinkedIn-minimum home for standing).

		// Broadcast credential badges to the feed (social proof). The user-facing
		// notification is handled separately by GamificationBridgeListener; this is
		// the public engagement surface. Gated to credential badges so tiny
		// participation badges never spam the feed.
		add_action( 'wb_gam_badge_awarded', array( $this, 'on_badge_awarded_activity' ), 10, 3 );
	}

	/**
	 * Post a feed activity when a member earns a CREDENTIAL badge.
	 *
	 * Real hook: `wb_gam_badge_awarded( int $user_id, array $def, string $badge_id )`.
	 * `$def` is the badge definition row (carries `name` + `is_credential`). Links
	 * to the badge's public share page. Idempotent per share URL via
	 * IntegrationActivity, so a re-award never duplicates the card.
	 *
	 * @param int    $user_id  Member who earned the badge.
	 * @param array  $def      Badge definition row.
	 * @param string $badge_id Badge slug.
	 */
	public function on_badge_awarded_activity( int $user_id, array $def, string $badge_id ): void {
		if ( $user_id <= 0 || empty( $def['is_credential'] ) ) {
			return;
		}

		$name = isset( $def['name'] ) ? (string) $def['name'] : '';
		if ( '' === $name ) {
			return;
		}

		IntegrationActivity::publish(
			$user_id,
			/* translators: %s: badge name. */
			sprintf( __( 'earned the %s badge', 'buddynext' ), $name ),
			$this->badge_share_url( $badge_id, $user_id ),
			$name
		);
	}

	/**
	 * Public share URL for a badge.
	 *
	 * Mirrors WB Gamification's `\WBGam\Engine\BadgeSharePage::get_share_url()` —
	 * the canonical share-page rewrite (`gamification/badge/{id}/{uid}/share/`).
	 *
	 * @param string $badge_id Badge slug.
	 * @param int    $user_id  Member.
	 * @return string
	 */
	private function badge_share_url( string $badge_id, int $user_id ): string {
		return home_url( 'gamification/badge/' . $badge_id . '/' . $user_id . '/share/' );
	}
}
