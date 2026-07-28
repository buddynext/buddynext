<?php
/**
 * Three small REST payload gaps the native app could not work around.
 *
 * Each one forced the client into a workaround that produced a visibly wrong UI:
 *
 *  - No GET for onboarding state, so the app kept a per-device "done" flag and
 *    re-ran the wizard for someone who had already finished it on another device.
 *  - No `current` marker on an app-password row, so the Devices screen could not
 *    label "This device" and could not stop a member revoking the credential they
 *    were signed in with.
 *  - No `digests_enabled` in the notification prefs, so the digest toggle was
 *    drawn without a trustworthy initial state.
 *
 * The third one also had an unsafe half, covered here: the card asked the PUT to
 * ACCEPT digests_enabled. It must not — `buddynext_digest_frequency` is a
 * site-wide administrator option and this route is `require_auth`, so accepting
 * it would let any logged-in member switch off email digests for everyone.
 *
 * This lives in tests/REST/ because it spans three unrelated domains and belongs
 * to none of them.
 *
 * @package BuddyNext\Tests\REST
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * App-facing payload additions.
 */
class AppPayloadGapsTest extends \WP_UnitTestCase {

	/**
	 * A regular member.
	 *
	 * @var int
	 */
	private $member = 0;

	/**
	 * Boot the schema, a member and the REST server.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->member );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Dispatch a GET as the current user.
	 *
	 * @param string $route Route path.
	 * @return array{status:int,data:array<string,mixed>}
	 */
	private function get( string $route ): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );

		return array(
			'status' => $response->get_status(),
			'data'   => (array) $response->get_data(),
		);
	}

	/**
	 * Onboarding state is now readable, so a second device does not re-run a
	 * wizard the member already finished.
	 *
	 * @return void
	 */
	public function test_onboarding_state_is_readable(): void {
		$before = $this->get( '/buddynext/v1/me/onboarding' );

		$this->assertSame( 200, $before['status'] );
		$this->assertFalse( $before['data']['complete'] );
		$this->assertSame( 1, $before['data']['step'] );
		$this->assertGreaterThan( 0, $before['data']['total'], 'total must describe THIS site, not a constant.' );

		update_user_meta( $this->member, 'bn_onboarding_complete', '1' );

		$this->assertTrue( $this->get( '/buddynext/v1/me/onboarding' )['data']['complete'] );
	}

	/**
	 * The route is member-scoped — a logged-out caller gets no state at all.
	 *
	 * @return void
	 */
	public function test_onboarding_state_requires_auth(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/buddynext/v1/me/onboarding' )['status'] );
	}

	/**
	 * Every app-password row carries the flag. It is false for a cookie-
	 * authenticated request (the web UI), which is correct — no row is "this
	 * device" when the request did not come from an app password.
	 *
	 * @return void
	 */
	public function test_app_password_rows_carry_the_current_flag(): void {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			$this->markTestSkipped( 'Application passwords are unavailable in this WordPress build.' );
		}

		$created = \WP_Application_Passwords::create_new_application_password(
			$this->member,
			array( 'name' => 'test-device' )
		);
		$this->assertNotWPError( $created );

		$result = $this->get( '/buddynext/v1/auth/app-password' );
		$rows   = (array) ( $result['data']['app_passwords'] ?? array() );

		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertArrayHasKey( 'current', $row, 'The Devices screen cannot label "This device".' );
			$this->assertIsBool( $row['current'] );
		}

		$this->assertSame(
			0,
			count( array_filter( $rows, static fn( array $r ): bool => $r['current'] ) ),
			'A cookie-authenticated request must not mark any credential as current.'
		);
	}

	/**
	 * The prefs GET reports the owner's site-wide digest switch, so the app can
	 * grey out Daily/Weekly instead of offering a choice the server ignores.
	 *
	 * @return void
	 */
	public function test_notification_prefs_report_the_digest_switch(): void {
		update_option( 'buddynext_digest_frequency', 'weekly' );
		$this->assertTrue( $this->get( '/buddynext/v1/me/notification-prefs' )['data']['digests_enabled'] );

		update_option( 'buddynext_digest_frequency', 'never' );
		$this->assertFalse( $this->get( '/buddynext/v1/me/notification-prefs' )['data']['digests_enabled'] );

		update_option( 'buddynext_digest_frequency', 'weekly' );
	}

	/**
	 * The unsafe half: a member must not be able to change a site-wide option
	 * through their own preferences route. This is the assertion that stops the
	 * card's second request from being implemented later by someone reading only
	 * the card.
	 *
	 * @return void
	 */
	public function test_a_member_cannot_switch_off_digests_for_the_whole_site(): void {
		update_option( 'buddynext_digest_frequency', 'weekly' );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/notification-prefs' );
		$request->set_body_params( array( 'digests_enabled' => false ) );
		rest_do_request( $request );

		$this->assertSame(
			'weekly',
			get_option( 'buddynext_digest_frequency' ),
			'A member changed the site-wide digest setting through their own prefs route.'
		);
	}
}
