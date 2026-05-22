<?php
/**
 * Tests for the GET /users/{id}/following REST endpoint.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\SocialGraph\FollowController::get_following
 */
class FollowingControllerTest extends \WP_Test_REST_TestCase {

	private int $owner_id;
	private int $target_a;
	private int $target_b;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->owner_id = self::factory()->user->create();
		$this->target_a = self::factory()->user->create();
		$this->target_b = self::factory()->user->create();

		\buddynext_service( 'follows' )->follow( $this->owner_id, $this->target_a );
		\buddynext_service( 'follows' )->follow( $this->owner_id, $this->target_b );
	}

	public function test_get_following_returns_200_and_ids_array(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->owner_id . '/following' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'ids', $data );
		$this->assertCount( 2, $data['ids'] );
		$this->assertContains( $this->target_a, $data['ids'] );
		$this->assertContains( $this->target_b, $data['ids'] );
	}

	public function test_get_following_filters_blocked(): void {
		$viewer = self::factory()->user->create();
		wp_set_current_user( $viewer );

		\buddynext_service( 'blocks' )->block( $viewer, $this->target_b );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->owner_id . '/following' );
		$response = rest_do_request( $request );

		$data = $response->get_data();
		$this->assertContains( $this->target_a, $data['ids'] );
		$this->assertNotContains( $this->target_b, $data['ids'] );
	}

	public function test_get_following_returns_empty_array_for_user_with_no_followings(): void {
		$lonely = self::factory()->user->create();

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $lonely . '/following' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['ids'] );
	}
}
