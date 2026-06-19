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
 * @covers \BuddyNext\Bridges\GamificationBridge
 */
class WBGamificationBridgeTest extends \WP_UnitTestCase {

	private GamificationBridge $bridge;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		// Plugin class and function stubs are registered in tests/bootstrap.php.
		$this->bridge = new GamificationBridge();
		$this->bridge->init();
	}

	public function test_credential_badge_awarded_posts_feed_activity(): void {
		global $wpdb;
		$user = self::factory()->user->create();

		do_action(
			'wb_gam_badge_awarded',
			$user,
			array( 'name' => 'Top Contributor', 'is_credential' => 1 ),
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

	public function test_non_credential_badge_posts_no_activity(): void {
		global $wpdb;
		$user = self::factory()->user->create();

		do_action(
			'wb_gam_badge_awarded',
			$user,
			array( 'name' => 'First Login', 'is_credential' => 0 ),
			'first-login'
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'link'", $user )
		);
		$this->assertSame( 0, $count, 'only credential badges broadcast to the feed' );
	}
}
