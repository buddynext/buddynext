<?php
/**
 * Native private-community lockdown.
 *
 * When enabled (Settings → Privacy & Data → Private Community), every
 * BuddyNext-routed front-end page AND its REST data require login — only the auth
 * surface (login / register / password-reset / verify) stays public. This closes
 * the gap a paying customer hit (Zoho #40662): BuddyNext serves its pages through
 * its own routing, so a membership plugin (Paid Memberships Pro / WP Fusion) that
 * only gates WordPress pages never saw those routes and the community leaked to
 * logged-out visitors. Rather than depend on a third-party gate reaching our
 * routes, we make the pages + data unreachable when logged out, natively.
 *
 * Two seams:
 *   - Pages: PageRouter::dispatch_hub_template() redirects logged-out hub visitors
 *            to the auth page (this class only supplies is_enabled()).
 *   - REST:  gate_rest() below returns 401 for any buddynext(-pro)/v1 route except
 *            the /auth/ surface, so the data cannot be read out from under the pages.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Private-community access control.
 */
final class PrivateCommunity {

	/**
	 * Option storing the on/off toggle (registered via the Privacy settings
	 * descriptor; default off — communities are public unless the owner opts in).
	 *
	 * @var string
	 */
	public const OPTION = 'buddynext_private_community';

	/**
	 * Whether private-community mode is on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Wire the REST gate. The page gate lives in
	 * PageRouter::dispatch_hub_template() (it needs the resolved hub).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'gate_rest' ), 10, 3 );
	}

	/**
	 * Block BuddyNext REST data for logged-out visitors while private mode is on.
	 *
	 * The /auth/ surface stays open so a guest can still log in, register, reset a
	 * password, verify email, or refresh a nonce; every other buddynext(-pro)/v1
	 * route returns 401. Non-BuddyNext namespaces (wp/v2, other plugins) are never
	 * touched. Runs before dispatch so no controller callback executes.
	 *
	 * @param mixed            $result  Pre-dispatch short-circuit (WP_Error/response) or null.
	 * @param \WP_REST_Server  $server  REST server (unused).
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed Unchanged result, or a 401 WP_Error for a gated route.
	 */
	public static function gate_rest( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $server is part of the rest_pre_dispatch signature.
		if ( null !== $result || is_user_logged_in() || ! self::is_enabled() ) {
			return $result;
		}
		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();
		// Only our namespaces.
		if ( ! preg_match( '#^/buddynext(?:-pro)?/v1/#', $route ) ) {
			return $result;
		}
		// Login / register / reset / verify / 2fa must stay reachable so a guest can
		// authenticate — that is the whole point of a login page.
		if ( preg_match( '#^/buddynext/v1/auth(?:/|$)#', $route ) ) {
			return $result;
		}

		return new \WP_Error(
			'buddynext_private_community',
			__( 'This community is private. Please log in to view it.', 'buddynext' ),
			array( 'status' => 401 )
		);
	}
}
