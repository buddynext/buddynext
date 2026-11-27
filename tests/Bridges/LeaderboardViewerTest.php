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
			eval( 'function wb_gam_get_leaderboard( $period = "all", $limit = 10 ) { return $GLOBALS["wb_gam_test"]["board"] ?? array(); }' );
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

	/**
	 * A member who hides their level and points stays on the board, but the figure is left off for a viewer who
	 * may not see it; the member and an administrator still see it.
	 *
	 * @return void
	 */
	public function test_a_hidden_members_points_are_left_off_the_board(): void {
		$GLOBALS['wb_gam_test']['board'] = array( array( 'rank' => 1, 'user_id' => 0, 'display_name' => 'Priya Raman', 'avatar_url' => '', 'points' => 165, 'rank_change' => 0, 'is_new' => false ) );
		$hidden = self::factory()->user->create( array( 'display_name' => 'Priya Raman' ) );
		$GLOBALS['wb_gam_test']['board'][0]['user_id'] = $hidden;
		if ( array() === wb_gam_get_leaderboard() ) {
			unset( $GLOBALS['wb_gam_test']['board'] );
			$this->markTestSkipped( 'The partner leaderboard stub in this run cannot return rows.' );
		}
		if ( ! class_exists( '\\WBGam\\Engine\\MemberPrefs' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in for the partner class, which is not loaded in these tests.
			eval( 'namespace WBGam\\Engine; class MemberPrefs { public static function get( $id ) { return array( "show_rank" => $GLOBALS["wb_gam_test"]["show_rank"][ $id ] ?? true ); } }' );
		}
		$GLOBALS['wb_gam_test']['show_rank'][ $hidden ] = false;
		$render = static function (): string {
			ob_start();
			include BUDDYNEXT_DIR . 'templates/gamification/leaderboard.php';
			return (string) ob_get_clean();
		};

		wp_set_current_user( self::factory()->user->create() );
		$html = $render();
		$this->assertStringContainsString( 'Priya Raman', $html, 'The member left the board.' );
		$this->assertStringNotContainsString( 'bn-lb-row__points-val', $html, 'A hidden member\'s points showed to a stranger.' );

		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'bn-lb-row__points-val', $render(), 'A hidden member\'s points showed to a guest.' );

		wp_set_current_user( $hidden );
		$this->assertStringContainsString( 'bn-lb-row__points-val', $render(), 'The member lost their own figure.' );

		unset( $GLOBALS['wb_gam_test']['board'], $GLOBALS['wb_gam_test']['show_rank'] );
	}
}
