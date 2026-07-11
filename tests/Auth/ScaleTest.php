<?php
/**
 * Big-site readiness of the signup/auth surfaces.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Core\Installer;

/**
 * The fleet baseline is 100k members per site. These surfaces did not hold.
 *
 * @covers \BuddyNext\Auth\SocialLogin
 * @covers \BuddyNext\Admin\Members\ApprovalManager
 */
class ScaleTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema per case.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * unique_login() used to be `while ( username_exists( $login ) )` — one query
	 * per collision, unbounded, growing with the site. Corporate domains collide
	 * hard on the SAME local part (info@, admin@, contact@), so a community with N
	 * members called info* paid N queries on the next info@ signup.
	 *
	 * It must now cost ONE query regardless of how deep the collision runs.
	 */
	public function test_unique_login_costs_one_query_however_deep_the_collision(): void {
		global $wpdb;

		// A realistic corporate pile-up: info, info2 ... info11 all taken.
		self::factory()->user->create( array( 'user_login' => 'info' ) );
		for ( $i = 2; $i <= 11; $i++ ) {
			self::factory()->user->create( array( 'user_login' => 'info' . $i ) );
		}

		$social = new \BuddyNext\Auth\SocialLogin();

		$method = new \ReflectionMethod( $social, 'unique_login' );
		$method->setAccessible( true );

		$before = $wpdb->num_queries;
		$login  = $method->invoke( $social, 'info@acme.com' );
		$cost   = $wpdb->num_queries - $before;

		$this->assertSame( 'info12', $login, 'it must still pick the next free suffix' );

		// One username_exists() + one LIKE sweep. The old loop would have cost ~12.
		$this->assertLessThanOrEqual(
			3,
			$cost,
			"unique_login must not scale with the number of collisions (cost {$cost} queries)"
		);
	}

	/**
	 * A fresh local part still gets the bare username, no suffix.
	 */
	public function test_an_uncontested_login_is_used_as_is(): void {
		$social = new \BuddyNext\Auth\SocialLogin();

		$method = new \ReflectionMethod( $social, 'unique_login' );
		$method->setAccessible( true );

		$this->assertSame( 'brandnew', $method->invoke( $social, 'brandnew@acme.com' ) );
	}

	/**
	 * The approval queue must report a TOTAL, not just show a truncated page. It
	 * used to be a hard 'number' => 200 with no count and no pagination: on a
	 * large community under a spam wave the owner saw 200 of an unknown number,
	 * with no way to reach the rest.
	 */
	public function test_the_approval_queue_reports_a_total_beyond_one_page(): void {
		update_option( 'buddynext_reg_mode', 'approval' );

		// 60 pending members — more than one 50-member page.
		for ( $i = 0; $i < 60; $i++ ) {
			$id = self::factory()->user->create();
			update_user_meta( $id, 'bn_pending_approval', '1' );
		}

		$manager = new \BuddyNext\Admin\Members\ApprovalManager();

		$count = new \ReflectionMethod( $manager, 'pending_count' );
		$count->setAccessible( true );
		$this->assertSame( 60, $count->invoke( $manager ), 'the owner must be told the real size of the queue' );

		$page_of = new \ReflectionMethod( $manager, 'pending_users' );
		$page_of->setAccessible( true );

		$this->assertCount( 50, $page_of->invoke( $manager, 1 ) );
		$this->assertCount( 10, $page_of->invoke( $manager, 2 ), 'page 2 must be reachable' );
	}
}
