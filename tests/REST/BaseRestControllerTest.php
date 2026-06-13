<?php
/**
 * Tests for BaseRestController permission helpers.
 *
 * @package BuddyNext\Tests\REST
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\REST\BaseRestController;
use WP_Error;

/**
 * @covers \BuddyNext\REST\BaseRestController
 */
class BaseRestControllerTest extends \WP_UnitTestCase {

	private BaseRestController $controller;

	public function set_up(): void {
		parent::set_up();
		// Anonymous concrete subclass — BaseRestController is abstract.
		$this->controller = new class() extends BaseRestController {
			public function register_routes(): void {}
		};
	}

	public function test_require_auth_returns_error_for_logged_out(): void {
		wp_set_current_user( 0 );
		$result = $this->controller->require_auth();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_require_auth_returns_true_for_logged_in(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertTrue( $this->controller->require_auth() );
	}

	public function test_require_admin_returns_error_for_non_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = $this->controller->require_admin();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_require_admin_returns_true_for_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $this->controller->require_admin() );
	}
}
