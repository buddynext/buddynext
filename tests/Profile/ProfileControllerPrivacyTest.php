<?php
/**
 * Tests for the privacy + notification-preference fields accepted by
 * PUT /me/profile.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Profile\ProfileController::update_profile
 */
class ProfileControllerPrivacyTest extends \WP_Test_REST_TestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->user_id = self::factory()->user->create();
	}

	private function authed_put( array $body ) {
		wp_set_current_user( $this->user_id );
		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	public function test_privacy_audience_persists(): void {
		$response = $this->authed_put(
			array(
				'bn_privacy_see_email' => 'connections',
				'bn_privacy_dm'        => 'members',
				'bn_privacy_mention'   => 'everyone',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'connections', get_user_meta( $this->user_id, 'bn_privacy_see_email', true ) );
		$this->assertSame( 'members',     get_user_meta( $this->user_id, 'bn_privacy_dm', true ) );
		$this->assertSame( 'everyone',    get_user_meta( $this->user_id, 'bn_privacy_mention', true ) );
	}

	public function test_invalid_audience_returns_422(): void {
		$response = $this->authed_put(
			array( 'bn_privacy_see_email' => 'somewhere-else' )
		);

		$this->assertSame( 422, $response->get_status() );
		$body = $response->get_data();
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'errors', $body );
		$this->assertArrayHasKey( 'bn_privacy_see_email', $body['errors'] );
		$this->assertFalse( $body['saved'] );
	}

	public function test_boolean_privacy_flags_persist_as_string_one_zero(): void {
		$response = $this->authed_put(
			array(
				'bn_privacy_show_in_directory' => false,
				'bn_privacy_search_indexable'  => true,
				'bn_pro_hide_profile_views'    => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '0', get_user_meta( $this->user_id, 'bn_privacy_show_in_directory', true ) );
		$this->assertSame( '1', get_user_meta( $this->user_id, 'bn_privacy_search_indexable', true ) );
		$this->assertSame( '1', get_user_meta( $this->user_id, 'bn_pro_hide_profile_views', true ) );
	}

	public function test_notification_pref_keys_persist(): void {
		$response = $this->authed_put(
			array(
				'bn_pref_email_replies'  => true,
				'bn_pref_email_mentions' => false,
				'bn_pref_email_follows'  => true,
				'bn_pref_email_digest'   => false,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', get_user_meta( $this->user_id, 'bn_pref_email_replies', true ) );
		$this->assertSame( '0', get_user_meta( $this->user_id, 'bn_pref_email_mentions', true ) );
		$this->assertSame( '1', get_user_meta( $this->user_id, 'bn_pref_email_follows', true ) );
		$this->assertSame( '0', get_user_meta( $this->user_id, 'bn_pref_email_digest', true ) );
	}
}
