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

		// Render the badge feed card through Free's typed-card seam, so it shows the
		// uniform integration bridge card (icon + "Badge" + linked name) instead of
		// the plain-text fallback — the same one job/listing/course use.
		add_filter( 'buddynext_render_post_body_badge', array( $this, 'render_feed_card' ), 10, 2 );

		// Points are a SILENT background reward in BuddyNext. wb-gamification's
		// per-action cooldown is a transient anti-burst limit; surfacing it to the
		// member as a toast ("You're on cooldown for this action - try again in a
		// bit.") scolds them for normal activity (post, then comment) and runs
		// counter to the mainstream-social bar (Facebook/LinkedIn never nag you for
		// acting "too fast"). wb-gamification 1.6.3 drops cooldown from its member-
		// facing skip reasons at the source; this guard keeps the notice suppressed
		// even when a site is still on an older wb-gamification build. Daily/weekly
		// cap notices are informative (a real limit that resets) and are left alone.
		add_filter( 'wb_gam_toast_data', array( $this, 'suppress_cooldown_toast' ), 10, 2 );

		// wb-gamification is the canonical source for streaks. Without this, a member
		// sees TWO different streaks with the same label: the sidebar greeting reads
		// BuddyNext's own StreakService (which infers a streak from post dates) while
		// the leaderboard widget reads wb_gam_get_user_streak() -- "7 days" in one
		// place and "3" in the other. StreakService exposes this filter for exactly
		// this purpose and nothing was hooking it, so BN was answering a question it
		// should have been forwarding.
		add_filter( 'buddynext_user_activity_streak', array( $this, 'canonical_streak' ), 10, 2 );
	}

	/**
	 * Defer the member's current streak to wb-gamification.
	 *
	 * Only the CURRENT streak is mapped. wb-gamification's `longest_streak` is an
	 * all-time record, which is not what `buddynext_user_activity_best_month_streak`
	 * asks for (the best run within a month) -- mapping it would trade one wrong
	 * number for another, so that filter is deliberately left alone.
	 *
	 * @param int $streak  BuddyNext's inline-computed streak.
	 * @param int $user_id Member whose streak is being resolved.
	 * @return int Canonical current streak in days.
	 */
	public function canonical_streak( int $streak, int $user_id ): int {
		if ( $user_id <= 0 || ! function_exists( 'wb_gam_get_user_streak' ) ) {
			return $streak;
		}

		$data = wb_gam_get_user_streak( $user_id );

		// Fall back to BN's own figure if the engine has no row for this member yet,
		// rather than showing a hard 0 to someone who has been posting all week.
		return isset( $data['current_streak'] ) ? (int) $data['current_streak'] : $streak;
	}

	/**
	 * Suppress EVERY gamification "skip" toast. Members are never told they earned
	 * no points.
	 *
	 * A skip is the engine saying "this action did not award points" — a cooldown, a
	 * daily cap, a weekly cap. In every one of those cases the member's ACTION
	 * SUCCEEDED. They posted, they reacted, they commented. The only thing that did
	 * not happen is an invisible points increment they never asked about.
	 *
	 * Interrupting them to say "You've hit your daily limit for this action. Resets
	 * tomorrow." tells them nothing they can act on, reads like their action FAILED
	 * when it did not, and — because a capped member keeps acting — it fires again and
	 * again. QA saw it stacked dozens deep across every page. A points cap is an
	 * anti-farming guard; it is our business, not the member's.
	 *
	 * This used to drop ONLY the `cooldown` reason, and deliberately let the daily and
	 * weekly cap notices through as "informative". They are not informative. They are
	 * nagging, and there is nothing the member can do about them. All three are now
	 * silent. (Varun, 2026-07-11: "we should not display any stale or hit limit message
	 * in the first place".)
	 *
	 * The engine calls `wb_gam_toast_data` for every toast before queueing and treats
	 * an empty array as "do not show". POSITIVE toasts — points earned, badge awarded,
	 * level up — pass through untouched: those are the ones worth interrupting for.
	 *
	 * Note this is enforced HERE rather than relying on wb-gamification's own
	 * `wb_gam_award_skip_toast_reasons` default: BuddyNext owns the member's UX, and it
	 * must not depend on a partner plugin's default staying the way we like it.
	 *
	 * @param array $event   Toast event data (type, reason, message, …).
	 * @param int   $user_id Member who would see the toast.
	 * @return array The event, or an empty array to suppress it.
	 */
	public function suppress_cooldown_toast( $event, $user_id ): array {
		$event = is_array( $event ) ? $event : array();

		if ( 'skip' !== ( $event['type'] ?? '' ) ) {
			return $event;
		}

		/**
		 * Allow re-enabling a gamification skip toast (cooldown / daily cap / weekly cap).
		 *
		 * Default false: a member is never told that an action they successfully
		 * performed earned them no points. An owner whose community genuinely wants
		 * cap feedback can switch a specific reason back on.
		 *
		 * @since 1.0.8
		 *
		 * @param bool   $show    Whether to show the skip toast (default false — silent).
		 * @param array  $event   The toast event.
		 * @param int    $user_id Member who would see the toast.
		 * @param string $reason  Skip reason: cooldown | daily_cap | weekly_cap.
		 */
		$show = (bool) apply_filters(
			'buddynext_gamification_show_skip_toast',
			false,
			$event,
			(int) $user_id,
			(string) ( $event['reason'] ?? '' )
		);

		return $show ? $event : array();
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
		// Owner control: respect the Gamification activity toggle (Integrations).
		if ( ! buddynext_integration_enabled( 'gamification', 'feed' ) ) {
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
			$name,
			'badge'
		);
	}

	/**
	 * Render the 'badge' feed card via Free's typed-card seam.
	 *
	 * @param string              $html Default HTML ('' — the helper builds it).
	 * @param array<string,mixed> $args Post-body args ({ link_preview, post_content }).
	 * @return string Pre-escaped card HTML, or '' to fall back to plain text.
	 */
	public function render_feed_card( $html, $args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return IntegrationActivity::render_bridge_card(
			is_array( $args ) ? $args : array(),
			'award',
			__( 'Badge', 'buddynext' )
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
		if ( is_callable( array( '\WBGam\Engine\BadgeSharePage', 'get_share_url' ) ) ) {
			return (string) \WBGam\Engine\BadgeSharePage::get_share_url( $badge_id, $user_id );
		}
		return home_url( 'gamification/badge/' . $badge_id . '/' . $user_id . '/share/' ); // bn-route-ok: wb-gam's fixed share rewrite, fallback only.
	}
}
