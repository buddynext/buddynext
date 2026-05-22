<?php
/**
 * Tests for the notification-prefs REST surface.
 *
 * Covers:
 *   GET  /me/notification-prefs    — returns one row per catalogue type with
 *                                   defaults filled.
 *   PUT  /me/notification-prefs    — accepts a partial map and persists.
 *   PUT  /me/notification-prefs    — 422 on invalid email_freq.
 *   GET  /me/notification-prefs    — 401 anonymous.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationPrefCatalogue;
use BuddyNext\Notifications\NotificationPrefService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Notifications\NotificationController
 */
class NotificationPrefControllerTest extends \WP_Test_REST_TestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->user_id = self::factory()->user->create();
	}

	public function test_get_prefs_requires_auth(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notification-prefs' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_get_prefs_returns_one_row_per_catalogue_type_with_defaults(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notification-prefs' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'prefs', $data );

		$catalogue = ( new NotificationPrefCatalogue() )->all();
		foreach ( array_keys( $catalogue ) as $type ) {
			$this->assertArrayHasKey( $type, $data['prefs'], "missing pref row for {$type}" );
			$this->assertArrayHasKey( 'on_site', $data['prefs'][ $type ] );
			$this->assertArrayHasKey( 'email_freq', $data['prefs'][ $type ] );
		}
	}

	public function test_get_prefs_overlays_stored_values_on_top_of_defaults(): void {
		wp_set_current_user( $this->user_id );

		$service = new NotificationPrefService();
		$service->set_pref(
			$this->user_id,
			'bn.new_follower',
			array(
				'on_site'    => false,
				'email_freq' => 'weekly',
			)
		);

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notification-prefs' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['prefs']['bn.new_follower']['on_site'] );
		$this->assertSame( 'weekly', $data['prefs']['bn.new_follower']['email_freq'] );
	}

	public function test_put_prefs_accepts_partial_map_and_persists(): void {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/notification-prefs' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'bn.new_follower' => array(
						'on_site'    => false,
						'email_freq' => 'daily',
					),
				)
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['prefs']['bn.new_follower']['on_site'] );
		$this->assertSame( 'daily', $data['prefs']['bn.new_follower']['email_freq'] );

		// Verify persistence via the service layer.
		$stored = ( new NotificationPrefService() )->get_pref( $this->user_id, 'bn.new_follower' );
		$this->assertFalse( $stored['on_site'] );
		$this->assertSame( 'daily', $stored['email_freq'] );
	}

	public function test_put_prefs_returns_422_on_invalid_email_freq(): void {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/notification-prefs' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'bn.new_follower' => array(
						'email_freq' => 'never',
					),
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
	}

	public function test_put_prefs_returns_400_on_empty_body(): void {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/notification-prefs' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array() ) );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_get_channels_defaults_and_persists(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/notification-channels' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'channels', $data );
		$this->assertTrue( $data['channels']['in_app'] );
		$this->assertTrue( $data['channels']['email'] );

		$put = new WP_REST_Request( 'PUT', '/buddynext/v1/me/notification-channels' );
		$put->set_header( 'content-type', 'application/json' );
		$put->set_body( wp_json_encode( array( 'email' => false ) ) );

		$put_response = rest_do_request( $put );
		$put_data     = $put_response->get_data();

		$this->assertSame( 200, $put_response->get_status() );
		$this->assertFalse( $put_data['channels']['email'] );
	}
}
