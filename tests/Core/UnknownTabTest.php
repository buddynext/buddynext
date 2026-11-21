<?php
/**
 * A made-up profile or Space tab address is a 404, not the default page.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;

/**
 * Unknown tab gate.
 *
 * @covers \BuddyNext\Core\PageRouter::is_unknown_tab
 */
class UnknownTabTest extends \WP_UnitTestCase {

	/**
	 * Run the gate for a profile tab as a guest.
	 *
	 * @param int    $user_id Profile owner.
	 * @param string $tab     Tab slug in the address.
	 * @return bool
	 */
	private function profile_unknown( int $user_id, string $tab ): bool {
		set_query_var( 'bn_profile_action', $tab );
		$method = new \ReflectionMethod( PageRouter::class, 'is_unknown_tab' );
		$method->setAccessible( true );
		return (bool) $method->invoke( new PageRouter(), 'people', array( 'user_id' => $user_id ) );
	}

	/**
	 * Made-up tab is unknown; no tab, edit and a real tab are not.
	 *
	 * @return void
	 */
	public function test_made_up_profile_tab_is_unknown(): void {
		$user = self::factory()->user->create();

		$this->assertTrue( $this->profile_unknown( $user, 'zzzz' ) );
		$this->assertFalse( $this->profile_unknown( $user, '' ) );
		$this->assertFalse( $this->profile_unknown( $user, 'edit' ) );
		$this->assertFalse( $this->profile_unknown( $user, 'followers' ) );
	}

	/**
	 * Space: made-up tab unknown, core tab known, old Blog address kept.
	 *
	 * @return void
	 */
	public function test_space_tabs(): void {
		$method = new \ReflectionMethod( PageRouter::class, 'is_unknown_tab' );
		$method->setAccessible( true );
		$run = function ( string $tab ) use ( $method ): bool {
			set_query_var( 'bn_space_action', $tab );
			return (bool) $method->invoke( new PageRouter(), 'spaces', array( 'space_id' => 987654 ) );
		};

		$this->assertTrue( $run( 'zzzz' ) );
		$this->assertFalse( $run( '' ) );
		$this->assertFalse( $run( 'members' ) );

		add_filter( 'buddynext_route_tab_exists', static fn( $k, $s, $t ) => $k || 'blog' === $t, 10, 3 );
		$this->assertFalse( $run( 'blog' ) );
	}
}
