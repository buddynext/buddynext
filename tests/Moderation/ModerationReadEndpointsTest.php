<?php
/**
 * Tests for the app-readiness moderation read endpoints.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Moderation\ModerationController
 */
class ModerationReadEndpointsTest extends \WP_Test_REST_TestCase {

	private ModerationService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ModerationService();
	}

	public function test_me_appeals_lists_own_appeals(): void {
		$user  = self::factory()->user->create();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->service->suspend_user( $user, $admin, 'spam' );
		$this->service->create_appeal( $user, 'mine' );

		wp_set_current_user( $user );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/appeals' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
		$this->assertSame( $user, (int) $response->get_data()[0]['user_id'] );
	}

	public function test_me_appeals_requires_login(): void {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/appeals' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_user_warnings_requires_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/users/1/warnings' ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_user_warnings_returns_entries_for_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = self::factory()->user->create();
		$this->service->warn( $user, $admin, 'be nice' );

		wp_set_current_user( $admin );
		$response = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$user}/warnings" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
	}

	public function test_shadow_ban_status_for_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = self::factory()->user->create();
		wp_set_current_user( $admin );
		$this->service->shadow_ban( $user, $admin );

		$response = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$user}/shadow-ban" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['shadow_banned'] );
	}

	public function test_user_suspensions_for_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = self::factory()->user->create();
		$this->service->suspend_user( $user, $admin, 'spam' );

		wp_set_current_user( $admin );
		$response = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$user}/suspensions" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
	}
}
