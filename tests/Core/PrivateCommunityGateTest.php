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
	 * Gate on: a guest cannot bypass it by changing the route's case.
	 *
	 * WordPress dispatches routes case-insensitively (WP_REST_Server matches with
	 * the `i` flag), so /BuddyNext/v1/Spaces reaches the same __return_true
	 * controller /buddynext/v1/spaces does. A case-sensitive namespace test let the
	 * mixed-case path through as "not ours" — anonymous read of a private community
	 * (Zoho #41763). Every case variant must be gated.
	 */
	public function test_gate_on_blocks_mixed_case_data_routes(): void {
		update_option( PrivateCommunity::OPTION, true );
		wp_set_current_user( 0 );

		foreach ( array( '/BuddyNext/v1/Spaces', '/BUDDYNEXT/V1/SPACES', '/buddynext/v1/Spaces/Fields', '/BuddyNext-Pro/v1/Members' ) as $route ) {
			$result = PrivateCommunity::gate_rest( null, null, new WP_REST_Request( 'GET', $route ) );
			$this->assertInstanceOf( \WP_Error::class, $result, "{$route} (a case variant of a data route) must be gated." );
			$this->assertSame( 'buddynext_private_community', $result->get_error_code() );
		}
	}

	/**
	 * Gate on: an exempt surface stays open in ANY case — normalising the route
	 * without normalising the exempt prefixes would wrongly gate /BuddyNext/v1/Auth
	 * and lock a guest out of logging in.
	 */
	public function test_gate_on_keeps_mixed_case_auth_surface_open(): void {
		update_option( PrivateCommunity::OPTION, true );
		wp_set_current_user( 0 );

		foreach ( array( '/BuddyNext/v1/Auth/Login', '/BUDDYNEXT/V1/AUTH/NONCE', '/BuddyNext/v1/PWA/manifest' ) as $route ) {
			$result = PrivateCommunity::gate_rest( null, null, new WP_REST_Request( 'GET', $route ) );
			$this->assertNull( $result, "{$route} (a case variant of an exempt route) must stay reachable." );
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

	/**
	 * A namespace owner can declare its own public routes.
	 *
	 * This is the seam Pro uses for its payment webhooks and guest-facing
	 * membership routes. Before it existed the exempt list was hardcoded in
	 * Free, which could never know what Pro registers — so a paid signup on a
	 * private community was 401'd at the gate and silently provisioned nothing.
	 */
	public function test_declared_routes_are_exempt(): void {
		update_option( PrivateCommunity::OPTION, true );

		$declare = static function ( array $exempt ): array {
			$exempt[] = '/buddynext-pro/v1/stripe/membership-webhook';

			return $exempt;
		};
		add_filter( 'buddynext_private_community_exempt_routes', $declare );

		$this->assertNull(
			PrivateCommunity::gate_rest( null, null, new \WP_REST_Request( 'POST', '/buddynext-pro/v1/stripe/membership-webhook' ) ),
			'A declared route must reach its own signature check.'
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			PrivateCommunity::gate_rest( null, null, new \WP_REST_Request( 'GET', '/buddynext-pro/v1/members' ) ),
			'Declaring one route must not open the rest of the namespace.'
		);

		remove_filter( 'buddynext_private_community_exempt_routes', $declare );
	}
}
