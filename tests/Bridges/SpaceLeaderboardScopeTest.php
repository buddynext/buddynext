<?php
/**
 * A space leaderboard lists only that space's members, and only when it may.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\GamificationBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceFieldRegistry;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * Card 10313407174: GamificationBridge answers wb-gamification's
 * `wb_gam_leaderboard_scope_user_ids` for scope `bn_space`. Ranking stays on
 * site-wide points; the space only limits who is listed. The engine treats an
 * empty list as an empty board, so "off" and "not allowed to see the roster"
 * must both return nothing - otherwise the public leaderboard REST route would
 * list a private space's members.
 *
 * @covers \BuddyNext\Bridges\GamificationBridge::space_scope_user_ids
 * @covers \BuddyNext\Bridges\GamificationBridge::space_leaderboard_on
 */
class SpaceLeaderboardScopeTest extends \WP_UnitTestCase {

	/**
	 * Bridge under test.
	 *
	 * @var GamificationBridge
	 */
	private GamificationBridge $bridge;

	/**
	 * Space owner.
	 *
	 * @var int
	 */
	private int $owner = 0;

	/**
	 * A member of both spaces.
	 *
	 * @var int
	 */
	private int $member = 0;

	/**
	 * Stub the engine class the availability check looks for, when the plugin is absent.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( ! class_exists( '\\WBGam\\Engine\\LeaderboardEngine' ) ) {
			// Safe: a fixed string defining an empty stub class, only in the test run, so the
			// availability check (class_exists) passes without the partner plugin installed.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub of an optional partner class.
			eval( 'namespace WBGam\\Engine; class LeaderboardEngine { public static function get_leaderboard_page() { return array(); } }' );
		}
	}

	/**
	 * Schema, an owner and a member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->bridge = new GamificationBridge();
		$this->owner  = self::factory()->user->create();
		$this->member = self::factory()->user->create();
	}

	/**
	 * Create a space with the member joined, and the tab switched on or off.
	 *
	 * @param string $type Space type.
	 * @param bool   $on   Leaderboard tab switch.
	 * @return int Space ID.
	 */
	private function space( string $type, bool $on ): int {
		$slug = 'board-' . $type . '-' . strtolower( wp_generate_password( 6, false ) );
		$id   = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => $slug,
				'slug' => $slug,
				'type' => $type,
			)
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : 'space' );
		wp_set_current_user( $this->member );
		$joined = ( new SpaceMemberService() )->join( $id, $this->member );
		if ( true !== $joined ) {
			// A private space queues a join request; approve it as the owner.
			( new SpaceMemberService() )->approve_request( $id, $this->owner, $this->member );
		}
		SpaceFieldRegistry::instance()->save_for_space( $id, array( 'gamification_leaderboard_tab' => $on ? '1' : '0' ), $this->owner );
		return $id;
	}

	/**
	 * Resolve the scope as the engine would, for a viewer.
	 *
	 * @param int $space_id Space.
	 * @param int $viewer   Viewer (0 = logged out).
	 * @return int[]
	 */
	private function resolve( int $space_id, int $viewer ): array {
		wp_set_current_user( $viewer );
		$ids = (array) $this->bridge->space_scope_user_ids( array(), GamificationBridge::SPACE_SCOPE, $space_id );
		sort( $ids );
		return $ids;
	}

	/**
	 * An open space with the tab on lists exactly its members, to anyone.
	 *
	 * @return void
	 */
	public function test_open_space_lists_its_members(): void {
		$id       = $this->space( 'open', true );
		$expected = array( $this->owner, $this->member );
		sort( $expected );

		$this->assertSame( $expected, $this->resolve( $id, 0 ) );
		$this->assertNotContains( self::factory()->user->create(), $this->resolve( $id, 0 ) );
	}

	/**
	 * With the space owner's switch off, the board is empty (never site-wide).
	 *
	 * @return void
	 */
	public function test_switched_off_space_resolves_to_nobody(): void {
		$this->assertSame( array(), $this->resolve( $this->space( 'open', false ), $this->member ) );
	}

	/**
	 * A private space's board follows its member list: members see it, visitors get nothing.
	 *
	 * @return void
	 */
	public function test_private_space_is_hidden_from_non_members(): void {
		$id = $this->space( 'private', true );

		$this->assertSame( array(), $this->resolve( $id, 0 ), 'Logged out.' );
		$this->assertSame( array(), $this->resolve( $id, self::factory()->user->create() ), 'Logged-in non-member.' );
		$this->assertContains( $this->member, $this->resolve( $id, $this->member ) );
	}

	/**
	 * Other scope types pass through untouched.
	 *
	 * @return void
	 */
	public function test_other_scopes_are_left_alone(): void {
		$this->assertSame( array( 7, 9 ), $this->bridge->space_scope_user_ids( array( 7, 9 ), 'bp_group', 3 ) );
	}
}
