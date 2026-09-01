<?php
/**
 * Deleting an account re-verifies the password.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * DELETE /me/account took an EMPTY body: no password, no confirmation token, no
 * re-auth. It returned 200 and the WordPress user was gone. The most destructive
 * action a member has asked for less than disabling 2FA on the same plugin, which
 * has always re-checked the password (Basecamp 10252058720).
 *
 * @covers \BuddyNext\Profile\ProfileController::delete_my_account
 */
class AccountDeletionRequiresPasswordTest extends \WP_UnitTestCase {

	private const PASSWORD = 'Correct-Horse-Battery-9!';

	private int $member;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		do_action( 'rest_api_init' );

		$this->member = self::factory()->user->create(
			array(
				'role'      => 'subscriber',
				'user_pass' => self::PASSWORD,
			)
		);
		wp_set_current_user( $this->member );
	}

	/**
	 * @param array<string,mixed> $params Body params.
	 * @return \WP_REST_Response
	 */
	private function delete_account( array $params = array() ) {
		$request = new WP_REST_Request( 'DELETE', '/buddynext/v1/me/account' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * @param int $user_id User.
	 * @return bool
	 */
	private function user_exists( int $user_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID = %d", $user_id ) ) > 0;
	}

	public function test_an_empty_body_does_not_delete_the_account(): void {
		$response = $this->delete_account();

		$this->assertSame( 400, $response->get_status(), 'A password-less delete must be refused.' );
		$this->assertSame( 'password_required', $response->get_data()['code'] ?? '' );
		$this->assertTrue( $this->user_exists( $this->member ), 'The account must still exist.' );
	}

	public function test_a_wrong_password_does_not_delete_the_account(): void {
		$response = $this->delete_account( array( 'password' => 'not-the-password' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'incorrect_password', $response->get_data()['code'] ?? '' );
		$this->assertTrue( $this->user_exists( $this->member ) );
	}

	/**
	 * The point of the guard is friction, not a lockout — the real flow must work.
	 *
	 * @return void
	 */
	public function test_the_correct_password_still_deletes_the_account(): void {
		$response = $this->delete_account( array( 'password' => self::PASSWORD ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( (bool) ( $response->get_data()['deleted'] ?? false ) );
		$this->assertFalse( $this->user_exists( $this->member ), 'A confirmed deletion must still delete.' );
	}

	/**
	 * An SSO-only community turns the requirement off; deletion must not be
	 * unreachable there. Registration reads the same filter, so the parameter is
	 * no longer required either.
	 *
	 * @return void
	 */
	public function test_an_owner_can_turn_the_password_requirement_off(): void {
		// No re-registration: the filter is read at REQUEST time precisely so an
		// owner whose filter loads late is not left with a route that still
		// demands the parameter they removed.
		add_filter( 'buddynext_account_deletion_requires_password', '__return_false' );

		$response = $this->delete_account();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $this->user_exists( $this->member ) );
	}

	/**
	 * The admin guard runs before the password check, so an administrator is told
	 * they cannot self-delete rather than being asked for a password first.
	 *
	 * @return void
	 */
	public function test_an_administrator_is_refused_before_the_password_is_asked_for(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator', 'user_pass' => self::PASSWORD ) );
		wp_set_current_user( $admin );

		$response = $this->delete_account( array( 'password' => self::PASSWORD ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'admin_cannot_self_delete', $response->get_data()['code'] ?? '' );
		$this->assertTrue( $this->user_exists( $admin ) );
	}
}
