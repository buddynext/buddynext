<?php
/**
 * GET /me/social: the member's sign-in accounts as Settings lists them.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\AuthController;
use BuddyNext\Auth\SocialLogin;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The app could unlink a provider (DELETE /me/social/{provider}) but not see
 * which ones were linked, or that unlinking the last one is refused.
 *
 * @covers \BuddyNext\Auth\SocialLogin::account_rows
 */
class ConnectedAccountsRestTest extends WP_UnitTestCase {

	/**
	 * The current member's rows over REST.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		return rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/social' ) )->get_data();
	}

	/**
	 * Linked providers are listed; the only way in is flagged and its unlink refused.
	 *
	 * @return void
	 */
	public function test_linked_accounts_and_last_credential(): void {
		$member = self::factory()->user->create();
		update_user_meta( $member, 'bn_social_google_id', 'g-1' );
		wp_set_current_user( $member );

		$rows = $this->rows();
		$this->assertSame( array( 'google' ), wp_list_pluck( $rows, 'id' ), 'Linked, not configured: still listed.' );
		$this->assertTrue( $rows[0]['linked'] );
		$this->assertFalse( $rows[0]['only_credential'], 'They have a password too.' );

		AuthController::mark_password_generated( $member ); // A social signup: no password they know.
		$rows = $this->rows();
		$this->assertTrue( $rows[0]['only_credential'], 'Google is their only way in.' );
		$this->assertSame( SocialLogin::is_last_credential( $member, 'google' ), $rows[0]['only_credential'], 'Same rule the unlink endpoint enforces.' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/social' ) )->get_status() );
	}
}
