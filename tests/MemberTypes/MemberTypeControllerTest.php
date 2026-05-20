<?php
/**
 * Tests for MemberTypeController REST endpoints.
 *
 * @package BuddyNext\Tests\MemberTypes
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\MemberTypes;

use BuddyNext\Core\CacheService;
use BuddyNext\Core\Installer;
use BuddyNext\MemberTypes\MemberTypeService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\MemberTypes\MemberTypeController
 */
class MemberTypeControllerTest extends \WP_Test_REST_TestCase {

	private MemberTypeService $service;
	private int $admin_id;
	private int $regular_user_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service        = new MemberTypeService( new CacheService() );
		$this->admin_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->regular_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	// ── Route registration ────────────────────────────────────────────────────

	public function test_list_types_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );

		$this->assertArrayHasKey( '/buddynext/v1/member-types', $routes );
	}

	public function test_user_member_type_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );

		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/member-type', $routes );
	}

	// ── GET /member-types (public) ────────────────────────────────────────────

	public function test_list_types_is_publicly_accessible(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/member-types' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_list_types_returns_created_types(): void {
		wp_set_current_user( $this->admin_id );

		$this->service->create( array( 'slug' => 'bronze', 'name' => 'Bronze' ) );
		$this->service->create( array( 'slug' => 'silver', 'name' => 'Silver' ) );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/member-types' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );

		$slugs = array_column( $data, 'slug' );
		$this->assertContains( 'bronze', $slugs );
		$this->assertContains( 'silver', $slugs );
	}

	// ── POST /member-types (admin only) ───────────────────────────────────────

	public function test_create_type_requires_admin(): void {
		wp_set_current_user( $this->regular_user_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/member-types' );
		$request->set_body_params( array( 'slug' => 'gold', 'name' => 'Gold' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_create_type_requires_auth(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/member-types' );
		$request->set_body_params( array( 'slug' => 'gold', 'name' => 'Gold' ) );
		$response = rest_do_request( $request );

		// Unauthenticated → 401 or 403 depending on WP version.
		$this->assertGreaterThanOrEqual( 401, $response->get_status() );
	}

	public function test_create_type_returns_201_for_admin(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/member-types' );
		$request->set_body_params(
			array(
				'slug'  => 'gold',
				'name'  => 'Gold',
				'color' => '#ffd700',
			)
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'gold', $data['slug'] );
		$this->assertSame( 'Gold', $data['name'] );
	}

	public function test_create_type_returns_error_for_duplicate_slug(): void {
		wp_set_current_user( $this->admin_id );

		$this->service->create( array( 'slug' => 'platinum', 'name' => 'Platinum' ) );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/member-types' );
		$request->set_body_params( array( 'slug' => 'platinum', 'name' => 'Duplicate' ) );
		$response = rest_do_request( $request );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	// ── DELETE /member-types/{slug} (admin only) ──────────────────────────────

	public function test_delete_type_requires_admin(): void {
		$this->service->create( array( 'slug' => 'diamond', 'name' => 'Diamond' ) );

		wp_set_current_user( $this->regular_user_id );

		$request  = new WP_REST_Request( 'DELETE', '/buddynext/v1/member-types/diamond' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_delete_type_returns_200_for_admin(): void {
		$this->service->create( array( 'slug' => 'emerald', 'name' => 'Emerald' ) );

		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'DELETE', '/buddynext/v1/member-types/emerald' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( 'emerald', $data['slug'] );
	}

	public function test_delete_nonexistent_type_returns_404(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'DELETE', '/buddynext/v1/member-types/does-not-exist' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	// ── GET /users/{id}/member-type (public) ──────────────────────────────────

	public function test_get_user_type_returns_200_when_no_type_assigned(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->regular_user_id . '/member-type' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}
}
