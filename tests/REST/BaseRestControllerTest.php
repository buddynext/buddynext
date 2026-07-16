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

			/**
			 * Expose the protected status helper for testing.
			 *
			 * @param \WP_Error $error    Error.
			 * @param int       $fallback Status when the service set none.
			 * @return \WP_Error
			 */
			public function preserve( \WP_Error $error, int $fallback ): \WP_Error {
				return $this->preserve_status( $error, $fallback );
			}
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

	public function test_require_moderator_gates_on_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->controller->require_moderator() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $this->controller->require_moderator() );
	}

	/**
	 * A status the service set must survive the controller.
	 *
	 * WP_Error::add_data() REPLACES the data array for a code rather than merging
	 * (class-wp-error.php: `$this->error_data[ $code ] = $data`), so the
	 * natural-looking `$result->add_data( array( 'status' => 400 ) )` silently
	 * overwrote whatever the service decided. 19 controller sites did exactly that,
	 * so a suspension (403) and a rate limit (429) both reached the client as a flat
	 * 400 — a caller could not tell "you are suspended" from "your input was
	 * malformed", and a native client cannot back off on a 429 it never sees.
	 *
	 * The suite was green throughout, because nothing asserted a status that a
	 * service rather than a controller had set.
	 *
	 * @return void
	 */
	public function test_preserve_status_keeps_a_status_the_service_set(): void {
		$suspended = new \WP_Error( 'suspended', 'You are suspended.', array( 'status' => 403 ) );
		$this->assertSame(
			403,
			$this->controller->preserve( $suspended, 400 )->get_error_data()['status'],
			'A 403 must survive; flattening it is how "you are suspended" became "bad request".'
		);

		$rate_limited = new \WP_Error( 'rate_limited', 'Slow down.', array( 'status' => 429 ) );
		$this->assertSame(
			429,
			$this->controller->preserve( $rate_limited, 400 )->get_error_data()['status'],
			'A 429 must survive, or a native client never learns to back off.'
		);
	}

	/**
	 * The default applies only when the service expressed no opinion.
	 *
	 * @return void
	 */
	public function test_preserve_status_defaults_only_when_absent(): void {
		$opinionless = new \WP_Error( 'invalid', 'Bad input.' );

		$this->assertSame(
			400,
			$this->controller->preserve( $opinionless, 400 )->get_error_data()['status']
		);
	}

	/**
	 * Preserving the status must not discard the rest of the payload.
	 *
	 * The add_data() call replaces the whole array for a code, so a careless fix
	 * would drop fields the client needs alongside the status.
	 *
	 * @return void
	 */
	public function test_preserve_status_does_not_drop_other_error_data(): void {
		$rich = new \WP_Error(
			'rate_limited',
			'Slow down.',
			array(
				'status'      => 429,
				'retry_after' => 60,
			)
		);

		$data = $this->controller->preserve( $rich, 400 )->get_error_data();

		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 60, $data['retry_after'] ?? null );
	}
}
