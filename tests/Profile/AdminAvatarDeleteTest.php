<?php
/**
 * Tests for the admin avatar-delete endpoint.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Profile\ProfileController
 */
class AdminAvatarDeleteTest extends \WP_Test_REST_TestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_admin_can_delete_user_avatar(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = self::factory()->user->create();
		update_user_meta( $user, 'bn_avatar', 'http://example.test/a.png' );

		wp_set_current_user( $admin );
		$response = rest_do_request( new WP_REST_Request( 'DELETE', "/buddynext/v1/users/{$user}/avatar" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', (string) get_user_meta( $user, 'bn_avatar', true ) );
	}

	public function test_non_admin_cannot_delete_user_avatar(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = rest_do_request( new WP_REST_Request( 'DELETE', '/buddynext/v1/users/1/avatar' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
}
