<?php
/**
 * Tests for the admin pending-appeals list endpoint.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Moderation\ModerationController::list_appeals
 * @covers \BuddyNext\Moderation\ModerationService::get_pending_appeals
 * @covers \BuddyNext\Moderation\ModerationService::count_pending_appeals
 */
class AppealsListTest extends \WP_Test_REST_TestCase {

	private ModerationService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ModerationService();
	}

	private function seed_pending_appeal(): int {
		$user  = self::factory()->user->create();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->service->suspend_user( $user, $admin, 'spam' );
		$this->service->create_appeal( $user, 'Please reconsider' );
		return $user;
	}

	public function test_list_appeals_requires_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/appeals' );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'page', 1 );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_list_appeals_returns_envelope_for_admin(): void {
		$this->seed_pending_appeal();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/appeals' );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'page', 1 );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertSame( 1, $data['total'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertArrayHasKey( 'message', $data['items'][0] );
		$this->assertSame( 'pending', $data['items'][0]['status'] );
	}

	public function test_count_pending_appeals(): void {
		$this->seed_pending_appeal();
		$this->seed_pending_appeal();
		$this->assertSame( 2, $this->service->count_pending_appeals() );
	}
}
