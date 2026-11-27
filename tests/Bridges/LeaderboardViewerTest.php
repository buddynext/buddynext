<?php
/**
 * The leaderboard's "you" parts belong to a signed-in member, and a Space board's
 * position belongs to that Space's members.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\GamificationBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Bridges\GamificationBridge::viewer_rank
 */
class LeaderboardViewerTest extends \WP_UnitTestCase {

	/**
	 * Stub the site board read the template guards on, when the plugin is absent.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( ! function_exists( 'wb_gam_get_leaderboard' ) ) {
			// Safe: a fixed string defining an empty stub, only in the test run.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub of an optional partner function.
			eval( 'function wb_gam_get_leaderboard( $period = "all", $limit = 10 ) { return array(); }' );
		}
	}

	/**
	 * Schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Someone who is not in the Space has no place on its board; the site board keeps the site rank.
	 *
	 * @return void
	 */
	public function test_a_non_member_has_no_position_on_a_space_board(): void {
		$owner    = self::factory()->user->create();
		$outsider = self::factory()->user->create();
		$space    = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Design critique',
				'slug' => 'design-critique-' . $owner,
				'type' => 'open',
			)
		);
		$this->assertIsInt( $space );
		$GLOBALS['wb_gam_test']['rank'][ $outsider ] = 7;

		$this->assertSame( array(), GamificationBridge::viewer_rank( $outsider, 'all', $space ), 'no site rank on a Space board' );
		$this->assertSame( 7, GamificationBridge::viewer_rank( $outsider, 'all' )['rank'] ?? 0, 'the site board still shows it' );
		$this->assertSame( array(), GamificationBridge::viewer_rank( 0, 'all' ), 'a visitor has no position' );
	}

	/**
	 * A visitor on the site board sees no "Your Badges" or "Your Streak" widgets.
	 *
	 * @return void
	 */
	public function test_a_visitor_sees_no_your_widgets(): void {
		wp_set_current_user( 0 );
		ob_start();
		include BUDDYNEXT_DIR . 'templates/gamification/leaderboard.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bn-lb-shell', $html );
		$this->assertStringNotContainsString( 'Your Badges', $html );
		$this->assertStringNotContainsString( 'Your Streak', $html );
	}
}
