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
	 * A credential badge award broadcasts a feed activity (social proof).
	 */
	public function test_credential_badge_awarded_posts_feed_activity(): void {
		global $wpdb;
		$user = self::factory()->user->create();

		do_action(
			'wb_gam_badge_awarded',
			$user,
			array(
				'name'          => 'Top Contributor',
				'is_credential' => 1,
			),
			'top-contributor'
		);

		$url      = home_url( 'gamification/badge/top-contributor/' . $user . '/share/' );
		$activity = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'link' AND link_url = %s",
				$user,
				$url
			)
		);
		$this->assertSame( 1, $activity );
	}

	/**
	 * A non-credential badge does not broadcast to the feed.
	 */
	public function test_non_credential_badge_posts_no_activity(): void {
		global $wpdb;
		$user = self::factory()->user->create();

		do_action(
			'wb_gam_badge_awarded',
			$user,
			array(
				'name'          => 'First Login',
				'is_credential' => 0,
			),
			'first-login'
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'link'", $user )
		);
		$this->assertSame( 0, $count, 'only credential badges broadcast to the feed' );
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
	public function test_cap_skip_toast_passes_through(): void {
		$cap = array(
			'type'    => 'skip',
			'reason'  => 'daily_cap',
			'message' => 'daily limit',
		);
		$this->assertSame( $cap, apply_filters( 'wb_gam_toast_data', $cap, 123 ), 'only the cooldown nag is silenced; cap notices are kept' );
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
	 * Owners can opt the cooldown toast back on via the public filter.
	 */
	public function test_cooldown_toast_can_be_reenabled_by_filter(): void {
		add_filter( 'buddynext_gamification_show_cooldown_toast', '__return_true' );
		$event = array(
			'type'    => 'skip',
			'reason'  => 'cooldown',
			'message' => 'cooldown',
		);
		$this->assertSame( $event, apply_filters( 'wb_gam_toast_data', $event, 123 ), 'owners can opt the cooldown toast back on' );
		remove_filter( 'buddynext_gamification_show_cooldown_toast', '__return_true' );
	}
}
