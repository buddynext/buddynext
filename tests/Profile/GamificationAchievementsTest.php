<?php
/**
 * Tests for the gamification Achievements profile tab.
 *
 * Gamification is a CORE integration → its own prominent profile tab (like
 * Discussions), NOT the Portfolio panel. The tab shows a badge grid
 * (credential-first) + a standing strip (points/level/streak). Data is driven
 * through the $GLOBALS['wb_gam_test'] store stubbed in tests/bootstrap.php.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\GamificationAchievements;

/**
 * @covers \BuddyNext\Profile\GamificationAchievements
 */
class GamificationAchievementsTest extends \WP_UnitTestCase {

	private GamificationAchievements $tab;
	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		$this->tab       = new GamificationAchievements();
		$this->member_id = self::factory()->user->create();
		// Reset the gamification stub store.
		$GLOBALS['wb_gam_test'] = array(
			'actions' => array(),
			'points'  => array(),
			'level'   => array(),
			'badges'  => array(),
			'streak'  => array(),
			'rank'    => array(),
		);
	}

	private function set_badges( array $badges ): void {
		$GLOBALS['wb_gam_test']['badges'][ $this->member_id ] = $badges;
	}

	private function render(): string {
		ob_start();
		$this->tab->render_panel( array( 'profile_user_id' => $this->member_id ) );
		return (string) ob_get_clean();
	}

	/**
	 * Resolve the Achievements primary tab for this member via the unified Nav API
	 * (the tab is now registered through buddynext_register_nav, not the old
	 * add_tab() filter), or null when its condition gate hides it.
	 *
	 * @return \BuddyNext\Nav\NavItem|null
	 */
	private function achievements_item(): ?\BuddyNext\Nav\NavItem {
		$registry = \BuddyNext\Nav\NavRegistry::instance();
		$registry->reset();
		remove_all_actions( 'buddynext_register_nav' );
		$this->tab->register_nav( $registry );
		$out = $registry->resolve( new \BuddyNext\Nav\NavContext( 'profile', $this->member_id, $this->member_id ) );
		foreach ( $out->layer( 'primary' ) as $item ) {
			if ( 'achievements' === $item->id ) {
				return $item;
			}
		}
		return null;
	}

	public function test_tab_added_when_member_has_badges(): void {
		$this->set_badges( array( array( 'id' => 'champ', 'name' => 'Champion', 'is_credential' => 1, 'earned_at' => '2026-01-01' ) ) );

		$item = $this->achievements_item();
		$this->assertNotNull( $item );
		$this->assertSame( 'achievements', $item->id );
		$this->assertSame( 1, (int) $item->count_value );
	}

	public function test_tab_added_when_member_has_points_but_no_badges(): void {
		$GLOBALS['wb_gam_test']['points'][ $this->member_id ] = 120;

		$this->assertNotNull( $this->achievements_item(), 'standing alone earns the tab' );
	}

	public function test_no_tab_for_member_with_no_standing(): void {
		$this->assertNull( $this->achievements_item(), 'brand-new member gets no empty Achievements tab' );
	}

	public function test_panel_lists_badges_credential_first(): void {
		$this->set_badges(
			array(
				array( 'id' => 'participant', 'name' => 'Participant', 'is_credential' => 0, 'earned_at' => '2026-02-01' ),
				array( 'id' => 'champ', 'name' => 'Champion', 'is_credential' => 1, 'earned_at' => '2026-01-01' ),
			)
		);

		$html = $this->render();
		$this->assertStringContainsString( 'data-tab-panel="achievements"', $html );
		$this->assertStringContainsString( 'Champion', $html );
		$this->assertStringContainsString( 'Participant', $html );
		$this->assertLessThan(
			strpos( $html, 'Participant' ),
			strpos( $html, 'Champion' ),
			'credential badge renders before the participation badge'
		);
	}

	public function test_badge_links_to_public_share_url(): void {
		$this->set_badges( array( array( 'id' => 'champ', 'name' => 'Champion', 'is_credential' => 1, 'earned_at' => '2026-01-01' ) ) );

		$expected = home_url( 'gamification/badge/champ/' . $this->member_id . '/share/' );
		$this->assertStringContainsString( esc_url( $expected ), $this->render() );
	}

	public function test_panel_shows_standing_strip(): void {
		$this->set_badges( array( array( 'id' => 'champ', 'name' => 'Champion', 'is_credential' => 1, 'earned_at' => '2026-01-01' ) ) );
		$GLOBALS['wb_gam_test']['points'][ $this->member_id ] = 340;
		$GLOBALS['wb_gam_test']['level'][ $this->member_id ]  = array( 'id' => 3, 'name' => 'Trailblazer', 'min_points' => 300 );
		$GLOBALS['wb_gam_test']['streak'][ $this->member_id ] = array( 'current_streak' => 7, 'longest_streak' => 9, 'last_active' => '2026-06-14' );
		$GLOBALS['wb_gam_test']['rank'][ $this->member_id ]   = 4;

		$html = $this->render();
		$this->assertStringContainsString( '340', $html );
		$this->assertStringContainsString( 'Trailblazer', $html );
		$this->assertStringContainsString( '7', $html );
		$this->assertStringContainsString( '#4', $html, 'leaderboard rank shown' );
	}

	public function test_rank_omitted_when_unranked(): void {
		$this->set_badges( array( array( 'id' => 'champ', 'name' => 'Champion', 'is_credential' => 1, 'earned_at' => '2026-01-01' ) ) );
		// No rank set → engine stub returns 0 → no Rank tile.

		$this->assertStringNotContainsString( 'Rank', $this->render() );
	}
}

namespace WBGam\Engine;

if ( ! class_exists( 'WBGam\\Engine\\LeaderboardEngine' ) ) {
	/**
	 * Test stub for WB Gamification's leaderboard engine — the plugin is not
	 * loaded in the BN harness. Reads rank from the $GLOBALS['wb_gam_test'] store.
	 */
	class LeaderboardEngine {
		/**
		 * @param int    $user_id    User.
		 * @param string $period     Period.
		 * @param string $scope_type Scope type.
		 * @param int    $scope_id   Scope id.
		 * @param string $point_type Point type.
		 * @return array<string,mixed>
		 */
		public static function get_user_rank( int $user_id, string $period = 'all', string $scope_type = '', int $scope_id = 0, string $point_type = '' ): array {
			$rank = (int) ( $GLOBALS['wb_gam_test']['rank'][ $user_id ] ?? 0 );
			return array( 'rank' => $rank, 'points' => 0, 'points_to_next' => null );
		}
	}
}
