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

	/**
	 * Channels endpoint now ships the sound toggle (notifications completion
	 * Wave C / D2). Defaults to false; PUT honours the boolean.
	 */
	public function test_channels_endpoint_includes_sound_key(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notification-channels' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'channels', $data );
		$this->assertArrayHasKey( 'sound', $data['channels'] );
		$this->assertFalse( $data['channels']['sound'] );
	}

	/**
	 * PUT /me/notification-channels with sound=true persists across reads.
	 */
	public function test_channels_endpoint_persists_sound_toggle(): void {
		wp_set_current_user( $this->user_id );

		$put = new WP_REST_Request( 'PUT', '/buddynext/v1/me/notification-channels' );
		$put->set_header( 'Content-Type', 'application/json' );
		$put->set_body( wp_json_encode( array( 'sound' => true ) ) );

		$response = rest_do_request( $put );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['channels']['sound'] );

		// Re-fetch and confirm persistence.
		$get      = new WP_REST_Request( 'GET', '/buddynext/v1/me/notification-channels' );
		$response = rest_do_request( $get );
		$data     = $response->get_data();
		$this->assertTrue( $data['channels']['sound'] );
	}

	/**
	 * Unread-count endpoint shape — relied on by the background poller (C1).
	 */
	public function test_unread_count_response_shape(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notifications/unread-count' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'count', $data );
		$this->assertIsInt( $data['count'] );
	}

	/**
	 * The mark-all-read route also accepts PUT (REST verbs were both
	 * registered in Wave 1 so the in-app dropdown could use the canonical verb).
	 */
	public function test_mark_all_read_accepts_put(): void {
		wp_set_current_user( $this->user_id );

		$this->notif_service->create(
			array(
				'recipient_id' => $this->user_id,
				'sender_id'    => $this->sender_id,
				'type'         => 'bn.new_follower',
			)
		);

		$request  = new WP_REST_Request( 'PUT', '/buddynext/v1/me/notifications/read-all' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$count_req  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notifications/unread-count' );
		$count_res  = rest_do_request( $count_req );
		$count_data = $count_res->get_data();
		$this->assertSame( 0, $count_data['count'] );
	}
}
