<?php
/**
 * Tests for the POST /auth/change-password REST endpoint.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Auth\AuthController::change_password
 */
class AuthControllerPasswordTest extends \WP_Test_REST_TestCase {

	private int $user_id;
	private string $current_password = 'OldP@ssw0rd!';

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->user_id = self::factory()->user->create(
			array(
				'user_pass'  => $this->current_password,
				'user_login' => 'pwtester' . uniqid(),
			)
		);
		wp_set_current_user( $this->user_id );
	}

	private function post( array $body ) {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/change-password' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	public function test_happy_path_changes_password_and_returns_200(): void {
		$response = $this->post(
			array(
				'current_password' => $this->current_password,
				'new_password'     => 'NewP@ssw0rd!',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['saved'] );

		// New password must verify; the old one must not.
		$fresh = get_userdata( $this->user_id );
		$this->assertTrue( wp_check_password( 'NewP@ssw0rd!', $fresh->user_pass, $this->user_id ) );
		$this->assertFalse( wp_check_password( $this->current_password, $fresh->user_pass, $this->user_id ) );
	}

	public function test_wrong_current_password_returns_422(): void {
		$response = $this->post(
			array(
				'current_password' => 'NotMyPassword',
				'new_password'     => 'NewP@ssw0rd!',
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$body = $response->get_data();
		$this->assertFalse( $body['saved'] );
		$this->assertArrayHasKey( 'current_password', $body['errors'] );
	}

	public function test_same_as_current_password_returns_422(): void {
		$response = $this->post(
			array(
				'current_password' => $this->current_password,
				'new_password'     => $this->current_password,
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$body = $response->get_data();
		$this->assertFalse( $body['saved'] );
		$this->assertArrayHasKey( 'new_password', $body['errors'] );
	}

	public function test_short_new_password_returns_422(): void {
		$response = $this->post(
			array(
				'current_password' => $this->current_password,
				'new_password'     => 'short',
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayHasKey( 'new_password', $body['errors'] );
	}

	public function test_unauthenticated_returns_401(): void {
		wp_set_current_user( 0 );
		$response = $this->post(
			array(
				'current_password' => $this->current_password,
				'new_password'     => 'NewP@ssw0rd!',
			)
		);
		$this->assertSame( 401, $response->get_status() );
	}
}
