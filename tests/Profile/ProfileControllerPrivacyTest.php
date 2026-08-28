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
 * Privacy audience levers on PUT /me/profile.
 *
 * @covers \BuddyNext\Profile\ProfileController::update_profile
 */
class ProfileControllerPrivacyTest extends \WP_Test_REST_TestCase {

	/**
	 * The member whose privacy settings are under test.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Install the schema and seed the member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->user_id = self::factory()->user->create();
	}

	/**
	 * PUT the given body to /me/profile as the seeded member.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return \WP_REST_Response
	 */
	private function authed_put( array $body ) {
		wp_set_current_user( $this->user_id );
		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	/**
	 * Privacy audience persists.
	 *
	 * @return void
	 */
	public function test_privacy_audience_persists(): void {
		$response = $this->authed_put(
			array(
				'bn_privacy_dm'      => 'members',
				'bn_privacy_mention' => 'everyone',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'members', get_user_meta( $this->user_id, 'bn_privacy_dm', true ) );
		$this->assertSame( 'everyone', get_user_meta( $this->user_id, 'bn_privacy_mention', true ) );
	}

	/**
	 * Invalid audience returns 422.
	 *
	 * @return void
	 */
	public function test_invalid_audience_returns_422(): void {
		$response = $this->authed_put(
			array( 'bn_privacy_dm' => 'somewhere-else' )
		);

		$this->assertSame( 422, $response->get_status() );
		$body = $response->get_data();
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'errors', $body );
		$this->assertArrayHasKey( 'bn_privacy_dm', $body['errors'] );
		$this->assertFalse( $body['saved'] );
	}

	/**
	 * The bn_privacy_see_email lever is gone and must not come back by accident.
	 *
	 * It was rendered, validated and saved - and read by nothing. Worse, it offered
	 * a choice over an exposure that does not exist: BuddyNext never shows a
	 * member's email to another member. A lever that saves but never gates buys
	 * false confidence, so it was removed from the settings UI AND from the REST
	 * write allow-list. This pins the second half: the endpoint must no longer
	 * accept the key, or the control could be restored without its missing gate.
	 *
	 * Since 1.1.6 the refusal is LOUD. The endpoint used to ignore the key and
	 * answer 200, which pinned the storage but not the contract: a client could
	 * keep sending it forever and read success every time. A caller now learns
	 * that BuddyNext does not understand the key.
	 *
	 * @return void
	 */
	public function test_removed_see_email_lever_is_not_writable(): void {
		$response = $this->authed_put( array( 'bn_privacy_see_email' => 'connections' ) );

		$this->assertSame( 400, $response->get_status(), 'an unwritable key is refused, not ignored' );
		$body = $response->get_data();
		$this->assertSame( 'bn_unknown_params', $body['code'] ?? '' );
		$this->assertContains( 'bn_privacy_see_email', $body['data']['params'] ?? array() );
		$this->assertSame(
			'',
			get_user_meta( $this->user_id, 'bn_privacy_see_email', true ),
			'the dead lever must not be persisted - nothing reads it'
		);
	}

	/**
	 * Boolean privacy flags persist as string one zero.
	 *
	 * @return void
	 */
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

	/**
	 * Notification pref keys persist.
	 *
	 * @return void
	 */
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
