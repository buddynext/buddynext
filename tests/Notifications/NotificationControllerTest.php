<?php
/**
 * Tests for NotificationController REST endpoints.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Notifications\NotificationController
 */
class NotificationControllerTest extends \WP_Test_REST_TestCase {

	private NotificationService $notif_service;
	private int $user_id;
	private int $sender_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->notif_service = new NotificationService();
		$this->user_id       = self::factory()->user->create();
		$this->sender_id     = self::factory()->user->create();
	}

	public function test_list_notifications_requires_auth(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notifications' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_list_notifications_returns_200(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notifications' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_list_notifications_response_shape(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notifications' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'next_cursor', $data );
	}

	public function test_list_notifications_items_include_message(): void {
		wp_set_current_user( $this->user_id );

		$this->notif_service->create(
			array(
				'recipient_id' => $this->user_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
				'object_type'  => 'user',
				'object_id'    => $this->sender_id,
			)
		);

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notifications' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $data['items'] );

		$first = $data['items'][0];
		$this->assertArrayHasKey( 'message', $first );
		$this->assertArrayHasKey( 'url', $first );
		$this->assertArrayHasKey( 'icon', $first );
		$this->assertArrayHasKey( 'tone', $first );
		$this->assertArrayHasKey( 'label', $first );
		$this->assertArrayHasKey( 'actor_name', $first );

		$this->assertNotSame( '', $first['message'] );
		$this->assertStringContainsString( 'started following you', $first['message'] );
		$this->assertStringNotContainsString( 'sent you a notification', $first['message'] );
	}

	public function test_unread_count_requires_auth(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notifications/unread-count' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_unread_count_returns_correct_value(): void {
		wp_set_current_user( $this->user_id );

		$this->notif_service->create(
			array(
				'recipient_id' => $this->user_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
			)
		);

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notifications/unread-count' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'count', $data );
		$this->assertSame( 1, $data['count'] );
	}

	public function test_mark_read_requires_auth(): void {
		$notif_id = $this->notif_service->create(
			array(
				'recipient_id' => $this->user_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
			)
		);

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/me/notifications/' . $notif_id . '/read' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_mark_all_read_returns_200(): void {
		wp_set_current_user( $this->user_id );

		$this->notif_service->create(
			array(
				'recipient_id' => $this->user_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
			)
		);

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/me/notifications/read-all' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}
}
