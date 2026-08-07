<?php
/**
 * The private-community REST gate.
 *
 * Private mode must lock the buddynext(-pro)/v1 data surface for guests while
 * keeping the two surfaces a BROWSER (not a member) has to fetch reachable:
 * the /auth/ surface so a guest can log in at all, and the /pwa/ app shell,
 * which the browser requests without credentials (manifest) or without a
 * nonce (service worker) — so a session gate 401s it even for logged-in
 * members (Basecamp 10180597390).
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PrivateCommunity;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\PrivateCommunity
 */
class PrivateCommunityGateTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( PrivateCommunity::OPTION );
		remove_all_filters( 'buddynext_private_community_can_access' );
		parent::tear_down();
	}

	/**
	 * Gate off: guests pass everywhere.
	 */
	public function test_gate_off_passes_guests(): void {
		wp_set_current_user( 0 );

		$result = PrivateCommunity::gate_rest( null, null, new WP_REST_Request( 'GET', '/buddynext/v1/members' ) );
		$this->assertNull( $result );
	}

	/**
	 * Gate on: guests are blocked on the data surface.
	 */
	public function test_gate_on_blocks_guest_data_routes(): void {
		update_option( PrivateCommunity::OPTION, true );
		wp_set_current_user( 0 );

		$result = PrivateCommunity::gate_rest( null, null, new WP_REST_Request( 'GET', '/buddynext/v1/members' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'buddynext_private_community', $result->get_error_code() );
	}

	/**
	 * Gate on: members pass.
	 */
	public function test_gate_on_passes_members(): void {
		update_option( PrivateCommunity::OPTION, true );
		wp_set_current_user( self::factory()->user->create() );

		$result = PrivateCommunity::gate_rest( null, null, new WP_REST_Request( 'GET', '/buddynext/v1/members' ) );
		$this->assertNull( $result );
	}

	/**
	 * Gate on: the auth surface stays reachable so a guest can log in.
	 */
	public function test_gate_on_keeps_auth_surface_open(): void {
		update_option( PrivateCommunity::OPTION, true );
		wp_set_current_user( 0 );

		$result = PrivateCommunity::gate_rest( null, null, new WP_REST_Request( 'POST', '/buddynext/v1/auth/login' ) );
		$this->assertNull( $result );
	}

	/**
	 * Gate on: the PWA app shell stays reachable — the browser fetches the
	 * manifest with NO credentials and the service worker with no nonce, so
	 * these requests are always anonymous to the gate. Blocking them logs a
	 * 401 console error on EVERY page for EVERY visitor, members included,
	 * and kills add-to-home-screen (Basecamp 10180597390). The routes serve
	 * only app-shell assets (name, icons, offline page), no member data.
	 */
	public function test_gate_on_keeps_pwa_app_shell_open(): void {
		update_option( PrivateCommunity::OPTION, true );
		wp_set_current_user( 0 );

		foreach ( array( '/buddynext/v1/pwa/manifest', '/buddynext/v1/pwa/sw', '/buddynext/v1/pwa/offline' ) as $route ) {
			$result = PrivateCommunity::gate_rest( null, null, new WP_REST_Request( 'GET', $route ) );
			$this->assertNull( $result, "{$route} must stay publicly fetchable under private mode." );
		}
	}

	/**
	 * The exemption is the /pwa/ segment exactly — a lookalike prefix in the
	 * same namespace is still gated.
	 */
	public function test_pwa_lookalike_routes_stay_gated(): void {
		update_option( PrivateCommunity::OPTION, true );
		wp_set_current_user( 0 );

		$result = PrivateCommunity::gate_rest( null, null, new WP_REST_Request( 'GET', '/buddynext/v1/pwafake' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
