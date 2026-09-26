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
	 * The site's name for points ("Points" unless the owner renamed the default
	 * point type, e.g. "Coins"), so every BuddyNext surface says what the
	 * gamification plugin says (card 10343975769).
	 *
	 * @return string
	 */
	public static function points_label(): string {
		static $label = null;
		if ( null === $label ) {
			$label = '';
			if ( class_exists( '\\WBGam\\Services\\PointTypeService' ) ) {
				$types  = new \WBGam\Services\PointTypeService();
				$record = $types->get( $types->default_slug() );
				$label  = is_array( $record ) ? trim( (string) ( $record['label'] ?? '' ) ) : '';
			}
			if ( '' === $label ) {
				$label = __( 'Points', 'buddynext' );
			}
		}
		return $label;
	}


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
		// the public engagement surface. Broadcast on the member's explicit SHARE, not
		// on award: wb-gamification 1.6.4 made badges private until the member presses
		// Share (wb_gam_badge_shared / _unshared). Broadcasting on award published a
		// credential to the public feed BEFORE the member consented (card 10303345360).
		// Still gated to credential badges so tiny participation badges never spam the
		// feed. Unshare WITHDRAWS the card reversibly (draft), so a re-share brings the
		// same card — id, date, reactions, comments — back rather than minting a new one.
		add_action( 'wb_gam_badge_shared', array( $this, 'on_badge_shared_activity' ), 10, 2 );
		add_action( 'wb_gam_badge_unshared', array( $this, 'on_badge_unshared_activity' ), 10, 2 );

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
		// ...and for the active days behind it: the greeting card's 7-day strip and
		// "best this month" read this date list. Left unhooked, the number came from
		// gamification and the strip from BN post dates, so one card disagreed with
		// itself (card 10343081902).
		add_filter( 'buddynext_user_active_dates', array( $this, 'canonical_active_dates' ), 10, 3 );

		// BuddyNext is the master community. When wb-gamification can't show a badge
		// on its own share page (un-earned / un-published), it would redirect to its
		// OWN profile page; fill its seam so the visitor lands on the BuddyNext
		// profile instead. BuddyNext's task lives here in the bridge, not in the
		// partner — wb-gamification only exposes the filter.
		add_filter( 'wb_gam_badge_share_redirect_url', array( $this, 'badge_share_redirect_url' ), 10, 2 );

		// Same reasoning for wb-gamification's standalone /u/ member profile: BN
		// owns the member profile (Achievements / Points / Kudos render there), so
		// point its /u/ page at the BN profile. wb-gamification exposes the filter;
		// the bridge fills it.
		add_filter( 'wb_gam_profile_redirect_url', array( $this, 'profile_redirect_url' ), 10, 2 );
	}

	/**
	 * Point wb-gamification's standalone /u/ profile at the BuddyNext profile.
	 *
	 * @param string $url     wb-gamification's default (empty = render own page).
	 * @param int    $user_id Profile owner.
	 * @return string BuddyNext profile URL, or the incoming default if unresolvable.
	 */
	public function profile_redirect_url( $url, $user_id ): string {
		return $this->resolve_profile_redirect( $url, $user_id );
	}

	/**
	 * Resolve a member's BuddyNext profile URL for a partner redirect filter,
	 * falling back to the partner's own default when it cannot be resolved.
	 *
	 * Shared by profile_redirect_url() and badge_share_redirect_url(): both
	 * partner filters answer the same question (send this user to their BN
	 * profile, else leave the partner default alone) with different @param docs.
	 *
	 * @param string $url     The partner's default URL (empty = render own page).
	 * @param int    $user_id Profile/badge owner.
	 * @return string BuddyNext profile URL, or the incoming default if unresolvable.
	 */
	private function resolve_profile_redirect( $url, $user_id ): string {
		$uid = (int) $user_id;
		if ( $uid <= 0 ) {
			return (string) $url;
		}

		$bn_profile = \BuddyNext\Core\PageRouter::profile_url( $uid );
		return '' !== $bn_profile ? $bn_profile : (string) $url;
	}

	/**
	 * Redirect wb-gamification's badge-share fallback to the BuddyNext profile.
	 *
	 * @param string $url     wb-gamification's default redirect URL.
	 * @param int    $user_id Badge owner.
	 * @return string BuddyNext profile URL, or the partner default if unresolvable.
	 */
	public function badge_share_redirect_url( $url, $user_id ): string {
		return $this->resolve_profile_redirect( $url, $user_id );
	}

	/**
	 * Source the member's active days from wb-gamification: every site-local day
	 * with any points in the window. The greeting card's 7-day strip and "best
	 * this month" are computed from this list, so they agree with the streak
	 * number canonical_streak() takes from the same engine.
	 *
	 * @param mixed $dates   Null (BuddyNext computes) or a date list from another filter.
	 * @param int   $user_id Member.
	 * @param int   $window  Lookback in days.
	 * @return mixed List of 'Y-m-d' dates, or the incoming value when the engine is absent.
	 */
	public function canonical_active_dates( $dates, int $user_id, int $window = 30 ) {
		if ( $user_id <= 0 || ! class_exists( '\\WBGam\\Engine\\StreakEngine' ) ) {
			return $dates;
		}
		// Days with any points in the window, site-local like StreakService's "today".
		return array_keys( \WBGam\Engine\StreakEngine::get_contribution_data( $user_id, $window ) );
	}

	/**
	 * Defer the member's current streak to wb-gamification.
	 *
	 * Only the CURRENT streak is mapped. wb-gamification's `longest_streak` is an
	 * all-time record, which is not what `buddynext_user_activity_best_month_streak`
	 * asks for (the best run within a month) -- mapping it would trade one wrong
	 * number for another, so that filter is deliberately left alone. "Best this
	 * month" still agrees, because it is computed from canonical_active_dates().
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
	 * Post a feed activity when a member SHARES a credential badge.
	 *
	 * Real hook: `wb_gam_badge_shared( int $user_id, string $badge_id )` — fired only
	 * when the member presses Share, so this never publishes before consent. The badge
	 * definition (name + is_credential) is resolved from the member's own badges via
	 * the partner's public getter, since the share hook carries only the id.
	 *
	 * Reversible: a card a prior unshare WITHDREW (set to 'draft') is RESTORED here —
	 * same id, date, reactions and comments — rather than minting a new one, so a
	 * share -> unshare -> re-share cycle keeps its engagement. Idempotent per share URL.
	 *
	 * @param int    $user_id  Member who shared the badge.
	 * @param string $badge_id Badge slug.
	 * @return void
	 */
	public function on_badge_shared_activity( int $user_id, string $badge_id ): void {
		if ( $user_id <= 0 || '' === $badge_id ) {
			return;
		}
		// Owner control: respect the Gamification activity toggle (Integrations).
		if ( ! buddynext_integration_enabled( 'gamification', 'feed' ) ) {
			return;
		}

		$def = $this->badge_definition( $user_id, $badge_id );
		// Gated to credential badges so tiny participation badges never spam the feed.
		if ( null === $def || empty( $def['is_credential'] ) ) {
			return;
		}
		$name = isset( $def['name'] ) ? (string) $def['name'] : '';
		if ( '' === $name ) {
			return;
		}

		$url = $this->badge_activity_url( $badge_id, $user_id );
		if ( IntegrationActivity::restore( $url, 'badge' ) ) {
			return;
		}
		IntegrationActivity::publish(
			$user_id,
			/* translators: %s: badge name. */
			sprintf( __( 'earned the %s badge', 'buddynext' ), $name ),
			$url,
			$name,
			'badge'
		);
	}

	/**
	 * Withdraw a member's shared-badge card when they UNSHARE it.
	 *
	 * Real hook: `wb_gam_badge_unshared( int $user_id, string $badge_id )`. Withdraws
	 * the card reversibly (to 'draft', hidden from every feed but its row, date and
	 * comments preserved) rather than deleting it, so a later re-share restores this
	 * exact card. A no-op when no card exists for the badge (a non-credential badge, or
	 * one shared while the feed toggle was off).
	 *
	 * @param int    $user_id  Member who unshared the badge.
	 * @param string $badge_id Badge slug.
	 * @return void
	 */
	public function on_badge_unshared_activity( int $user_id, string $badge_id ): void {
		if ( $user_id <= 0 || '' === $badge_id ) {
			return;
		}
		IntegrationActivity::withdraw( $this->badge_activity_url( $badge_id, $user_id ), 'badge' );
	}

	/**
	 * The member's own row for one badge (carries `name` + `is_credential`), or null.
	 *
	 * Resolved through the partner's public getter — never a direct table read — and
	 * scoped to badges the member actually holds, so it also confirms the share is for
	 * a real, earned badge.
	 *
	 * @param int    $user_id  Member.
	 * @param string $badge_id Badge slug.
	 * @return array<string,mixed>|null
	 */
	private function badge_definition( int $user_id, string $badge_id ): ?array {
		if ( ! function_exists( 'wb_gam_get_user_badges' ) ) {
			return null;
		}
		foreach ( (array) wb_gam_get_user_badges( $user_id ) as $badge ) {
			if ( is_array( $badge ) && isset( $badge['id'] ) && (string) $badge['id'] === $badge_id ) {
				return $badge;
			}
		}
		return null;
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
	 * Feed-card link for an earned badge — the member's own BuddyNext Achievements
	 * tab, NOT wb-gamification's public share page.
	 *
	 * The badge already lives on the member's profile here — this IS their
	 * profile — so BuddyNext needs no separate "share" step to surface it, and
	 * wb-gamification's share page is gated to publicly-shared badges (an un-shared
	 * badge there 404s / redirects to the profile anyway). Linking to the
	 * Achievements tab lands on a surface that always exists at award time
	 * (the tab shows whenever the member has standing) and that BuddyNext owns.
	 *
	 * A per-badge fragment keeps the link unique so IntegrationActivity's
	 * dedup-on-link_url still stores one card per badge (a bare profile URL would
	 * collide across every badge and suppress all but the first).
	 *
	 * @param string $badge_id Badge slug.
	 * @param int    $user_id  Member.
	 * @return string
	 */
	private function badge_activity_url( string $badge_id, int $user_id ): string {
		$base = trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $user_id ) ) . 'achievements/';
		return $base . '#badge-' . rawurlencode( $badge_id );
	}
}
