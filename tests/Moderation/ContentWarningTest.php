<?php
/**
 * Tests for content-warning service methods.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::set_post_content_warning
 * @covers \BuddyNext\Moderation\ModerationService::get_post_content_warning
 */
class ContentWarningTest extends \WP_UnitTestCase {

	private ModerationService $service;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ModerationService();

		// set_post_content_warning() is gated behind manage_options; direct-call
		// tests must act as an admin (the REST round-trip test sets its own admin).
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id' => self::factory()->user->create(),
				'content' => 'hello',
				'status'  => 'published',
			)
		);
		$this->post_id = (int) $wpdb->insert_id;
	}

	public function test_set_then_get_content_warning(): void {
		$result = $this->service->set_post_content_warning( $this->post_id, true, 'nsfw' );
		$this->assertTrue( $result );

		$warning = $this->service->get_post_content_warning( $this->post_id );
		$this->assertIsArray( $warning );
		$this->assertTrue( $warning['has_warning'] );
		$this->assertSame( 'nsfw', $warning['warning_type'] );
	}

	public function test_clear_content_warning(): void {
		$this->service->set_post_content_warning( $this->post_id, true, 'nsfw' );
		$this->service->set_post_content_warning( $this->post_id, false, 'nsfw' );

		$warning = $this->service->get_post_content_warning( $this->post_id );
		$this->assertFalse( $warning['has_warning'] );
	}

	public function test_get_returns_null_for_missing_post(): void {
		$this->assertNull( $this->service->get_post_content_warning( 999999 ) );
	}

	public function test_set_returns_null_for_missing_post(): void {
		$this->assertNull( $this->service->set_post_content_warning( 999999, true, 'nsfw' ) );
	}

	public function test_rest_set_and_get_round_trip(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$set = new \WP_REST_Request( 'PUT', "/buddynext/v1/posts/{$this->post_id}/content-warning" );
		$set->set_body_params(
			array(
				'content_warning'      => true,
				'content_warning_type' => 'spoilers',
			)
		);
		$set_response = rest_do_request( $set );
		$this->assertSame( 200, $set_response->get_status() );

		$get_response = rest_do_request(
			new \WP_REST_Request( 'GET', "/buddynext/v1/posts/{$this->post_id}/content-warning" )
		);
		$this->assertSame( 200, $get_response->get_status() );
		$data = $get_response->get_data();
		$this->assertTrue( $data['has_warning'] );
		$this->assertSame( 'spoilers', $data['warning_type'] );
	}

	public function test_rest_get_returns_404_for_missing_post(): void {
		$response = rest_do_request(
			new \WP_REST_Request( 'GET', '/buddynext/v1/posts/999999/content-warning' )
		);
		$this->assertSame( 404, $response->get_status() );
	}
}
