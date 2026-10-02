<?php
/**
 * An add-on's account hold applies over REST with the routes it leaves open.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\MemberHold;
use BuddyNext\Auth\RestHoldGate;
use BuddyNext\Core\Installer;
use BuddyNext\REST\Router;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The `buddynext_member_hold` seam: Free knows nothing about why a member is
 * held (Pro uses it for "Paying members only"), only how to enforce it like its
 * own holds.
 *
 * @covers \BuddyNext\Auth\MemberHold
 * @covers \BuddyNext\Auth\RestHoldGate
 */
class MemberHoldTest extends \WP_UnitTestCase {

	/**
	 * Boot REST with the gate wired.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new Router() )->register();
		( new RestHoldGate() )->register();
		do_action( 'rest_api_init' );
	}

	/**
	 * Drop the hold.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_member_hold' );
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Hold every member, leaving the given routes open.
	 *
	 * @param string[] $routes Open route patterns.
	 * @return void
	 */
	private function hold_everyone( array $routes ): void {
		add_filter(
			'buddynext_member_hold',
			static fn() => array(
				'code'    => 'test_hold',
				'message' => 'Held for the test.',
				'url'     => home_url( '/held/' ),
				'hubs'    => array( 'settings' ),
				'routes'  => $routes,
			)
		);
	}

	/**
	 * A held member is refused community routes and keeps the open ones.
	 *
	 * @return void
	 */
	public function test_hold_refuses_all_but_open_routes(): void {
		$member = self::factory()->user->create();
		wp_set_current_user( $member );
		$this->hold_everyone( array( '/buddynext/v1/me/notification-prefs' ) );

		$refused = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/feed' ) );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'test_hold', $refused->as_error()->get_error_code() );
		$this->assertSame( home_url( '/held/' ), $refused->as_error()->get_error_data()['hold_url'] );

		$open = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/notification-prefs' ) );
		$this->assertNotSame( 'test_hold', $open->is_error() ? $open->as_error()->get_error_code() : '' );
	}

	/**
	 * Administrators are never held, and a hold without a destination is ignored.
	 *
	 * @return void
	 */
	public function test_admins_and_malformed_holds_pass(): void {
		$this->hold_everyone( array() );
		$this->assertNull( MemberHold::get( self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );

		remove_all_filters( 'buddynext_member_hold' );
		add_filter( 'buddynext_member_hold', static fn() => array( 'code' => 'no_url' ) );
		$this->assertNull( MemberHold::get( self::factory()->user->create() ) );
	}
}
