<?php
/**
 * Tests for OnboardingController REST endpoints.
 *
 * @package BuddyNext\Tests\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Onboarding;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * Covers route registration, auth gating, and the four onboarding actions.
 *
 * @covers \BuddyNext\Onboarding\OnboardingController
 */
class OnboardingControllerTest extends \WP_Test_REST_TestCase {

	/**
	 * Test user provisioned in set_up().
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Boot the installer and create a fresh test user before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->user_id = self::factory()->user->create();
	}

	// ── Route registration ────────────────────────────────────────────────────

	/**
	 * POST /me/onboarding/step is registered.
	 */
	public function test_step_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );
		$this->assertArrayHasKey( '/buddynext/v1/me/onboarding/step', $routes );
	}

	/**
	 * POST /me/onboarding/skip is registered.
	 */
	public function test_skip_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );
		$this->assertArrayHasKey( '/buddynext/v1/me/onboarding/skip', $routes );
	}

	/**
	 * POST /me/onboarding/complete is registered.
	 */
	public function test_complete_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );
		$this->assertArrayHasKey( '/buddynext/v1/me/onboarding/complete', $routes );
	}

	/**
	 * POST /me/interests is registered.
	 */
	public function test_interests_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );
		$this->assertArrayHasKey( '/buddynext/v1/me/interests', $routes );
	}

	// ── Auth gating ───────────────────────────────────────────────────────────

	/**
	 * Step endpoint rejects unauthenticated callers.
	 */
	public function test_step_requires_auth(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/step' );
		$request->set_param( 'step', 1 );
		$response = rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Skip endpoint rejects unauthenticated callers.
	 */
	public function test_skip_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/skip' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Complete endpoint rejects unauthenticated callers.
	 */
	public function test_complete_requires_auth(): void {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/complete' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	// ── POST /me/onboarding/step ─────────────────────────────────────────────

	/**
	 * Saving step 1 persists display_name and advances the step counter.
	 */
	public function test_save_step_advances_and_responds(): void {
		wp_set_current_user( $this->user_id );
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/step' );
		$request->set_param( 'step', 1 );
		$request->set_param( 'data', array( 'display_name' => 'Alice' ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['saved'] );
		$this->assertSame( 2, $data['next_step'] );
		$this->assertSame( 'Alice', get_userdata( $this->user_id )->display_name );
	}

	// ── POST /me/onboarding/skip ─────────────────────────────────────────────

	/**
	 * Skip marks the wizard complete for the current user.
	 */
	public function test_skip_marks_complete(): void {
		wp_set_current_user( $this->user_id );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/skip' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', (string) get_user_meta( $this->user_id, 'bn_onboarding_complete', true ) );
	}

	// ── POST /me/onboarding/complete ─────────────────────────────────────────

	/**
	 * Complete marks done, persists interests, and returns a redirect target.
	 */
	public function test_complete_marks_complete_and_stores_interests(): void {
		wp_set_current_user( $this->user_id );
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/complete' );
		$request->set_param( 'interests', array( 'Web Dev', 'Design' ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['completed'] );
		$this->assertSame( '1', (string) get_user_meta( $this->user_id, 'bn_onboarding_complete', true ) );
		$this->assertSame( 'Web Dev,Design', (string) get_user_meta( $this->user_id, 'bn_interests', true ) );
	}

	/**
	 * The complete endpoint fires buddynext_onboarding_completed exactly once.
	 */
	public function test_complete_fires_buddynext_onboarding_completed_once(): void {
		wp_set_current_user( $this->user_id );
		$count = 0;
		add_action(
			'buddynext_onboarding_completed',
			static function () use ( &$count ): void {
				++$count;
			}
		);
		rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/complete' ) );
		rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/me/onboarding/complete' ) );
		$this->assertSame( 1, $count );
	}

	// ── POST /me/interests ───────────────────────────────────────────────────

	/**
	 * Save-interests endpoint stores the labels under bn_interests.
	 */
	public function test_save_interests_persists_labels(): void {
		wp_set_current_user( $this->user_id );
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/me/interests' );
		$request->set_param( 'interests', array( 'AI & ML', 'Startups' ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'AI & ML,Startups', (string) get_user_meta( $this->user_id, 'bn_interests', true ) );
	}
}
