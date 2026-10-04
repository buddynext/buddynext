<?php
/**
 * Tests for the WBGamification bridge — consumer side.
 *
 * The producer wiring (on_user_followed, on_post_created, on_connection_accepted,
 * on_space_joined, on_strike_issued, on_profile_completion_changed,
 * on_reaction_received, on_comment_created) has been retired from GamificationBridge
 * and is now owned by the wb-gamification manifest at integrations/buddynext.php.
 * Producer-side tests belong in the wb-gamification test suite, not here.
 *
 * These tests cover the remaining consumer responsibility: posting feed activity
 * when WBGamification awards a credential badge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\GamificationBridge;
use BuddyNext\Core\Installer;

/**
 * Consumer-side + toast-guard tests for the WBGamification bridge.
 *
 * @covers \BuddyNext\Bridges\GamificationBridge
 */
class WBGamificationBridgeTest extends \WP_UnitTestCase {

	/**
	 * Bridge under test.
	 *
	 * @var GamificationBridge
	 */
	private GamificationBridge $bridge;

	/**
	 * Boot the installer + bridge (registers the toast-data filter).
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		// Plugin class and function stubs are registered in tests/bootstrap.php.
		$this->bridge = new GamificationBridge();
		$this->bridge->init();
	}

	/**
	 * A credential badge broadcasts on the member's SHARE (consent), never on award;
	 * unsharing withdraws it reversibly and re-sharing restores the same card.
	 *
	 * wb-gamification 1.6.4 made badges private until the member presses Share, so
	 * broadcasting on award published a credential before the member consented (card
	 * 10303345360). The share hook carries only (user_id, badge_id); the bridge
	 * resolves the def from the member's own badges (stubbed here via the wb_gam store).
	 */
	public function test_shared_credential_badge_broadcasts_and_unshare_withdraws(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$GLOBALS['wb_gam_test']['badges'][ $user ] = array(
			array(
				'id'            => 'top-contributor',
				'name'          => 'Top Contributor',
				'is_credential' => true,
			),
		);

		$published = function () use ( $wpdb, $user ): int {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'badge' AND status = 'published'", $user )
			);
		};
		$card_id   = function () use ( $wpdb, $user ): int {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'badge' LIMIT 1", $user )
			);
		};

		// Award alone must NOT broadcast — the badge is private until the member shares.
		do_action( 'wb_gam_badge_awarded', $user, array( 'name' => 'Top Contributor', 'is_credential' => 1 ), 'top-contributor' );
		$this->assertSame( 0, $published(), 'awarding must not broadcast before the member shares' );

		// Sharing broadcasts it.
		do_action( 'wb_gam_badge_shared', $user, 'top-contributor' );
		$this->assertSame( 1, $published(), 'sharing a credential badge broadcasts a feed card' );
		$shared_id = $card_id();
		$this->assertGreaterThan( 0, $shared_id );

		// Unsharing withdraws it reversibly: hidden from feeds but the row is preserved.
		do_action( 'wb_gam_badge_unshared', $user, 'top-contributor' );
		$this->assertSame( 0, $published(), 'unsharing withdraws the card from every feed' );
		$this->assertSame( $shared_id, $card_id(), 'the card row is preserved on unshare, not deleted' );

		// Re-sharing restores the SAME card, never a duplicate.
		do_action( 'wb_gam_badge_shared', $user, 'top-contributor' );
		$this->assertSame( 1, $published(), 're-sharing restores the card' );
		$this->assertSame( $shared_id, $card_id(), 're-share brings back the same card, no duplicate' );
	}

	/**
	 * A deleted badge definition removes every holder's shared-badge card.
	 *
	 * @return void
	 */
	public function test_deleted_badge_removes_the_shared_cards(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$GLOBALS['wb_gam_test']['badges'][ $user ] = array(
			array(
				'id'            => 'veteran',
				'name'          => 'Veteran',
				'is_credential' => true,
			),
		);
		do_action( 'wb_gam_badge_shared', $user, 'veteran' );
		$count = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'badge'", $user ) );
		$this->assertSame( 1, $count() );

		do_action( 'wb_gam_badge_deleted', 'veteran', array( $user ), array() );
		$this->assertSame( 0, $count(), 'the card of a deleted badge is removed' );
	}

	/**
	 * A non-credential badge does not broadcast to the feed, even when shared.
	 */
	public function test_non_credential_shared_badge_posts_no_activity(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$GLOBALS['wb_gam_test']['badges'][ $user ] = array(
			array(
				'id'            => 'first-login',
				'name'          => 'First Login',
				'is_credential' => false,
			),
		);

		do_action( 'wb_gam_badge_shared', $user, 'first-login' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'badge'", $user )
		);
		$this->assertSame( 0, $count, 'only credential badges broadcast to the feed, even on share' );
	}

	/**
	 * The transient cooldown skip toast is dropped so members are never nagged for acting quickly.
	 */
	public function test_cooldown_skip_toast_is_suppressed(): void {
		$event = apply_filters(
			'wb_gam_toast_data',
			array(
				'type'    => 'skip',
				'reason'  => 'cooldown',
				'message' => "You're on cooldown for this action - try again in a bit.",
			),
			123
		);
		$this->assertSame( array(), $event, 'a cooldown skip toast is dropped so members are never nagged for acting too fast' );
	}

	/**
	 * Daily/weekly cap skip toasts are informative (a real limit that resets) and pass through.
	 */
	public function test_cap_skip_toast_is_suppressed(): void {
		$cap = array(
			'type'    => 'skip',
			'reason'  => 'daily_cap',
			'message' => 'daily limit',
		);
		$this->assertSame(
			array(),
			apply_filters( 'wb_gam_toast_data', $cap, 123 ),
			'a capped member successfully performed the action; the only thing that did not happen is an invisible points increment, so there is nothing to interrupt them about'
		);
	}

	/**
	 * The weekly cap is silenced on the same reasoning as the daily one.
	 */
	public function test_weekly_cap_skip_toast_is_suppressed(): void {
		$cap = array(
			'type'    => 'skip',
			'reason'  => 'weekly_cap',
			'message' => 'weekly limit',
		);
		$this->assertSame( array(), apply_filters( 'wb_gam_toast_data', $cap, 123 ) );
	}

	/**
	 * Positive award toasts are never touched.
	 */
	public function test_positive_points_toast_passes_through(): void {
		$points = array(
			'type'    => 'points',
			'message' => '+10 points',
		);
		$this->assertSame( $points, apply_filters( 'wb_gam_toast_data', $points, 123 ), 'positive award toasts are never touched' );
	}

	/**
	 * Silent is the default, not the law. An owner whose community genuinely wants
	 * cap feedback can switch a specific reason back on — and the filter carries the
	 * reason so they can re-enable one without re-enabling all three.
	 */
	public function test_skip_toast_can_be_reenabled_by_filter(): void {
		$only_caps = static fn( $show, $event, $user_id, $reason ) => 'daily_cap' === $reason;
		add_filter( 'buddynext_gamification_show_skip_toast', $only_caps, 10, 4 );

		$cap = array(
			'type'    => 'skip',
			'reason'  => 'daily_cap',
			'message' => 'daily limit',
		);
		$this->assertSame( $cap, apply_filters( 'wb_gam_toast_data', $cap, 123 ), 'the owner switched the daily cap notice back on' );

		$cooldown = array(
			'type'    => 'skip',
			'reason'  => 'cooldown',
			'message' => 'cooldown',
		);
		$this->assertSame( array(), apply_filters( 'wb_gam_toast_data', $cooldown, 123 ), 'the other reasons stay silent — the filter is per-reason' );

		remove_filter( 'buddynext_gamification_show_skip_toast', $only_caps, 10 );
	}

	/**
	 * BuddyNext profile privacy decides who sees a member's standing, through
	 * wb_gam_can_view_public_profile, whatever gamification's own switches say.
	 */
	public function test_profile_privacy_decides_standing_visibility(): void {
		$owner   = self::factory()->user->create();
		$viewer  = self::factory()->user->create();
		$privacy = buddynext_service( 'privacy' );

		$privacy->set_preference( $owner, 'profile_visibility', 'public' );
		$this->assertTrue( apply_filters( 'wb_gam_can_view_public_profile', false, $owner, $viewer ), 'public profile: visible even when gamification said no' );
		$this->assertTrue( GamificationBridge::can_view_standing( $owner, 0 ), 'public profile: visible to a visitor' );

		$privacy->set_preference( $owner, 'profile_visibility', 'private' );
		$this->assertFalse( apply_filters( 'wb_gam_can_view_public_profile', true, $owner, $viewer ), 'private profile: hidden even when gamification said yes' );
		$this->assertFalse( GamificationBridge::can_view_standing( $owner, 0 ), 'private profile: hidden from a visitor' );
		$this->assertTrue( GamificationBridge::can_view_standing( $owner, $owner ), 'a member always sees their own standing' );
		$this->assertFalse( GamificationBridge::can_view_standing( 0, $viewer ), 'no member, nothing to show' );
	}

	/**
	 * A WB Gamification bell row opens on the member's own profile tab for it; the plugin
	 * links every row to the profile front page, and a reward keeps its own hub link.
	 */
	public function test_gamification_bell_rows_open_the_matching_profile_tab(): void {
		$member = self::factory()->user->create();
		wp_set_current_user( $member );
		$profile = trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $member ) );
		$plugin  = 'http://example.org/members/somebody/';

		foreach ( array(
			'wb_gamification.badge_awarded'       => 'achievements/',
			'wb_gamification.level_up'            => 'achievements/',
			'wb_gamification.challenge_completed' => 'achievements/',
			'wb_gamification.credential_expired'  => 'achievements/',
			'wb_gamification.streak_milestone'    => 'achievements/',
			'wb_gamification.kudos_received'      => 'kudos/',
			'wb_gamification.personal_record'     => 'points/',
		) as $type => $tab ) {
			$this->assertSame( $profile . $tab, $this->bridge->filter_notification_url( $plugin, $type, 0, 0, array() ), $type );
		}

		$this->assertSame( $plugin, $this->bridge->filter_notification_url( $plugin, 'wb_gamification.reward_fulfilled', 0, 0, array() ), 'A reward keeps the plugin hub link.' );
		$this->assertSame( $plugin, $this->bridge->filter_notification_url( $plugin, 'jetonomy.reply_to_post', 0, 0, array() ), 'Another plugin is left alone.' );

		wp_set_current_user( 0 );
		$this->assertSame( $plugin, $this->bridge->filter_notification_url( $plugin, 'wb_gamification.kudos_received', 0, 0, array() ), 'No member, no tab.' );
	}

	/**
	 * Toasts are held on the sign-up/verify (auth) and onboarding hubs, not on
	 * ordinary pages, and another host's hold is kept.
	 */
	public function test_toasts_are_held_on_entry_screens_only(): void {
		set_query_var( 'bn_hub', 'auth' );
		$this->assertTrue( $this->bridge->hold_toasts_on_entry_screens( false ) );
		set_query_var( 'bn_hub', 'onboarding' );
		$this->assertTrue( $this->bridge->hold_toasts_on_entry_screens( false ) );
		set_query_var( 'bn_hub', 'feed' );
		$this->assertFalse( $this->bridge->hold_toasts_on_entry_screens( false ) );
		$this->assertTrue( $this->bridge->hold_toasts_on_entry_screens( true ), 'another host holding is kept' );
		set_query_var( 'bn_hub', '' );
	}
}
